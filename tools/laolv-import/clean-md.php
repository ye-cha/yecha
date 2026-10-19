<?php
require dirname(__DIR__, 2) . '/config.inc.php';
require dirname(__DIR__, 2) . '/core/bootstrap.php';
$rows = Db::rows("SELECT id, content FROM " . Db::t('contents') . " WHERE type='post' AND deleted_at IS NULL");
$fixed = 0;
foreach ($rows as $r) {
    $c = (string)$r['content'];
    $c = preg_replace('/^\s*\*\*\s*$/m', '', $c) ?? $c;                       // 孤立强调行
    $c = preg_replace('/^>\s*#{1,6}\s+(.+)$/m', '> **$1**', $c) ?? $c;        // 引用内标题 → 引用加粗
    $c = preg_replace('/\n{3,}/', "\n\n", $c) ?? $c;
    if ($c !== (string)$r['content']) {
        Db::update('contents', ['content' => $c], 'id=?', [(int)$r['id']]);
        $fixed++;
    }
}
Cache::flush('pages');
Cache::flush('blocks');
echo "cleaned={$fixed}\n";
