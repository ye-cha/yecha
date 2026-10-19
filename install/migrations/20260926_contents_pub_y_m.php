<?php
declare(strict_types=1);
/**
 * 迁移（2026-09-26）：内容表补「发布日期生成列」pub_y / pub_m，并补一条归档用组合索引。
 *
 * 背景（必修缺陷）：内核 `core/Front.php` 的归档路径一直按
 *   `SELECT pub_y y, pub_m m, COUNT(*) … GROUP BY pub_y, pub_m`
 * 取年月计数（生成列可直接用索引做覆盖扫描，避免对 17 万行做 YEAR()/MONTH() 函数分组），
 * 但 `install/schema.sql` **从未定义这两列** ⇒ **空库全新安装的站点访问 /archives 直接 500**
 * （实测：`SQLSTATE[42S22]: Unknown column 'pub_y'`，core/Front.php:954）。
 * 老站的这两列是当年手写 ALTER 加上去的，从未回写进 schema —— 这正是历史发行包
 * 「装上跑不起来」的缺陷之一。
 *
 * 幂等：先查 information_schema 再 ALTER；列已存在则跳过；索引已存在则跳过。
 * 可重复执行：Upgrade 逐条迁移只跑一次（记录在 ye_migrations），此处仍按幂等写。
 */
$T = Db::t('contents');

$hasCol = function (string $col) use ($T): bool {
    $r = Db::rows(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$T, $col]
    );
    return !empty($r);
};
$hasIdx = function (string $key) use ($T): bool {
    foreach (Db::rows('SHOW INDEX FROM ' . $T) as $ix) {
        if (($ix['Key_name'] ?? '') === $key) { return true; }
    }
    return false;
};

if (!$hasCol('pub_y')) {
    Db::run("ALTER TABLE {$T} ADD COLUMN pub_y SMALLINT UNSIGNED GENERATED ALWAYS AS (YEAR(published_at)) STORED COMMENT '发布年（生成列，供归档计数走覆盖索引）'");
    echo "add contents.pub_y\n";
} else {
    echo "contents.pub_y exists\n";
}

if (!$hasCol('pub_m')) {
    Db::run("ALTER TABLE {$T} ADD COLUMN pub_m TINYINT UNSIGNED GENERATED ALWAYS AS (MONTH(published_at)) STORED COMMENT '发布月（生成列，供归档计数走覆盖索引）'");
    echo "add contents.pub_m\n";
} else {
    echo "contents.pub_m exists\n";
}

if (!$hasIdx('idx_contents_archive')) {
    Db::run("ALTER TABLE {$T} ADD KEY idx_contents_archive (type, status, pub_y, pub_m)");
    echo "add contents.idx_contents_archive\n";
} else {
    echo "contents.idx_contents_archive exists\n";
}

// 修正既有行（生成列由 MySQL 自动回填，这里只做一次断言式的可读性校验输出）
$n = (int)Db::one('SELECT COUNT(*) FROM ' . $T . ' WHERE published_at IS NOT NULL AND pub_y IS NULL');
echo "pub_y/pub_m 生成列就绪（published_at 非空但 pub_y 为空的行数={$n}，应为 0）\n";
