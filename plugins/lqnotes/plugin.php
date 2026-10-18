<?php
declare(strict_types=1);
/**
 * 云笔记（lqnotes）· 最小插件清单
 * ===========================================================================
 * 为什么是插件：内核 index.php 在解析完路径、进 Router 之前会触发 `do_action('route')`
 *   （core/bootstrap.php:445-456 已把这个「插件认领 URL」挂载点写进内核），
 *   于是下面两个新 URL 可以**不碰 core/、不碰 index.php、不碰 nginx** 就上线：
 *     · `/api/notes`      —— 云同步 JSON API（session 鉴权 + 站内 Csrf 令牌）
 *     · `/share/{token}`  —— 笔记公开分享页（服务端渲染、未登录可读、恒 noindex）
 * 数据落在本插件自己的三张新表（只新增，不改任何既有表结构）：
 *     ye_note_books / ye_note_items / ye_note_shares
 * 零外发：不接任何第三方笔记 / 同步 / 统计服务；前台只调同源 /api/notes。
 * 开关：`toggle=false` ⇒ Plugins::isOn() 恒真，**不写任何 option**（也就没有 set_option）。
 * 撤销：见 /root/lqn_rollback.sh（DROP 三张新表 + 删 ye_migrations 里本组件那行 + 恢复 /notes 内容行）。
 */
return [
    'id'      => 'lqnotes',
    'name'    => '云笔记',
    'ver'     => '1.2.0',
    'desc'    => '前台「Markdown 云笔记」的服务端：笔记/笔记本/回收站/云同步 API（同源、本站 DB）'
               . ' + 笔记公开分享页（服务端渲染、未登录可读、恒 noindex）'
               . ' + 分享增强（自定义短链 /s/<alias> · 有效期 · 访问密码 · PV/UV/14 天趋势/来源 · 撤销）'
               . ' + 历史版本（自动保存时记录 · 最多 20 条 · 恢复前留快照）。不接任何第三方服务。',
    'author'  => 'liulanqi',
    'license' => 'MIT',
    'toggle'  => false,                        // 固定启用：不写 option，不需要后台开启
    'tables'  => ['note_books', 'note_items', 'note_shares', 'note_share_views', 'note_share_days', 'note_revs'],
    'uninstall' => [
        'tables'  => ['note_books', 'note_items', 'note_shares', 'note_share_views', 'note_share_days', 'note_revs'],
        'options' => [],
        'like'    => [],
    ],
    'register_file' => 'boot.php',
];
