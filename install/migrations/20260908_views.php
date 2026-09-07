<?php
declare(strict_types=1);
// 迁移：阅读量明细表（append 式日计数，避免每浏览一次 UPDATE contents 行锁）
$t = Db::t('views');
$exists = Db::rows('SHOW TABLES LIKE "' . $t . '"');
if (!$exists) {
    Db::run("CREATE TABLE {$t} (
      day        DATE NOT NULL,
      content_id BIGINT UNSIGNED NOT NULL,
      n          INT UNSIGNED NOT NULL DEFAULT 1,
      PRIMARY KEY (day, content_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "create ye_views ok\n";
} else {
    echo "ye_views exists\n";
}
