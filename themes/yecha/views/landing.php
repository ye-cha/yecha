<?php
/** yecha.org 首页 · 大 CMS 化版（t40 · 中文）
 *
 * 结构：hero（版本徽章 + 价值主张 + 环境要求 + 「下载」主 CTA + 文档/更新日志/应用市场/论坛 四入口）
 *      → 站点数据（主题/插件/安装包/版本，现场数）→ 功能特性（6）→ 主题精选（8）
 *      → 插件精选（8）→ 最新更新（/changelog 最近 4 条）→ 最新发布 → 文档入口（4 卡）
 *      → 社区（论坛最新 5 主题 + 统计）→ 案例 → 收尾 CTA → 页脚分类导航
 *
 * 取数全部在 partials/landing-cms.php（中英共用），本文件只管中文侧变量：
 *   数字不写死：主题/插件数来自目录，安装包数来自 download/pkg/index.json，
 *   论坛数字来自 ye_forum_*，版本来自 core/version.php，发布日来自 /changelog 或 CHANGELOG.md。
 */
$lc_en = false;
$lp = '';

// 最新发布（内核已取好 4 篇 post，这里同时接受页面给的结构化列表）
$lc_posts = [];
foreach ((array)($posts ?? []) as $p) {
    $slug = (string)($p['slug'] ?? '');
    if ($slug === '') { continue; }
    $lc_posts[] = [
        'title' => (string)($p['title'] ?? $slug),
        'date'  => (string)($p['published_at'] ?? ''),
        'href'  => '/' . rawurlencode($slug) . '.html',
    ];
}

require __DIR__ . '/partials/landing-cms.php';
