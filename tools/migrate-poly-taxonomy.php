<?php
/**
 * 迁移（翻译组模型）：
 *  1) ye_metas 增加 name_en（术语可选英文名，空则前台回落中文名）
 *  2) ye_poly_contents 增加 cat_ids / tag_ids（JSON，英文版独立选择的分类/标签，指向 ye_metas.id）
 *  3) 既有英文译文（无分类/标签记录）默认继承其中文原文的分类/标签选择
 */
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';

$T = Db::t('');
$info = fn(string $tbl) => array_map('strtolower', array_column(Db::rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$T}{$tbl}'"), 'COLUMN_NAME'));

$cols = $info('metas');
if (!in_array('name_en', $cols, true)) {
    Db::run("ALTER TABLE {$T}metas ADD COLUMN name_en VARCHAR(100) NOT NULL DEFAULT '' COMMENT '英文名（空=前台回落中文名）' AFTER name");
    echo "metas.name_en added\n";
} else { echo "metas.name_en exists\n"; }

$cols2 = $info('poly_contents');
foreach (['cat_ids' => "TEXT NULL COMMENT '英文版分类 meta id JSON'", 'tag_ids' => "TEXT NULL COMMENT '英文版标签 meta id JSON'"] as $col => $def) {
    if (!in_array($col, $cols2, true)) {
        Db::run("ALTER TABLE {$T}poly_contents ADD COLUMN {$col} {$def}");
        echo "poly_contents.{$col} added\n";
    } else { echo "poly_contents.{$col} exists\n"; }
}

// 既有英文译文：默认继承中文原文分类/标签
$fixed = 0;
foreach (Db::rows("SELECT id, group_id FROM {$T}poly_contents WHERE lang='en'") as $p) {
    $rel = Db::rows(
        "SELECT r.meta_id, m.type FROM {$T}relationships r JOIN {$T}metas m ON m.id=r.meta_id WHERE r.content_id=? AND m.type IN ('category','tag')",
        [(int)$p['group_id']]
    );
    $cats = array_values(array_map('intval', array_column(array_filter($rel, fn($x) => $x['type'] === 'category'), 'meta_id')));
    $tags = array_values(array_map('intval', array_column(array_filter($rel, fn($x) => $x['type'] === 'tag'), 'meta_id')));
    $cur = Db::row('SELECT cat_ids, tag_ids FROM ' . Db::t('poly_contents') . ' WHERE id=?', [(int)$p['id']]);
    if ((($cur['cat_ids'] ?? '') === null || ($cur['cat_ids'] ?? '') === '') && (($cur['tag_ids'] ?? '') === null || ($cur['tag_ids'] ?? '') === '')) {
        Db::update('poly_contents', [
            'cat_ids' => json_encode($cats, JSON_UNESCAPED_UNICODE),
            'tag_ids' => json_encode($tags, JSON_UNESCAPED_UNICODE),
        ], 'id=?', [(int)$p['id']]);
        $fixed++;
    }
}
echo "backfilled en poly rows: {$fixed}\n";
Cache::flush('pages');
Cache::flush('blocks');
echo "done\n";
