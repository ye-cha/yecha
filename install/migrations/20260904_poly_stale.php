<?php
// 迁移：ye_poly_contents 增加 stale 列（基准原文更新后标记译文过期=1，重译/人工保存后归零）。幂等。
declare(strict_types=1);
$t = Db::t('poly_contents');
$cols = Db::rows('SHOW COLUMNS FROM ' . $t);
$names = array_map('strval', array_column($cols, 'Field'));
if (!in_array('stale', $names, true)) {
    Db::run("ALTER TABLE {$t} ADD COLUMN stale TINYINT NOT NULL DEFAULT 0 COMMENT '基准原文更新后=1（需重译）' AFTER ai_revision");
}
