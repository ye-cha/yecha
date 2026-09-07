<?php
declare(strict_types=1);
// 迁移：性能索引（幂等）
// - contents: (type,status,published_at,id) 供列表/归档/侧栏按发布时间排序（COALESCE 表达式无法走索引）
// - metas:    (type,count) 供标签云/索引按 count 排序
// - comments: (content_id,status,kind,id) 供文章评论区键集分页
// - 清理：老库残留的重复索引（按名 DROP IF EXISTS，新 schema 不建故无影响）
$t = Db::t('');
$has = function (string $table, string $key): bool {
    foreach (Db::rows('SHOW INDEX FROM ' . $table) as $ix) {
        if (($ix['Key_name'] ?? '') === $key) { return true; }
    }
    return false;
};

if (!$has($t . 'contents', 'idx_contents_pub')) {
    Db::run("ALTER TABLE {$t}contents ADD KEY idx_contents_pub (type, status, published_at, id)");
    echo "add contents.idx_contents_pub\n";
}
if (!$has($t . 'metas', 'idx_metas_type_count')) {
    Db::run("ALTER TABLE {$t}metas ADD KEY idx_metas_type_count (type, count)");
    echo "add metas.idx_metas_type_count\n";
}
if (!$has($t . 'comments', 'idx_comments_content_status_kind_id')) {
    Db::run("ALTER TABLE {$t}comments ADD KEY idx_comments_content_status_kind_id (content_id, status, kind, id)");
    echo "add comments.idx_comments_content_status_kind_id\n";
}

// 老库遗留重复索引（新 schema 从未创建这些名字，IF EXISTS 语义由 SHOW INDEX 保证）
foreach (['relationships' => ['idx_relationships_meta'], 'favorites' => ['idx_fav_user_content', 'idx_fav_user'], 'notifications' => ['idx_notif_user_read']] as $tb => $keys) {
    foreach ($keys as $k) {
        if ($has($t . $tb, $k)) {
            if ($tb === 'relationships' && $k === 'idx_relationships_meta' && !$has($t . $tb, 'idx_rel_meta')) {
                continue; // 无更全的 idx_rel_meta(meta_id,order) 覆盖时保留 meta_id 索引
            }
            Db::run("ALTER TABLE {$t}{$tb} DROP KEY `{$k}`");
            echo "drop {$tb}.{$k}\n";
        }
    }
}
echo "perf indexes ok\n";
