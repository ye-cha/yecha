<?php
declare(strict_types=1);
// 迁移（幂等）：友链分组 + 链接
$t = Db::t('link_groups');
$cols = Db::rows('SHOW TABLES LIKE "' . $t . '"');
if (!$cols) {
    Db::run("CREATE TABLE {$t} (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL DEFAULT '',
      slug VARCHAR(160) NOT NULL DEFAULT '',
      kind VARCHAR(12) NOT NULL DEFAULT 'friend' COMMENT 'friend|nav|mixed',
      description VARCHAR(255) NOT NULL DEFAULT '',
      sort INT NOT NULL DEFAULT 0,
      enabled TINYINT NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uk_lg_slug (slug),
      KEY idx_lg_kind (kind)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
$t2 = Db::t('links');
$cols2 = Db::rows('SHOW TABLES LIKE "' . $t2 . '"');
if (!$cols2) {
    Db::run("CREATE TABLE {$t2} (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      group_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      name VARCHAR(160) NOT NULL DEFAULT '',
      url VARCHAR(500) NOT NULL DEFAULT '',
      logo VARCHAR(500) NOT NULL DEFAULT '',
      description VARCHAR(255) NOT NULL DEFAULT '',
      sort INT NOT NULL DEFAULT 0,
      status VARCHAR(10) NOT NULL DEFAULT 'show' COMMENT 'show|hide',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_lk_group (group_id, sort)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
