<?php
declare(strict_types=1);
// 商城（Mall）：对标 ShopXO 的克制路线 —— 商品 / 购物车 / 订单 / 支付，挂站点用户中心，同库部署。
return [
    'id' => 'mall',
    'name' => '商城 Mall',
    'ver' => '0.1.0',
    'desc' => '商城插件：商品 / SKU / 购物车 / 订单 / 支付 / 营销，51puer 传统电商风格，与博客共用用户中心（ye_users）。',
    'author' => '51普洱茶网',
    'license' => 'MIT',
    'requires' => ['core' => '1.0.0'],
    'toggle' => true,
    'tables' => [
        'mall_user_address', 'mall_cart',
        'mall_goods_category', 'mall_brand', 'mall_goods', 'mall_goods_sku', 'mall_freight_template',
        'mall_order', 'mall_order_goods', 'mall_order_log', 'mall_payment_log', 'mall_aftersale',
        'mall_coupon', 'mall_user_coupon', 'mall_goods_article', 'mall_ad', 'mall_config',
        'mall_review', 'mall_search_keyword',
        // M7 会员中心（个人中心加强）
        'mall_member', 'mall_balance_log', 'mall_withdraw', 'mall_points_log',
        'mall_virtual_code', 'mall_favorite', 'mall_footprint',
        // M8 商品维度增强（标签）
        'mall_tag', 'mall_goods_tag',
        // M10 积分体系（签到记录）
        'mall_signin',
        // M11 促销玩法（秒杀 / 拼团 / 砍价 / 组合优惠）
        'mall_seckill', 'mall_groupon', 'mall_groupon_team', 'mall_groupon_join',
        'mall_bargain', 'mall_bargain_launch', 'mall_bargain_help',
        'mall_bundle', 'mall_bundle_goods',
        // M12 履约通知（物流轨迹 / 站内消息）
        'mall_delivery_trace', 'mall_message',
        // M13 会员等级折扣
        'mall_level',
    ],
    'uninstall' => [
        'tables' => [
            'mall_user_address', 'mall_cart',
            'mall_goods_category', 'mall_brand', 'mall_goods', 'mall_goods_sku', 'mall_freight_template',
            'mall_order', 'mall_order_goods', 'mall_order_log', 'mall_payment_log', 'mall_aftersale',
            'mall_coupon', 'mall_user_coupon', 'mall_goods_article', 'mall_ad', 'mall_config',
            'mall_review', 'mall_search_keyword',
            'mall_member', 'mall_balance_log', 'mall_withdraw', 'mall_points_log',
            'mall_virtual_code', 'mall_favorite', 'mall_footprint',
                'mall_tag', 'mall_goods_tag',
                'mall_signin',
            'mall_seckill', 'mall_groupon', 'mall_groupon_team', 'mall_groupon_join',
            'mall_bargain', 'mall_bargain_launch', 'mall_bargain_help',
            'mall_bundle', 'mall_bundle_goods',
            'mall_delivery_trace', 'mall_message',
            'mall_level',
        ],
        // 注：不删 nav.custom（可能与站点自定义导航共存），卸载后如需移除「商城」入口请在后台自行编辑。
        'options' => [], 'like' => ['mall.%'],
    ],
    'admin' => ['label' => '商城', 'desc' => '商品 / 订单 / 分类 / 营销管理'],
    'pages' => [
        ['slug' => 'mall', 'title' => '商城'],
    ],
    'register_file' => 'lib.php', // 启用即加载（Mall 类 + 内核 case 'mall' 分发依赖）
    'admin_file' => 'admin.php',
    'admin_fn' => 'yc_mall_admin',
    'page_file' => 'page.php',
    'page_fn' => 'yc_mall_page',
];
