<?php
declare(strict_types=1);
// 迁移：ye_metas 增加 slug_i18n（各语言术语 URL 别名 JSON {"en":"puer-tea",…}）
$t = Db::t('metas');
$cols = Db::rows('SHOW COLUMNS FROM ' . $t);
$names = array_map('strval', array_column($cols, 'Field'));
if (!in_array('slug_i18n', $names, true)) {
    Db::run("ALTER TABLE {$t} ADD COLUMN slug_i18n LONGTEXT NULL COMMENT '各语言 slug JSON（缺失回落默认 slug）' AFTER names_i18n");
}
