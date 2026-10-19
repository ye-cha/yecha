-- YeCha 夜叉 数据库结构（E0 安装用）
-- 约定：utf8mb4 / InnoDB；{PREFIX} 由安装向导替换为实际表前缀（默认 ye_）
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS {PREFIX}users (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL COMMENT '登录名，唯一',
  email         VARCHAR(190) NOT NULL COMMENT '邮箱，唯一',
  password      VARCHAR(255) NOT NULL COMMENT 'bcrypt/argon2 哈希',
  role          VARCHAR(16)  NOT NULL DEFAULT 'subscriber' COMMENT 'admin | subscriber',
  display_name  VARCHAR(100) NOT NULL DEFAULT '' COMMENT '昵称',
  bio           VARCHAR(500) NOT NULL DEFAULT '' COMMENT '博主信息卡·签名',
  avatar        VARCHAR(255) NOT NULL DEFAULT '' COMMENT '头像(URL 或附件路径)',
  website       VARCHAR(255) NOT NULL DEFAULT '' COMMENT '主页',
  social        JSON NULL COMMENT '博主信息卡·社交链接',
  status        VARCHAR(16)  NOT NULL DEFAULT 'normal' COMMENT 'normal | disabled',
  reset_code    VARCHAR(64)  NULL,
  reset_expires DATETIME     NULL,
  last_login_at DATETIME     NULL,
  last_login_ip VARCHAR(45)  NOT NULL DEFAULT '',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_users_username (username),
  UNIQUE KEY uk_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {PREFIX}options (
  name       VARCHAR(100) NOT NULL COMMENT '键',
  value      MEDIUMTEXT   NULL,
  autoload   TINYINT      NOT NULL DEFAULT 1,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {PREFIX}contents (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type           VARCHAR(16)  NOT NULL DEFAULT 'post' COMMENT 'post | page',
  status         VARCHAR(16)  NOT NULL DEFAULT 'draft' COMMENT 'draft | publish | hidden | trash',
  title          VARCHAR(200) NOT NULL DEFAULT '',
  slug           VARCHAR(200) NULL,
  excerpt        TEXT NULL,
  description    VARCHAR(300) NOT NULL DEFAULT '',
  keywords       VARCHAR(255) NOT NULL DEFAULT '',
  content        LONGTEXT NULL,
  content_format VARCHAR(16)  NOT NULL DEFAULT 'markdown' COMMENT 'markdown | richtext',
  author_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  template       VARCHAR(100) NOT NULL DEFAULT '',
  `order`        INT          NOT NULL DEFAULT 0,
  featured       TINYINT      NOT NULL DEFAULT 0,
  allow_comment  TINYINT      NOT NULL DEFAULT 1,
  views          BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '阅读量：自然日去重；多语共用',
  password       VARCHAR(255) NOT NULL DEFAULT '',
  published_at   DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at     DATETIME     NULL,
  UNIQUE KEY uk_contents_slug (slug),
  KEY idx_contents_list (type, status, published_at),
  KEY idx_contents_author (author_id),
  FULLTEXT KEY ft_contents_body (title, content) WITH PARSER ngram
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {PREFIX}metas (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type        VARCHAR(16)  NOT NULL COMMENT 'category | tag',
  name        VARCHAR(100) NOT NULL,
  name_en     VARCHAR(100) NOT NULL DEFAULT '' COMMENT '英文名（空=前台回落中文名）',
  slug        VARCHAR(100) NOT NULL DEFAULT '',
  parent      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  description VARCHAR(500) NOT NULL DEFAULT '',
  `order`     INT NOT NULL DEFAULT 0,
  count       INT NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_metas_type_slug (type, slug),
  KEY idx_metas_parent (type, parent, `order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {PREFIX}relationships (
  content_id BIGINT UNSIGNED NOT NULL,
  meta_id    BIGINT UNSIGNED NOT NULL,
  `order`    INT NOT NULL DEFAULT 0,
  PRIMARY KEY (content_id, meta_id),
  KEY idx_relationships_meta (meta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {PREFIX}comments (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  content_id   BIGINT UNSIGNED NOT NULL,
  parent_id    BIGINT UNSIGNED NULL,
  author_id    BIGINT UNSIGNED NULL,
  author_name  VARCHAR(100) NOT NULL DEFAULT '',
  author_email VARCHAR(190) NOT NULL DEFAULT '',
  author_url   VARCHAR(255) NOT NULL DEFAULT '',
  ip           VARCHAR(45)  NOT NULL DEFAULT '',
  agent        VARCHAR(255) NOT NULL DEFAULT '',
  body         TEXT NOT NULL,
  kind         VARCHAR(16)  NOT NULL DEFAULT 'normal' COMMENT 'normal | segment',
  anchor_kind  VARCHAR(16)  NULL,
  anchor_ref   VARCHAR(128) NULL,
  anchor_quote VARCHAR(500) NULL,
  status       VARCHAR(16)  NOT NULL DEFAULT 'pending' COMMENT 'pending | approved | spam | trash',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_comments_list (content_id, status, created_at),
  KEY idx_comments_author (author_id),
  KEY idx_comments_kind (kind, content_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {PREFIX}favorites (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT UNSIGNED NOT NULL,
  content_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_fav (user_id, content_id),
  KEY idx_fav_content (content_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {PREFIX}marks (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  content_id  BIGINT UNSIGNED NOT NULL,
  anchor_kind VARCHAR(16)  NOT NULL DEFAULT 'sentence',
  anchor_ref  VARCHAR(128) NOT NULL DEFAULT '',
  quote       VARCHAR(500) NOT NULL COMMENT '被划线原文快照',
  color       VARCHAR(16)  NOT NULL DEFAULT '#ffe08a',
  note_count  INT NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_marks_user (user_id, created_at),
  KEY idx_marks_content (content_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {PREFIX}annotations (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  content_id  BIGINT UNSIGNED NOT NULL,
  mark_id     BIGINT UNSIGNED NULL,
  anchor_kind VARCHAR(16) NULL,
  anchor_ref  VARCHAR(128) NOT NULL DEFAULT '',
  quote       VARCHAR(500) NOT NULL DEFAULT '',
  body        TEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ann_user (user_id, created_at),
  KEY idx_ann_content (content_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {PREFIX}attachments (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  content_id BIGINT UNSIGNED NULL,
  user_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  file_path  VARCHAR(255) NOT NULL,
  file_name  VARCHAR(255) NOT NULL,
  file_type  VARCHAR(100) NOT NULL DEFAULT '',
  file_ext   VARCHAR(16)  NOT NULL DEFAULT '',
  size       INT UNSIGNED NOT NULL DEFAULT 0,
  width      INT UNSIGNED NULL,
  height     INT UNSIGNED NULL,
  md5        CHAR(32)     NULL,
  source     VARCHAR(16)  NOT NULL DEFAULT 'local',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_att_content (content_id),
  KEY idx_att_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {PREFIX}fields (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  content_id BIGINT UNSIGNED NOT NULL,
  name       VARCHAR(64)  NOT NULL,
  value      TEXT NULL,
  UNIQUE KEY uk_fields (content_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {PREFIX}import_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source      VARCHAR(16)  NOT NULL COMMENT '导入来源标识',
  source_kind VARCHAR(16)  NOT NULL COMMENT 'xml | sql',
  old_slug    VARCHAR(255) NOT NULL DEFAULT '',
  content_id  BIGINT UNSIGNED NULL,
  status      VARCHAR(16)  NOT NULL DEFAULT 'pending' COMMENT 'pending | ok | error',
  detail      TEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_import_status (source, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 多语插件（P-5）：翻译组内非默认语言的真实内容版本

-- 记事本插件（P-3）：私人快速笔记
CREATE TABLE IF NOT EXISTS {PREFIX}notes (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT UNSIGNED NOT NULL,
  title      VARCHAR(200) NOT NULL DEFAULT '',
  body       TEXT NOT NULL,
  tags       VARCHAR(255) NOT NULL DEFAULT '' COMMENT '空格分隔的轻量标签',
  status     VARCHAR(16)  NOT NULL DEFAULT 'private' COMMENT 'private | public（后续公开页用）',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_notes_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 站内通知（评论 @提及 等）
CREATE TABLE IF NOT EXISTS {PREFIX}notifications (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT UNSIGNED NOT NULL,
  type       VARCHAR(16)  NOT NULL DEFAULT 'mention' COMMENT 'mention | system',
  comment_id BIGINT UNSIGNED NULL,
  content_id BIGINT UNSIGNED NULL,
  body       VARCHAR(500) NOT NULL DEFAULT '' COMMENT '通知文案/摘要',
  is_read    TINYINT      NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notif_user (user_id, is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
