<?php
declare(strict_types=1);
// 论坛 v2 迁移（t39）：置顶/精华/锁定 + 引用 + 编辑时间 + 在线时间。幂等。
$tT = Db::t('forum_threads');
$tR = Db::t('forum_replies');
$tU = Db::t('users');

$has = static function (string $table, string $col): bool {
    return Db::row('SHOW COLUMNS FROM `' . $table . "` LIKE '" . $col . "'") !== null;
};
$idx = static function (string $table, string $key): bool {
    return Db::row('SHOW INDEX FROM `' . $table . "` WHERE Key_name='" . $key . "'") !== null;
};

if (!$has($tT, 'featured')) {
    Db::run("ALTER TABLE {$tT} ADD COLUMN featured TINYINT NOT NULL DEFAULT 0 AFTER pinned");
}
if (!$has($tT, 'locked')) {
    Db::run("ALTER TABLE {$tT} ADD COLUMN locked TINYINT NOT NULL DEFAULT 0 AFTER featured");
}
if (!$idx($tT, 'idx_ft_featured')) {
    Db::run("ALTER TABLE {$tT} ADD KEY idx_ft_featured (featured, last_at)");
}
if (!$idx($tT, 'idx_ft_last')) {
    Db::run("ALTER TABLE {$tT} ADD KEY idx_ft_last (last_at)");
}
if (!$has($tR, 'quote_id')) {
    Db::run("ALTER TABLE {$tR} ADD COLUMN quote_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER user_id");
}
if (!$has($tR, 'edited_at')) {
    Db::run("ALTER TABLE {$tR} ADD COLUMN edited_at DATETIME NULL DEFAULT NULL AFTER created_at");
}
if (!$has($tU, 'last_seen_at')) {
    Db::run("ALTER TABLE {$tU} ADD COLUMN last_seen_at DATETIME NULL DEFAULT NULL");
}
if (!$idx($tU, 'idx_u_seen')) {
    Db::run("ALTER TABLE {$tU} ADD KEY idx_u_seen (last_seen_at)");
}
echo 'threads.featured=' . ($has($tT, 'featured') ? '1' : '0') . ' locked=' . ($has($tT, 'locked') ? '1' : '0')
    . ' replies.quote_id=' . ($has($tR, 'quote_id') ? '1' : '0') . ' replies.edited_at=' . ($has($tR, 'edited_at') ? '1' : '0')
    . ' users.last_seen_at=' . ($has($tU, 'last_seen_at') ? '1' : '0') . "\n";
