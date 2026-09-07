<?php
declare(strict_types=1);
// 轻量论坛（Forum）：对标 Xiuno 的克制路线 —— 板块 → 帖子 → 回帖，随 YeCha 同库部署。
return [
    'id' => 'forum',
    'name' => '论坛 Forum',
    'ver' => '1.0.0',
    'desc' => '轻量论坛插件：板块 / 帖子 / 回帖 / 分页 / Markdown / 会员发帖游客可读，附后台板块与内容管理。',
    'author' => 'YeCha',
    'license' => 'MIT',
    'requires' => ['core' => '1.0.0'],
    'toggle' => true,
    'tables' => ['forum_boards', 'forum_threads', 'forum_replies'],
    'uninstall' => ['tables' => ['forum_boards', 'forum_threads', 'forum_replies'], 'options' => [], 'like' => ['forum.%']],
    'admin' => ['label' => '论坛', 'desc' => '板块管理 / 帖子与回帖审核'],
    'pages' => [
        ['slug' => 'forum', 'title' => '论坛'],
    ],
    'admin_file' => 'admin.php',
    'admin_fn' => 'yc_forum_admin',
    'page_file' => 'page.php',
    'page_fn' => 'yc_forum_page',
];
