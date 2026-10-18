<?php
declare(strict_types=1);
// 企业官网插件：全行业适配 —— 首页 / 产品 / 案例 / 新闻(复用博客文章) / 关于 / 招聘 / 联系 + 主题引擎。
// 页面主体 PHP SSR 直出；主题 = themes/<id>/style.css 设计令牌（商务稳重 / 国风自然，Taste 校准定稿）。
// AI 能力（客服 / 语义搜索 / 内容助手 / 多语言）经 ai 插件调用（Ai::chat / kbSearch / translate）。
return [
    'id' => 'site',
    'name' => '企业官网',
    'ver' => '0.1.0',
    'desc' => '企业官网插件：产品 / 案例 / 新闻 / 招聘 / 留言 + 主题引擎（商务稳重、国风自然）+ SEO；AI 客服与语义搜索依赖 ai 插件。',
    'author' => 'YeCha',
    'license' => 'MIT',
    'requires' => ['core' => '1.0.0'],
    'toggle' => true,
    'tables' => [
        'site_banner', 'site_product_category', 'site_product',
        'site_case', 'site_message', 'site_job', 'site_job_apply', 'site_i18n',
    ],
    'uninstall' => [
        'tables' => [
            'site_banner', 'site_product_category', 'site_product',
            'site_case', 'site_message', 'site_job', 'site_job_apply', 'site_i18n',
        ],
        'options' => [], 'like' => ['site.%'],
    ],
    'admin' => ['label' => '企业官网', 'desc' => '企业信息 / 产品 / 案例 / 招聘 / 留言 / 主题'],
    'pages' => [
        ['slug' => 'site', 'title' => '企业官网'],
    ],
    'register_file' => 'lib.php',
    'admin_file' => 'admin.php',
    'admin_fn' => 'yc_site_admin',
    'page_file' => 'page.php',
    'page_fn' => 'yc_site_page',
];
