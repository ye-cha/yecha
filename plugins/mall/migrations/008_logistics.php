<?php
/**
 * M12 履约通知迁移（幂等 .php 迁移）
 * - ye_mall_delivery_trace：物流轨迹（发货后由轨迹接口/后台录入）
 * - ye_mall_message：站内消息（下单/付款/发货/收货/取消等触发）
 * - 订单表补 cancel_auto_at（超时未付款自动取消到期时间）
 */
declare(strict_types=1);

$mk = static function (string $table, string $ddl): void {
    Db::run('CREATE TABLE IF NOT EXISTS ' . Db::t($table) . ' ' . $ddl);
};

/* ---------- 1. 物流轨迹 ---------- */
$mk('mall_delivery_trace', "(
    id int(10) unsigned NOT NULL AUTO_INCREMENT,
    order_no varchar(32) NOT NULL DEFAULT '',
    express_name varchar(32) NOT NULL DEFAULT '',
    express_no varchar(64) NOT NULL DEFAULT '',
    occur_time datetime NOT NULL COMMENT 'trace time',
    status_text varchar(32) NOT NULL DEFAULT '' COMMENT 'collected/transit/delivering/signed',
    content varchar(255) NOT NULL DEFAULT '',
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_order (order_no), KEY idx_express (express_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='mall delivery trace'");

/* ---------- 2. 站内消息 ---------- */
$mk('mall_message', "(
    id int(10) unsigned NOT NULL AUTO_INCREMENT,
    uid int(10) unsigned NOT NULL DEFAULT 0 COMMENT '0 = broadcast',
    type varchar(16) NOT NULL DEFAULT 'system' COMMENT 'order/pay/ship/finish/cancel/system',
    title varchar(128) NOT NULL DEFAULT '',
    content varchar(500) NOT NULL DEFAULT '',
    link varchar(255) NOT NULL DEFAULT '',
    is_read tinyint unsigned NOT NULL DEFAULT 0,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_uid_read (uid, is_read), KEY idx_uid_time (uid, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='mall user message'");

/* ---------- 3. 订单表补自动取消到期时间 ---------- */
$cols = [];
foreach (Db::rows('SHOW COLUMNS FROM ' . Db::t('mall_order')) as $c) { $cols[] = $c['Field']; }
if (!in_array('cancel_auto_at', $cols, true)) {
    Db::run('ALTER TABLE ' . Db::t('mall_order') . " ADD COLUMN cancel_auto_at datetime DEFAULT NULL COMMENT 'auto unpaid cancel deadline' AFTER cancel_reason");
}

// 4. 默认配置
// ⚠️ 宿主配置项一律存 ye_options（经 get_option/set_option 读写）；ye_mall_config 为历史遗留表，运行时从不读取。
$seedOpt = static function (string $k, string $v): void {
    if (get_option($k, null) === null) { set_option($k, $v); }
};
$seedOpt('mall.auto_cancel_hours', '2');    // 未付款订单 N 小时后自动取消，0=关闭
$seedOpt('mall.auto_finish_days', '15');    // 发货后 N 天自动确认收货，0=关闭
$seedOpt('mall.msg_order', '1');            // 下单消息
$seedOpt('mall.msg_pay', '1');              // 付款消息
$seedOpt('mall.msg_ship', '1');             // 发货消息
$seedOpt('mall.msg_finish', '1');           // 完成消息
$seedOpt('mall.msg_cancel', '1');           // 取消消息
