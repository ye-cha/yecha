<?php
declare(strict_types=1);
// links 插件迁移（阶段2「温柔聚合圈」）：文章收录明细 ye_circle_posts —— 只存元数据（标题/原文URL/摘要/发布时间），绝不存正文
$t = Db::t('circle_posts');
$exists = Db::rows('SHOW TABLES LIKE "' . $t . '"');
if (!$exists) {
    Db::run("CREATE TABLE {$t} (
      id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      link_id      BIGINT UNSIGNED NOT NULL,
      url          VARCHAR(500) NOT NULL,
      title        VARCHAR(300) NOT NULL,
      summary      VARCHAR(400) NOT NULL DEFAULT '',
      published_at DATETIME NULL DEFAULT NULL,
      fetched_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uk_cp_url (url),
      KEY idx_cp_link_pub (link_id, published_at),
      KEY idx_cp_pub (published_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "create ye_circle_posts ok\n";
} else {
    echo "ye_circle_posts exists\n";
}
