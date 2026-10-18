<?php
declare(strict_types=1);
/**
 * lqnotes · 批4 历史版本迁移（幂等）
 * ===========================================================================
 * 新增一张表 `ye_note_revs`：笔记的历史快照（服务端侧，登录后多设备可回溯）。
 *   口径与站内既有表一致：InnoDB / utf8mb4 / utf8mb4_unicode_ci / bigint unsigned 自增主键 / datetime。
 *   保留上限由 inc.php 的 LQN_MAX_REVS(=20) 在写入时裁剪（与参照"最多 20 条"同口径）。
 * ★ 只新增，不 ALTER 任何既有表。
 * 撤销：DROP TABLE ye_note_revs; + 删 ye_migrations 里本文件那一行。
 */
Db::run('CREATE TABLE IF NOT EXISTS `' . Db::t('note_revs') . '` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `note_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\',
  `body` mediumtext COLLATE utf8mb4_unicode_ci,
  `fmt` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'md\' COMMENT \'md | html\',
  `reason` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'auto\' COMMENT \'auto | restore | manual\',
  `body_len` int(11) NOT NULL DEFAULT 0 COMMENT \'正文长度，列表里显示"N 字"用\',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_note_revs_note` (`user_id`,`note_id`,`id`),
  KEY `idx_note_revs_time` (`user_id`,`note_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'云笔记 · 历史版本（lqnotes）\'');
