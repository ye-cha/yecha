<?php
/**
 * 批量 AI 翻译：把全部已发布博文翻译为指定语言（默认 en）并直接发布。
 * 幂等可续跑：已存在「已发布」译文的分组自动跳过；中途中断后重跑会接着完成。
 *
 * 用法（在 docker/ 目录下）：
 *   docker compose exec -T php php tools/translate-all.php
 *   docker compose exec -T php php tools/translate-all.php --lang=en --limit=10 --sleep=0.5
 *   docker compose exec -T php php tools/translate-all.php --ids=12,45,99   # 只译指定 id
 *   docker compose exec -T php php tools/translate-all.php --draft          # 只生成草稿不发布
 *   docker compose exec -T php php tools/translate-all.php --force          # 重译全部已发布（跳过 manual_override=1 的人工修正篇）
 * 退出码：0=全部成功 / 1=有失败 / 2=未配置 AI Key
 */
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';

$lang = 'en';
$publish = true;
$force = false;
$limit = 0;
$sleep = 0.2;
$ids = [];
$logPath = '';
$args = $argv;
array_shift($args);
for ($i = 0; $i < count($args); $i++) {
    if (str_starts_with($args[$i], '--log=')) { $logPath = substr($args[$i], 6); continue; }
    switch ($args[$i]) {
        case '--lang':  $lang = (string)($args[++$i] ?? 'en'); break;
        case '--force': $force = true; break;
        case '--limit': $limit = max(0, (int)($args[++$i] ?? 0)); break;
        case '--sleep': $sleep = max(0, (float)($args[++$i] ?? 0.2)); break;
        case '--draft': $publish = false; break;
        case '--log':   $logPath = (string)($args[++$i] ?? ''); break;
        case '--ids':
            foreach (explode(',', (string)($args[++$i] ?? '')) as $v) {
                $v = (int)trim($v);
                if ($v > 0) { $ids[] = $v; }
            }
            break;
    }
}
$logFh = null;
if ($logPath !== '') {
    $logDir = dirname($logPath);
    if (!is_dir($logDir)) { @mkdir($logDir, 0777, true); }
    $logFh = @fopen($logPath, 'ab');
}
/** 进度落盘（可选 --log），stdout 与日志双写 */
function wlog(string $s): void
{
    global $logFh;
    if ($logFh) { fwrite($logFh, date('H:i:s') . ' ' . $s . "\n"); }
}
if (!yc_opt_has('ai.key')) {
    fwrite(STDERR, "未配置 AI Key：请先在 后台 → 多语 → AI 设置 填写，或 set_option('ai.key', ...)\n");
    exit(2);
}

$T = Db::t('contents');
$P = Db::t('poly_contents');
$langQ = preg_replace('/[^a-z0-9]/', '', strtolower($lang)) ?: 'en';
$where = "c.type='post' AND c.status='publish' AND c.deleted_at IS NULL ";
if ($force) {
    // 重译全部（人工修正 manual_override=1 的除外）
    $where .= "AND NOT EXISTS (SELECT 1 FROM {$P} p2 WHERE p2.group_id=c.id AND p2.lang='{$langQ}' AND p2.manual_override=1)";
} else {
    $where .= "AND NOT EXISTS (SELECT 1 FROM {$P} p WHERE p.group_id=c.id AND p.lang='{$langQ}' AND p.status='publish')";
}
if ($ids) {
    $where .= ' AND c.id IN (' . implode(',', $ids) . ')';
}
$rows = Db::rows("SELECT c.id, c.title FROM {$T} c WHERE {$where} ORDER BY c.id ASC");
$total = count($rows);
if ($total === 0) {
    echo "没有需要翻译的博文（目标语言 {$lang} 已全部有已发布译文）。\n";
    exit(0);
}
echo "待翻译 {$total} 篇（lang={$lang}, publish=" . ($publish ? 'yes' : 'no') . "）\n";
$ok = 0; $fail = 0; $lastErr = '';
$t0 = microtime(true);
foreach ($rows as $i => $r) {
    if ($limit > 0 && $ok >= $limit) {
        echo "[limit] 已达本次上限 {$limit}\n";
        break;
    }
    $id = (int)$r['id'];
    try {
        $res = Poly::aiTranslate($id, $lang, '', '', '', $publish);
    } catch (Throwable $e) {
        $res = ['ok' => false, 'error' => $e->getMessage()];
    }
    if ($res['ok'] ?? false) {
        $ok++;
        wlog("[" . ($i + 1) . "/{$total}] OK   #{$id} " . mb_substr((string)$r['title'], 0, 60));
        echo "[" . ($i + 1) . "/{$total}] OK   #{$id} " . mb_substr((string)$r['title'], 0, 40) . "\n";
    } else {
        $fail++;
        $lastErr = (string)($res['error'] ?? '未知错误');
        wlog("[" . ($i + 1) . "/{$total}] FAIL #{$id} " . mb_substr((string)$r['title'], 0, 60) . " :: {$lastErr}");
        echo "[" . ($i + 1) . "/{$total}] FAIL #{$id} " . mb_substr((string)$r['title'], 0, 40) . " :: {$lastErr}\n";
    }
    if ($i < $total - 1 && $sleep > 0) { usleep((int)($sleep * 1e6)); }
}
$sec = round(microtime(true) - $t0, 1);
$st = Poly::stats($lang);
echo "----\n耗时 {$sec}s | OK={$ok} FAIL={$fail} | 已发布译文 {$st['published']}/{$st['total']}，剩余 {$st['missing']}\n";
wlog("---- done: 耗时 {$sec}s | OK={$ok} FAIL={$fail} | published={$st['published']}/{$st['total']} missing={$st['missing']}");
exit($fail > 0 ? 1 : 0);
