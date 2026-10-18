<?php
declare(strict_types=1);
/**
 * lqnotes · 建表迁移（幂等）
 * ===========================================================================
 * 执行方式（站内既有口径）：Upgrade::apply('lqnotes')
 *   · 迁移文件目录 = plugins/lqnotes/migrations/，逐条执行后把文件名写进 ye_migrations
 *   · Plugins::boot() 也会在「manifest 声明的自有表缺失」时自动应用本文件（安全网）
 * 撤销：DROP TABLE ye_note_books, ye_note_items, ye_note_shares; + 删 ye_migrations 本组件那一行
 *       （见 /root/lqn_rollback.sh，一条命令）
 * 口径：与站内既有表一致 —— InnoDB / utf8mb4 / utf8mb4_unicode_ci / bigint(20) unsigned 自增主键
 *       / datetime NOT NULL DEFAULT CURRENT_TIMESTAMP（+ ON UPDATE）/ 索引名 idx_* / 唯一键 uk_*
 * ★ 只新增，不 ALTER 任何既有表（既有的 ye_notes 后台记事本表一个字节不动）。
 */

// ① 笔记本
Db::run('CREATE TABLE IF NOT EXISTS `' . Db::t('note_books') . '` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\',
  `sort` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_note_books_user` (`user_id`,`sort`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'云笔记 · 笔记本（lqnotes）\'');

// ② 笔记（正文 mediumtext：上限 16 MiB；服务端另有 256 KiB 硬闸）
Db::run('CREATE TABLE IF NOT EXISTS `' . Db::t('note_items') . '` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `book_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT \'0 = 未归类（默认）\',
  `client_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\' COMMENT \'浏览器端稳定 id，用于本机↔云端合并去重\',
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\',
  `body` mediumtext COLLATE utf8mb4_unicode_ci,
  `fmt` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'md\' COMMENT \'md | html\',
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'normal\' COMMENT \'normal | trash\',
  `sort` int(11) NOT NULL DEFAULT 0,
  `trashed_at` datetime DEFAULT NULL COMMENT \'进回收站的时间；彻底删 = 真 DELETE\',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_note_items_user` (`user_id`,`status`,`updated_at`),
  KEY `idx_note_items_book` (`user_id`,`book_id`),
  KEY `idx_note_items_ck` (`user_id`,`client_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'云笔记 · 笔记（lqnotes）\'');

// ③ 分享（正文快照 + 可选口令/有效期；token 即 /share/{token} 的 URL 段）
Db::run('CREATE TABLE IF NOT EXISTS `' . Db::t('note_shares') . '` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `token` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `note_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\',
  `body` mediumtext COLLATE utf8mb4_unicode_ci,
  `fmt` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'md\',
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\' COMMENT \'空 = 无口令\',
  `expires_at` datetime DEFAULT NULL COMMENT \'NULL = 永久\',
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'on\' COMMENT \'on | off\',
  `views` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_note_shares_token` (`token`),
  KEY `idx_note_shares_user` (`user_id`,`note_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'云笔记 · 分享链接（lqnotes）\'');
