<?php
declare(strict_types=1);
// 论坛 v3 迁移（t50）：点赞反应 / 草稿 / 已读 + 通知 link 列。幂等；在线 DDL。
$tRx = Db::t('forum_reactions');
$tDr = Db::t('forum_drafts');
$tRd = Db::t('forum_reads');
$tN  = Db::t('notifications');

Db::run("CREATE TABLE IF NOT EXISTS `{$tRx}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_type` VARCHAR(8) NOT NULL DEFAULT 'thread' COMMENT 'thread|reply',
  `post_id` BIGINT UNSIGNED NOT NULL,
  `thread_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `user_id` INT UNSIGNED NOT NULL,
  `kind` VARCHAR(16) NOT NULL DEFAULT 'like',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_frx` (`post_type`,`post_id`,`user_id`,`kind`),
  KEY `idx_frx_post` (`post_type`,`post_id`,`kind`),
  KEY `idx_frx_user` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

Db::run("CREATE TABLE IF NOT EXISTS `{$tDr}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `kind` VARCHAR(8) NOT NULL DEFAULT 'thread' COMMENT 'thread|reply',
  `thread_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `board_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `title` VARCHAR(200) NOT NULL DEFAULT '',
  `content` MEDIUMTEXT COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fd` (`user_id`,`kind`,`thread_id`),
  KEY `idx_fd_upd` (`user_id`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

Db::run("CREATE TABLE IF NOT EXISTS `{$tRd}` (
  `user_id` INT UNSIGNED NOT NULL,
  `thread_id` BIGINT UNSIGNED NOT NULL,
  `last_read_post_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `read_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`thread_id`),
  KEY `idx_frd_thread` (`thread_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$has = static function (string $table, string $col): bool {
    return Db::row('SHOW COLUMNS FROM `' . $table . "` LIKE '" . $col . "'") !== null;
};
if (!$has($tN, 'link')) {
    try {
        Db::run("ALTER TABLE `{$tN}` ADD COLUMN `link` VARCHAR(255) NOT NULL DEFAULT '' AFTER `body`, ALGORITHM=INPLACE, LOCK=NONE");
    } catch (Throwable) {
        Db::run("ALTER TABLE `{$tN}` ADD COLUMN `link` VARCHAR(255) NOT NULL DEFAULT '' AFTER `body`");
    }
}

echo 'forum_reactions=' . (Db::row("SHOW TABLES LIKE '{$tRx}'") !== null ? '1' : '0')
    . ' forum_drafts=' . (Db::row("SHOW TABLES LIKE '{$tDr}'") !== null ? '1' : '0')
    . ' forum_reads=' . (Db::row("SHOW TABLES LIKE '{$tRd}'") !== null ? '1' : '0')
    . ' notifications.link=' . ($has($tN, 'link') ? '1' : '0') . "\n";
