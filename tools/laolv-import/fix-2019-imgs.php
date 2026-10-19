<?php
require dirname(__DIR__, 2) . '/config.inc.php';
require dirname(__DIR__, 2) . '/core/bootstrap.php';
// 重写 6-grams-of-forest 旧路径到本机 laolv 副本
$rows = Db::rows('SELECT id, content FROM ' . Db::t('contents') . " WHERE slug='6-grams-of-forest'");
$fixed = 0;
foreach ($rows as $r) {
    $c = (string)$r['content'];
    $n = str_replace('/uploads/2019/07/', '/uploads/laolv/2019/07/', $c);
    if ($n !== $c) { Db::update('contents', ['content' => $n], 'id=?', [(int)$r['id']]); $fixed++; }
}
Cache::flush('pages');
Cache::flush('blocks');
echo "fixed={$fixed}\n";
