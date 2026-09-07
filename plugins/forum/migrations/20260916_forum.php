<?php
declare(strict_types=1);
// 论坛插件迁移：板块 / 帖子 / 回帖三表（首次创建时写入默认板块，幂等）
$tB = Db::t('forum_boards');
$tT = Db::t('forum_threads');
$tR = Db::t('forum_replies');
$exists = Db::rows('SHOW TABLES LIKE "' . $tB . '"');
if ($exists) {
    echo "ye_forum tables exist\n";
    return;
}
Db::run("CREATE TABLE {$tB} (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug       VARCHAR(80) NOT NULL,
  name       VARCHAR(120) NOT NULL DEFAULT '',
  name_en    VARCHAR(120) NOT NULL DEFAULT '',
  intro      VARCHAR(500) NOT NULL DEFAULT '',
  sort       INT NOT NULL DEFAULT 0,
  status     VARCHAR(10) NOT NULL DEFAULT 'show' COMMENT 'show|hide',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fb_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
Db::run("CREATE TABLE {$tT} (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  board_id     INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  title        VARCHAR(200) NOT NULL DEFAULT '',
  content      MEDIUMTEXT NOT NULL,
  views        INT UNSIGNED NOT NULL DEFAULT 0,
  replies      INT UNSIGNED NOT NULL DEFAULT 0,
  status       VARCHAR(10) NOT NULL DEFAULT 'normal' COMMENT 'normal|hidden|deleted',
  pinned       TINYINT NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NULL DEFAULT NULL,
  last_at      DATETIME NULL DEFAULT NULL,
  last_user_id INT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_ft_board (board_id, pinned DESC, last_at DESC),
  KEY idx_ft_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
Db::run("CREATE TABLE {$tR} (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  thread_id  BIGINT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  content    TEXT NOT NULL,
  status     VARCHAR(10) NOT NULL DEFAULT 'normal' COMMENT 'normal|hidden|deleted',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fr_thread (thread_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
// 默认板块（仅空库时写入，管理员可改）
$n = (int)Db::one("SELECT COUNT(*) FROM {$tB}");
if ($n === 0) {
    $seed = [
        ['general',  '综合交流', 'General Discussion', 'YeCha 使用心得、求助与闲聊都欢迎。', 10],
        ['feedback', '建议与反馈', 'Feedback & Bugs',    '功能建议、Bug 反馈、文档纠错。',     20],
        ['showcase', '作品展示', 'Showcase',            '用 YeCha 建出来的站，拿出来晒一晒。',  30],
    ];
    foreach ($seed as $s) {
        Db::insert('forum_boards', [
            'slug' => $s[0], 'name' => $s[1], 'name_en' => $s[2], 'intro' => $s[3], 'sort' => $s[4], 'status' => 'show',
        ]);
    }
}
echo "create ye_forum_* ok (boards=" . (int)Db::one("SELECT COUNT(*) FROM {$tB}") . ")\n";
