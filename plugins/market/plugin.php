<?php
declare(strict_types=1);
// 市场收录（官方插件）：公开上架申请(/market-app) + 后台验证/发布到本地市场 manifest
return [
    'id' => 'market',
    'name' => '应用市场收录',
    'ver' => '1.0.0',
    'desc' => '应用市场收录流程：公开提交插件/主题包申请 → 站长验证（下载/结构/清单/php 语法/SHA）→ 一键发布进 var/market 市场',
    'author' => 'YeCha',
    'license' => 'MIT',
    'requires' => ['core' => '1.0.0'],
    'toggle' => true,
    'tables' => ['market_reqs'],
    'uninstall' => ['tables' => ['market_reqs'], 'options' => [], 'like' => []],
    'admin' => ['label' => '市场收录', 'desc' => '待验证 / 已发布的市场收录申请'],
    'pages' => [
        // [2026-09-27 整版升级时补] 新内核把 /store 交给插件页分发（core/Router.php 判成
        // kind=plugin_page），不再由 index.php 的 case 'store' 接管 ⇒ 必须显式声明 slug=store，
        // 否则 /store 会 404。page.php 早已实现该 slug（yc_market_store_html），故只需声明。
        ['slug' => 'store', 'title' => '应用商店'],
        // t39：应用市场一级汇总页（/market：主题 / 插件两块 + 计数）
        ['slug' => 'market', 'title' => '应用市场'],
        ['slug' => 'market-app', 'title' => '申请上架应用'],
        // t38：在线主题 / 插件页（列表 + 详情，数据实时扫描 themes/ 与 plugins/）
        ['slug' => 'theme', 'title' => '主题'],
        ['slug' => 'plugin', 'title' => '插件'],
        ['slug' => 'downloads', 'title' => '下载中心'],
    ],
    'admin_file' => 'admin.php',
    'admin_fn' => 'yc_market_admin',
    'page_file' => 'page.php',
    'page_fn' => 'yc_market_page',
];
