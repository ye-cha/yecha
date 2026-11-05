-- 企业官网插件 site · 留言回复与公开问答
-- 适用 ye_site_message：可能不存在 / 可能缺新列，本脚本幂等可重复执行。
CREATE TABLE IF NOT EXISTS `ye_site_message` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL DEFAULT '',
  `contact` varchar(128) NOT NULL DEFAULT '',
  `content` varchar(2000) NOT NULL DEFAULT '',
  `intent` varchar(32) NOT NULL DEFAULT '',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `is_read` tinyint unsigned NOT NULL DEFAULT 0,
  `reply` text NULL,
  `reply_at` datetime NULL,
  `reply_by` varchar(64) NOT NULL DEFAULT '',
  `is_public` tinyint unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `read_time` (`is_read`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //
DROP PROCEDURE IF EXISTS _ye_add_msg_reply_cols //
CREATE PROCEDURE _ye_add_msg_reply_cols()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE() AND table_name = 'ye_site_message' AND column_name = 'reply'
  ) THEN
    ALTER TABLE `ye_site_message`
      ADD COLUMN `reply` text NULL AFTER `content`,
      ADD COLUMN `reply_at` datetime NULL AFTER `reply`,
      ADD COLUMN `reply_by` varchar(64) NOT NULL DEFAULT '' AFTER `reply_at`,
      ADD COLUMN `is_public` tinyint unsigned NOT NULL DEFAULT 0 AFTER `reply_by`;
  END IF;
END //
DELIMITER ;
CALL _ye_add_msg_reply_cols();
DROP PROCEDURE IF EXISTS _ye_add_msg_reply_cols;
