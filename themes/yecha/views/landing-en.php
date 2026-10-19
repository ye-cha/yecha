<?php
/** yecha.org 英文首页 · 大 CMS 化版（t40 · English）
 *
 * 与 landing.php 同结构、同取数（partials/landing-cms.php），文案英文；站内链接带 /en 前缀。
 * 更新条目取 /changelog 的英文译文（ye_poly_contents.lang=en），缺译回落中文正文。
 */
$lc_en = true;
$lp = '/en';

$lc_posts = [];
foreach ((Poly::on() ? Poly::publishedList('en', 4, false) : []) as $p) {
    $slug = (string)($p['slug'] ?? '');
    if ($slug === '') { continue; }
    $lc_posts[] = [
        'title' => (string)($p['title'] ?? $slug),
        'date'  => (string)($p['published_at'] ?? ''),
        'href'  => '/en/' . rawurlencode($slug) . '.html',
    ];
}

require __DIR__ . '/partials/landing-cms.php';
