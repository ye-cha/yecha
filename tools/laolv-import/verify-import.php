<?php
require dirname(__DIR__, 2) . '/config.inc.php';
require dirname(__DIR__, 2) . '/core/bootstrap.php';
$T = Db::t('');
$all = (int)Db::one("SELECT COUNT(*) FROM {$T}contents WHERE type='post' AND deleted_at IS NULL");
$laolv = (int)Db::one("SELECT COUNT(*) FROM {$T}import_log WHERE source='laolv' AND status='ok'");
$metaC = Db::rows("SELECT name, `count` FROM {$T}metas WHERE type='category' AND slug IN ('zawen','chawen','zhishangtancha') ORDER BY `count` DESC");
$tags = (int)Db::one("SELECT COUNT(*) FROM {$T}metas WHERE type='tag'");
echo "posts_total={$all} laolv_ok={$laolv} tags={$tags}\n";
foreach ($metaC as $c) { echo "cat:{$c['name']}={$c['count']}\n"; }
// 抽查
$row = Db::row("SELECT title, slug, published_at FROM {$T}contents WHERE slug='tea-leaves-can-no-longer-be-ground-into-powder-like-coffee-beans'");
echo 'sample1 date=' . ($row['published_at'] ?? '?') . ' len=' . mb_strlen((string)$row['title']) . "\n";
$c = Db::row('SELECT content FROM ' . Db::t('contents') . " WHERE slug='lets-talk-about-tea-making-from-white-tea'");
echo 'sample2 has_img_local=' . (str_contains((string)$c['content'], '/uploads/laolv/') ? 'y' : 'n') . ' len=' . mb_strlen((string)$c['content']) . "\n";
// HTTP 抽样
$urls = ['/tea-leaves-can-no-longer-be-ground-into-powder-like-coffee-beans.html', '/category/chawen/', '/tag/普洱茶/'];
$found = 0;
foreach ($urls as $u) {
    $b = @file_get_contents('http://nginx' . $u);
    echo "http {$u} len=" . strlen((string)$b) . ($b !== false ? ' ok' : ' fail') . "\n";
}
