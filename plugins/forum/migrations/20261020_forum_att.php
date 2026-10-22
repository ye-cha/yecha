<?php
declare(strict_types=1);
/**
 * 社区附件表 v1（yf-upload-20261020）
 *   ye_forum_att —— 上传过的附件索引：谁传的、多大、什么尺寸、什么格式。
 *   · hash 唯一：与磁盘文件名一致（内容 SHA1 前 16），同一张图只登记一次
 *   · 只做索引，不做外键：帖子正文里的 markdown 才是真引用关系
 *   · 回收靠 yf_att_gc()：扫 att 表，正文里没人引用的才删文件
 */
$T = Db::t("forum_att");

Db::run("CREATE TABLE IF NOT EXISTS `{$T}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `hash` CHAR(16) NOT NULL COMMENT \x27内容 SHA1 前16，与磁盘文件名一致\x27,
  `path` VARCHAR(255) NOT NULL COMMENT \x27公开 URL\x27,
  `mime` VARCHAR(40) NOT NULL DEFAULT \x27\x27,
  `size` INT UNSIGNED NOT NULL DEFAULT 0,
  `width` INT UNSIGNED NOT NULL DEFAULT 0,
  `height` INT UNSIGNED NOT NULL DEFAULT 0,
  `kind` VARCHAR(8) NOT NULL DEFAULT \x27img\x27 COMMENT \x27img|file\x27,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fatt_hash` (`hash`),
  KEY `idx_fatt_user` (`user_id`,`created_at`),
  KEY `idx_fatt_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\x27社区附件索引\x27");
