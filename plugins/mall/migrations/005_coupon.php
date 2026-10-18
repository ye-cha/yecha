<?php
/**
 * M9 优惠券增强迁移（幂等 .php 迁移）
 * - 折扣券：coupon 表补 discount_rate / max_discount（type=2）
 * - 新人券：补 grant_newcomer（注册/首次访问自动发放）
 * - 种子：一张折扣券 + 一张新人券（按 name 去重，重复启用安全）
 */
declare(strict_types=1);

$existing = [];
foreach (Db::rows('SHOW COLUMNS FROM ' . Db::t('mall_coupon')) as $c) { $existing[] = $c['Field']; }
$addCol = static function (string $name, string $def) use (&$existing): void {
    if (in_array($name, $existing, true)) { return; }
    Db::run('ALTER TABLE ' . Db::t('mall_coupon') . ' ADD COLUMN ' . $name . ' ' . $def);
    $existing[] = $name;
};
$addCol('discount_rate', "decimal(4,3) NOT NULL DEFAULT 0.000 COMMENT '折扣率 0.850=8.5折' AFTER amount");
$addCol('max_discount', "decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '折扣券封顶 0=不封顶' AFTER discount_rate");
$addCol('grant_newcomer', "tinyint unsigned NOT NULL DEFAULT 0 COMMENT '1=新人自动发放' AFTER status");

// 种子：折扣券（无门槛 8.5 折，封顶 50）
if ((int)Db::one('SELECT COUNT(*) FROM ' . Db::t('mall_coupon') . ' WHERE name=? AND is_delete_time=0', ['新茶尝鲜 8.5折']) === 0) {
    Db::insert('mall_coupon', [
        'name' => '新茶尝鲜 8.5折',
        'type' => 2,
        'threshold' => 0.00,
        'amount' => 0.00,
        'discount_rate' => 0.850,
        'max_discount' => 50.00,
        'total_count' => 200,
        'issued_count' => 0,
        'per_user_limit' => 1,
        'start_time' => null,
        'end_time' => null,
        'status' => 1,
        'grant_newcomer' => 0,
    ]);
}
// 种子：新人券（满 99 减 20，注册/首次访问自动发放）
if ((int)Db::one('SELECT COUNT(*) FROM ' . Db::t('mall_coupon') . ' WHERE name=? AND is_delete_time=0', ['新人专享 满99减20']) === 0) {
    Db::insert('mall_coupon', [
        'name' => '新人专享 满99减20',
        'type' => 1,
        'threshold' => 99.00,
        'amount' => 20.00,
        'discount_rate' => 0.000,
        'max_discount' => 0.00,
        'total_count' => 9999,
        'issued_count' => 0,
        'per_user_limit' => 1,
        'start_time' => null,
        'end_time' => null,
        'status' => 1,
        'grant_newcomer' => 1,
    ]);
}
