<?php
/** chawiki theme · layout：整页外壳；变量：$title, $page_title, $main, $sub(可选), $htmlLang
 *  双色系：html[data-pal=green|warm] × html.dark（右下角切换，localStorage 记忆，默认绿系）。
 */
$pal = (string)theme_opt('palette', 'green'); if (!in_array($pal, ['green', 'warm'], true)) { $pal = 'green'; }
/* ★★ 方案 D · P1（2026-09-18）：词条页 v2 版式开关（只读；不改动任何 v1 代码路径）★★
   $GLOBALS['YC_WIKI_V2'] 由 partials/post.php 置位（同一请求内 post 先于 layout 渲染）：
   条件 = theme.options.layout === 'v2'（**字符串**）且 当前是词条页（type=post）。
   ⇒ 其余 12 个页型（首页/分类/索引/标签/搜索/归档/独立页/编辑/历史/diff/404）一律 $v2entry=false，
     输出一个字节都不变。默认 false（即使 post.php 没跑到也一样）。 */
$v2entry = !empty($GLOBALS['YC_WIKI_V2']);

/* ★★★★ 方案 D · P3（2026-09-18）：v2 铺开到其余 12 个页型 ★★★★
   ① 开关仍是同一个**字符串** `theme.options.layout`（'v2' 启用 / 其它值 = v1）。
      ⇒ R1 回滚依然只改这一个字符，且下面每一处分支都保留 v1 原路径。
   ② 页型识别：优先用 partial 自己置的 `$GLOBALS['YC_P3_KIND']`（landing/list/meta-index/search/
      page/pages-list/archives/404/post 各置一次），没置的（如 wikiedit 的编辑/历史/diff 三页、
      内核直出的 404）按 REQUEST_URI 兜底判定 —— 这样**不需要改任何插件或内核**就能给它们上 v2 外壳。
   ③ 骨架规则（设计 §③-1 表 + §③-2(2)「页型决定侧栏」）：
        · 列表 / 分类 / 索引 / 标签 / 归档 页 ⇒ **保留满高左栏**（4 组 13 类 = 17 行，无 max-height、无 overflow）
        · 其余页型                               ⇒ **无左栏**
   ④ 变量命名：$v2entry 沿用 P1 的名字（= 「无左栏的 v2 页」），使 wiki2.css 里既有的
      `.layout.wiki.v2-entry` / `:root.v2-entry` 规则在 P1 那批页面上继续原样命中。 */
$cwV2 = ((string)theme_opt('layout', 'v1') === 'v2');
$__p3path = trim((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/'), '/');
$__p3segs = $__p3path === '' ? [] : explode('/', $__p3path);
if (!empty($__p3segs[0]) && Poly::on() && in_array($__p3segs[0], Poly::enabled(), true) && $__p3segs[0] !== Poly::defaultLang()) { array_shift($__p3segs); }
$__p3head = (string)($__p3segs[0] ?? '');
$__p3n = count($__p3segs);
if ($__p3path === '' || $__p3head === 'page') { $__p3kind = 'home'; }
elseif ($__p3head === 'category' || $__p3head === 'tag') { $__p3kind = ($__p3n <= 1) ? 'index' : 'list'; }
elseif ($__p3head === 'archives') { $__p3kind = 'list'; }
elseif ($__p3head === 'search') { $__p3kind = 'search'; }
elseif ($__p3head === 'latest' || $__p3head === 'pages') { $__p3kind = 'index'; }
elseif ($__p3head === 'entry') {
    $__p3act = strtolower((string)($__p3segs[2] ?? ''));
    $__p3kind = in_array($__p3act, ['edit', 'history', 'diff'], true) ? $__p3act : 'entry';
} else { $__p3kind = 'entry'; }
$cwKind = (string)($GLOBALS['YC_P3_KIND'] ?? '');
if ($cwKind !== '') { $__p3kind = $cwKind; }
$cwKind = $__p3kind;
/* 列表/分类/索引/标签/归档页保留左栏；其余页型无左栏 */
$cwNav = $cwV2 && ($cwKind === 'list' || $cwKind === 'index');
$v2entry = $cwV2 && !$cwNav;
$GLOBALS['YC_P3_KIND'] = $cwKind;
$cwHtmlCls = $cwV2 ? ('v2 v2-' . $cwKind . ($cwKind !== 'entry' && $v2entry ? ' v2-entry' : '')) : ($v2entry ? 'v2-entry' : '');
?><!DOCTYPE html>
<html lang="<?= e((string)($htmlLang ?? 'zh-CN')) ?>" dir="<?= e(ui_dir()) ?>" data-pal="<?= e($pal) ?>"<?= $cwHtmlCls !== '' ? ' class="' . e($cwHtmlCls) . '"' : '' ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?></title>
<script>
/* 首屏前应用访客记忆的色系/明暗，避免闪动；localStorage 缺失时用主题默认（绿系）。
   预览支持：URL 参数 ?pal=green|warm、?dark=1|0 可强制（截图/评审用，不会写入记忆）。 */
(function () {
  try {
    var d = document.documentElement, q = new URLSearchParams(location.search);
    var p = q.get('pal') || localStorage.getItem('cw-pal');
    if (p === 'green' || p === 'warm') { d.setAttribute('data-pal', p); }
    var k = q.has('dark') ? q.get('dark') : localStorage.getItem('cw-dark');
    if (k === '1') { d.classList.add('dark'); }
    else if (k !== '0' && k === null && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) { d.classList.add('dark'); }
  } catch (e) {}
})();
</script>
<?php
$favRel = trim((string)get_option('site.logo', ''));
/* ★ 修 404：主题目录里只有 logo.svg（无 logo.png），旧回退值指向不存在的 logo.png ⇒
   每次访问都产生一次 404（Chromium 控制台 error，验收项「JS 报错 0」）。回退改为实际存在的 logo.svg。 */
if ($favRel === '' || !is_file(dirname(__DIR__, 3) . $favRel)) { $favRel = '/themes/chawiki/logo.svg'; }
$favAbs = dirname(__DIR__, 3) . $favRel;
$favSrc = $favRel . (is_file($favAbs) ? '?v=' . filemtime($favAbs) : '');
?>
<link rel="icon" type="image/svg+xml" href="<?= e($favSrc) ?>">
<link rel="apple-touch-icon" href="<?= e($favSrc) ?>">
<link rel="alternate" type="application/rss+xml" title="RSS" href="<?= e(ui_lp() . '/feed') ?>">
<link rel="stylesheet" href="<?= e(View::asset('style.css')) ?>">
<link rel="stylesheet" href="<?= e(View::asset('chawiki.css')) ?>">
<?php /* ★ Wiki 版式层（2026-09-18）：内容优先三栏重塑 + 左栏紧凑化 + TOC/infobox 右浮。
        放在 style.css / chawiki.css 之后 ⇒ 只覆盖版式，配色令牌与既有组件全部沿用。 */ ?>
<link rel="stylesheet" href="<?= e(View::asset('wiki.css')) ?>">
<?php /* ★ 方案 D · P1「v2 版式层」：**新增文件**，只在词条页 v2 下加载。
        与 wiki.css 并存且加载在其后 ⇒ 只覆盖版式；wiki.css / chawiki.css / style.css 一行未改。
        回滚 R3 = 删掉本文件 + 删掉这一行。 */ ?>
<?php if ($cwV2): ?>
<link rel="stylesheet" href="<?= e(View::asset('wiki2.css')) ?>">
<?php endif; ?>
<?php
$tw = (string)theme_opt('width', '');
if ($tw !== '') { echo '<style>:root{--wide-w:' . (int)$tw . 'px}</style>'; }
$headStat = stat_html('stats.head'); if ($headStat !== '') { echo "\n<!-- stats.head -->\n" . $headStat; }
/* 【cha.wiki 主题】canonical 按 URL 方案 B 统一生成：词条页恒为 /entry/{slug}（见 inc.php） */
require_once dirname(__DIR__) . '/inc.php';
$__cwCanon = chawiki_canonical();
if ($__cwCanon !== '') {
    echo "\n" . '<link rel="canonical" href="' . e($__cwCanon) . '" />';
    echo "\n" . '<meta property="og:url" content="' . e($__cwCanon) . '">';
    echo "\n" . '<meta property="og:type" content="article">';
}
if (!empty($GLOBALS['YC_HREFLANG']) && is_array($GLOBALS['YC_HREFLANG'])) {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8082';
    $scheme = yc_theme_scheme();
    $origin = $scheme . '://' . $host;
    foreach (Poly::enabled() as $lc) {
        $rel = $GLOBALS['YC_HREFLANG'][$lc] ?? null;
        if ($rel === null) { continue; }
        echo "\n" . '<link rel="alternate" hreflang="' . e($lc) . '" href="' . e($origin . $rel) . '" />';
    }
    $xdef = $GLOBALS['YC_HREFLANG'][Poly::defaultLang()] ?? null;
    if ($xdef !== null) { echo "\n" . '<link rel="alternate" hreflang="x-default" href="' . e($origin . $xdef) . '" />'; }
}
if (!empty($GLOBALS['YC_META'])) {
    $ym = $GLOBALS['YC_META'];
    if (!empty($ym['description'])) { echo "\n" . '<meta name="description" content="' . e($ym['description']) . '">'; }
    if (!empty($ym['keywords'])) { echo "\n" . '<meta name="keywords" content="' . e($ym['keywords']) . '">'; }
}
/* ★ P3：单例标签页 noindex,follow（设计 §③-2(3) R3；由 partials/list.php 置位） */
if ($cwV2 && !empty($GLOBALS['YC_ROBOTS'])) {
    echo "\n" . '<meta name="robots" content="' . e((string)$GLOBALS['YC_ROBOTS']) . '">';
}
?>
</head>
<body>
<?php /* ★ 方案 D：跳转链接（A14）。v2 下**每个页型都渲染**（P1 只有词条页）；必须在所有可聚焦元素之前。 */ ?>
<?php if ($cwV2): ?>
<a class="skip" href="<?= $cwKind === 'entry' ? '#articleBody' : '#main' ?>">跳到正文</a>
<?php endif; ?>
<?php
/* 顶栏（对齐参考站）：brand(logo+站名) + docs-nav(首页+独立页) + docs-search
   多语：非基准语言下标签取语言包、独立页标题取该语言译文、链接带 /{lang}/ 前缀 */
$curLangShort = Lang::short();
$polyOn = Poly::on();
$defaultLang = Poly::defaultLang();
$homeHref = ($polyOn && $curLangShort !== $defaultLang) ? '/' . $curLangShort . '/' : '/';
$curPath = trim(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/', '/');
$isHomePg = $curPath === '' || preg_match('#^(?:[a-z]{2}/)?page/\d+$#', $curPath) === 1 || preg_match('#^[a-z]{2}/?$#', $curPath) === 1;
$topPages = Db::rows('SELECT id, slug, title FROM ' . Db::t('contents') . " WHERE type='page' AND status='publish' AND deleted_at IS NULL AND slug<>'privacy-policy' ORDER BY `order` ASC, id ASC LIMIT 8");
$polyPageTitles = []; // pageId -> [lang => title] 仅当前语言译文（含未发布译文则回落原文标题）
if ($polyOn && $curLangShort !== $defaultLang && $topPages) {
    $ids = array_column($topPages, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach (Db::rows('SELECT group_id, title, status FROM ' . Poly::table() . " WHERE lang=? AND group_id IN ($in)", array_merge([$curLangShort], array_map('intval', $ids))) as $pt) {
        $polyPageTitles[(int)$pt['group_id']] = $pt['status'] === 'publish' ? (string)$pt['title'] : '';
    }
}
?>
<header class="site-header docs-header">
  <div class="docs-header-inner">
    <a class="docs-brand" href="<?= e($homeHref) ?>" title="<?= e(site_label()) ?>" aria-label="<?= e(site_label()) ?>">
      <?php
      $logoRel2 = trim((string)get_option('site.logo', '/themes/chawiki/logo.svg'));
      $logoAbs2 = dirname(__DIR__, 3) . $logoRel2;
      if ($logoRel2 !== '' && is_file($logoAbs2)): ?>
      <img class="docs-brand-logo" src="<?= e($logoRel2 . '?v=' . filemtime($logoAbs2)) ?>" alt="logo">
      <?php endif; ?>
      <span class="docs-brand-name"><?= e(site_label()) ?></span>
    </a>
    <nav class="docs-nav" aria-label="<?= e(__('ui.main_nav')) ?>">
      <?php if ($cwV2):
      /* ★★★★ P3：顶栏重构（判据 A15 / A16 / 设计 §③-2(1)）★★★★
         改前 9 项：首页 分类 最新词条 最新词条 学茶入门 茶人 老吕视角 中国茶产区地图 Cha Baike…(46 字符)
           ⇒ ① 同名重复「最新词条」×2（/latest 与 /entry/latest）；② /entry/latest 正文仅 144 字 = 空壳页；
             ③ 46 字符的英文项把顶栏撑到 scrollWidth 961 > clientWidth 920（横向溢出）。
         改后：**5 个直接链接 + 2 个 <details> 下拉**（设计原文：首页 · 分类▾ · 词条索引 · 学茶入门 ·
               产区地图 · 老吕视角 · 更多▾）—— 同名 0、单项 ≤12 汉字、顶栏不溢出。
         ★ /entry/latest（空壳页）不再进顶栏（A15）；「最新词条」这个名字只保留在 /latest 一项上，
           并在下面改叫「词条索引」（与设计一致，彻底消除同名重复）。 */
      $navReady = function (string $slug) use ($topPages): bool {
          foreach ($topPages as $tp) { if ((string)$tp['slug'] === $slug) { return true; } }
          return false;
      };
      $navHref = function (string $slug) use ($polyOn, $curLangShort, $defaultLang): string {
          $u = chawiki_entry_url($slug);
          return ($polyOn && $curLangShort !== $defaultLang) ? '/' . $curLangShort . $u : $u;
      };
      $navAct = function (string $slug) use ($curPath): string {
          return trim(parse_url(chawiki_entry_url($slug), PHP_URL_PATH) ?? '', '/') === $curPath ? ' class="active"' : '';
      };
      $cwGroups = function_exists('chawiki_cat_groups') ? chawiki_cat_groups() : [];
      $lpNav = ($polyOn && $curLangShort !== $defaultLang) ? '/' . $curLangShort : '';
      ?>
      <a href="<?= e($homeHref) ?>"<?= $isHomePg ? ' class="active"' : '' ?>><?= e(__('nav.home')) ?></a>
      <?php if ($cwGroups): ?>
      <details class="dnav-dd">
        <summary class="dnav-sum" aria-label="分类导航">分类</summary>
        <div class="dnav-panel">
          <?php foreach ($cwGroups as $g): ?>
          <div class="dnav-grp">
            <div class="dnav-gcap"><?= e((string)$g['name']) ?></div>
            <?php foreach ((array)$g['cats'] as $c): ?>
            <a class="dnav-cat" href="<?= e($lpNav . '/category/' . rawurlencode((string)$c['slug']) . '/') ?>"><span><?= e((string)$c['name']) ?></span><span class="dnav-n"><?= number_format((int)$c['n']) ?></span></a>
            <?php endforeach; ?>
          </div>
          <?php endforeach; ?>
          <a class="dnav-all" href="<?= e($lpNav . '/category/') ?>">全部分类 →</a>
        </div>
      </details>
      <?php endif; ?>
      <a href="<?= e($lpNav . '/latest') ?>"<?= $curPath === 'latest' ? ' class="active"' : '' ?>>词条索引</a>
      <a href="<?= e($navHref('learn')) ?>"<?= $navAct('learn') ?>>学茶入门</a>
      <a href="<?= e($navHref('map')) ?>"<?= $navAct('map') ?>>产区地图</a>
      <a href="<?= e($navHref('laolv')) ?>"<?= $navAct('laolv') ?>>老吕视角</a>
      <details class="dnav-dd">
        <summary class="dnav-sum" aria-label="更多入口">更多</summary>
        <div class="dnav-panel dnav-panel-narrow">
          <?php if ($navReady('people')): ?><a href="<?= e($navHref('people')) ?>"<?= $navAct('people') ?>>茶人</a><?php endif; ?>
          <?php if ($navReady('en')): ?><a href="<?= e($navHref('en')) ?>"<?= $navAct('en') ?>>Cha Baike (EN)</a><?php endif; ?>
          <a href="<?= e($navHref('privacy-policy')) ?>"<?= $navAct('privacy-policy') ?>>隐私政策</a>
          <a href="<?= e($lpNav . '/pages') ?>">站点地图（人读版）</a>
          <a href="<?= e($lpNav . '/sitemap.xml') ?>">sitemap.xml</a>
          <a href="<?= e(ui_lp() . '/feed') ?>">RSS 订阅</a>
        </div>
      </details>
      <?php else: ?>
      <a href="<?= e($homeHref) ?>"<?= $isHomePg ? ' class="active"' : '' ?>><?= e(__('nav.home')) ?></a>
      <a href="<?= e($homeHref === '/' ? '/category/' : $homeHref . 'category/') ?>">分类</a>
      <a href="<?= e($homeHref === '/' ? '/latest' : $homeHref . 'latest') ?>">最新词条</a>
      <?php foreach ($topPages as $tp):
          $pid = (int)$tp['id'];
          $tpLabel = isset($polyPageTitles[$pid]) && $polyPageTitles[$pid] !== '' ? $polyPageTitles[$pid] : (string)$tp['title'];
          $tpSlug = (string)$tp['slug'];
          /* ★ URL 统一（方案 B）：独立页也走 /entry/{slug}，与 canonical 同源（原先 /{slug}.html） */
          $tpHref = ($polyOn && $curLangShort !== $defaultLang) ? '/' . $curLangShort . chawiki_entry_url($tpSlug) : chawiki_entry_url($tpSlug);
          $tpPath = trim(parse_url($tpHref, PHP_URL_PATH) ?? $tpHref, '/'); ?>
      <a href="<?= e($tpHref) ?>"<?= $curPath === $tpPath ? ' class="active"' : '' ?>><?= e($tpLabel) ?></a>
      <?php endforeach; ?>
      <?php endif; ?>
    </nav>
    <form class="docs-search" action="<?= e(ui_lp() . '/search') ?>" method="get" role="search">
      <input type="search" name="q" placeholder="<?= e(__('ui.search')) ?>…" aria-label="<?= e(__('ui.search')) ?>">
      <button type="submit" aria-label="<?= e(__('ui.search')) ?>">🔍</button>
    </form>
    <?php if ($cwV2): /* ★ P3：语言切换改成两个直接 <a>（设计 §③-5：去掉 JS langBtn 菜单；零 JS 可读 A11） */ ?>
      <?php if (Poly::on() && count(Poly::enabled()) > 1): ?>
      <nav class="dnav-langs" aria-label="<?= e(__('ui.lang_cap')) ?>">
        <?php foreach (Poly::enabled() as $lc): ?>
        <a href="<?= e(ui_lang_url($lc)) ?>"<?= $lc === Lang::short() ? ' class="cur"' : '' ?>><?= e($lc === Poly::defaultLang() ? '中' : 'EN') ?></a>
        <?php endforeach; ?>
      </nav>
      <?php endif; ?>
    <?php elseif (Poly::on() && count(Poly::enabled()) > 1): ?>
    <div class="lang-sw hs-lang">
      <button type="button" class="icon-btn hs-lang-btn" id="langBtn" aria-haspopup="true" aria-expanded="false" aria-label="<?= e(__('ui.lang_cap')) ?>" title="<?= e(__('ui.lang_cap')) ?>">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 8 6 6"></path><path d="m4 14 6-6 2-3"></path><path d="M2 5h12"></path><path d="M7 2h1"></path><path d="m22 22-5-10-5 10"></path><path d="M14 18h6"></path></svg>
      </button>
      <div class="lang-menu" id="langMenu" hidden>
        <?php foreach (Poly::enabled() as $lc): ?>
        <a href="<?= e(ui_lang_url($lc)) ?>"<?= $lc === Lang::short() ? ' class="cur"' : '' ?>><?= e(Poly::label($lc)) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if (!$cwV2): ?>
    <button type="button" class="icon-btn nav-toggle" id="navToggle" aria-label="<?= e(__('ui.menu')) ?>" aria-expanded="false">
      <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16"></path><path d="M4 12h16"></path><path d="M4 19h16"></path></svg>
    </button>
    <?php endif; ?>
  </div>
</header>
<?php if ($cwV2): /* ★★★★ P3：移动端主导航 = 顶栏下方一个 <details>（**文档流内展开**，不做固定浮层抽屉）
   依据设计 §③-5/J4：去掉现在的 JS 抽屉（#navToggle/#drawer）⇒ 零 JS 也能展开、可访问、无遮挡。
   代价：它不是浮层（设计已写明并接受这一点）。 */ ?>
<details class="v2-menu">
  <summary class="v2-menu-sum"><span class="v2-menu-ico" aria-hidden="true">☰</span>菜单</summary>
  <nav class="v2-menu-in" aria-label="<?= e(__('ui.main_nav')) ?>">
    <div class="v2-menu-grp">
      <a href="<?= e($homeHref) ?>"><?= e(__('nav.home')) ?></a>
      <a href="<?= e($lpNav . '/latest') ?>">词条索引</a>
      <a href="<?= e($lpNav . '/category/') ?>">全部分类</a>
      <a href="<?= e($lpNav . '/tag/') ?>">标签索引</a>
      <a href="<?= e($lpNav . '/archives') ?>">归档</a>
      <a href="<?= e($lpNav . '/pages') ?>">站点地图</a>
      <a href="<?= e($navHref('learn')) ?>">学茶入门</a>
      <a href="<?= e($navHref('map')) ?>">产区地图</a>
      <a href="<?= e($navHref('laolv')) ?>">老吕视角</a>
      <a href="<?= e($navHref('people')) ?>">茶人</a>
      <a href="<?= e($navHref('en')) ?>">Cha Baike (EN)</a>
      <a href="<?= e($navHref('privacy-policy')) ?>">隐私政策</a>
    </div>
    <div class="v2-menu-grp">
      <div class="v2-menu-cap">搜索</div>
      <form class="docs-search v2-menu-search" action="<?= e(ui_lp() . '/search') ?>" method="get" role="search">
        <input type="search" name="q" placeholder="<?= e(__('ui.search')) ?>…" aria-label="<?= e(__('ui.search')) ?>">
        <button type="submit" aria-label="<?= e(__('ui.search')) ?>">🔍</button>
      </form>
    </div>
    <?php if ($cwGroups): ?>
    <div class="v2-menu-grp">
      <div class="v2-menu-cap">分类（4 组 13 类）</div>
      <?php foreach ($cwGroups as $g): ?>
      <div class="v2-menu-gcap"><?= e((string)$g['name']) ?></div>
      <div class="v2-menu-cats">
        <?php foreach ((array)$g['cats'] as $c): ?>
        <a href="<?= e($lpNav . '/category/' . rawurlencode((string)$c['slug']) . '/') ?>"><?= e((string)$c['name']) ?><span class="dnav-n"><?= number_format((int)$c['n']) ?></span></a>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if (Poly::on() && count(Poly::enabled()) > 1): ?>
    <div class="v2-menu-grp">
      <div class="v2-menu-cap"><?= e(__('ui.lang_cap')) ?></div>
      <div class="v2-menu-cats">
        <?php foreach (Poly::enabled() as $lc): ?>
        <a href="<?= e(ui_lang_url($lc)) ?>"<?= $lc === Lang::short() ? ' class="cur"' : '' ?>><?= e(Poly::label($lc)) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </nav>
</details>
<?php else: ?>
<div class="drawer" id="drawer" aria-label="<?= e(__('ui.drawer')) ?>">
  <div class="drawer-in">
    <button type="button" class="drawer-close" id="drawerClose" aria-label="Close">
      <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg>
    </button>
    <nav class="drawer-nav">
      <?php foreach (Nav::list('top', Lang::short()) as $ni): ?>
      <a href="<?= e($ni['href']) ?>"<?= $ni['active'] ? ' class="active"' : '' ?>><?= e($ni['label']) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php
    /* 移动端抽屉直达：分类/归档 + 独立页（桌面 docs-nav 已有独立页，此处仅窄屏使用） */
    $dlp = ($polyOn && $curLangShort !== $defaultLang) ? '/' . $curLangShort : '';
    $drLinks = [
      ['label' => __('tt.mi_category'), 'href' => $dlp . '/category/'],
      ['label' => __('nav.archive'), 'href' => $dlp . '/archives'],
    ];
    foreach ($topPages as $tp) {
        $dlT = isset($polyPageTitles[(int)$tp['id']]) && $polyPageTitles[(int)$tp['id']] !== '' ? $polyPageTitles[(int)$tp['id']] : (string)$tp['title'];
        $drLinks[] = ['label' => $dlT, 'href' => $dlp . chawiki_entry_url((string)$tp['slug'])];
    }
    ?>
    <?php if ($drLinks): ?>
    <div class="drawer-group">
      <div class="drawer-cap"><?= e(__('ui.articles')) ?></div>
      <div class="drawer-langs">
        <?php foreach ($drLinks as $dl): ?>
        <a href="<?= e($dl['href']) ?>"><?= e($dl['label']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if (Poly::on()): ?>
    <div class="drawer-group">
      <div class="drawer-cap"><?= e(__('ui.lang_cap')) ?></div>
      <div class="drawer-langs">
        <?php foreach (Poly::enabled() as $lc): ?>
        <a href="<?= e(ui_lang_url($lc)) ?>"<?= $lc === Lang::short() ? ' class="cur"' : '' ?>><?= e(Poly::label($lc)) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <div class="drawer-group">
      <div class="drawer-cap"><?= e(__('ui.account_cap')) ?></div>
      <div class="drawer-langs">
        <?php if (\Auth::check()): ?>
          <a href="<?= e(ui_user()) ?>"><?= __('ui.my') ?></a>
          <?php if (\Auth::isAdmin()): ?><a href="/admin/"><?= __('ui.admin') ?></a><?php endif; ?>
          <a href="/logout"><?= __('ui.logout') ?></a>
        <?php else: ?>
          <a href="<?= e(ui_login()) ?>"><?= __('ui.login') ?></a>
          <a href="<?= e(ui_register()) ?>"><?= __('ui.register') ?></a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; /* /v2-menu vs drawer */ ?>

<?php
/* 左栏“全部文章导航”：除首页与功能页外所有页面（文章/独立页/栏目/搜索/归档…）三栏统一
   多语：当前语言下分组标题取 names_i18n、文章标题取该语言已发布译文；链接带 /{lang}/ 前缀 */
/* ★ 方案 D · P1：词条页 v2 = **无左栏**（G1/G2）。
   为什么：站长反对的两件事 —— ① 330px 半截盒子 + 内部滚动条（A2 ❌）② 指针停在左栏上页面不滚
   （`overscroll-behavior: contain`，A3 ❌）—— 都出自左栏；词条页把导航交给「面包屑 + 顶栏 + 页脚」。
   ★ 只关掉这一页型的骨架，`$hasLeft` 的判定逻辑（多语前缀/deny 名单/分类高亮）一个字未改：
     $v2entry 为真时整段跳过 —— 左栏树、高亮、自动滚动 JS 全部不参与，**也不是「隐藏」而是不渲染**。
   ★★★★ P3 追加（2026-09-18）：v2 下**列表/分类/索引/标签/归档页仍要左栏**，
   但那不是这棵树 —— 它换成「4 组 13 类 = 17 行」的固定导航（见下面 $cwNav 分支），
   因为 G2 要求「导航块的形状不许依赖当前条目」（v1 左栏会把当前分类的 30 条词条展开，
   形状随页面变 ⇒ 必然要补位、必然要内部滚动）。 */
$hasLeft = false;
$cwNavTree = [];   /* v2 左栏用的 4 组 13 类（不依赖当前条目） */
if ($cwNav) {
    $cwNavTree = function_exists('chawiki_cat_groups') ? chawiki_cat_groups() : [];
} elseif (empty($GLOBALS['YC_HOME_HERO']) && !$v2entry) {
    $segL = trim($curPath, '/');
    $segL = strtok($segL, '/') !== false ? $segL : '';
    $firstSeg = ($pos = strpos($segL, '/')) !== false ? substr($segL, 0, $pos) : $segL;
    $activeLang = $defaultLang;
    $restPath = $segL;
    if ($polyOn && $firstSeg !== '' && in_array($firstSeg, Poly::enabled(), true) && $firstSeg !== $defaultLang) {
        $activeLang = $firstSeg;
        $restPath = $pos === false ? '' : substr($segL, $pos + 1);
    }
    $first = strtok($restPath, '/') ?: '';
    $deny = ['user', 'admin', 'login', 'register', 'logout', 'count', 'feed', 'health', 'store', 'market', 'market-app', 'links', 'collection', 'submit', 'selected', 'circle'];
    $langPrefix = ($activeLang !== $defaultLang) ? '/' . $activeLang : '';
    if ($restPath !== '' && $restPath !== $activeLang && !in_array($first, $deny, true) && !str_ends_with($restPath, '.xml')) {
        $hasLeft = true;
        /* ★ 当前条目 slug：兼容三种形态
             /entry/{slug} · /entry/{slug}.html · /{slug}.html
           （URL 统一到 /entry/{slug} 后，$first 变成前缀段 "entry"，直接用会把 $curSlug 误判成 "entry"
             ⇒ 左栏「当前条目高亮 + 自动滚动」会全部失效 —— 这里按前缀段再取一段。）*/
        $slugSeg = $first;
        $__bp = function_exists('chawiki_base_path') ? ltrim(chawiki_base_path(), '/') : '';
        if ($__bp !== '' && $first === $__bp) {
            $__rest = substr($restPath, strlen($first));
            $__rest = trim((string)$__rest, '/');
            if ($__rest !== '') { $slugSeg = strtok($__rest, '/') ?: $first; }
        }
        $curSlug = preg_replace('#\.html$#', '', $slugSeg) ?: '';
        /* ★ 当前分类 slug：分类页 /category/{slug}/ 从路径取；
           词条页优先用 partials/post.php 传进来的「真实分类归属」（$GLOBALS['YC_WIKI_CURCAT']）——
           为什么不能靠"在树的 30 条样本里找自己"：13 类各有 280~4,400 条，树里只有每类前 30 条，
           实测 gong-cha 在历史类排第 84、bei-cha 在茶类排第 2156 ⇒ 旧写法永远命中不了、高亮全灭。 */
        $curCatSlug = (string)($GLOBALS['YC_WIKI_CURCAT'] ?? '');
        if ($curCatSlug === '' && preg_match('#^(?:[a-z]{2}/)?category/([^/]+)#', $restPath, $__cm)) { $curCatSlug = (string)$__cm[1]; }
        /* ★ 性能（chawiki 优化 ⒝）+ ★ Wiki 版式（2026-09-18）
           ① 树数据取「内核块缓存 Cache::blocks」（chawiki_left_tree，TTL 600s）：每页 0 条 SQL。
              缓存里只存树数据（不含 on 高亮），高亮按当前请求在缓存外算 —— 无 per-slug 缓存碎片。
           ② ★ 只渲染「当前分类」的条目：390 条 → ≤30 条 ⇒ 左栏不再比正文长十几倍、页面体积腰斩。
              其余 12 个分类只出「分类名 + 真实词条数」并直链到 /category/{slug}/（该页本身就是完整列表）。
           ③ ★ 折叠全部用 <details>/CSS（零 JS）：非当前分类默认收起，当前分类默认展开且高亮当前词条。
           ④ 链接前缀仍走 chawiki_entry_url() ⇒ /entry/{slug} 形态不变。 */
        $useLangTitles = $polyOn && $activeLang !== $defaultLang;
        $leftTree = chawiki_left_tree($activeLang, $useLangTitles);
        $leftTree = chawiki_tree_mark($leftTree, $curSlug, $curCatSlug);
        $GLOBALS['YC_DOCS_TREE'] = $leftTree;
        $GLOBALS['YC_DOCS_TREE_LP'] = $langPrefix;
        $hasLeft = true;
    }
}
if ($cwNav && $cwNavTree !== []) { $hasLeft = true; }
$GLOBALS['YC_P3_NAV'] = $cwNav && $hasLeft;
/* v2 左栏的「当前分类」高亮（分类页 /category/{slug}/ ⇒ 该行高亮；词条页给的是真实分类归属） */
$curCatSlugP3 = (string)($GLOBALS['YC_WIKI_CURCAT'] ?? '');
if ($curCatSlugP3 === '' && preg_match('#^(?:[a-z]{2}/)?category/([^/]+)#', $curPath, $__m3)) { $curCatSlugP3 = rawurldecode((string)$__m3[1]); }
?>
<?php /* ★ SVG 精灵已随 wiki 版式移除：新左栏用纯 CSS 三角/箭头做折叠指示，不再需要图标 <use> 复用
     （旧版为压体积把 1,560 个内联图标改成 3 个 <symbol> + <use>；现在条目数已降到「当前分类 ≤30 条」，
      连图标本身一起去掉，再省一份体积与一次 SVG 解析。 */ ?>
<div class="layout wiki<?= $cwV2 ? ' v2 v2-' . e($cwKind) : '' ?><?= $v2entry ? ' v2-entry' : '' ?><?= empty($side) ? ' no-side' : '' ?><?= !empty($tocSide) ? ' toc-rail' : '' ?><?= (!empty($tocSide) && !empty($sbSticky)) ? ' sb-sticky' : '' ?><?= !empty($GLOBALS['YC_HOME_HERO']) ? ' is-home' : '' ?><?= $hasLeft ? ' has-left' : '' ?><?= ($hasLeft && !empty($side)) ? ' has-right' : '' ?>">
  <?php if ($cwNav && $cwNavTree): /* ★★★★ P3：v2 的列表/分类/索引/标签/归档页左栏 —— **4 组 13 类 = 17 行**
      依据 §③-2(2) / §③-6 / 判据 A19：
        · 行数固定 17 行 ≈ 422px ≤ 826px（900 视口）⇒ 自然高 ≤ 一屏；
        · **没有 max-height、没有 overflow**（v1 的 `max-height:min(46vh,330px)+overflow-y:auto` 是 A2 ❌ 的根因）；
        · **不列任何词条条目** ⇒ 导航形状不依赖当前词条（G2）；
        · 矮屏兜底 `@media (max-height:640px){ position: static }`（A20）。
      数据走块缓存（0 条新增 SQL），与 v1 左栏同一性质。 */ ?>
  <aside class="leftnav wnav v2-nav" aria-label="分类导航">
    <div class="wnav-inner">
      <nav class="wnav-box wnav-groups" aria-label="<?= e(__('tt.mi_category')) ?>">
        <div class="wnav-cap"><?= e(__('tt.mi_category')) ?><a class="wnav-more" href="<?= e($lpNav . '/category/') ?>">全部 →</a></div>
        <?php foreach ($cwNavTree as $g):
            $gOn = false;
            foreach ((array)$g['cats'] as $c) { if ((string)$c['slug'] === ($curCatSlugP3 ?? '')) { $gOn = true; } }
        ?>
        <div class="wnav-group">
          <div class="wnav-gcap"><?= e((string)$g['name']) ?><span class="wnav-n"><?= number_format((int)$g['n']) ?></span></div>
          <?php foreach ((array)$g['cats'] as $c):
              $cOn = (string)$c['slug'] === ($curCatSlugP3 ?? ''); ?>
          <a class="wnav-cat<?= $cOn ? ' on' : '' ?>" href="<?= e($lpNav . '/category/' . rawurlencode((string)$c['slug']) . '/') ?>"<?= $cOn ? ' aria-current="page"' : '' ?>><span class="wnav-gname"><?= e((string)$c['name']) ?></span><span class="wnav-n"><?= number_format((int)$c['n']) ?></span></a>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </nav>
    </div>
  </aside>
  <?php elseif ($hasLeft): /* v1 左栏原样保留 */ ?>
     ① 结构：分类（13 条，当前分类展开其词条 ≤30 条并高亮当前词条）——站点导航/标签/归档顶栏与页脚已有，不重复
     ② 默认紧凑：非当前分类只是一行分类名（不渲染条目）⇒ 左栏链接 390 → ≤49 条、页面体积腰斩
     ③ 硬上限：.wnav 高度受 max-height(≤330px) 约束 + 内部滚动 ⇒ 结构上不可能再出现「侧栏 ≫ 正文」
     ④ 零 JS：折叠/展开全部 <details> + CSS；「滚动到当前词条」沿用页面底部已有的那段原生 JS（选择器已同步改） */ ?>
  <aside class="leftnav wnav" aria-label="<?= e(__('ui.articles')) ?>">
    <div class="wnav-inner">
      <nav class="wnav-box" aria-label="<?= e(__('tt.mi_category')) ?>">
        <div class="wnav-cap"><?= e(__('tt.mi_category')) ?><a class="wnav-more" href="<?= e(($GLOBALS['YC_DOCS_TREE_LP'] ?? '') . '/category/') ?>">全部 →</a></div>
        <ul class="wnav-cats">
          <?php foreach (($GLOBALS['YC_DOCS_TREE'] ?? []) as $grp):
              $gOn    = !empty($grp['on']);
              $gItems = (array)($grp['items'] ?? []);
              $gName  = (string)($grp['name'] ?? '');
              $gN     = (int)($grp['n'] ?? count($gItems));
              $gUrl   = ($GLOBALS['YC_DOCS_TREE_LP'] ?? '') . '/category/' . rawurlencode((string)($grp['slug'] ?? '')) . '/';
              /* ★ 当前词条若不在该组样本里（几乎总是不在，见上面的说明），把它补到列表最前并高亮，
                 这样「左栏当前位置」在三种 URL 形态下都成立（/entry/{slug}、/entry/{slug}.html、/{slug}.html）。 */
              $curE = $GLOBALS['YC_WIKI_CURENTRY'] ?? null;
              if ($gOn && $curE && ($curSlug ?? '') !== '') {
                  $found = false;
                  foreach ($gItems as $__x) { if ((string)($__x['slug'] ?? '') === $curSlug) { $found = true; break; } }
                  if (!$found) { array_unshift($gItems, ['title' => (string)($curE['title'] ?? ''), 'slug' => $curSlug, 'on' => true, 'cur' => true]); }
              } ?>
          <li class="wnav-catli<?= $gOn ? ' on' : '' ?>">
            <?php if ($gOn && $gItems): ?>
            <details class="wnav-grp" open>
              <summary class="wnav-sum"><span class="wnav-gname"><?= e($gName) ?></span><span class="wnav-n"><?= $gN ?></span></summary>
              <ul class="wnav-items">
                <li class="wnav-allli"><a class="wnav-all" href="<?= e($gUrl) ?>">查看「<?= e($gName) ?>」全部 <?= $gN ?> 条 →</a></li>
                <?php foreach ($gItems as $it): $itOn = !empty($it['on']); ?>
                <li><a class="wnav-item<?= $itOn ? ' on' : '' ?><?= !empty($it['cur']) ? ' cur' : '' ?>" href="<?= e(($GLOBALS['YC_DOCS_TREE_LP'] ?? '') . chawiki_entry_url((string)$it['slug'])) ?>"<?= $itOn ? ' aria-current="page"' : '' ?>><?= e(Str::excerpt((string)$it['title'], 34)) ?></a></li>
                <?php endforeach; ?>
              </ul>
            </details>
            <?php else: ?>
            <a class="wnav-cat<?= $gOn ? ' on' : '' ?>" href="<?= e($gUrl) ?>"><span class="wnav-gname"><?= e($gName) ?></span><span class="wnav-n"><?= $gN ?></span></a>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
      </nav>

      <?php /* ★ 只保留「分类」一栏：站点导航（首页/分类总览/最新词条/独立页）顶栏已有、
              标签/归档页脚与抽屉已有 —— 左栏重复渲染既占高度又占体积。
              这样栏内自然高度 ≈ 13 分类 × 23px + 栏头 ≈ 325px，正好落在 330px 硬上限内。 */ ?>
    </div>
  </aside>
  <?php endif; ?>
  <main class="main"<?= $cwV2 ? ' id="main"' : '' ?>>
    <?= $main ?>
  </main>
  <?php if (!empty($side)): ?>
  <aside class="side">
    <?= $side ?>
  </aside>
  <?php endif; ?>
</div>

<footer class="site-footer"><div class="wrap">
  <nav class="foot-nav" aria-label="<?= e(__('ui.foot_nav')) ?>">
    <?php $footItems = array_values(array_filter(Nav::list('foot', Lang::short()), fn($x) => !in_array(($x['key'] ?? ''), ['links', 'about'], true))); ?>
    <?php foreach ($footItems as $fi => $ni): ?><?= $fi > 0 ? ' · ' : '' ?><a href="<?= e($ni['href']) ?>"><?= e($ni['label']) ?></a><?php endforeach; ?>
    <?php /* ★ P3：页脚补「站点地图（人读版）」入口（设计 §③-8「新增人读版站点地图」）。 */ ?>
    <?php if ($cwV2): ?> · <a href="<?= e($lpNav . '/pages') ?>">站点地图</a> · <a href="<?= e($lpNav . '/sitemap.xml') ?>">sitemap.xml</a><?php endif; ?>
  </nav>
  <span class="foot-copy"><span>© <?= date('Y') ?> <?= e(trim((string)get_option('site.name_en', '')) !== '' ? (string)get_option('site.name_en', '') : site_label()) ?></span> · <a href="https://yecha.org/" rel="noopener" target="_blank" title="YeCha Blog System">Powered by YeCha</a> · <a class="fc-wp" href="https://yecha.org/" rel="noopener" target="_blank" title="ChaWiki Theme">ChaWiki</a><?php $icp = trim((string)get_option('site.icp', '')); if ($icp !== ''): ?> · <a href="<?= e((string)get_option('site.icp_link', 'https://beian.miit.gov.cn/')) ?>" rel="noopener" target="_blank"><?= e($icp) ?></a><?php endif; ?></span>
  <p class="foot-note" style="max-width:var(--wide-w);margin:8px auto 0;color:var(--text-soft);font-size:12.5px;line-height:1.9;">茶百科 cha.wiki：以词条形式整理茶类、产区、工艺、器具、健康与茶史知识，全部正文服务端直出、可检索、可引用。</p>
</div></footer>

<!-- 右下 UX 工具栏（照搬参考站三件套 + 配色钮放最下） -->
<?php if (!empty($side)): ?>
<button type="button" class="toc-dock" id="tocDock" title="<?= e(__('ui.toc_collapse')) ?>" aria-label="<?= e(__('ui.toc_aria')) ?>" aria-expanded="true"><svg class="toc-dock-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"></path></svg></button>
<?php endif; ?>
<div class="ux-toolbar" role="group" aria-label="<?= e(__('ui.pal_title')) ?>">
  <button type="button" class="ux-tool ux-dark" id="darkToggle" title="<?= e(__('ui.dark_title')) ?>" aria-label="<?= e(__('ui.dark_title')) ?>">
    <svg class="sun" viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M12,18A6,6 0 0,1 6,12A6,6 0 0,1 12,6A6,6 0 0,1 18,12A6,6 0 0,1 12,18M12,2A1,1 0 0,0 11,3V5A1,1 0 0,0 13,5V3A1,1 0 0,0 12,2M17,7A1,1 0 0,0 18,6A1,1 0 0,0 17,5A1,1 0 0,0 16,6A1,1 0 0,0 17,7M21,11A1,1 0 0,0 22,12A1,1 0 0,0 21,13A1,1 0 0,0 20,12A1,1 0 0,0 21,11M17,17A1,1 0 0,0 18,18A1,1 0 0,0 17,19A1,1 0 0,0 16,18A1,1 0 0,0 17,17M12,22A1,1 0 0,0 13,21V19A1,1 0 0,0 11,19V21A1,1 0 0,0 12,22M7,17A1,1 0 0,0 6,18A1,1 0 0,0 7,19A1,1 0 0,0 8,18A1,1 0 0,0 7,17M3,11A1,1 0 0,0 2,12A1,1 0 0,0 3,13A1,1 0 0,0 4,12A1,1 0 0,0 3,11M7,7A1,1 0 0,0 6,6A1,1 0 0,0 7,5A1,1 0 0,0 8,6A1,1 0 0,0 7,7Z"/></svg>
    <svg class="moon" viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M17.75,4.09L15.22,6.03L16.13,9.09L13.5,7.28L10.87,9.09L11.78,6.03L9.25,4.09L12.44,4L13.5,1L14.56,4L17.75,4.09M18.97,15.95C19.8,15.87 20.69,17.05 20.16,17.8C19.84,18.25 19.5,18.67 19.08,19.07C15.17,23 8.84,23 4.94,19.07C1.03,15.17 1.03,8.83 4.94,4.93C5.34,4.53 5.76,4.17 6.21,3.85C6.96,3.32 8.14,4.21 8.06,5.04C7.79,7.9 8.75,10.87 10.95,13.06C13.14,15.26 16.1,16.22 18.97,15.95M17.33,17.97C14.5,17.81 11.7,16.64 9.53,14.5C7.36,12.31 6.2,9.5 6.04,6.68C3.23,9.82 3.34,14.4 6.35,17.41C9.37,20.43 14,20.54 17.33,17.97Z"/></svg>
  </button>
  <button type="button" class="ux-tool ux-font" id="fontToggle" title="<?= e(__('ui.font_title')) ?>" aria-label="<?= e(__('ui.font_title')) ?>">
    <svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M9 4v3h5v12h3V7h5V4H9zm-6 8h3v7h3v-7h3V9H3v3z"/></svg>
  </button>
  <button type="button" class="ux-tool ux-read" id="readToggle" title="<?= e(__('ui.read_title')) ?>" aria-label="<?= e(__('ui.read_title')) ?>">
    <svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M21 5c-1.11-.35-2.33-.5-3.5-.5-1.95 0-4.05.4-5.5 1.5-1.45-1.1-3.55-1.5-5.5-1.5S2.45 4.9 1 6v14.65c0 .25.25.5.5.5.1 0 .15-.05.25-.05C3.1 20.45 5.05 20 6.5 20c1.95 0 4.05.4 5.5 1.5 1.35-.85 3.8-1.5 5.5-1.5 1.65 0 3.35.3 4.75 1.05.1.05.15.05.25.05.25 0 .5-.25.5-.5V6c-.6-.45-1.25-.75-2-1zm0 13.5c-1.1-.35-2.3-.5-3.5-.5-1.7 0-4.15.65-5.5 1.5v-12c1.35-.85 3.8-1.5 5.5-1.5 1.2 0 2.4.15 3.5.5v11.5z"/></svg>
  </button>
  <button type="button" class="ux-tool ux-pal" id="palToggle" title="<?= e(__('ui.pal_title')) ?>" aria-label="<?= e(__('ui.pal_title')) ?>"></button>
</div>

<?php /* stats.foot 历史占位已移除 */ ?>
<script>
/* 左栏“wiki 导航”：打开时把当前词条条目滚动到左栏可视区域（只滚左栏，不滚动整页）
   ★ 选择器随 wiki 版式改名：.ln-item.on → .wnav-item.on（左栏内部可滚动，故必须自动定位）
   ★★★★ P3：v2 下**这段 JS 完全不输出**（设计 §③-5 J6 明确要求删掉它）——
   v2 左栏已经没有「当前词条条目」也不需要内部滚动（A2）；词条页更是没有左栏。 */
<?php if (!$cwV2): ?>
(function () {
  function locate() {
    var box = document.querySelector('.leftnav');
    var on = box && box.querySelector('.wnav-item.on');
    if (!box || !on) { return; }
    var need = on.offsetTop - box.offsetTop - box.clientHeight / 2 + on.clientHeight / 2;
    var max = box.scrollHeight - box.clientHeight;
    if (max > 0) { box.scrollTop = Math.max(0, Math.min(need, max)); }
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', locate); }
  else { locate(); }
  /* 稍后再校准一次（图片/字体加载后高度变化） */
  setTimeout(locate, 400);
})();
<?php endif; ?>
(function () {
  var d = document.documentElement, b = document.getElementById('darkToggle');
  if (b) b.addEventListener('click', function () {
    var on = d.classList.toggle('dark');
    try { localStorage.setItem('cw-dark', on ? '1' : '0'); } catch (e) {}
  });
  /* 右栏目录收起/展开（默认展开，同参考站不记忆） */
  var dock = document.getElementById('tocDock');
  if (dock) dock.addEventListener('click', function () {
    var off = document.body.classList.toggle('side-off');
    dock.setAttribute('aria-expanded', off ? 'false' : 'true');
  });
  /* 配色：单钮在 绿系(青绿) ↔ 暖纸(土黄) 间切换；按钮本身左土黄右青绿 */
  var pb = document.getElementById('palToggle');
  function palNow() { return d.getAttribute('data-pal') === 'warm' ? 'warm' : 'green'; }
  if (pb) pb.addEventListener('click', function () {
    d.setAttribute('data-pal', palNow() === 'warm' ? 'green' : 'warm');
    try { localStorage.setItem('cw-pal', palNow()); } catch (e) {}
  });
  /* 字体大小：小 / 中 / 大 循环 */
  var ft = document.getElementById('fontToggle');
  var FONT = ['15px', '16.5px', '18px'];
  function setFont(idx) {
    d.style.setProperty('--reading-font-size', FONT[idx]);
    try { localStorage.setItem('cw-font', String(idx)); } catch (e) {}
  }
  var fIdx = 1; try { fIdx = parseInt(localStorage.getItem('cw-font') || '1', 10); if (isNaN(fIdx)) fIdx = 1; } catch (e) {}
  setFont(fIdx);
  if (ft) ft.addEventListener('click', function () { fIdx = (fIdx + 1) % FONT.length; setFont(fIdx); });
  /* 阅读模式：正文铺满、隐藏左右栏 */
  var rt = document.getElementById('readToggle');
  function setRead(on) {
    document.body.classList.toggle('reading-mode', on);
    try { localStorage.setItem('cw-reading', on ? '1' : '0'); } catch (e) {}
  }
  try { setRead(localStorage.getItem('cw-reading') === '1'); } catch (e) {}
  if (rt) rt.addEventListener('click', function () {
    setRead(!document.body.classList.contains('reading-mode'));
  });
})();
(function () {
  var lb = document.getElementById('langBtn'), lm = document.getElementById('langMenu');
  if (!lb || !lm) return;
  function setLang(open) { lm.hidden = !open; lb.setAttribute('aria-expanded', open ? 'true' : 'false'); }
  lb.addEventListener('click', function (ev) { ev.stopPropagation(); setLang(lm.hidden); });
  document.addEventListener('click', function () { setLang(false); });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { setLang(false); } });
})();
(function () {
  var nt = document.getElementById('navToggle'), dr = document.getElementById('drawer'), dc = document.getElementById('drawerClose');
  if (!nt || !dr) return;
  function setDrawer(open) {
    dr.classList.toggle('open', open);
    nt.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.style.overflow = open ? 'hidden' : '';
  }
  nt.addEventListener('click', function () { setDrawer(!dr.classList.contains('open')); });
  if (dc) { dc.addEventListener('click', function () { setDrawer(false); }); }
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { setDrawer(false); } });
  window.addEventListener('resize', function () { if (window.innerWidth > 900) { setDrawer(false); } });
})();
document.addEventListener('error', function (e) {
  var t = e.target;
  if (t && t.tagName === 'IMG') {
    var s = document.createElement('span');
    s.className = 'img-miss';
    s.textContent = t.getAttribute('alt') || '<?= __('ui.img_miss') ?>';
    t.replaceWith(s);
  }
}, true);
/* 代码块右上角复制按钮 */
(function () {
  var C = <?= json_encode(['c' => __('ui.copy'), 'ok' => __('ui.copied'), 'fail' => __('ui.copy_fail'), 'aria' => __('ui.copy_aria')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  function init() {
    var pres = document.querySelectorAll('#articleBody pre');
    if (!pres.length) return;
    pres.forEach(function (pre) {
      if (pre.querySelector('.code-copy')) return;
      if (pre.closest('.no-copy')) return;
      var raw = pre.innerText.replace(/\n$/, '');
      pre.setAttribute('data-copy', raw);
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'code-copy';
      btn.textContent = C.c;
      btn.setAttribute('aria-label', C.aria);
      btn.addEventListener('click', function () {
        var text = pre.getAttribute('data-copy') || raw;
        function done(ok) {
          btn.textContent = ok ? C.ok : C.fail;
          btn.classList.toggle('ok', ok);
          setTimeout(function () { btn.textContent = C.c; btn.classList.remove('ok'); }, 1600);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(function () { done(true); }, function () { fallback(); });
        } else { fallback(); }
        function fallback() {
          try {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;left:-9999px;top:0';
            document.body.appendChild(ta);
            ta.select();
            done(document.execCommand('copy'));
            ta.remove();
          } catch (e) { done(false); }
        }
      });
      pre.appendChild(btn);
      pre.classList.add('has-copy');
    });
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
</script>
<?php $footStat = stat_html('stats.foot'); if ($footStat !== '') { echo "\n<!-- stats.foot -->\n" . $footStat; } ?>
<?php /* ★ StatX 采集标签（站长已批 ⒟③）：站点 key = cha.wiki，端点 tj.51puer.com。
   ★ 注入点=主题 layout（**不用 nginx sub_filter**）；回滚 = 删掉下面 statx:begin/end 这一块。
   ★ 版本串 ?v= 与 sites 表里其它站一致（4e088c0b）；改造脚本/端点后由 statx 侧统一换。
   ★ 前置确认：本实例已无任何旧 tj 像素（0 处；取证见报告 ⒟③）。 */ ?>
<!-- statx:begin -->
<script async src="https://tj.51puer.com/st.js?v=4e088c0b" data-site="cha.wiki" data-ep="https://tj.51puer.com/st"></script>
<!-- statx:end -->
<script src="<?= e(View::asset('chawiki.js')) ?>" defer></script>
</body>
</html>
