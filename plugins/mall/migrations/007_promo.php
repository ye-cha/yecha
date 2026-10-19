<?php
/**
 * M11 促销玩法迁移（幂等 .php 迁移）
 * - 秒杀 ye_mall_seckill：场次 + 活动库存 + 每人限购
 * - 拼团 ye_mall_groupon(活动) / ye_mall_groupon_team(团) / ye_mall_groupon_join(参团)
 * - 砍价 ye_mall_bargain(活动) / ye_mall_bargain_launch(发起) / ye_mall_bargain_help(帮砍)
 * - 组合优惠 ye_mall_bundle(套餐) / ye_mall_bundle_goods(套餐内商品)
 * 注意：本文件用 Db::t('mall_xxx') 全名（Db::t 只拼全局前缀，不带 mall_）
 */
declare(strict_types=1);

/** 幂等建表 */
$mk = static function (string $table, string $ddl): void {
    Db::run('CREATE TABLE IF NOT EXISTS ' . Db::t($table) . ' ' . $ddl);
};
/** 幂等加列 */
$cols = [];
$addCol = static function (string $table, string $name, string $def) use (&$cols): void {
    $key = $table . '.' . $name;
    if (isset($cols[$key])) { return; }
    $exists = false;
    foreach (Db::rows('SHOW COLUMNS FROM ' . Db::t($table)) as $c) {
        if ($c['Field'] === $name) { $exists = true; break; }
    }
    if ($exists) { $cols[$key] = true; return; }
    Db::run('ALTER TABLE ' . Db::t($table) . ' ADD COLUMN ' . $name . ' ' . $def);
    $cols[$key] = true;
};

/* ---------- 1. 秒杀 ---------- */
$mk('mall_seckill', "(
    id int(10) unsigned NOT NULL AUTO_INCREMENT,
    goods_id int(10) unsigned NOT NULL DEFAULT 0,
    sku_id int(10) unsigned NOT NULL DEFAULT 0,
    title varchar(128) NOT NULL DEFAULT '',
    price decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '秒杀价',
    market_price decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '划线价',
    seckill_stock int(11) NOT NULL DEFAULT 0 COMMENT '活动总库存',
    sold int(11) NOT NULL DEFAULT 0 COMMENT '已售',
    per_limit int(11) NOT NULL DEFAULT 1 COMMENT '每人限购 0=不限',
    start_time datetime NOT NULL,
    end_time datetime NOT NULL,
    sort int(11) NOT NULL DEFAULT 0,
    status tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1上架 0下架',
    is_delete_time int(10) unsigned NOT NULL DEFAULT 0,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_goods (goods_id), KEY idx_sku (sku_id), KEY idx_time (start_time, end_time), KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商城秒杀活动'");

/* ---------- 2. 拼团 ---------- */
$mk('mall_groupon', "(
    id int(10) unsigned NOT NULL AUTO_INCREMENT,
    goods_id int(10) unsigned NOT NULL DEFAULT 0,
    sku_id int(10) unsigned NOT NULL DEFAULT 0,
    title varchar(128) NOT NULL DEFAULT '',
    price decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '团购价',
    market_price decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '单买划线价',
    group_size int(11) NOT NULL DEFAULT 2 COMMENT '成团人数',
    hours int(11) NOT NULL DEFAULT 24 COMMENT '团有效时长(小时)',
    per_limit int(11) NOT NULL DEFAULT 0 COMMENT '每人限购 0=不限',
    sales int(11) NOT NULL DEFAULT 0 COMMENT '已成团数',
    start_time datetime DEFAULT NULL,
    end_time datetime DEFAULT NULL,
    sort int(11) NOT NULL DEFAULT 0,
    status tinyint unsigned NOT NULL DEFAULT 1,
    is_delete_time int(10) unsigned NOT NULL DEFAULT 0,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_goods (goods_id), KEY idx_sku (sku_id), KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商城拼团活动'");

$mk('mall_groupon_team', "(
    id int(10) unsigned NOT NULL AUTO_INCREMENT,
    groupon_id int(10) unsigned NOT NULL DEFAULT 0,
    leader_uid int(10) unsigned NOT NULL DEFAULT 0 COMMENT '团长uid',
    need_size int(11) NOT NULL DEFAULT 2 COMMENT '所需人数',
    joined_count int(11) NOT NULL DEFAULT 1 COMMENT '已参团人数(含团长)',
    status tinyint unsigned NOT NULL DEFAULT 0 COMMENT '0进行中 1成团 2失败',
    expire_at datetime NOT NULL COMMENT '到期时间',
    success_at datetime DEFAULT NULL,
    is_delete_time int(10) unsigned NOT NULL DEFAULT 0,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_groupon (groupon_id), KEY idx_leader (leader_uid), KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='拼团团实例'");

$mk('mall_groupon_join', "(
    id int(10) unsigned NOT NULL AUTO_INCREMENT,
    team_id int(10) unsigned NOT NULL DEFAULT 0,
    groupon_id int(10) unsigned NOT NULL DEFAULT 0,
    uid int(10) unsigned NOT NULL DEFAULT 0,
    order_no varchar(32) NOT NULL DEFAULT '',
    is_leader tinyint unsigned NOT NULL DEFAULT 0 COMMENT '1=团长',
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_team_uid (team_id, uid),
    KEY idx_groupon (groupon_id), KEY idx_order (order_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='拼团参团记录'");

/* ---------- 3. 砍价 ---------- */
$mk('mall_bargain', "(
    id int(10) unsigned NOT NULL AUTO_INCREMENT,
    goods_id int(10) unsigned NOT NULL DEFAULT 0,
    sku_id int(10) unsigned NOT NULL DEFAULT 0,
    title varchar(128) NOT NULL DEFAULT '',
    floor_price decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '砍到底价',
    stock int(11) NOT NULL DEFAULT 0 COMMENT '活动库存 0=不限',
    buy_limit int(11) NOT NULL DEFAULT 1 COMMENT '每人可砍价次数',
    hours int(11) NOT NULL DEFAULT 24 COMMENT '砍价时效(小时)',
    min_cut decimal(10,2) NOT NULL DEFAULT 0.01 COMMENT '单次最小砍价额',
    max_cut decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '单次最大砍价额 0=不限',
    need_help int(11) NOT NULL DEFAULT 0 COMMENT '需要好友帮砍人数 0=不限',
    start_time datetime DEFAULT NULL,
    end_time datetime DEFAULT NULL,
    sort int(11) NOT NULL DEFAULT 0,
    status tinyint unsigned NOT NULL DEFAULT 1,
    is_delete_time int(10) unsigned NOT NULL DEFAULT 0,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_goods (goods_id), KEY idx_sku (sku_id), KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商城砍价活动'");

$mk('mall_bargain_launch', "(
    id int(10) unsigned NOT NULL AUTO_INCREMENT,
    bargain_id int(10) unsigned NOT NULL DEFAULT 0,
    uid int(10) unsigned NOT NULL DEFAULT 0,
    goods_id int(10) unsigned NOT NULL DEFAULT 0,
    sku_id int(10) unsigned NOT NULL DEFAULT 0,
    origin_price decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '砍价起始价',
    floor_price decimal(10,2) NOT NULL DEFAULT 0.00,
    current_price decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '当前价',
    cut_count int(11) NOT NULL DEFAULT 0 COMMENT '已砍次数',
    helper_count int(11) NOT NULL DEFAULT 0 COMMENT '帮砍人数',
    status tinyint unsigned NOT NULL DEFAULT 0 COMMENT '0进行中 1成功到底价 2已下单 3已过期',
    expire_at datetime NOT NULL,
    order_no varchar(32) NOT NULL DEFAULT '',
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bargain (bargain_id), KEY idx_uid (uid), KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='砍价发起记录'");

$mk('mall_bargain_help', "(
    id int(10) unsigned NOT NULL AUTO_INCREMENT,
    launch_id int(10) unsigned NOT NULL DEFAULT 0,
    helper_uid int(10) unsigned NOT NULL DEFAULT 0,
    helper_name varchar(32) NOT NULL DEFAULT '',
    cut_price decimal(10,2) NOT NULL DEFAULT 0.00,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_launch_helper (launch_id, helper_uid),
    KEY idx_launch (launch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='砍价帮砍记录'");

/* ---------- 4. 组合优惠 ---------- */
$mk('mall_bundle', "(
    id int(10) unsigned NOT NULL AUTO_INCREMENT,
    title varchar(128) NOT NULL DEFAULT '',
    summary varchar(255) NOT NULL DEFAULT '',
    cover varchar(255) NOT NULL DEFAULT '',
    type tinyint unsigned NOT NULL DEFAULT 2 COMMENT '1=满件减 2=套餐固定价',
    price decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'type2 套餐价 / type1 优惠金额',
    need_num int(11) NOT NULL DEFAULT 2 COMMENT 'type1 达标件数',
    total_value decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '原价合计(展示用)',
    sort int(11) NOT NULL DEFAULT 0,
    status tinyint unsigned NOT NULL DEFAULT 1,
    is_delete_time int(10) unsigned NOT NULL DEFAULT 0,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商城组合优惠套餐'");

$mk('mall_bundle_goods', "(
    id int(10) unsigned NOT NULL AUTO_INCREMENT,
    bundle_id int(10) unsigned NOT NULL DEFAULT 0,
    goods_id int(10) unsigned NOT NULL DEFAULT 0,
    sku_id int(10) unsigned NOT NULL DEFAULT 0 COMMENT '0=任意规格',
    num int(11) NOT NULL DEFAULT 1 COMMENT '套餐内所需数量',
    sort int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_bundle (bundle_id), KEY idx_goods (goods_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='组合套餐内商品'");

/* ---------- 5. 订单/明细记录活动来源与原始数据，便于后续对账与限制配查 ---------- */
$addCol('mall_order_goods', 'promo_type', "varchar(16) NOT NULL DEFAULT '' COMMENT 'seckill/groupon/bargain/normal' AFTER cover");
$addCol('mall_order_goods', 'promo_id', "int(10) unsigned NOT NULL DEFAULT 0 COMMENT '活动ID' AFTER promo_type");
$addCol('mall_order_goods', 'origin_price', "decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '优惠前单价' AFTER promo_id");
$addCol('mall_order', 'promo_discount', "decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '促销(组合)优惠金额' AFTER points_discount");

/* ---------- 6. 默认配置 ---------- */
// ⚠️ 宿主配置项一律存 ye_options（经 get_option/set_option 读写）；ye_mall_config 为历史遗留表，运行时从不读取。
$seedOpt = static function (string $k, string $v): void {
    if (get_option($k, null) === null) { set_option($k, $v); }
};
$seedOpt('mall.promo_on', '1');              // 总开关
$seedOpt('mall.seckill_on', '1');
$seedOpt('mall.groupon_on', '1');
$seedOpt('mall.bargain_on', '1');
$seedOpt('mall.bundle_on', '1');
