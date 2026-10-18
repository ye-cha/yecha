<?php
declare(strict_types=1);
// 多语言插件（YeCha）：翻译组 + AI 翻译 + /{lang}/ 多语路径与 hreflang。
// 说明：多语是 YeCha 的可选能力——不安装本插件则站点为单语（zh），
// 前台不解析语言前缀、文章编辑不显示译文面板。安装后自动建 ye_poly_contents。
// 表与核心垫片：core/Poly.php 的 on()/enabled() 以 option 为闸；数据表仅在本插件安装后存在。
return [
    'id' => 'poly',
    'name' => '多语言',
    'ver' => '1.0.0',
    'desc' => '多语言：翻译组 / AI 翻译 / /en/ 等多语路径与 hreflang（站点可选能力，安装后启用）',
    'author' => 'YeCha',
    'license' => 'MIT',
    'requires' => ['core' => '1.0.0-beta.2'],
    'toggle' => true,
    'tables' => ['poly_contents'],
    'uninstall' => [
        'tables' => ['poly_contents'],
        'options' => [],
        'like' => ['site.languages', 'site.default_lang', 'ai.%', 'poly.%'],
    ],
    'admin' => ['label' => '多语言', 'desc' => '翻译组 / AI 翻译 / 语言目录'],
    'admin_file' => 'admin.php',
    'admin_fn' => 'yc_poly_admin',
];
