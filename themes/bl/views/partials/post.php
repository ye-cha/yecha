<?php
/** 文章/详情页（★ 精仿第 2 批 2026-10-05 —— 以 www.liulanqi.com 的 /tips/<slug> 真实骨架为模板）
 * ===========================================================================
 * 骨架 = `lqwww/gen/detail_tips.html`（+ `side_nav` / `side_toc` / `detail_related*`），
 * 由 `_lqpix/src/mk2.py` 从参照页 `seo/tips/chrome-tips.html` **按字节切片**得到。
 * 本文件只取真数据 + 填占位符（**没有一行"画 UI"的代码**）。
 *
 * ★ 保留（一个都不删）：文章正文（Markdown 渲染结果，逐字节）· 标签 · 上一篇/下一篇 ·
 *   阅读条（收藏 / 登录 CTA / 划线笔记）· 评论区 · 阅读数上报 · 右栏 TOC（参照的「本文目录」卡）·
 *   朗读/打印（lq-mech，由 layout 渲染）· 结构化数据（layout 统一发）。
 * slug 以 tool__ 开头 ⇒ 交给 partials/tool.php（工具页形态）。
 */
require_once __DIR__ . '/../../parts/lq-www.php';
require_once __DIR__ . '/../../parts/lq-page.php';
require_once __DIR__ . '/../../parts/lq-mech.php';

/* ★ t10（2026-10-14）：`/download/<slug>` 的三块（相邻篇 `nav.adjacent` / 最新文章 `aside.lq-rel` /
   相关技巧 `aside.side`）逐页照**参照字节**（表 = `lqwww/gen/dl_ref_blocks.json`，取件 = 第十棒
   `t10_blocks.mjs`；三块内 href 去重 64 条**已在本实例逐条 curl 全 200**）。
   ★ 表里没有该 slug ⇒ 返回 null ⇒ 各调用点**保持既有数据驱动实现**（fail-open，绝不半改）。 */
if (!function_exists('lq10_dl_ref_block')) {
    function lq10_dl_ref_block(string $slug, string $key): ?string
    {
        static $all = null;
        if ($all === null) {
            $f = __DIR__ . '/../../lqwww/gen/dl_ref_blocks.json';
            $all = is_file($f) ? (array)json_decode((string)file_get_contents($f), true) : [];
        }
        $v = $all[$slug][$key] ?? null;
        return (is_string($v) && $v !== '') ? $v : null;
    }
}
$p      = $post;
$cid    = (int)$p['id'];
$logged = Auth::check();
$csrf   = Csrf::token();
$lp2    = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';
$store  = lqwww_store_slug((string)$p['slug']);

if (str_starts_with($store, 'tool__')) {
    include __DIR__ . '/tool.php';
    return;
}
/* ★ /learn 与 /download 在实例里是两行 `type=page`（slug='learn' / 'download'，id=790 / 711），
   而参照侧它们是**列表页形态**（`seo/learn.html` / `seo/download.html`）⇒
   按 slug 分派到列表骨架，只换呈现层，**不动这两行 DB 内容**（内容仍由正文页可用）。
   ★ 只改呈现：/learn 的富版特征串（ld+json 4 块 / CollectionPage+ItemList+BreadcrumbList+FAQPage /
     大纲 57 · FAQ 8 / 最后更新 3）仍由 layout 的机制层与本页标题结构保住，见报告 §④。
   ★★ 但实测有硬约束：/learn 的原生页面视图**不**产出 `<h3>`，所以走列表骨架后大纲会变成
     h1=1 / h2=10 / h3=0（原 57 = h1 1 + h2 10 + h3 46 会掉）。为不动 B6 的成果，
     **`$store === 'learn'` 这条不改**，只有 /download 真正分派到列表骨架（报告 §⑤-⑦）。 */
if ($store === 'download') {
    $__lp = $lp2;
    $__posts = ($store === 'learn')
        ? Db::rows('SELECT id, slug, title, description, excerpt, views, published_at, created_at FROM ' . Db::t('contents')
            . " WHERE type='post' AND status='publish' AND deleted_at IS NULL AND slug LIKE 'learn\\_\\_%'"
            . ' ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT 16')
        : Db::rows('SELECT id, slug, title, description, excerpt, views, published_at, created_at FROM ' . Db::t('contents')
            . " WHERE type='post' AND status='publish' AND deleted_at IS NULL AND slug LIKE 'download\\_\\_%'"
            . ' ORDER BY slug ASC LIMIT 15');
    foreach ($__posts as $__i => $__r) {
        $__posts[$__i]['updated_at'] = (string)($__r['published_at'] ?? '');
    }
    $__h1 = $store === 'learn' ? '浏览器学堂' : '浏览器下载';
    $__label = $store === 'learn' ? '浏览器学堂' : '浏览器下载';
    $__sub = $store === 'learn'
        ? sprintf('从选型、下载、设置到技巧与隐私，%d 篇图文与浏览器专题。', count($__posts))
        : sprintf('汇总 %d 款主流浏览器的官网与国内镜像下载入口。', count($__posts));
    echo View::render('partials/list', [
        'posts'       => $__posts,
        'page_title'  => $__h1,
        'sub'         => $__sub,
        'page'        => 1,
        'total_pages' => 1,
        'pager_base'  => '/' . $store . '/page/{n}',
        'link_prefix' => $__lp,
    ]);
    return;
}

/* ★ 参照页左侧栏（230px）由骨架 side_nav / side_toc 提供
   ⇒ 关掉 layout 的老右栏，避免"双侧栏"（老右栏的搜索 / 最新文章 / 归档在实例其它页型仍在，
     本条已逐条写进报告 §⑤） */
$GLOBALS['YC_NO_SIDE'] = true;

$pos   = strpos($store, '__');
$sec   = $pos !== false ? substr($store, 0, $pos) : '';
$secLb = $sec !== '' ? lqwww_label($sec) : '';

/* ★★ lqart 2026-10-06 —— 「文章范式」逐页型清零（★ 参照 = liulanqi.com 线上字节）
 * 参照的 /tips/<slug>、/learn/<slug>、/gallery/<cat> 三个页型里**没有**：
 *   ① 评论区 + 段评  ② reader-bar（收藏/划线/段评入口）  ③ 上下篇「上一篇/下一篇」
 *   ④ 文章元信息条（更新于·阅读N·评论N + 朗读/打印）  ⑤ 侧栏「本文目录」  ⑥ 标签框
 *   ⑧ 自造「继续学习」相关卡   ⑨ 内层分类 pill
 * 参照**也有**的（必须留）：230px 侧栏导航 · article.rounded-2xl 外壳 · hero(h1+lead) ·
 *   底部「浏览器学堂 · 按专题系统学 / 全部浏览器技巧」卡（= 任务 ③⒜「参照有等价物」那条）。
 * ⇒ 本开关只影响 `tips__*` / `learn__*` / `gallery__*` 三族；工具页在函数更早处已分流；
 *   `download__*`（参照自己就是文章式 meta + nav.adjacent + aside.lq-rel）**不在内**，一个字节不变。
 * 回滚 = `bash /root/lqart_rollback.sh`（逐文件守卫 + 只还原本批 2 个文件 + 自动重建产物）。 */
/* ★ lqart（站主裁定②）：/download/<slug> 的参照**本身就是文章式**（article.article + article-meta +
   nav.adjacent + 侧栏）⇒ 它也是"不套 tips 技巧骨架"，但**保留**文章形态（下面 $__lqArt 区分）。 */
$__lqBare = in_array($sec, ['tips', 'learn', 'gallery', 'download'], true);
$__lqGal = ($sec === 'gallery');
$__lqArt  = ($sec === 'download');            /* 参照同形的"文章式"页型 */
if ($__lqBare) { $GLOBALS['YC_LQ_BARE'] = 1; }
if ($__lqArt)  { $GLOBALS['YC_LQ_ART']  = 1; }
/* ★ lqart（站主裁定①）：layout 里的阅读数 beacon 需要本行 content_id；
   本文件的局部变量在 layout 的作用域里看不到 ⇒ 落全局。 */
$GLOBALS['YC_LQ_CID'] = $cid;
$title = lqwww_tool_title($store, (string)$p['title']);
$url   = yc_entry_url($store);
$upd   = strtotime((string)(($p['updated_at'] ?? '') ?: ($p['published_at'] ?: ($p['created_at'] ?? ''))));
$pop   = lqwww_pop($store, (int)($p['views'] ?? 0));
$lead  = lqwww_lead((array)$p, 200);
$back  = $lp2 . $url;

/* ★ lqart：「继续学习」卡在参照里**只对有专题归属的条目出**（/tips/chrome-tips 有、/tips/adblock 没有）。
   专题名由**实例自己的 /learn 行**反查（站点既有函数 lqpg_topic_of，不新增数据模型、不动 DB）。 */
$topic = ($sec === 'tips' && function_exists('lqpg_topic_of')) ? trim(lqpg_topic_of($store)) : '';
$topicLb = $topic !== '' ? (string)preg_replace('/[：:].*$/u', '', $topic) : '';

/* ★ 左栏（230px）= 参照的同一份侧栏骨架；「本文目录」用 Front::post 给的真 TOC（$toc） */
/* ★ lqart：参照 /tips/<slug> 侧栏第二个链接 = 「浏览器技巧全览」（不是「浏览器技巧」），
   且 href 指 /tips。这里传完整名 + 本 section 的 href（其余页型本来就走同一份侧栏字节）。 */
$sideHtml = lqpg_sidebar('浏览器学堂', lqpg_sidebar_ctx(
    $sec === 'tips' ? '浏览器技巧全览' : ($secLb !== '' ? $secLb : '浏览器技巧'),
    '/' . $sec, '浏览器学堂', (string)$url));
$tocItems = '';
$tocSrc = (array)($GLOBALS['YC_TOC'] ?? ($toc ?? []));
foreach ($tocSrc as $i => $t) {
    $id = (string)($t['id'] ?? ('sec-' . $i));
    $tx = (string)($t['t'] ?? ($t['text'] ?? ''));
    if ($tx === '') { continue; }
    $tocItems .= lqskel_fill(lqskel('detail_toc_item'), [
        'href'  => e('#' . $id),
        'num'   => (string)($i + 1),   /* ★ lqrd：参照目录条目带序号 */
        'label' => e($tx),
    ], 'toc_item');
}
/* ★ lqrd 2026-10-07（更正前棒判断）：实测参照 /tips/<slug> 的侧栏**有**第二张卡「本文目录」
   （参照 aside 内层 754 = 导航 598 + 目录 156；目录条目 = 文章真 h2，href #sec-0..#sec-2）。
   前棒「参照侧栏只有导航卡」的判断与实测不符 ⇒ 这里放行 tips 族；
   /learn/<slug>（参照确无此卡）、/gallery、/download 三族维持清空。
   回滚：bash /root/lqrd-backup/ROLLBACK-BATCH4.sh */
$tocHtml = (($__lqBare && $sec !== 'tips') || $tocItems === '' || lqskel('side_toc') === '')
    ? ''
    : lqskel_fill(lqskel('side_toc'), ['toc_label' => e('本文目录'), 'toc_items' => $tocItems], 'side_toc');

/* ★ lqart：/download/<slug> = 参照同形的文章式外壳（`<article class="article">` +
   `<h1 class="article-title">` + `<div class="article-meta">` + `<div class="article-content" id="articleBody">`），
   外加参照那个 `<aside class="side"><div class="side-box"><h4>相关技巧</h4>…` 侧栏。
   正文（镜像卡 / table / h2#sec-0 / h2#sec-1 / nav.adjacent）来自 DB 行自身，逐字节不动。 */
$__dlArticle = '';
$__dlAside   = '';
if ($__lqArt) {
    $pt2 = $upd ? date('Y-m-d H:i', $upd) : '';
    /* ★ lqart：参照的 `<nav class="adjacent">`（`← 上一篇` / `下一篇 →`，rel=prev/next）
       在实例的 download 行正文里没有（旧版由生成器发）⇒ 用同族真数据补齐（slug 序相邻两条）。 */
    $__dlAdjacent = '';
    try {
        /* ★ lqart：与 lq_mech_related() 内部同一套写法（LEFT(slug,?)=?），不再另写 LIKE 转义。 */
        $__sib = Db::rows('SELECT slug, title FROM ' . Db::t('contents')
            . " WHERE LEFT(slug, ?)=? AND status='publish' AND deleted_at IS NULL ORDER BY slug ASC",
            [strlen('download__'), 'download__']);
        $__idx = -1;
        foreach ($__sib as $__i2 => $__rr) { if ((string)$__rr['slug'] === $store) { $__idx = $__i2; break; } }
        if ($__idx >= 0) {
            $__pv = $__idx > 0 ? $__sib[$__idx - 1] : null;
            $__nx = $__idx < count($__sib) - 1 ? $__sib[$__idx + 1] : null;
            $__ph = $__pv ? '<a rel="prev" href="' . e('/download/' . substr((string)$__pv['slug'], strlen('download__')))
                 . '"><b>← 上一篇</b>' . e((string)$__pv['title']) . '</a>' : '';
            $__nh = $__nx ? '<a rel="next" href="' . e('/download/' . substr((string)$__nx['slug'], strlen('download__')))
                 . '" class="nx"><b>下一篇 →</b>' . e((string)$__nx['title']) . '</a>' : '';
            if ($__ph !== '' || $__nh !== '') { $__dlAdjacent = '<nav class="adjacent">' . $__ph . $__nh . '</nav>'; }
        }
    } catch (Throwable $e) { $__dlAdjacent = ''; }
    /* ★ t10：表键 = URL slug（存储 slug 形如 `download__360`，表键是 `360`）——★ 这一处口径错会让三块**静默回落**成旧实现（实测一次） */
    $__t10slug = str_replace('download__', '', $store);
    /* ★ t10：相邻篇改用参照逐页定值（表里有就用参照；没有则保留上面算出的同族邻居） */
    $__t10adj = lq10_dl_ref_block($__t10slug, 'adj');
    if ($__t10adj !== null) { $__dlAdjacent = $__t10adj; }
    $__dlArticle =
        '<article class="article">'
      . '<h1 class="article-title">' . e($title) . '</h1>'
      . '<div class="article-meta">'
      .   ($pt2 !== '' ? '<span>' . e($pt2) . '</span>' : '')
      .   '<span>阅读 <span id="lqViews">' . (int)$pop . '</span></span>'
      .   '<span>评论 <span id="lqCmt">' . (int)($comment_total ?? 0) . '</span></span>'
      . '</div>'
      . '<div class="article-content" id="articleBody">' . (string)$html . '</div>'
      . $__dlAdjacent
      . '</article>';
    /* 侧栏 = 参照同一族（相关技巧 = 同专题的 tips 条目，参照给的正是这个口径） */
    /* ★ lqart：统一口径 `lq_mech_related()`（本批已根治它在 post.php 里不可见的问题：见文件头 require）。
       前缀 = 该浏览器专题（chrome→`tips__chrome-`、firefox→`tips__firefox-`…），与参照同族；
       排序按参照用 **slug 升序**（函数新增可选参数，默认日期序 ⇒ 既有调用点行为不变）。
       —— 原先那套「直查 + PHP 侧前缀过滤」的临时兜底已**收回**，不再留两套。 */
    $__tpre = strtolower((string)(preg_split('/[-_]/', (string)preg_replace('/^download__/', '', $store))[0] ?? ''));
    $__rel = [];
    if (function_exists('lq_mech_related')) {
        if ($__tpre !== '') { $__rel = (array)lq_mech_related('tips__' . $__tpre . '-', $store, 9, 'slug'); }
        if ($__rel === []) { $__rel = (array)lq_mech_related('tips__', $store, 9, 'slug'); }
    }
    $__lis = '';
    foreach ($__rel as $r) {
        $ru = (string)($r['u'] ?? ($r['href'] ?? ''));
        if ($ru === '' && isset($r['slug'])) { $ru = '/' . str_replace('__', '/', (string)$r['slug']); }
        $rt = (string)($r['t'] ?? ($r['title'] ?? ''));
        if ($ru === '' || $rt === '') { continue; }
        $__lis .= '<li><a href="' . e($lp2 . $ru) . '">' . e($rt) . '</a></li>';
    }
    if ($__lis !== '') {
        $__dlAside = '<aside class="side"><div class="side-box"><h4>相关技巧</h4><ul class="side-list">'
                   . $__lis . '</ul></div></aside>';
        $GLOBALS['YC_LQ_DL_SIDE'] = $__dlAside;   /* ★ 由 layout 发在 </main> 之后（参照同位置） */
    }
    /* ★ lqart：参照 `main` 里还有第 2 个直接子 `<aside class="lq-rel">`（「最新文章」6 条，
       `<h2 class="lq-rel-cap">` + `<ul><li><a>`）。用**实例自己的真数据**补上
       （同族条目，口径与参照一致）。 */
    /* ★ t10：相关技巧改用参照逐页定值（含标签文案与顺序）；无论上面是否算出了 $__lis，都以参照为准 */
    $__t10side = lq10_dl_ref_block($__t10slug, 'side');
    if ($__t10side !== null) { $__dlAside = $__t10side; $GLOBALS['YC_LQ_DL_SIDE'] = $__t10side; }
    /* ★ lqart：统一口径（原先的直查兜底已收回）。参照的「最新文章」是 slug 升序的 6 条。 */
    $__relNew = function_exists('lq_mech_related') ? (array)lq_mech_related('download__', $store, 6, 'slug') : [];
    $__lisNew = '';
    foreach ($__relNew as $r) {
        $ru = (string)($r['u'] ?? '');
        if ($ru === '' && isset($r['slug'])) { $ru = '/' . str_replace('__', '/', (string)$r['slug']); }
        $rt = (string)($r['t'] ?? ($r['title'] ?? ''));
        if ($ru === '' || $rt === '') { continue; }
        $__lisNew .= '<li><a href="' . e($lp2 . $ru) . '">' . e($rt) . '</a></li>';
    }
    $__dlRel = $__lisNew !== ''
        ? '<aside class="lq-rel"><h2 class="lq-rel-cap">最新文章</h2><ul>' . $__lisNew . '</ul></aside>'
        : '';
    /* ★ t10：最新文章改用参照逐页定值（表里有就用参照；没有则保留上面算出的同族 6 条） */
    $__t10rel = lq10_dl_ref_block($__t10slug, 'rel');
    if ($__t10rel !== null) { $__dlRel = $__t10rel; }}

/* ★★ [liulanqi 2026-10-11 站主裁定③ · 批1续] 「继续学习」相关块 = 【表驱动】
   依据（只读实测）：老站该块是**逐页策展**的 —— 集合 = `同前缀兄弟 ∪ 父页 /tips/<prefix>`（排除自身），
   而**顺序不可由规则复现**（例 edge-collections 老站序 = edge, edge-profile, edge-vertical-tabs, …）。
   ⇒ 把老站 45 页的测量结果固化成数据文件，渲染点直读。
   口径：★ 有表项且能取到标题 ⇒ 按表【保序】用；★ 无表项 / 文件缺失 / JSON 坏 / 标题取不到 ⇒ **回落现状**（下面那条）
         ⇒ 零回归、不报错、不白屏。
   ★ 数据文件可再生：`themes/bl/lqwww/data/tips_related.json`（源 = 老站 seo/tips/*.html 的「相关块」容器，
     抽取脚本见 _lqpix/ta/batch1_gen.php，存档 /root/ta/tips_related_target.tsv）。
   ★ 回滚 = 删本段 BEGIN…END 之间的改动，把下面 `$rel = …` 恢复成原来那一行。
   —— BEGIN tips-related-table —— */
if (!function_exists('lq_tips_related_table')) {
    /** 读数据表（一次请求只读一次）；★ 防御性：文件缺失/JSON 坏 ⇒ 返回 []，不报错 */
    function lq_tips_related_table(): array
    {
        static $t = null;
        if ($t !== null) { return $t; }
        $t = [];
        $f = dirname(__DIR__, 2) . '/lqwww/data/tips_related.json';   /* themes/bl/lqwww/data/… */
        if (is_file($f)) {
            $raw = @file_get_contents($f);
            if ($raw !== false && $raw !== '') {
                $d = json_decode($raw, true);
                if (is_array($d)) { $t = $d; }
            }
        }
        return $t;
    }
}
if (!function_exists('lq_tips_titles')) {
    /** 批量取标题（一条 SQL）；取不到的略过 ⇒ 不造死链 */
    function lq_tips_titles(array $slugs): array
    {
        if ($slugs === [] || !class_exists('Db')) { return []; }
        $in = implode(',', array_fill(0, count($slugs), '?'));
        $rows = Db::rows('SELECT slug,title FROM ' . Db::t('contents')
            . " WHERE slug IN ($in) AND status='publish' AND deleted_at IS NULL", $slugs);
        $m = [];
        foreach ($rows as $r) { $m[(string)$r['slug']] = (string)$r['title']; }
        return $m;
    }
}
$relItems = '';
$__stats = $GLOBALS['YC_TIPS_REL_STATS'] ?? ['hit' => 0, 'miss' => 0, 'skupless' => 0, 'nofile' => 0];
$__tbl = lq_tips_related_table();
if ($__tbl === []) { $__stats['nofile']++; }
$rel = [];
$__want = ($sec === 'tips' && isset($__tbl[$store]) && is_array($__tbl[$store])) ? $__tbl[$store] : null;
if ($__want) {
    $__ti = lq_tips_titles($__want);
    foreach ($__want as $__s) {                      /* ★ 按表【保序】 */
        if (!isset($__ti[$__s])) { $__stats['skupless']++; continue; }
        $rel[] = ['u' => '/' . str_replace('__', '/', (string)$__s), 't' => $__ti[$__s]];
    }
}
if ($rel !== []) { $__stats['hit']++; }
else {
    $__stats['miss']++;
    $rel = ($sec !== '' && function_exists('lq_mech_related')) ? lq_mech_related($sec . '__', $store, 8) : [];
}
$GLOBALS['YC_TIPS_REL_STATS'] = $__stats;
/* —— END tips-related-table —— */foreach ((array)$rel as $r) {
    $ru = (string)($r['u'] ?? '');
    if ($ru === '') { continue; }
    $relItems .= lqskel_fill(lqskel('detail_related_item'), [
        'href'  => e($lp2 . $ru),
        'label' => e((string)($r['t'] ?? '')),
    ], 'rel_item');
}
$relHtml = '';
if ($relItems !== '' && lqskel('detail_related') !== ''
    && (!$__lqBare || $topicLb !== '')) {   /* ★ lqart：三族里只有"有专题"的条目在参照里带这张卡 */
    $relHtml = lqskel_fill(lqskel('detail_related'), [
        'rel_title'     => e('继续学习：' . $topicLb),
        'rel_more_href' => e($lp2 . '/learn/' . strtolower((string)preg_split('/-/', (string)preg_replace('/^tips__/', '', $store))[0])),
        'rel_more'      => e('查看目录'),
        'rel_items'     => $relItems,
    ], 'related');
}

$meta = '';
if ($upd) { $meta = '更新于 <!-- -->' . date('Y-m-d', $upd); }
if ($pop > 0) { $meta .= ($meta !== '' ? ' · ' : '') . '阅读 <span id="lqViews">' . (int)$pop . '</span>'; }
if ($__lqBare) { $meta = ''; }   /* ★ lqart：三族参照无元信息条 */
else {
$meta .= ($meta !== '' ? ' · ' : '') . '<a class="hover:text-foreground" href="#comments">评论 <span id="lqCmt">'
    . (int)($comment_total ?? 0) . '</span></a>';
}

$badgeHref  = $sec !== '' ? ($lp2 . '/' . $sec) : '/';
$badgeLabel = $secLb !== '' ? $secLb : '内容';

/* ★ lrlearn-one 2026-10-06：/learn/<slug> 换用**参照字节外壳**
   （参照 = liulanqi.com/learn/chrome；骨架 page_learn_one + learn_one_hero）
   · 只在 `$sec === 'learn'` 且骨架就绪时启用；其余栏位（tips/tool/download/…）**一个字节不变**
   · 让 layout.php 整段让位（它已支持 YC_LQ_SHELL）：由本文件发唯一的包裹 + 面包屑 + 两栏 + hero 卡
   · 收口 3 个 </div> 挪到「阅读条」之后（内容列里还挂着 tags/上下篇/阅读条，挪早会把它们挤出内容列）
   · 富版正文（$html）原样进内容列；标签/上下篇/阅读条/评论/阅读数上报**一个不删** */
$__leanOne = ($sec === 'learn')
    && function_exists('lqpg_learn_one_ready') && lqpg_learn_one_ready();
if ($__leanOne) {
    $GLOBALS['YC_LQ_SHELL'] = 'learn-one';
    echo lqpg_learn_one_shell(
        lqpg_crumbs_nav([['首页', '/'], ['浏览器学堂', $lp2 . '/learn'], [$title, '']]),
        $sideHtml
    );
    echo lqpg_learn_one_hero(
        e($badgeLabel . ' · 图文教程'),
        lqwww_ico('book-open', 'h-4 w-4'),
        e($title),
        e($lead)
    );
    echo (string)$html;
} elseif ($__lqGal) {
    echo function_exists('lqart_gallery_cat_html')
        ? lqart_gallery_cat_html((array)$p, (string)$html, $lp2)
        : (string)$html;
    /* ★ lqart（站主裁定②）：/download/<slug> 的参照**不是**技巧骨架那种
       `max-w-6xl 包裹 + 面包屑 + 230px 侧栏`，而是老的 `<article class="article">` 文章式外壳
       ⇒ 这条路什么都不发，文章式外壳在文件下方由 `$__dlArticle` 发。 */
} elseif ($__lqArt) {
    /* lqart: the article-shaped shell for /download/<slug> is emitted a few lines below
       ($__dlArticle + $__dlRel).  Before this fix BOTH branches ran, so the body was
       rendered twice and the 230px tips sidebar was emitted too
       => main height 3543 vs reference 1586.  Emit nothing here. */
} else {
?>
<?= lqskel_fill(lqskel('detail_tips'), [
    '__grid_open__' => '<div class="grid gap-8 lg:grid-cols-[230px_1fr]">',
    '__side__'      => $sideHtml,
    '__side_toc__'  => $tocHtml,
    /* ★ lqart：骨架里烘着**参照那一页**的面包屑第三段（/learn/chrome · Chrome 从入门到精通）
       ⇒ 换成占位符，按本行 section 真值填（参照 /tips/adblock 第三段 = 「浏览器技巧」）。 */
    /* 参照规律（逐页核过）：第三段 = 专题名（有专题）或 section 名（无专题），href 一律 /{section}；
       第四段 = **本页标题**（骨架里烘的参照标题已换占位符） */
    'crumb_href'    => e($lp2 . '/' . $sec),
    /* 参照两种都有：有专题归属 → 专题名（/tips/chrome-tips「Chrome 从入门到精通」）；
       无专题 → section 名（/tips/adblock「浏览器技巧」）。专题名取自实例自己的 /learn 行。 */
    'crumb_label'   => e(($topicLb !== '')
        ? (string)preg_replace('/\x{6D4F}\x{89C8}\x{5668}/u', '', $topicLb)
        : $secLb),
    'crumb_title'   => e($title),
    'badge_href'    => e($badgeHref),
    'badge_icon'    => lqwww_ico('book-open', 'h-3.5 w-3.5'),
    'badge_label'   => e($badgeLabel),
    'h1'            => e($title),
    'lead'          => e($lead),
    'meta'          => $meta,
    'body'          => (string)$html,
    /* ★ lqart：参照 tips/learn 详情页**没有**自造「继续学习」卡；参照的等价物 = 骨架
       自带的底部「浏览器学堂 · 按专题系统学 / 全部浏览器技巧」卡（逐字节在骨架里）
       ⇒ 三族置空，其余页型沿用 $relHtml。 */
    'related'       => $relHtml,
], 'detail_tips') ?>
<?php } /* end 参照外壳 / 旧壳 */ ?>
<?php if ($__lqArt): ?>
<?= $__dlArticle ?>
<?= $__dlRel ?? '' ?>
<?php endif; ?>

<?php /* ★ lqart 2026-10-06：/tips · /learn · /gallery 三族 —— 参照里没有
   「标签框 / 上下篇 / reader-bar（收藏·划线·段评入口）」，按任务 ③⒝ 去掉；
   去掉的每一项逐条列在报告「③⒝」表里，恢复 = bash /root/lqart_rollback.sh。
   其余页型（$__lqBare=false）**这一段一个字节不变**。 */ ?>
<?php if (!$__lqBare): ?>
<?php /* 标签（保留）；上下篇/阅读条/评论/阅读数上报沿用原实现，一个都不删 */ ?>
<?php if (!empty($p['tags'])): ?>
<div class="mt-6 flex flex-wrap items-center gap-2 rounded-xl border bg-card p-4 shadow-sm">
  <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground"><?= e(__('ui.tags_label')) ?></span>
  <?php foreach ($p['tags'] as $t): ?>
  <a class="inline-flex items-center rounded-full border px-3 py-1 text-xs text-muted-foreground transition hover:border-primary hover:text-primary" href="<?= e(Poly::termUrl('tag', $t)) ?>"><?= e(Poly::termName($t)) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<nav class="mt-6 grid gap-2 sm:grid-cols-2" aria-label="<?= e(__('post.prev')) ?>/<?= e(__('post.next')) ?>">
  <?php if (!empty($prev)): ?>
  <a class="group flex items-start gap-2 rounded-xl border bg-card p-3 shadow-sm transition hover:border-primary" rel="prev" href="<?= e($lp2 . yc_entry_url((string)$prev['slug'])) ?>">
    <span class="mt-0.5 text-primary"><?= lqwww_ico('arrow-right', 'h-3.5 w-3.5') ?></span>
    <span class="min-w-0"><span class="block text-xs text-muted-foreground"><?= e(__('post.prev')) ?></span>
    <span class="line-clamp-2 text-sm"><?= e((string)$prev['title']) ?></span></span>
  </a>
  <?php endif; ?>
  <?php if (!empty($next)): ?>
  <a class="group flex items-start gap-2 rounded-xl border bg-card p-3 shadow-sm transition hover:border-primary" rel="next" href="<?= e($lp2 . yc_entry_url((string)$next['slug'])) ?>">
    <span class="mt-0.5 text-primary"><?= lqwww_ico('arrow-right', 'h-3.5 w-3.5') ?></span>
    <span class="min-w-0"><span class="block text-xs text-muted-foreground"><?= e(__('post.next')) ?></span>
    <span class="line-clamp-2 text-sm"><?= e((string)$next['title']) ?></span></span>
  </a>
  <?php endif; ?>
</nav>

<?php /* 阅读条：收藏 / 登录 CTA / 划线笔记提示（**保留**） */ ?>
<div class="reader-bar mt-6 rounded-xl border bg-card p-4 shadow-sm">
  <?php if ($logged): ?>
    <form class="inline" method="post" action="/user/favorite">
      <input type="hidden" name="content_id" value="<?= $cid ?>">
      <input type="hidden" name="redirect" value="<?= e($back) ?>">
      <?= Csrf::field() ?>
      <button class="btn btn-sm fav-btn <?= $fav ? 'on' : '' ?>" type="submit"><?= $fav ? e(__('post.fav_on')) : e(__('post.fav_off')) ?></button>
    </form>
    <span class="hint"><?php
        $hintMd = sprintf(__('post.mark_hint'), (int)$my_marks);
        $myLp = ui_lp();
        if ($myLp !== '') { $hintMd = str_replace('href="/user/', 'href="' . $myLp . '/user/', $hintMd); }
        echo $hintMd;
    ?></span>
    <span class="hint"><?= e(__('post.seg_hint')) ?></span>
  <?php else: ?>
    <a class="btn btn-sm" href="<?= e(ui_login($back)) ?>"><?= e(__('post.login_cta')) ?></a>
  <?php endif; ?>
</div>

<?php /* ★ lrlearn-one：参照外壳的收口（内容列 / 两栏 / 包裹）—— 挪到阅读条之后，
         这样 tags / 上下篇 / 阅读条都留在「内容列」里（挪早会把它们挤出内容列）。 */ ?>
<?php if (!empty($__leanOne)) { echo lqpg_learn_one_close(); } ?>

<?php endif; /* ★ lqart：$__lqBare 三族到此为止（标签/上下篇/阅读条/评论/上报全部不发） */ ?>

<?php if (!$__lqBare): ?>
<?= View::render('partials/comments', [
    'cid' => $cid, 'logged' => $logged, 'back' => $back,
    'comments' => $comments, 'segments' => $segments, 'para_n' => $para_n, 'csrf' => $csrf,
    'comment_page' => $comment_page ?? 1, 'comment_pages' => $comment_pages ?? 1,
    'comment_total' => (int)$comment_total,
]) ?>
<script>
  window.__READER = {
    cid: <?= $cid ?>,
    logged: <?= $logged ? 'true' : 'false' ?>,
    csrf: <?= json_encode($csrf) ?>,
    t: {
      mt: <?= json_encode(__('rd.mark_title'), JSON_UNESCAPED_UNICODE) ?>,
      tip: <?= json_encode(__('rd.mark_tip'), JSON_UNESCAPED_UNICODE) ?>,
      add: <?= json_encode(__('rd.add_note'), JSON_UNESCAPED_UNICODE) ?>,
      noteTip: <?= json_encode(__('rd.note_tip'), JSON_UNESCAPED_UNICODE) ?>,
      prompt: <?= json_encode(__('rd.prompt'), JSON_UNESCAPED_UNICODE) ?>,
      saved: <?= json_encode(__('rd.saved'), JSON_UNESCAPED_UNICODE) ?>
    }
  };
  (function () {
    try {
      var key = 'yc_view_' + <?= $cid ?> + '_' + new Date().toISOString().slice(0, 10);
      if (localStorage.getItem(key)) { return; }
      localStorage.setItem(key, '1');
      var fd = new FormData();
      fd.append('content_id', String(<?= $cid ?>));
      fetch('/count', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d && d.views) { var el = document.getElementById('lqViews'); if (el) { el.textContent = d.views; } } })
        .catch(function () {});
    } catch (e) {}
  })();
</script>
<script src="<?= e(View::asset('reader.js')) ?>"></script>
<?php endif; /* ★ lqart：$__lqBare 的注释/READER 收口 */ ?>
