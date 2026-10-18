<?php
declare(strict_types=1);
// 在线体验与监控模块（官网用，也可安装到任意 YeCha 站）
return [
    'id' => 'demo',
    'name' => '在线体验与监控',
    'ver' => '1.0.0',
    'desc' => '官网「在线体验」页：展示已接入的 YeCha 体验站并定时探测可达状态；访客可提交“希望加入体验站”的申请，站长后台审核后纳入监控。',
    'author' => 'YeCha',
    'license' => 'MIT',
    'requires' => ['core' => '1.0.0'],
    'toggle' => true,
    'tables' => ['demo_sites'],
    'uninstall' => ['tables' => ['demo_sites'], 'options' => [], 'like' => []],
    'admin' => ['label' => '体验站', 'desc' => '体验站申请审核与监控状态'],
    'admin_file' => 'admin.php',
    'admin_fn' => 'yc_demo_admin',
    'pages' => [
        ['slug' => 'demo', 'title' => '在线体验'],
    ],
    'page_file' => 'page.php',
    'page_fn' => 'yc_demo_page',
];
