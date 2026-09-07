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
        ['slug' => 'market-app', 'title' => '申请上架应用'],
        ['slug' => 'store', 'title' => '应用商店'],
    ],
    'admin_file' => 'admin.php',
    'admin_fn' => 'yc_market_admin',
    'page_file' => 'page.php',
    'page_fn' => 'yc_market_page',
];
