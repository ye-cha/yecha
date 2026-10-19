<?php
declare(strict_types=1);
/**
 * laolv 导入器：posts.json → contents/metas/relationships/import_log（幂等）
 * 保留：slug（原 URL 末段）、published_at（原日期）、分类（杂文/茶文/纸上谈茶）、标签
 * 用法：docker compose exec -T php php tools/laolv-import/import.php
 */
require dirname(__DIR__, 2) . '/config.inc.php';
require dirname(__DIR__, 2) . '/core/bootstrap.php';

$dir = dirname(__DIR__, 2) . '/data/laolv';
$posts = json_decode((string)file_get_contents($dir . '/posts.json'), true);
if (!is_array($posts)) { fwrite(STDERR, "posts.json 读取失败\n"); exit(1); }

$T = Db::t('');
$admin = (int)Db::one("SELECT id FROM {$T}users WHERE role='admin' ORDER BY id LIMIT 1");
$CATS = ['zawen' => '杂文', 'chawen' => '茶文', 'zhishangtancha' => '纸上谈茶'];

$metaId = [];
foreach ($CATS as $slug => $name) {
    $row = Db::row("SELECT id FROM {$T}metas WHERE type='category' AND slug=? LIMIT 1", [$slug]);
    $metaId['c:' . $slug] = $row ? (int)$row['id'] : Db::insert('metas', ['type' => 'category', 'name' => $name, 'slug' => $slug]);
}

$stat = ['skip' => 0, 'new' => 0, 'tags' => 0];
foreach ($posts as $p) {
    $slug = trim((string)$p['slug']);
    if ($slug === '') { continue; }
    $exists = Db::row("SELECT id FROM {$T}contents WHERE slug=? LIMIT 1", [$slug]);
    if ($exists !== null) { $stat['skip']++; continue; }

    $title = (string)$p['title'];
    $content = (string)$p['content'];
    if ($title === '' || $content === '') { fwrite(STDERR, 'empty skip ' . $slug . "\n"); $stat['skip']++; continue; }
    $date = (string)$p['date'];
    $pub = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date . ' 09:00:00' : date('Y-m-d H:i:s');

    $excerpt = Str::excerpt($content, 150);
    $tags = array_values(array_filter((array)$p['tags']));
    $kw = implode(',', array_slice($tags, 0, 10));

    $cid = Db::insert('contents', [
        'type' => 'post', 'status' => 'publish',
        'title' => $title, 'slug' => $slug,
        'excerpt' => $excerpt, 'description' => '', 'keywords' => $kw,
        'content' => $content, 'content_format' => 'markdown',
        'author_id' => $admin, 'views' => 0, 'allow_comment' => 1,
        'published_at' => $pub,
    ]);
    Db::insert('import_log', [
        'source' => 'laolv', 'source_kind' => 'html', 'old_slug' => $slug,
        'content_id' => $cid, 'status' => 'ok',
    ]);

    // 分类
    $catSlug = (string)($p['cat_slug'] ?? '');
    if ($catSlug !== '' && isset($metaId['c:' . $catSlug])) {
        Db::insert('relationships', ['content_id' => $cid, 'meta_id' => $metaId['c:' . $catSlug]]);
    }
    // 标签
    foreach ($tags as $name) {
        $name = trim((string)$name);
        if ($name === '') { continue; }
        $row = Db::row("SELECT id, `count` FROM {$T}metas WHERE type='tag' AND name=? LIMIT 1", [$name]);
        if ($row === null) {
            $baseSlug = Str::tagSlug($name);
            $slug = $baseSlug;
            for ($i = 2; $i < 50; $i++) {
                $hit = Db::one("SELECT id FROM {$T}metas WHERE type='tag' AND slug=? LIMIT 1", [$slug]);
                if ($hit === null) { break; }
                $slug = $baseSlug . '-' . $i;
            }
            $tid = Db::insert('metas', ['type' => 'tag', 'name' => $name, 'slug' => $slug, 'count' => 1]);
        } else {
            $tid = (int)$row['id'];
            Db::update('metas', ['count' => (int)$row['count'] + 1], 'id=?', [$tid]);
        }
        Db::insert('relationships', ['content_id' => $cid, 'meta_id' => $tid]);
        $stat['tags']++;
    }
    $stat['new']++;
}
// 分类计数重算
foreach ($metaId as $mid) {
    $n = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('relationships') . ' r JOIN ' . Db::t('contents') . " c ON c.id=r.content_id WHERE r.meta_id=? AND c.type='post' AND c.deleted_at IS NULL", [$mid]);
    Db::update('metas', ['count' => $n], 'id=?', [$mid]);
}
Cache::flush('pages');
Cache::flush('blocks');
echo json_encode($stat, JSON_UNESCAPED_UNICODE) . "\n";
$total = (int)Db::one("SELECT COUNT(*) FROM {$T}contents WHERE type='post' AND deleted_at IS NULL");
echo "posts_total={$total}\n";
