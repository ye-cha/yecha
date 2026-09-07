<?php
declare(strict_types=1);
// links 插件迁移（1.4.1）：links 表加 engine（使用的博客程序）
$t = Db::t('links');
$cols = array_map('strval', array_column(Db::rows('SHOW COLUMNS FROM ' . $t), 'Field'));
if (!in_array('engine', $cols, true)) {
    Db::run("ALTER TABLE {$t} ADD COLUMN engine VARCHAR(40) NOT NULL DEFAULT '' COMMENT '使用的博客程序（wordpress/typecho/hexo…）' AFTER rss");
    echo "links.engine added\n";
} else {
    echo "links.engine exists\n";
}
