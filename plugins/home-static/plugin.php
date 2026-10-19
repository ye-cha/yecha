<?php
declare(strict_types=1);
// 首页生成静态（YeCha 通用插件）：把站点首页渲染为静态 HTML 文件并直出，
// 让 40 万级图库等重首页冷启动/大流量下毫秒级返回，不依赖页缓存重建。
// 交互说明：
//  - 安装启用后，首次访问首页会自动生成静态文件（前台触发，自动刷新，无需手工）。
//  - 前台命中条件：静态文件存在且未超过有效期（home_static.ttl，默认 600 秒）。
//    超时后该次访问回正常动态渲染并顺手重建，此后 600 秒内直接读静态文件。
//  - 后台「首页生成静态」可手动：立即生成 / 清除 / 调整有效期。
return [
    'id' => 'home-static',
    'name' => '首页生成静态',
    'ver' => '1.0.0',
    'desc' => '首页生成静态：把站点首页（含各语言前缀）渲染为静态 HTML 直出，重首页毫秒级返回，访问自动刷新、后台可手动重建。',
    'author' => 'YeCha',
    'license' => 'MIT',
    'requires' => ['core' => '1.0.0-beta'],
    'toggle' => true,
    'uninstall' => ['tables' => [], 'options' => [], 'like' => ['home_static.%', 'hs.%']],
    'admin' => ['label' => '首页生成静态', 'desc' => '首页静态化：直出静态 HTML，自动/手动刷新'],
    'admin_file' => 'admin.php',
    'admin_fn' => 'yc_home_static_admin',
    'register_file' => 'boot.php',
];
