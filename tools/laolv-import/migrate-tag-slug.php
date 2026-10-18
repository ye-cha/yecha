<?php
declare(strict_types=1);
/**
 * 存量标签 slug 可读化迁移：中文标签 slug 改为中文原名（去随机 p-xxx），冲突加后缀
 * 用法：docker compose exec -T php php tools/laolv-import/migrate-tag-slug.php
 */
require dirname(__DIR__, 2) . '/config.inc.php';
require dirname(__DIR__, 2) . '/core/bootstrap.php';
$T = Db::t('');
$rows = Db::rows("SELECT id, name, slug FROM {$T}metas WHERE type='tag' ORDER BY id ASC");
$up = 0;
foreach ($rows as $r) {
    $want = Str::tagSlug((string)$r['name']);
    if ($want === (string)$r['slug']) { continue; }
    $candidate = $want;
    for ($i = 2; $i < 60; $i++) {
        $hit = Db::one("SELECT id FROM {$T}metas WHERE type='tag' AND slug=? AND id<>? LIMIT 1", [$candidate, (int)$r['id']]);
        if ($hit === null) { break; }
        $candidate = $want . '-' . $i;
    }
    Db::update('metas', ['slug' => $candidate], 'id=?', [(int)$r['id']]);
    $up++;
}
Cache::flush('pages');
Cache::flush('blocks');
echo "migrated={$up}\n";
