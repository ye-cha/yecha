<?php
declare(strict_types=1);
// links 插件迁移（1.4.0 清遗留）：精选置顶列 + 月精选订阅表
$t = Db::t('circle_posts');
$cols = array_map('strval', array_column(Db::rows('SHOW COLUMNS FROM ' . $t), 'Field'));
if (!in_array('featured', $cols, true)) {
    Db::run("ALTER TABLE {$t} ADD COLUMN featured TINYINT NOT NULL DEFAULT 0 COMMENT '站长置顶精选' AFTER fetched_at, ADD COLUMN featured_at DATETIME NULL DEFAULT NULL AFTER featured");
    echo "circle_posts.featured added\n";
} else {
    echo "circle_posts.featured exists\n";
}
$s = Db::t('circle_subs');
$exists = Db::rows('SHOW TABLES LIKE "' . $s . '"');
if (!$exists) {
    Db::run("CREATE TABLE {$s} (
      email     VARCHAR(190) NOT NULL,
      type      VARCHAR(16) NOT NULL DEFAULT 'monthly' COMMENT 'monthly=月度精选',
      token     VARCHAR(40) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (email, type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "create ye_circle_subs ok\n";
} else {
    echo "ye_circle_subs exists\n";
}
