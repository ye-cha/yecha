-- YeCha 企业官网插件 · 初始建表迁移（幂等：CREATE TABLE IF NOT EXISTS）
-- 表名跟随宿主前缀机制（本机前缀 ye_，与 mall 迁移同约定）；MySQL 5.7 / utf8mb4 / InnoDB

-- ============ 首页横幅 ============

CREATE TABLE IF NOT EXISTS `ye_site_banner` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(128) NOT NULL DEFAULT '',
  `sub` varchar(255) NOT NULL DEFAULT '',
  `image` varchar(255) NOT NULL DEFAULT '',
  `link` varchar(255) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  `is_show` tinyint unsigned NOT NULL DEFAULT 1,
  `is_delete_time` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `show_sort` (`is_show`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ 产品 ============

CREATE TABLE IF NOT EXISTS `ye_site_product_category` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL DEFAULT '',
  `slug` varchar(64) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  `is_show` tinyint unsigned NOT NULL DEFAULT 1,
  `is_delete_time` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `show_sort` (`is_show`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ye_site_product` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `cate_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(128) NOT NULL DEFAULT '',
  `summary` varchar(500) NOT NULL DEFAULT '',
  `content` mediumtext NULL,
  `image` varchar(255) NOT NULL DEFAULT '',
  `params` text NULL COMMENT '参数表：每行「名称|值」',
  `sort` int NOT NULL DEFAULT 0,
  `is_show` tinyint unsigned NOT NULL DEFAULT 1,
  `is_delete_time` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `cate` (`cate_id`,`is_show`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ 客户案例 ============

CREATE TABLE IF NOT EXISTS `ye_site_case` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL DEFAULT '' COMMENT '客户/项目名',
  `industry` varchar(64) NOT NULL DEFAULT '' COMMENT '行业',
  `challenge` text NULL COMMENT '客户挑战',
  `solution` text NULL COMMENT '解决方案',
  `result` text NULL COMMENT '成果数据',
  `image` varchar(255) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  `is_show` tinyint unsigned NOT NULL DEFAULT 1,
  `is_delete_time` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `show_sort` (`is_show`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ 留言（询盘） ============

CREATE TABLE IF NOT EXISTS `ye_site_message` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL DEFAULT '',
  `contact` varchar(128) NOT NULL DEFAULT '' COMMENT '电话/邮箱/微信',
  `content` varchar(2000) NOT NULL DEFAULT '',
  `intent` varchar(32) NOT NULL DEFAULT '' COMMENT '意图分类：询价/售后/合作/其他',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `is_read` tinyint unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `read_time` (`is_read`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ 招聘 ============

CREATE TABLE IF NOT EXISTS `ye_site_job` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(128) NOT NULL DEFAULT '',
  `place` varchar(64) NOT NULL DEFAULT '' COMMENT '工作地点',
  `type` varchar(32) NOT NULL DEFAULT '全职',
  `salary` varchar(64) NOT NULL DEFAULT '',
  `requirement` text NULL COMMENT '任职要求（每行一条）',
  `duty` text NULL COMMENT '岗位职责（每行一条）',
  `sort` int NOT NULL DEFAULT 0,
  `is_show` tinyint unsigned NOT NULL DEFAULT 1,
  `is_delete_time` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `show_sort` (`is_show`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ye_site_job_apply` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `job_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(64) NOT NULL DEFAULT '',
  `contact` varchar(128) NOT NULL DEFAULT '',
  `intro` varchar(2000) NOT NULL DEFAULT '' COMMENT '简介/简历要点',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `job` (`job_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
