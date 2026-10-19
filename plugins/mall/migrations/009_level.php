<?php
/**
 * M13 会员等级折扣迁移（幂等 .php 迁移）
 * - ye_mall_level：等级定义（等级值 / 名称 / 折扣率 / 升级累计积分门槛 / 权益说明）
 * - 默认种子：Lv1~Lv4 普通会员→钻石会员，折扣 1.000 / 0.980 / 0.950 / 0.900
 * - 会员已有 level 列（tinyint），无需加列；按累计消费 (total_consume) 与积分综合判定升级
 */
declare(strict_types=1);

Db::run('CREATE TABLE IF NOT EXISTS ' . Db::t('mall_level') . " (
    id int(10) unsigned NOT NULL AUTO_INCREMENT,
    level tinyint unsigned NOT NULL DEFAULT 1 COMMENT 'level value',
    name varchar(32) NOT NULL DEFAULT '' COMMENT 'level name',
    icon varchar(32) NOT NULL DEFAULT '' COMMENT 'level icon emoji',
    discount decimal(4,3) NOT NULL DEFAULT 1.000 COMMENT 'discount rate 0.950 = 95%',
    min_consume decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'upgrade threshold by total consume',
    min_points int(11) NOT NULL DEFAULT 0 COMMENT 'upgrade threshold by points',
    benefit varchar(255) NOT NULL DEFAULT '' COMMENT 'rights description',
    sort int(11) NOT NULL DEFAULT 0,
    status tinyint unsigned NOT NULL DEFAULT 1,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_level (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='mall member level'");

// 默认等级（按 level 去重，重复启用安全）
$seed = [
    [1, '普通会员', '', 1.000, 0.00, 0, '下单即享基础价格，生日双倍积分'],
    [2, '铜牌会员', '', 0.980, 500.00, 0, '全场 98 折，专属客服优先响应'],
    [3, '银牌会员', '', 0.950, 2000.00, 0, '全场 95 折，每月赠送 100 积分'],
    [4, '钻石会员', '', 0.900, 8000.00, 0, '全场 9 折，新品优先购 + 包邮特权'],
];
foreach ($seed as $s) {
    if ((int)Db::one('SELECT COUNT(*) FROM ' . Db::t('mall_level') . ' WHERE level=?', [$s[0]]) === 0) {
        Db::insert('mall_level', [
            'level' => $s[0], 'name' => $s[1], 'icon' => $s[2], 'discount' => $s[3],
            'min_consume' => $s[4], 'min_points' => $s[5], 'benefit' => $s[6], 'sort' => $s[0], 'status' => 1,
        ]);
    }
}

// ⚠️ 宿主配置项一律存 ye_options（经 get_option/set_option 读写）；ye_mall_config 为历史遗留表，运行时从不读取。
$seedOpt = static function (string $k, string $v): void {
    if (get_option($k, null) === null) { set_option($k, $v); }
};
$seedOpt('mall.level_on', '1');           // 会员等级折扣总开关
$seedOpt('mall.level_on_promo', '0');     // 会员折扣是否与促销价叠加（1=叠加）
