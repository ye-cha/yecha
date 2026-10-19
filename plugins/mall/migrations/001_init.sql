-- YeCha Mall 插件 · 初始建表迁移（幂等：CREATE TABLE IF NOT EXISTS）
-- 表名在此写逻辑名 mall_*，运行时经 Db::t() 映射为 ye_mall_*（跟随宿主前缀机制）
-- 对齐 MySQL 5.7 / utf8mb4 / InnoDB；金额 DECIMAL(10,2)；软删 is_delete_time；每表 created_at

-- ============ 用户侧 ============

CREATE TABLE IF NOT EXISTS `ye_mall_user_address` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uid` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(32) NOT NULL DEFAULT '',
  `mobile` varchar(20) NOT NULL DEFAULT '',
  `province` varchar(32) NOT NULL DEFAULT '',
  `city` varchar(32) NOT NULL DEFAULT '',
  `county` varchar(32) NOT NULL DEFAULT '',
  `address_detail` varchar(255) NOT NULL DEFAULT '',
  `is_default` tinyint unsigned NOT NULL DEFAULT 0,
  `is_delete_time` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`,`is_delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ye_mall_cart` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uid` int unsigned NOT NULL DEFAULT 0,
  `goods_id` int unsigned NOT NULL DEFAULT 0,
  `sku_id` int unsigned NOT NULL DEFAULT 0,
  `num` int unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid_sku` (`uid`,`sku_id`),
  KEY `goods_id` (`goods_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ 商品侧 ============

CREATE TABLE IF NOT EXISTS `ye_mall_goods_category` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pid` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(64) NOT NULL DEFAULT '',
  `slug` varchar(64) NOT NULL DEFAULT '',
  `icon` varchar(255) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  `is_show` tinyint unsigned NOT NULL DEFAULT 1,
  `seo_title` varchar(128) NOT NULL DEFAULT '',
  `seo_keywords` varchar(255) NOT NULL DEFAULT '',
  `seo_desc` varchar(255) NOT NULL DEFAULT '',
  `is_delete_time` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `pid` (`pid`,`is_show`,`sort`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ye_mall_brand` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL DEFAULT '',
  `slug` varchar(32) NOT NULL DEFAULT '' COMMENT 'URL 用，留空自动',
  `logo` varchar(255) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  `is_show` tinyint unsigned NOT NULL DEFAULT 1,
  `is_delete_time` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ye_mall_goods` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int unsigned NOT NULL DEFAULT 0,
  `brand_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(128) NOT NULL DEFAULT '',
  `subtitle` varchar(255) NOT NULL DEFAULT '',
  `slug` varchar(96) NOT NULL DEFAULT '',
  `cover` varchar(255) NOT NULL DEFAULT '',
  `images` json DEFAULT NULL,
  `attrs` json DEFAULT NULL COMMENT '商品属性：外观/生熟/年份/价格区间（51puer 源对齐）',
  `detail` mediumtext,
  `status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '0下架 1上架',
  `is_recommend` tinyint unsigned NOT NULL DEFAULT 0,
  `min_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'SKU 最低价冗余',
  `market_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '划线价冗余',
  `total_stock` int NOT NULL DEFAULT 0 COMMENT 'SKU 库存合计冗余',
  `sales_count` int unsigned NOT NULL DEFAULT 0,
  `virtual_sales` int unsigned NOT NULL DEFAULT 0,
  `comments` int unsigned NOT NULL DEFAULT 0 COMMENT '累计评价数（51puer 源对齐；默认 0，下单后自增）',
  `freight_template_id` int unsigned NOT NULL DEFAULT 0,
  `seo_title` varchar(128) NOT NULL DEFAULT '',
  `seo_keywords` varchar(255) NOT NULL DEFAULT '',
  `seo_desc` varchar(255) NOT NULL DEFAULT '',
  `is_delete_time` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `category` (`category_id`,`status`,`is_delete_time`),
  KEY `recommend` (`is_recommend`,`status`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ye_mall_goods_sku` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `goods_id` int unsigned NOT NULL DEFAULT 0,
  `spec_json` json DEFAULT NULL COMMENT '{"规格":"饼茶 357g","年份":"2021"}',
  `spec_text` varchar(255) NOT NULL DEFAULT '' COMMENT '饼茶 357g / 2021',
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `market_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `stock` int NOT NULL DEFAULT 0,
  `sku_no` varchar(64) NOT NULL DEFAULT '',
  `weight` int unsigned NOT NULL DEFAULT 0 COMMENT '克',
  `is_default` tinyint unsigned NOT NULL DEFAULT 0,
  `is_delete_time` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `goods` (`goods_id`,`is_delete_time`),
  KEY `sku_no` (`sku_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ye_mall_freight_template` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL DEFAULT '',
  `charge_type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1按件 2按重 3按金额',
  `rules` json DEFAULT NULL COMMENT '[{"regions":["云南省"],"first_fee":0,"add_fee":0}]',
  `is_default` tinyint unsigned NOT NULL DEFAULT 0,
  `is_delete_time` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ 交易侧 ============

CREATE TABLE IF NOT EXISTS `ye_mall_order` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_no` varchar(32) NOT NULL DEFAULT '' COMMENT 'YmdHis+uid后4+随机4',
  `uid` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '0待付款 1待发货 2待收货 3已完成 4已取消 5售后中',
  `total_goods_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `freight_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pay_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `address_json` json DEFAULT NULL COMMENT '下单快照',
  `user_coupon_id` int unsigned NOT NULL DEFAULT 0,
  `remark` varchar(255) NOT NULL DEFAULT '',
  `pay_type` varchar(16) NOT NULL DEFAULT '' COMMENT 'alipay/wxpay',
  `pay_time` datetime DEFAULT NULL,
  `express_name` varchar(32) NOT NULL DEFAULT '',
  `express_no` varchar(64) NOT NULL DEFAULT '',
  `delivery_time` datetime DEFAULT NULL,
  `confirm_time` datetime DEFAULT NULL,
  `finish_time` datetime DEFAULT NULL,
  `cancel_time` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_no` (`order_no`),
  KEY `uid_status` (`uid`,`status`),
  KEY `status_ctime` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ye_mall_order_goods` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL DEFAULT 0,
  `goods_id` int unsigned NOT NULL DEFAULT 0,
  `sku_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(128) NOT NULL DEFAULT '' COMMENT '快照',
  `spec_text` varchar(255) NOT NULL DEFAULT '' COMMENT '快照',
  `cover` varchar(255) NOT NULL DEFAULT '' COMMENT '快照',
  `price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '快照单价',
  `num` int unsigned NOT NULL DEFAULT 1,
  `total_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `aftersale_status` tinyint unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `order` (`order_id`),
  KEY `goods` (`goods_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ye_mall_order_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL DEFAULT 0,
  `order_no` varchar(32) NOT NULL DEFAULT '',
  `action` varchar(32) NOT NULL DEFAULT '' COMMENT 'create/pay/deliver/confirm/cancel/refund...',
  `detail` varchar(255) NOT NULL DEFAULT '',
  `operator` varchar(32) NOT NULL DEFAULT '' COMMENT 'user/admin/system',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ye_mall_payment_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `out_trade_no` varchar(64) NOT NULL DEFAULT '' COMMENT '商城侧单号',
  `order_no` varchar(32) NOT NULL DEFAULT '',
  `pay_type` varchar(16) NOT NULL DEFAULT '',
  `trade_no` varchar(64) NOT NULL DEFAULT '' COMMENT '渠道流水号（幂等键）',
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '0待支付 1成功 2失败',
  `notify_raw` text,
  `notify_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `trade_no` (`trade_no`),
  KEY `out_trade_no` (`out_trade_no`),
  KEY `order_no` (`order_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ye_mall_aftersale` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `aftersale_no` varchar(32) NOT NULL DEFAULT '',
  `order_id` int unsigned NOT NULL DEFAULT 0,
  `order_goods_id` int unsigned NOT NULL DEFAULT 0,
  `uid` int unsigned NOT NULL DEFAULT 0,
  `type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1仅退款 2退货退款',
  `reason` varchar(255) NOT NULL DEFAULT '',
  `refund_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '0申请 1同意 2拒绝 3已退货 4已退款',
  `evidence_images` json DEFAULT NULL,
  `admin_note` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `aftersale_no` (`aftersale_no`),
  KEY `order` (`order_id`),
  KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ 营销 / 内容 ============

CREATE TABLE IF NOT EXISTS `ye_mall_coupon` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL DEFAULT '',
  `type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1满减',
  `threshold` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_count` int unsigned NOT NULL DEFAULT 0,
  `issued_count` int unsigned NOT NULL DEFAULT 0,
  `per_user_limit` int unsigned NOT NULL DEFAULT 1,
  `start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `is_delete_time` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ye_mall_user_coupon` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `coupon_id` int unsigned NOT NULL DEFAULT 0,
  `uid` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '0未用 1已用 2过期',
  `used_order_no` varchar(32) NOT NULL DEFAULT '',
  `received_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `coupon_uid` (`coupon_id`,`uid`,`status`),
  KEY `uid_status` (`uid`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ye_mall_goods_article` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `goods_id` int unsigned NOT NULL DEFAULT 0,
  `article_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'ye_contents.id',
  `sort` int NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ga` (`goods_id`,`article_id`),
  KEY `article` (`article_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ye_mall_ad` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL DEFAULT '',
  `position` varchar(32) NOT NULL DEFAULT 'home_slide' COMMENT 'home_slide/home_floor 等',
  `image` varchar(255) NOT NULL DEFAULT '',
  `url` varchar(255) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  `is_show` tinyint unsigned NOT NULL DEFAULT 1,
  `is_delete_time` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `position` (`position`,`is_show`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ye_mall_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `k` varchar(64) NOT NULL DEFAULT '',
  `v` text,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `k` (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ 二期预留（表先建，代码 M6+/二期实现） ============

CREATE TABLE IF NOT EXISTS `ye_mall_review` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_goods_id` int unsigned NOT NULL DEFAULT 0,
  `order_id` int unsigned NOT NULL DEFAULT 0,
  `goods_id` int unsigned NOT NULL DEFAULT 0,
  `uid` int unsigned NOT NULL DEFAULT 0,
  `score` tinyint unsigned NOT NULL DEFAULT 5,
  `content` text,
  `images` json DEFAULT NULL,
  `is_show` tinyint unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `goods` (`goods_id`,`is_show`),
  KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ye_mall_search_keyword` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `keyword` varchar(64) NOT NULL DEFAULT '',
  `hit_count` int unsigned NOT NULL DEFAULT 1,
  `last_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `keyword` (`keyword`(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
