<?php
/**
 * 长文分段翻译：单次 JSON 生成超长/超时失败时的兜底。
 * 用法：docker compose exec -T php php tools/translate-long.php --id 48 --id 153 [--log 路径]
 * - 元数据（title/excerpt/keywords/description）用小 JSON 请求；
 * - 正文按 ~4500 字符分块逐段翻译（纯文本输出）后 \n\n 拼接，整篇直接 publish（ai_generated=1）。
 */
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';

$ids = [];
$logPath = '';
$langCode = 'en';
$args = $argv; array_shift($args);
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--id') { $ids[] = (int)($args[++$i] ?? 0); continue; }
    if (str_starts_with($args[$i], '--id=')) { $ids[] = (int)substr($args[$i], 5); continue; }
    if ($args[$i] === '--lang') { $langCode = (string)($args[++$i] ?? 'en'); continue; }
    if (str_starts_with($args[$i], '--lang=')) { $langCode = substr($args[$i], 7); }
    if ($args[$i] === '--log') { $logPath = (string)($args[++$i] ?? ''); }
    if (str_starts_with($args[$i], '--log=')) { $logPath = substr($args[$i], 6); }
    if ($args[$i] === '--budget') { $budget = max(600, (int)($args[++$i] ?? 4500)); }
    if (str_starts_with($args[$i], '--budget=')) { $budget = max(600, (int)substr($args[$i], 9)); }
}
$budget = $budget ?? 4500;
$langCode = preg_replace('/[^a-z]/', '', strtolower($langCode)) ?: 'en';
$langName = Poly::label($langCode);
$ids = array_values(array_filter($ids, fn($v) => $v > 0));
if (!$ids) { fwrite(STDERR, "usage: --id N [--id M] [--log path]\n"); exit(2); }

$logFh = $logPath !== '' ? @fopen($logPath, 'ab') : null;
function clog(string $s): void { global $logFh; if ($logFh) { fwrite($logFh, date('H:i:s') . ' ' . $s . "\n"); } }

$base  = (string)get_option('ai.base', 'https://api.deepseek.com/chat/completions');
$key   = yc_opt('ai.key', '');
$model = (string)get_option('ai.model', 'deepseek-chat');
$protect = (string)get_option('ai.protect', 'YeCha,夜叉');
if ($key === '') { fwrite(STDERR, "no ai.key\n"); exit(2); }

/** 一次 chat 调用；$json=true 期望 JSON 输出 */
function chat(string $sys, string $user, bool $json, int $maxTok = 4096): string
{
    global $base, $key, $model;
    $payload = ['model' => $model, 'messages' => [
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user', 'content' => $user],
    ], 'temperature' => 0.3, 'max_tokens' => $maxTok];
    if ($json) { $payload['response_format'] = ['type' => 'json_object']; }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$key}\r\n",
        'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'timeout' => 180,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($base, false, $ctx);
    if ($resp === false) { throw new RuntimeException('网络/地址请求失败'); }
    $j = json_decode($resp, true);
    $text = trim((string)($j['choices'][0]['message']['content'] ?? ''));
    if ($text === '') { throw new RuntimeException('AI 返回为空：' . ($j['error']['message'] ?? '未知')); }
    return $text;
}

function withRetry(callable $fn, int $tries = 3): string
{
    $last = '';
    for ($i = 1; $i <= $tries; $i++) {
        try { return $fn(); } catch (Throwable $e) { $last = $e->getMessage(); usleep(400000); }
    }
    throw new RuntimeException('重试 ' . $tries . ' 次仍失败：' . $last);
}

/** 目标语言下最长连续汉字串长度（zh 恒 0）；ja 允许较多汉字词，其余语言阈值更低 */
function zhRunLen(string $s): int
{
    global $langCode;
    if ($langCode === 'zh') { return 0; }
    preg_match_all('/[\x{4E00}-\x{9FFF}]+/u', $s, $m);
    $max = 0;
    foreach ($m[0] as $run) { $max = max($max, mb_strlen($run)); }
    return $max;
}

$T = Db::t('contents');
$okAll = 0; $failAll = 0;
foreach ($ids as $baseId) {
    $baseRow = Db::row("SELECT * FROM {$T} WHERE id=? AND type='post'", [$baseId]);
    if ($baseRow === null) { clog("skip #{$baseId}: 不存在"); continue; }
    $title = (string)$baseRow['title'];
    clog("== #{$baseId} {$title} len=" . mb_strlen((string)$baseRow['content']) . " 开始分段翻译");
    try {
        // 1) 元数据（小 JSON）
        $metaSys = 'Translate blog metadata from Chinese into natural, fluent ' . $langName . '. Return ONLY JSON {"title":"...","excerpt":"...","keywords":"...","description":"..."}. Protected terms (keep as-is): ' . $protect;
        $meta = json_decode(withRetry(fn() => chat($metaSys,
            "Title: {$title}\n\nExcerpt:\n{$baseRow['excerpt']}", true)), true);
        if (!is_array($meta) || trim((string)($meta['title'] ?? '')) === '') {
            throw new RuntimeException('元数据 JSON 解析失败');
        }
        // 2) 正文分段
        $sysPart = 'Translate the following Chinese blog text chunk into natural, fluent ' . $langName . '. '
            . 'Preserve all Markdown syntax, code blocks, and image/link URLs exactly; keep their order and alt-text meaning. '
            . 'Do not translate these protected terms: ' . $protect . '. '
            . 'Output ONLY the translated Markdown text — no JSON, no code fences, no commentary.';
        $content = (string)$baseRow['content'];
        $segs = [];
        $pos = 0; $n = mb_strlen($content);
        $chunkIdx = 0;
        while ($pos < $n) {
            $chunkIdx++;
            $len = min($budget, $n - $pos);
            $seg = mb_substr($content, $pos, $len);
            // 尽量在换行处收尾，避免把代码块/列表拦腰截断
            if ($pos + $len < $n) {
                $nl = mb_strrpos($seg, "\n");
                if ($nl !== false && $nl > $budget * 0.6) { $seg = mb_substr($seg, 0, $nl); }
            }
            $part = withRetry(fn() => chat($sysPart, "[chunk {$chunkIdx}]\n{$seg}", false, 8192));
            $part = trim(preg_replace('/^```(?:markdown)?\s*/', '', $part));
            $part = trim(preg_replace('/```\s*$/', '', $part));
            if ($part === '') { throw new RuntimeException("chunk {$chunkIdx} 返回为空"); }
            // 防整段中文漏翻：连续汉字超过阈值时带警告重译该 chunk
            $zhThr = ($langCode === 'ja') ? 16 : 7;
            $try = 0;
            while (zhRunLen($part) > $zhThr && $try < 3) {
                $try++;
                clog("  #{$baseId} chunk {$chunkIdx} 含长中文段，重译 {$try}");
                $warn = $sysPart . "\n\n注意：上一轮该段仍包含较长中文/繁体内容。请把该段完整翻译成 " . $langName . '，不要保留中文句子。';
                $part2 = withRetry(fn() => chat($warn, "[chunk {$chunkIdx}]\n{$seg}", false, 8192));
                $part2 = trim(preg_replace('/^```(?:markdown)?\s*/', '', $part2));
                $part2 = trim(preg_replace('/```\s*$/', '', $part2));
                if ($part2 !== '') { $part = $part2; }
            }
            if (zhRunLen($part) > $zhThr) { clog("  WARN #{$baseId} chunk {$chunkIdx} 仍有长中文段（保留原文，可人工修订）"); }
            $segs[] = $part;
            $pos += mb_strlen($seg);
            clog("  #{$baseId} chunk {$chunkIdx} done (" . mb_strlen($seg) . ' chars)');
        }
        // 3) 落库发布
        $excerpt = Str::cleanMd(trim((string)($meta['excerpt'] ?? '')));
        $desc = mb_substr(Str::cleanMd(trim((string)($meta['description'] ?? ''))), 0, 300);
        $d = [
            'group_id' => $baseId, 'lang' => $langCode,
            'title' => trim((string)$meta['title']),
            'slug' => (string)$baseRow['slug'],
            'excerpt' => $excerpt,
            'keywords' => (string)($meta['keywords'] ?? ''),
            'description' => $desc,
            'content' => implode("\n\n", $segs),
            'status' => 'publish',
            'ai_generated' => 1,
            'source_lang' => 'zh',
        ];
        $old = Poly::row($baseId, $langCode);
        if ($old !== null) {
            Db::update('poly_contents', $d, 'id=?', [(int)$old['id']]);
        } else {
            Db::insert('poly_contents', $d);
        }
        Cache::flush('pages');
        Cache::flush('blocks');
        clog("OK #{$baseId} 分段完成，译文 " . mb_strlen($d['content']) . ' 字符');
        $okAll++;
    } catch (Throwable $e) {
        clog("FAIL #{$baseId} :: " . $e->getMessage());
        $failAll++;
    }
}
clog("---- translate-long done OK={$okAll} FAIL={$failAll}");
echo "OK={$okAll} FAIL={$failAll}\n";
exit($failAll > 0 ? 1 : 0);
