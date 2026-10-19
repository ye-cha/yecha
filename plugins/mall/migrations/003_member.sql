-- YeCha Mall 插件 · M7 会员中心（个人中心加强）建表迁移
-- 幂等：CREATE TABLE IF NOT EXISTS；uid 恒对应 ye_users.id（与博客共用用户中心，不建独立账号体系）
-- 说明：注释行独占一行（内核 Upgrade::splitSql 逐行剔注释，注释勿与 SQL 同行）

CREATE TABLE IF NOT EXISTS `ye_mall_member` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uid` int unsigned NOT NULL DEFAULT 0 COMMENT 'ye_users.id',
  `mobile` varchar(20) NOT NULL DEFAULT '',
  `gender` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '0保密 1男 2女',
  `birthday` date DEFAULT NULL,
  `real_name` varchar(32) NOT NULL DEFAULT '',
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '账户余额',
  `points` int NOT NULL DEFAULT 0 COMMENT '积分余额',
  `total_recharge` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_withdraw` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_consume` decimal(12,2) NOT NULL DEFAULT 0.00,
  `level` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '会员等级',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商城会员扩展';

CREATE TABLE IF NOT EXISTS `ye_mall_balance_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uid` int unsigned NOT NULL DEFAULT 0,
  `type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1充值 2消费 3退款 4提现 5调整',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '正数收入 负数支出',
  `balance_after` decimal(12,2) NOT NULL DEFAULT 0.00,
  `remark` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='余额流水';

CREATE TABLE IF NOT EXISTS `ye_mall_withdraw` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `withdraw_no` varchar(32) NOT NULL DEFAULT '',
  `uid` int unsigned NOT NULL DEFAULT 0,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `fee` decimal(12,2) NOT NULL DEFAULT 0.00,
  `real_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `account_type` varchar(16) NOT NULL DEFAULT 'alipay' COMMENT 'alipay/wechat/bank',
  `account_name` varchar(64) NOT NULL DEFAULT '',
  `account_no` varchar(64) NOT NULL DEFAULT '',
  `status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '0待审核 1已通过 2已拒绝 3已打款',
  `admin_note` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='提现记录';

CREATE TABLE IF NOT EXISTS `ye_mall_points_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uid` int unsigned NOT NULL DEFAULT 0,
  `type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1获得 2消耗',
  `points` int NOT NULL DEFAULT 0 COMMENT '正数获得 负数消耗',
  `points_after` int NOT NULL DEFAULT 0,
  `remark` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='积分流水';

CREATE TABLE IF NOT EXISTS `ye_mall_virtual_code` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uid` int unsigned NOT NULL DEFAULT 0,
  `order_id` int unsigned NOT NULL DEFAULT 0,
  `order_no` varchar(32) NOT NULL DEFAULT '',
  `goods_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(128) NOT NULL DEFAULT '',
  `code` varchar(64) NOT NULL DEFAULT '',
  `type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1卡密 2券码 3提货码',
  `status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '0未使用 1已使用 2已作废',
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='虚拟码';

CREATE TABLE IF NOT EXISTS `ye_mall_favorite` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uid` int unsigned NOT NULL DEFAULT 0,
  `goods_id` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uid_goods` (`uid`,`goods_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品收藏';

CREATE TABLE IF NOT EXISTS `ye_mall_footprint` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uid` int unsigned NOT NULL DEFAULT 0,
  `goods_id` int unsigned NOT NULL DEFAULT 0,
  `viewed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uid_goods` (`uid`,`goods_id`),
  KEY `idx_uid_time` (`uid`,`viewed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='浏览足迹';
