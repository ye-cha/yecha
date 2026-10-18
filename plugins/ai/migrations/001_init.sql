-- YeCha AI 插件 · 初始建表迁移（幂等：CREATE TABLE IF NOT EXISTS）
-- 表名跟随宿主前缀机制（Db::t('ai_x') 映射；本机前缀 ye_，与 mall 迁移同约定）
-- 对齐 MySQL 5.7 / utf8mb4 / InnoDB

-- ============ 知识库（RAG 语料 chunk） ============

CREATE TABLE IF NOT EXISTS `ye_ai_knowledge` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(128) NOT NULL DEFAULT '',
  `content` mediumtext NOT NULL,
  `tags` varchar(255) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  `is_show` tinyint unsigned NOT NULL DEFAULT 1,
  `is_delete_time` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FULLTEXT KEY `ft_knowledge` (`title`,`content`) WITH PARSER ngram,
  KEY `show_sort` (`is_show`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ AI 客服会话 ============

CREATE TABLE IF NOT EXISTS `ye_ai_chat_session` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `sid` varchar(64) NOT NULL DEFAULT '',
  `uid` int unsigned NOT NULL DEFAULT 0,
  `ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sid` (`sid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ye_ai_chat_message` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `sid` varchar(64) NOT NULL DEFAULT '',
  `role` varchar(16) NOT NULL DEFAULT 'user',
  `content` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sid` (`sid`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ 调用日志（成本 / 排障） ============

CREATE TABLE IF NOT EXISTS `ye_ai_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `scene` varchar(32) NOT NULL DEFAULT '',
  `provider` varchar(32) NOT NULL DEFAULT '',
  `model` varchar(64) NOT NULL DEFAULT '',
  `in_tokens` int unsigned NOT NULL DEFAULT 0,
  `out_tokens` int unsigned NOT NULL DEFAULT 0,
  `cost_ms` int unsigned NOT NULL DEFAULT 0,
  `ok` tinyint unsigned NOT NULL DEFAULT 1,
  `err` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `scene_time` (`scene`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
