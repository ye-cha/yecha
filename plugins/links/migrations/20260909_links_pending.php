<?php
declare(strict_types=1);
// links 插件迁移：公开申请支持 —— links 表加 email（申请人邮箱），status 语义扩展 pending（列类型已是 varchar(10) 无需变更）
$t = Db::t('links');
$cols = array_map('strval', array_column(Db::rows('SHOW COLUMNS FROM ' . $t), 'Field'));
if (!in_array('email', $cols, true)) {
    Db::run("ALTER TABLE {$t} ADD COLUMN email VARCHAR(190) NOT NULL DEFAULT '' COMMENT '申请人邮箱（公开申请）' AFTER logo");
    echo "links.email added\n";
} else {
    echo "links.email exists\n";
}
