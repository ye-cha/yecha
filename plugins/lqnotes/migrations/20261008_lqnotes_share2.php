<?php
declare(strict_types=1);
/**
 * lqnotes · 批3 分享增强迁移（幂等）
 * ===========================================================================
 * 做两件事（只新增 / 只加列，不改既有的任何一列语义、不碰别的表）：
 *   ① `ye_note_shares` 加 `alias` 列（自定义短链 /s/<alias>）+ 唯一键
 *        · 用 DEFAULT NULL：MySQL 唯一索引允许多个 NULL ⇒ 没设别名的分享不互相冲突
 *   ② 新建 `ye_note_share_views` 访问明细表（PV 逐次、UV 按 (token, ip_hash) 去重）
 *        · ip_hash = sha256(ip|当日|固定盐) —— **不存明文 IP**（与参照口径一致：同一访客当天只计一次）
 *        · referer 只存**来源主机名**（≤160），不存完整 URL（隐私）
 * 执行方式（站内既有口径）：Upgrade::apply('lqnotes')
 * 撤销：DROP TABLE ye_note_share_views; ALTER TABLE ye_note_shares DROP INDEX uk_note_shares_alias, DROP COLUMN alias;
 *       + 删 ye_migrations 里本文件那一行（见 /root/lqn2 的回滚脚本）
 * ★ MySQL 没有 ADD COLUMN IF NOT EXISTS ⇒ 先查 information_schema 再改（幂等）。
 */

// ① alias 列
$tbl = Db::t('note_shares');
$hasCol = (int)Db::one(
    'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
    [$tbl, 'alias']
);
if ($hasCol === 0) {
    Db::run('ALTER TABLE `' . $tbl . '` ADD COLUMN `alias` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL '
        . "COMMENT '自定义短链 /s/<alias>；NULL = 只用随机 token' AFTER `fmt`");
}
$hasIdx = (int)Db::one(
    'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',
    [$tbl, 'uk_note_shares_alias']
);
if ($hasIdx === 0) {
    Db::run('ALTER TABLE `' . $tbl . '` ADD UNIQUE KEY `uk_note_shares_alias` (`alias`)');
}

// ② 访问明细（UV 去重 + 最近来源）
Db::run('CREATE TABLE IF NOT EXISTS `' . Db::t('note_share_views') . '` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `token` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\',
  `ip_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\' COMMENT \'sha256(ip|当日|固定盐) —— 不存明文 IP\',
  `ua_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\',
  `referer` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\' COMMENT \'只存来源主机名\',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_nsv_token_ip` (`token`,`ip_hash`),
  KEY `idx_nsv_token_time` (`token`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'云笔记 · 分享访问明细（lqnotes）\'');

// ③ 按日聚合（近 14 天趋势用；PV 逐次累加、UV 只在新访客那天 +1）
Db::run('CREATE TABLE IF NOT EXISTS `' . Db::t('note_share_days') . '` (
  `token` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\',
  `day` date NOT NULL,
  `pv` int(11) NOT NULL DEFAULT 0,
  `uv` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`token`,`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'云笔记 · 分享按日统计（lqnotes）\'');
