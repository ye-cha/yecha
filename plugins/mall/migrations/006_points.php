<?php
/**
 * M10 积分体系迁移（幂等 .php 迁移）
 * - 订单表补 points_used / points_discount（结算用积分抵扣）
 * - 会员表补 signin_days / signin_at（连续签到天数 / 最近签到日）
 * - 新建 ye_mall_signin（每日签到记录，连签统计）
 */
declare(strict_types=1);

// 1. 订单表加积分抵扣字段
$orderCols = [];
foreach (Db::rows('SHOW COLUMNS FROM ' . Db::t('mall_order')) as $c) { $orderCols[] = $c['Field']; }
$addOrder = static function (string $name, string $def) use (&$orderCols): void {
    if (in_array($name, $orderCols, true)) { return; }
    Db::run('ALTER TABLE ' . Db::t('mall_order') . ' ADD COLUMN ' . $name . ' ' . $def);
    $orderCols[] = $name;
};
$addOrder('points_used', "int(11) NOT NULL DEFAULT 0 COMMENT '下单使用的积分' AFTER user_coupon_id");
$addOrder('points_discount', "decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '积分抵扣金额' AFTER points_used");

// 2. 会员表加签到字段
$memberCols = [];
foreach (Db::rows('SHOW COLUMNS FROM ' . Db::t('mall_member')) as $c) { $memberCols[] = $c['Field']; }
$addMember = static function (string $name, string $def) use (&$memberCols): void {
    if (in_array($name, $memberCols, true)) { return; }
    Db::run('ALTER TABLE ' . Db::t('mall_member') . ' ADD COLUMN ' . $name . ' ' . $def);
    $memberCols[] = $name;
};
$addMember('signin_days', "int(11) NOT NULL DEFAULT 0 COMMENT '连续签到天数' AFTER level");
$addMember('signin_at', "date DEFAULT NULL COMMENT '最近签到日期' AFTER signin_days");

// 3. 签到记录表
Db::run('CREATE TABLE IF NOT EXISTS ' . Db::t('mall_signin') . ' (
    id int(10) unsigned NOT NULL AUTO_INCREMENT,
    uid int(10) unsigned NOT NULL DEFAULT 0,
    day date NOT NULL,
    consecutive int(11) NOT NULL DEFAULT 1 COMMENT \'当日连续签到天数\',
    points int(11) NOT NULL DEFAULT 0 COMMENT \'当日获得积分\',
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_uid_day (uid, day),
    KEY idx_uid (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=\'商城每日签到\'');

// 4. 积分规则默认值（仅首装种子，重复启用安全）
// ⚠️ 宿主配置项一律存 ye_options（经 get_option/set_option 读写）；ye_mall_config 为历史遗留表，运行时从不读取。
$seedOpt = static function (string $k, string $v): void {
    if (get_option($k, null) === null) { set_option($k, $v); }
};
$seedOpt('mall.points_rate', '100');      // 多少积分抵 ¥1
$seedOpt('mall.points_max_rate', '0.5');   // 积分最多抵扣应付金额比例
$seedOpt('mall.signin_points', '5');       // 每日签到基础积分
$seedOpt('mall.signin_bonus', '10');       // 每连签 7 天的额外奖励积分
