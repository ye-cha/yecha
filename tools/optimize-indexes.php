<?php
/** 幂等索引优化：补齐热查询组合索引（可重复执行，仅加不删） */
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';
$T = Db::t('');

/** 已有索引名集合 */
function idx_list(string $table): array
{
    global $T;
    $out = [];
    foreach (Db::rows("SHOW INDEX FROM {$T}{$table}") as $r) { $out[] = $r['Key_name']; }
    return $out;
}
function add_key(string $table, string $name, string $cols): void
{
    global $T;
    if (!in_array($name, idx_list($table), true)) {
        Db::run("ALTER TABLE {$T}{$table} ADD KEY {$name} ({$cols})");
        echo "added {$table}.{$name} ({$cols})\n";
    }
}

add_key('comments', 'idx_comments_content_status_parent', 'content_id, status, parent_id');
add_key('comments', 'idx_comments_status_kind', 'status, kind');
add_key('favorites', 'idx_fav_user_content', 'user_id, content_id');
add_key('marks', 'idx_marks_user_content', 'user_id, content_id');
add_key('annotations', 'idx_ann_user_mark', 'user_id, mark_id');
add_key('notifications', 'idx_notif_user_read', 'user_id, is_read');
add_key('poly_contents', 'idx_poly_lang_status_group', 'lang, status, group_id');
add_key('relationships', 'idx_rel_meta', 'meta_id, `order`');
add_key('contents', 'idx_contents_slug_status', 'status, slug');
echo "indexes done\n";
