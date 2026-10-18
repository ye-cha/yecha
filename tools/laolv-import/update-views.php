<?php
declare(strict_types=1);
/**
 * 把 posts.json 中解析出的原站阅读量回写 contents.views（仅对导入的 laolv 文章）
 * 用法：docker compose exec -T php php tools/laolv-import/update-views.php
 */
require dirname(__DIR__, 2) . '/config.inc.php';
require dirname(__DIR__, 2) . '/core/bootstrap.php';

$dir = dirname(__DIR__, 2) . '/data/laolv';
$posts = json_decode((string)file_get_contents($dir . '/posts.json'), true);
$T = Db::t('');
$up = 0; $skip = 0; $has = 0;
foreach ($posts as $p) {
    $slug = (string)$p['slug'];
    $views = (int)($p['views'] ?? 0);
    if ($views <= 0) { $skip++; continue; }
    $row = Db::row("SELECT id, views FROM {$T}contents WHERE slug=? LIMIT 1", [$slug]);
    if ($row === null) { $skip++; continue; }
    $has++;
    if ((int)$row['views'] !== $views) {
        Db::update('contents', ['views' => $views], 'id=?', [(int)$row['id']]);
        $up++;
    }
}
Cache::flush('pages');
Cache::flush('blocks');
$already = $has - $up;
echo "updated={$up} already={$already} skipped_zero={$skip}\n";
$sum = (int)Db::one("SELECT COALESCE(SUM(views),0) FROM {$T}contents");
echo "total_views={$sum}\n";
