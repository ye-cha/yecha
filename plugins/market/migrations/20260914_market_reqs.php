<?php
declare(strict_types=1);
// 市场收录插件迁移：申请/审核表
$t = Db::t('market_reqs');
$exists = Db::rows('SHOW TABLES LIKE "' . $t . '"');
if (!$exists) {
    Db::run("CREATE TABLE {$t} (
      id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      type         VARCHAR(10) NOT NULL DEFAULT 'plugin' COMMENT 'plugin|theme',
      app_id       VARCHAR(40) NOT NULL,
      name         VARCHAR(120) NOT NULL DEFAULT '',
      ver          VARCHAR(32) NOT NULL DEFAULT '',
      author       VARCHAR(120) NOT NULL DEFAULT '',
      description  VARCHAR(500) NOT NULL DEFAULT '',
      url          VARCHAR(600) NOT NULL DEFAULT '',
      sha_claim    VARCHAR(80) NOT NULL DEFAULT '',
      email        VARCHAR(190) NOT NULL DEFAULT '',
      note         VARCHAR(500) NOT NULL DEFAULT '',
      status       VARCHAR(12) NOT NULL DEFAULT 'pending' COMMENT 'pending|verified|published|rejected',
      checked_note VARCHAR(600) NOT NULL DEFAULT '',
      checked_at   DATETIME NULL DEFAULT NULL,
      file_path    VARCHAR(400) NOT NULL DEFAULT '',
      created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_mr_status (status),
      KEY idx_mr_app (app_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "create ye_market_reqs ok\n";
} else {
    echo "ye_market_reqs exists\n";
}
