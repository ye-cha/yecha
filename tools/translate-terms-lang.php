<?php
/** 术语名翻译：为某语言把全部分类/标签补上 names_i18n[lang]（以英文名为参考源，缺失则用中文名）
 * 用法：docker compose exec -T php php tools/translate-terms-lang.php --lang ja | --lang es
 */
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';

$lang = '';
$args = $argv; array_shift($args);
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--lang') { $lang = preg_replace('/[^a-z]/', '', strtolower((string)($args[++$i] ?? ''))) ?? ''; }
}
if ($lang === '' || !isset(Poly::catalog()[$lang])) { fwrite(STDERR, "usage: --lang ja|es|...\n"); exit(2); }
$key = yc_opt('ai.key', '');
if ($key === '') { fwrite(STDERR, "no ai.key\n"); exit(2); }
$base = (string)get_option('ai.base', 'https://api.deepseek.com/chat/completions');
$model = (string)get_option('ai.model', 'deepseek-chat');

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
    if ($t === '') { throw new RuntimeException('empty'); }
    return $t;
}

$rows = Db::rows("SELECT id, type, name, name_en, names_i18n FROM " . Db::t('metas') . " WHERE type IN ('category','tag') ORDER BY id");
$pairs = [];
foreach ($rows as $r) {
    $i18n = json_decode((string)($r['names_i18n'] ?? ''), true);
    if (is_array($i18n) && isset($i18n[$lang]) && $i18n[$lang] !== '') { continue; } // 已有
    $en = (string)($r['name_en'] ?? '');
    $pairs[$r['id']] = $en !== '' ? $en : $r['name'];
}
$total = count($pairs);
echo "terms to translate to {$lang}: {$total}\n";
if (!$total) { echo "done\n"; exit(0); }

$human = ['ja' => 'Japanese', 'es' => 'Spanish', 'ko' => 'Korean'][$lang] ?? $lang;
$sys = 'Translate tea-blog taxonomy names to natural ' . $human . '. Use idiomatic tea vocabulary '
    . '(pu-erh etc.). Return ONLY JSON {"id":"translation"} with the given ids.';
$done = 0;
foreach (array_chunk($pairs, 110, true) as $chunk) {
    $ok = false;
    for ($try = 1; $try <= 4 && !$ok; $try++) {
        try {
            $out = json_decode(tchat($base, $key, $model, $sys, json_encode($chunk, JSON_UNESCAPED_UNICODE)), true);
            if (!is_array($out)) { throw new RuntimeException('bad json'); }
            foreach ($out as $id => $name) {
                $id = (int)$id;
                $name = trim((string)$name);
                if ($id > 0 && $name !== '' && isset($pairs[$id])) {
                    $i18n = json_decode((string)Db::one('SELECT names_i18n FROM ' . Db::t('metas') . ' WHERE id=?', [$id]), true);
                    if (!is_array($i18n)) { $i18n = []; }
                    $i18n[$lang] = mb_substr($name, 0, 100);
                    $typeRow = Db::one('SELECT type FROM ' . Db::t('metas') . ' WHERE id=?', [$id]);
                    $type = (string)$typeRow === 'category' ? 'category' : 'tag';
                    $slugMap = Poly::termSlugI18nFor($i18n, $type, $id);
                    Db::update('metas', [
                        'names_i18n' => json_encode($i18n, JSON_UNESCAPED_UNICODE),
                        'slug_i18n' => json_encode($slugMap, JSON_UNESCAPED_UNICODE),
                    ], 'id=?', [$id]);
                    $done++;
                }
            }
            $ok = true;
        } catch (Throwable $e) { usleep(700000); }
    }
    echo "batch done={$done}\n";
}
Cache::flush('pages');
Cache::flush('blocks');
echo "finished done={$done}\n";
