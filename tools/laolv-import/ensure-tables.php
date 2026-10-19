<?php
require dirname(__DIR__, 2) . '/config.inc.php';
require dirname(__DIR__, 2) . '/core/bootstrap.php';
foreach ([
    'notes' => 'CREATE TABLE IF NOT EXISTS ye_notes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, title VARCHAR(200) NOT NULL DEFAULT "", body TEXT NOT NULL, tags VARCHAR(255) NOT NULL DEFAULT "", status VARCHAR(16) NOT NULL DEFAULT "private", created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY idx_notes_user (user_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'notifications' => 'CREATE TABLE IF NOT EXISTS ye_notifications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, type VARCHAR(16) NOT NULL DEFAULT "mention", comment_id BIGINT UNSIGNED NULL, content_id BIGINT UNSIGNED NULL, body VARCHAR(500) NOT NULL DEFAULT "", is_read TINYINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_notif_user (user_id, is_read, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
] as $n => $sql) {
    $has = Db::one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?", ['ye_' . $n]);
    if ((int)$has === 0) { Db::run($sql); echo "created ye_$n\n"; } else { echo "exists ye_$n\n"; }
}
