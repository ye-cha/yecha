<?php
declare(strict_types=1);
// 友链·网址导航（通用模块）插件清单；启用时自动应用 plugins/links/migrations
return [
    'id' => 'links',
    'name' => '友链·网址导航',
    'ver' => '1.5.1',
    'desc' => '博客收录圈·门户版：全宽无右栏门户(收录广场/文章流/月度精选/申请收录)、优点展示区与CTA、收录/审核/体检、博客程序、搜索/排序/筛选、富信息列表与流量回执(CSV)、月度精选按月回看与站长精选★、公平串门、RSS 温柔聚合、徽标、采集互认、邮件通知',
    'author' => 'YeCha',
    'license' => 'MIT',
    'requires' => ['core' => '1.0.0'],
    'toggle' => true,
    'tables' => ['link_groups', 'links', 'link_clicks', 'circle_posts', 'circle_subs'],
    'uninstall' => ['tables' => ['link_groups', 'links', 'link_clicks', 'circle_posts', 'circle_subs'], 'options' => [], 'like' => []],
    'admin' => ['label' => '友链/网址', 'desc' => '友链·网址导航分组与链接（含机器体检 / RSS 聚合）'],
    'blocks' => [
        ['id' => 'friend', 'label' => '友链墙'],
        ['id' => 'nav', 'label' => '网址导航'],
    ],
    'pages' => [
        ['slug' => 'links', 'title' => '博客收录'],
        ['slug' => 'submit', 'title' => '申请收录'],
        ['slug' => 'go', 'title' => '前往原站'],
        ['slug' => 'random', 'title' => '随机串门'],
        ['slug' => 'stats', 'title' => '流量回执'],
        ['slug' => 'circle', 'title' => '最新文章流'],
        ['slug' => 'selected', 'title' => '月度回看'],
        ['slug' => 'certificate', 'title' => '已收录徽标'],
        ['slug' => 'collection', 'title' => '采集与互认说明'],
    ],
    'admin_file' => 'admin.php',
    'admin_fn' => 'yc_links_admin',
    'block_file' => 'blocks.php',
    'block_fn' => 'yc_links_block',
    'page_file' => 'page.php',
    'page_fn' => 'yc_links_page',
];
