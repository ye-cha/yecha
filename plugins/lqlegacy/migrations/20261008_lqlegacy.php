<?php
declare(strict_types=1);
/**
 * lqlegacy · 旁表迁移（幂等）
 * ★ 只新增一张旁表；**不 ALTER 任何既有表**（方案 B）。
 * 撤销：DROP TABLE ye_user_legacy; + 删 ye_migrations 里本组件那一行。
 */
Db::run('CREATE TABLE IF NOT EXISTS `' . Db::t('user_legacy') . '` (
  `user_id` bigint(20) unsigned NOT NULL,
  `algo` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'pbkdf2-sha256-100000-32\',
  `salt` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\',
  `hash` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'老站口令旁存（lqlegacy）仅用于透明升级\'');
