<?php
/** 列表页（★ 精仿第 2 批 2026-10-05 —— 以 www.liulanqi.com 的真实 HTML 骨架为模板）
 * ===========================================================================
 * 覆盖 5 个栏目索引页：/tips · /tool · /learn · /download · /gallery
 *   `/tips` `/tool` `/learn`    → 参照的「有侧栏」版式（mx-auto max-w-6xl + grid[230px_1fr]）
 *   `/download` `/gallery`      → 参照的「无侧栏」版式（space-y-8 / space-y-10）
 * ⇒ 骨架 = `lqwww/gen/list_*.html`（由 `_lqpix/src/mk2.py` 从参照页按字节切出）；
 *   本文件**没有一行"画 UI"的代码**，只有取真数据 + 填占位符。
 *
 * ★ 保留（一个都不删）：分页（lqpg_pager）· 工具分类 chips（lqpg_cat_chips，/tool 的 11 个分类入口）·
 *   每条的日期/人气 · 面包屑（参照的面包屑槽位）。
 * ★ 本文件的辅助函数**全部走 lqpg_page_helpers() 的 function_exists 守卫**
 *   —— 同一请求里 list.php 可能被渲染多次（列表 + 侧栏 + 相关块），裸 function 会 Fatal
 *      「Cannot redeclare」（本轮真踩到，报告 §③ 记了这一条）。
 * 入参（与改造前完全一致）：$posts,$page_title,$sub,$page,total_pages,$pager_base,$link_prefix
 */
require_once __DIR__ . '/../../parts/lq-www.php';
require_once __DIR__ . '/../../parts/lq-page.php';
require_once __DIR__ . '/pagelib.php';

$posts      = (array)($posts ?? []);
$pageTitle  = (string)($page_title ?? '');
$subTxt     = (string)($sub ?? '');
$page       = max(1, (int)($page ?? 1));
$totalPages = max(1, (int)($total_pages ?? 1));
$pagerBase  = (string)($pager_base ?? '/page/{n}');
$lp         = trim((string)($link_prefix ?? ''));
if ($lp === '' && Poly::on() && Lang::short() !== Poly::defaultLang()) { $lp = '/' . Lang::short(); }

[$section, $cat] = lqwww_section_of($pagerBase);
$label = lqwww_label($section);
$total = lqwww_total($section, $cat);
if ($total < count($posts)) { $total = count($posts) * $totalPages; }
$per = max(5, (int)theme_opt('per_page', 30));

$h1    = $pageTitle !== '' ? $pageTitle : $label;
$lead  = $subTxt;
/* ★ 2026-10-05（精仿第 3 批 · 像素轮）：参照的栏目**索引页**是全量单页，实例走内核分页只有 30 条
   ⇒ 首屏内容量差 2.6 倍（/tips 参照 78 卡 / 实例 30 卡）。只对 store 第 1 页换成同一口径的全量取数，
   数据源与排序与内核 sectionIndex() 完全一致（同 postWhere / 同 publishAt 排序），仅放开 LIMIT。 */
$__all = lqpg_store_all($section, $page);
if ($__all !== null) { $posts = $__all; }
$total = max($total, count($posts));
$cards = lqpg_cards($posts, $section, $lp, $lp);
$items = $cards !== '' ? $cards : '<div class="rounded-xl border bg-card p-6 text-sm text-muted-foreground">'
    . e((string)($empty ?? '暂无内容')) . '</div>';
$pager = lqpg_pager($page, $totalPages, $pagerBase, $total, $per);
$crumbHtml = lqpg_crumbs(lqpg_sec_crumbs($section, $page, $pageTitle, $label));
$sideHtml  = '';
if (lqskel('side_nav') !== '') {
    /* ★ lqrd 2026-10-07：参照的列表页侧栏也套 `aside.hidden.lg:block > div.sticky.top-20`
       （/tips 参照 aside=1、我们原来 aside=0）⇒ 与 /learn 同形。 */
    $sideHtml = '<aside class="hidden lg:block"><div class="sticky top-20">'
        . lqpg_sidebar('浏览器学堂', lqpg_sidebar_ctx($label, '/' . $section, '浏览器学堂', '/' . $section))
        . '</div></aside>';
}
/* ★ 侧栏由参照骨架负责（也就是参照自己的那两栏）⇒ 关掉 layout 的老右栏，避免"双侧栏"。
   老右栏里的三块（站内搜索 / 最新文章 / 文章归档）在实例**其它页型仍然全在**，这里只是不在
   列表页重复渲染第二份（报告 §⑤ 逐条列明）。 */
$GLOBALS['YC_NO_SIDE'] = true;
/* lqart: /gallery 列表参照的 main 只有 1 个顶层 div(space-y-10)、里面自带面包屑；
   实例多一个布局层 nav.lq-crumbs => 关掉它（其余列表页不变）。 */
if ($section === 'gallery') { $GLOBALS['YC_LQ_BARE'] = 1; }
/* ★ lqrd 2026-10-07：参照的 /tips 列表面包屑是「首页 / 浏览器学堂 / 浏览器技巧全览」
   （列表页第 3 段用**完整栏目名**，与详情页那种短栏目名不同）；原来这个分支**没往骨架里填 crumb**
   ⇒ 线上是空 <nav class="mb-6 …">（高 0）⇒ 整条 grid 比参照高 20px（y=145 vs 165）。 */
$crumbTips = lqpg_crumbs([['首页', $lp . '/'], ['浏览器学堂', $lp . '/learn'],
    [$page > 1 ? '浏览器技巧全览 · 第 ' . $page . ' 页' : '浏览器技巧全览', '']]);

if ($section === 'download') {
    echo lqskel_fill(lqskel('list_download'), [
        'h1'    => e($h1),
        'lead'  => e($lead),
        'items' => $items,
    ], 'list_download') . $pager;
} elseif ($section === 'gallery') {
    echo lqskel_fill(lqskel('list_gallery'), [
        'h1'       => e($h1),
        'lead'     => e($lead),
        'sections' => lqpg_gallery_sections($posts, $lp),
        'pager'    => $pager,
    ], 'list_gallery');
} elseif ($section === 'learn') {
    echo lqskel_fill(lqskel('list_learn'), [
        'crumb'         => $crumbHtml,
        '__grid_open__' => '<div class="grid gap-8 lg:grid-cols-[230px_1fr]">',
        '__side__'      => $sideHtml,
        'hero'          => lqpg_learn_hero($h1, $lead, $total, $totalPages),
        'sec_courses'   => lqpg_learn_courses($lp),
        'sec_reads'     => lqpg_learn_reads($lp),
        'sec_topics'    => lqpg_learn_topics($lp),
        'sec_recent'    => lqpg_learn_recent($lp),
        'sec_more'      => lqpg_learn_more($lp),
        'pager'         => $pager,
    ], 'list_learn');
} elseif ($section === 'tool') {
    echo lqskel_fill(lqskel('list_tool'), [
        'h1'       => e($h1),
        'lead'     => e($lead),
        'sections' => lqpg_tool_sections($posts, $lp),
    ], 'list_tool') . $pager;
} else {
    echo lqskel_fill(lqskel('list_tips'), [
        'crumb'         => $crumbTips,
        '__grid_open__' => '<div class="grid gap-8 lg:grid-cols-[230px_1fr]">',
        '__side__'      => $sideHtml,
        'h1'    => e($h1),
        'lead'  => e($lead),
        'chips' => lqpg_list_chips($section, $lp),
        'items' => $items,
        'pager' => $pager,
    ], 'list_tips');
}
