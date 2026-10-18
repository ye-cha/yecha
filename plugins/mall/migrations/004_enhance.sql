-- YeCha Mall 插件 · M8 商品维度增强建表迁移（标签 / 商品标签关联）
-- 幂等：CREATE TABLE IF NOT EXISTS；注释行独占一行（内核 Upgrade::splitSql 逐行剔注释，注释勿与 SQL 同行）
-- 品牌表 mall_brand 在 001 已建，本迁移不重复；本迁移仅补齐 tag 维度

CREATE TABLE IF NOT EXISTS `ye_mall_tag` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(32) NOT NULL DEFAULT '',
  `slug` varchar(32) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 99,
  `is_show` tinyint unsigned NOT NULL DEFAULT 1,
  `goods_count` int unsigned NOT NULL DEFAULT 0 COMMENT '冗余计数',
  `is_delete_time` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `sort` (`sort`,`is_show`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='商品标签';

CREATE TABLE IF NOT EXISTS `ye_mall_goods_tag` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `goods_id` int unsigned NOT NULL DEFAULT 0,
  `tag_id` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gt` (`goods_id`,`tag_id`),
  KEY `tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='商品-标签关联';
