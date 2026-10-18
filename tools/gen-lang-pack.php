<?php
/** 生成界面语言包：把 lang/en_US.php 的全部文案 AI 翻译成目标语言（保留 key、占位符与 HTML）
 * 用法：docker compose exec -T php php tools/gen-lang-pack.php --lang ja_JP ja | --lang es_ES es
 * （参数：--lang <pack文件名> <短语言码，写入 names 无关；仅文件名为准>）
 */
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';

$pack = '';
$args = $argv; array_shift($args);
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--pack') { $pack = (string)($args[++$i] ?? ''); }
}
if ($pack === '') { fwrite(STDERR, "usage: --pack ja_JP|es_ES ...\n"); exit(2); }
$key = yc_opt('ai.key', '');
if ($key === '') { fwrite(STDERR, "no ai.key\n"); exit(2); }
$base = (string)get_option('ai.base', 'https://api.deepseek.com/chat/completions');
$model = (string)get_option('ai.model', 'deepseek-chat');
$src = require dirname(__DIR__) . '/lang/en_US.php';
if (!is_array($src)) { fwrite(STDERR, "bad en_US\n"); exit(2); }

// 目标语言自然名
$human = ['ja_JP' => 'Japanese', 'es_ES' => 'Spanish', 'ko_KR' => 'Korean'][$pack] ?? $pack;

function gchat(string $base, string $key, string $model, string $sys, string $user): string
{
    $payload = ['model' => $model, 'messages' => [
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user', 'content' => $user],
    ], 'temperature' => 0.3, 'max_tokens' => 8192, 'response_format' => ['type' => 'json_object']];
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$key}\r\n",
        'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'timeout' => 180,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($base, false, $ctx);
    if ($resp === false) { throw new RuntimeException('network fail'); }
    $j = json_decode($resp, true);
    $t = trim((string)($j['choices'][0]['message']['content'] ?? ''));
    if ($t === '') { throw new RuntimeException('empty answer'); }
    return $t;
}

$sys = 'You translate UI copy for a blog from English to natural ' . $human . '. '
    . 'Return ONLY JSON with the same keys. Preserve every key exactly, keep "%s"/"%d" placeholders and '
    . 'all HTML markup/entities (&lt;a…&gt;, →, ✓) intact; translate the human-readable words only. Concise and friendly.';
$out = [];
foreach (array_chunk($src, 70, true) as $chunk) {
    $ok = false;
    for ($try = 1; $try <= 4 && !$ok; $try++) {
        try {
            $json = json_decode(gchat($base, $key, $model, $sys, json_encode($chunk, JSON_UNESCAPED_UNICODE)), true);
            if (!is_array($json)) { throw new RuntimeException('bad json'); }
            foreach ($chunk as $k => $v) {
                $out[$k] = isset($json[$k]) && trim((string)$json[$k]) !== '' ? (string)$json[$k] : (string)$v;
            }
            $ok = true;
        } catch (Throwable $e) { usleep(800000); }
    }
    if (!$ok) { foreach ($chunk as $k => $v) { $out[$k] = (string)$v; } }
    echo 'chunk done ' . count($out) . "/" . count($src) . "\n";
}
$php = "<?php\n// YeCha 语言包：{$pack}（{$human}，由 en_US 经 AI 初译生成，可人工修订）\nreturn [\n";
foreach ($src as $k => $_) {
    $php .= '    ' . var_export($k, true) . ' => ' . var_export((string)($out[$k] ?? $_), true) . ",\n";
}
$php .= "];\n";
$file = dirname(__DIR__) . '/lang/' . $pack . '.php';
file_put_contents($file, $php);
echo 'written ' . $file . ' keys=' . count($src) . "\n";
