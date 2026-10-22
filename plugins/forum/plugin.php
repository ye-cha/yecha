<?php
declare(strict_types=1);
// 轻量论坛（Forum）：对标 Xiuno 的克制路线 —— 板块 → 帖子 → 回帖，随 YeCha 同库部署。
return [
    'id' => 'forum',
    'name' => '论坛 Forum',
    'ver' => '1.2.0',
    'desc' => '轻量社区插件：版块 / 主题 / 回帖 / 引用 / 编辑 / 软删 / 搜索 / 分页 / Markdown / 个人资料 / 置顶·精华·锁定 / 点赞 / 分享短链 / 自动草稿 / 未读标记 / 站内通知，附后台板块与内容管理。',
    'author' => 'YeCha',
    'license' => 'MIT',
    'requires' => ['core' => '1.0.0'],
    'toggle' => true,
    'tables' => ['forum_boards', 'forum_threads', 'forum_replies', 'forum_reactions', 'forum_drafts', 'forum_reads', 'forum_att'],
    'uninstall' => ['tables' => ['forum_boards', 'forum_threads', 'forum_replies', 'forum_reactions', 'forum_drafts', 'forum_reads', 'forum_att'], 'options' => [], 'like' => ['forum.%']],
    'admin' => ['label' => '社区', 'desc' => '板块管理 / 帖子与回帖审核'],
    'pages' => [
        ['slug' => 'forum', 'title' => '社区'],
    ],
    'admin_file' => 'admin.php',
    'admin_fn' => 'yc_forum_admin',
    'page_file' => 'page.php',
    'page_fn' => 'yc_forum_page',
];
