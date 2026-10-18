-- M14+：微信小程序接口层所需表（openid↔uid 映射 + API token）
-- 本地 dev 下 Api::ensureTables 已运行时建表；本迁移供全新安装/生产部署补齐。

CREATE TABLE IF NOT EXISTS `ye_mall_user_wx` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `openid` varchar(64) NOT NULL DEFAULT '',
  `unionid` varchar(64) NOT NULL DEFAULT '',
  `uid` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `openid` (`openid`),
  KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ye_mall_api_token` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `token` varchar(64) NOT NULL DEFAULT '',
  `uid` int unsigned NOT NULL DEFAULT 0,
  `openid` varchar(64) NOT NULL DEFAULT '',
  `expire_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
