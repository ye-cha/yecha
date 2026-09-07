<?php
declare(strict_types=1);
// links 插件迁移（阶段1「看得见的信任」）：links 表加 RSS/体检列；新建出站点击表 ye_link_clicks（按访客会话日去重）
$t = Db::t('links');
$cols = array_map('strval', array_column(Db::rows('SHOW COLUMNS FROM ' . $t), 'Field'));
$add = [
    'rss'          => "VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'RSS/Feed 地址（体检与最近更新用）' AFTER url",
    'probe_at'     => "DATETIME NULL DEFAULT NULL COMMENT '最近一次机器体检时间'",
    'probe_state'  => "VARCHAR(12) NOT NULL DEFAULT '' COMMENT 'ok|err|down|no_rss|'' 体检概要'",
    'probe_note'   => "VARCHAR(255) NOT NULL DEFAULT '' COMMENT '体检说明（错误原因/站点不可达等）'",
    'last_post_at' => "DATETIME NULL DEFAULT NULL COMMENT 'RSS 最新一篇文章时间'",
    'last_ok_at'   => "DATETIME NULL DEFAULT NULL COMMENT '最近一次 RSS 探测成功时间'",
];
foreach ($add as $name => $def) {
    if (!in_array($name, $cols, true)) {
        Db::run("ALTER TABLE {$t} ADD COLUMN {$name} {$def}");
        echo "links.{$name} added\n";
    } else {
        echo "links.{$name} exists\n";
    }
}
$c = Db::t('link_clicks');
$exists = Db::rows('SHOW TABLES LIKE "' . $c . '"');
if (!$exists) {
    Db::run("CREATE TABLE {$c} (
      day      DATE NOT NULL,
      link_id  BIGINT UNSIGNED NOT NULL,
      sid      VARCHAR(40) NOT NULL COMMENT '访客会话标识（PHPSESSID 摘要）',
      PRIMARY KEY (day, link_id, sid),
      KEY idx_lc_link_day (link_id, day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "create ye_link_clicks ok\n";
} else {
    echo "ye_link_clicks exists\n";
}
