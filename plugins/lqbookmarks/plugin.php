<?php
declare(strict_types=1);
/**
 * 收藏（书签）· lqbookmarks —— 最小插件清单
 * ===========================================================================
 * 为什么是独立插件：站主裁定「`plugins/lqnotes/**` 是云笔记的 ⇒ 收藏走自己的插件/表」。
 * 认领的 URL（走内核既有 `do_action('route')`，**不碰 core/、不碰 nginx**）：
 *   · GET/PUT  /api/auth/bookmarks                  —— 云同步（v2 结构，照参照路径与形态）
 *   · POST     /api/auth/bookmarks/{id}/share       —— 单条书签分享（快照语义）
 *   · GET      /api/share/bookmark/{token}          —— 分享快照 JSON
 *   · GET      /b/{token}                           —— 分享页（服务端渲染、恒 noindex）
 *   · GET      /api/auth/me                         —— 登录态自检（站主批准一并补上，形态照参照）
 * 数据：本插件自己的两张新表（只新增，不改任何既有表）
 *   · ye_bm_sets   —— 每用户一行，`data` 存**参照 v2 原样 JSON**（1:1、零损耗）
 *   · ye_bm_shares —— 书签单条分享快照
 * 公共导航：直接读既有 `ye_links`(151) / `ye_link_groups`(22)，**不另建表、不动 plugins/links**。
 * ★ 不碰 `ye_favorites`（那是"文章收藏"，不是网址书签）。
 * 零外发：前台只调同源 API；本插件不请求任何第三方。
 * 开关：`toggle=false` ⇒ Plugins::isOn() 恒真，不写任何 option。
 */
return [
    'id'      => 'lqbookmarks',
    'name'    => '收藏（书签）',
    'ver'     => '1.0.0',
    'desc'    => '前台「书签管理」的服务端：书签库云同步（v2 结构 · 分组≤50 · 条目≤1000）'
               . ' + 单条书签分享（快照语义、服务端渲染、恒 noindex）。公共导航读 ye_links/ye_link_groups。'
               . '不接任何第三方服务。',
    'author'  => 'liulanqi',
    'license' => 'MIT',
    'toggle'  => false,
    'tables'  => ['bm_sets', 'bm_shares'],
    'uninstall' => [
        'tables'  => ['bm_sets', 'bm_shares'],
        'options' => [],
        'like'    => [],
    ],
    'register_file' => 'boot.php',
];
