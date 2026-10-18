<?php
declare(strict_types=1);
// 迁移：体验站表（申请/接入/监控状态）
$t = Db::t('demo_sites');
$cols = Db::rows('SHOW TABLES LIKE "' . $t . '"');
if (!$cols) {
    Db::run("CREATE TABLE {$t} (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL DEFAULT '',
      url VARCHAR(300) NOT NULL DEFAULT '',
      note VARCHAR(500) NOT NULL DEFAULT '',
      email VARCHAR(160) NOT NULL DEFAULT '',
      status VARCHAR(16) NOT NULL DEFAULT 'pending' COMMENT 'pending|ok|down|hidden',
      last_code INT NOT NULL DEFAULT 0,
      last_ms INT NOT NULL DEFAULT 0,
      checked_at DATETIME NULL DEFAULT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_demo_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
