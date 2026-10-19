<?php
declare(strict_types=1);
/**
 * lqbookmarks · 建表迁移（幂等）
 * ===========================================================================
 * 执行方式（站内既有口径）：Upgrade::apply('lqbookmarks')
 * ★ 只新增两张表；**不 ALTER 任何既有表**、**不碰 ye_favorites**、**不碰 lqnotes 的表**。
 * 撤销：DROP TABLE ye_bm_sets, ye_bm_shares; + 删 ye_migrations 里本组件那一行。
 */
// ① 书签库：每用户一行，data 存【参照 v2 原样 JSON】⇒ 与参照 1:1、零损耗（也是批8 迁移 1498 条的落点）
Db::run('CREATE TABLE IF NOT EXISTS `' . Db::t('bm_sets') . '` (
  `user_id` bigint(20) unsigned NOT NULL,
  `data` mediumtext COLLATE utf8mb4_unicode_ci,
  `version` int(11) NOT NULL DEFAULT 2 COMMENT \'结构版本；2 = 参照 v2 {version:2,groups:[...]}\',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'收藏 · 书签库（lqbookmarks）\'');

// ② 单条书签分享（快照语义：书签删了不影响已分享链接）—— 列照参照 bookmark_shares
Db::run('CREATE TABLE IF NOT EXISTS `' . Db::t('bm_shares') . '` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `item_id` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\',
  `token` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\',
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\',
  `url` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\',
  `descr` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bm_shares_token` (`token`),
  UNIQUE KEY `uk_bm_shares_user_item` (`user_id`,`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'收藏 · 书签单条分享（lqbookmarks）\'');
