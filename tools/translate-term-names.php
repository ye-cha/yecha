<?php
/** 一次性：为全部空 name_en 的分类/标签术语生成英文名（保留专名/茶类用词风格） */
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';

$key = yc_opt('ai.key', '');
if ($key === '') { fwrite(STDERR, "no ai.key\n"); exit(2); }
$base = (string)get_option('ai.base', 'https://api.deepseek.com/chat/completions');
$model = (string)get_option('ai.model', 'deepseek-chat');
$rows = Db::rows("SELECT id, name FROM " . Db::t('metas') . " WHERE type IN ('category','tag') AND (name_en IS NULL OR name_en='') ORDER BY id");
$total = count($rows);
echo "terms to translate: {$total}\n";
if (!$total) { echo "done\n"; exit(0); }

function tchat(string $base, string $key, string $model, string $sys, string $user): string
{
    $payload = ['model' => $model, 'messages' => [
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user', 'content' => $user],
    ], 'temperature' => 0.2, 'max_tokens' => 4096, 'response_format' => ['type' => 'json_object']];
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$key}\r\n",
        'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'timeout' => 120,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($base, false, $ctx);
    if ($resp === false) { throw new RuntimeException('network fail'); }
    $j = json_decode($resp, true);
    $t = trim((string)($j['choices'][0]['message']['content'] ?? ''));
    if ($t === '') { throw new RuntimeException('empty ai answer'); }
    return $t;
}

$done = 0; $failed = 0;
foreach (array_chunk($rows, 120) as $chunk) {
    $pairs = [];
    foreach ($chunk as $r) { $pairs[$r['id']] = $r['name']; }
    $sys = 'Translate these tea-blog taxonomy names (categories and tags) from Chinese to concise natural English. '
        . 'Keep proper nouns and common tea terms recognizable (pu-erh, Dianhong, etc.). '
        . 'Return ONLY JSON: {"1":"translated name","2":...} using the given ids.';
    $user = json_encode($pairs, JSON_UNESCAPED_UNICODE);
    $ok = false;
    for ($try = 1; $try <= 3 && !$ok; $try++) {
        try {
            $out = json_decode(tchat($base, $key, $model, $sys, $user), true);
            if (!is_array($out)) { throw new RuntimeException('bad json'); }
            foreach ($out as $id => $en) {
                $id = (int)$id;
                $en = trim((string)$en);
                if ($id > 0 && $en !== '' && isset($pairs[$id])) {
                    Db::update('metas', ['name_en' => mb_substr($en, 0, 100)], 'id=?', [$id]);
                    $done++;
                }
            }
            $ok = true;
        } catch (Throwable $e) {
            usleep(600000);
            $failed++;
        }
    }
    echo "batch done={$done} total=" . count($pairs) . "\n";
}
Cache::flush('pages');
Cache::flush('blocks');
echo "finished done={$done} failedBatches={$failed}\n";
