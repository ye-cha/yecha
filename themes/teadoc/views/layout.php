<?php
/** xuecha theme · layout：整页外壳；变量：$title, $page_title, $main, $sub(可选), $htmlLang
 *  双色系：html[data-pal=green|warm] × html.dark（右下角切换，localStorage 记忆，默认绿系）。
 */
$pal = (string)theme_opt('palette', 'green'); if (!in_array($pal, ['green', 'warm'], true)) { $pal = 'green'; }
?><!DOCTYPE html>
<html lang="<?= e((string)($htmlLang ?? 'zh-CN')) ?>" dir="<?= e(ui_dir()) ?>" data-pal="<?= e($pal) ?>">
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
    var p = q.get('pal') || localStorage.getItem('xc-pal');
    if (p === 'green' || p === 'warm') { d.setAttribute('data-pal', p); }
    var k = q.has('dark') ? q.get('dark') : localStorage.getItem('xc-dark');
    if (k === '1') { d.classList.add('dark'); }
    else if (k !== '0' && k === null && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) { d.classList.add('dark'); }
  } catch (e) {}
})();
</script>
<?php
$favRel = trim((string)get_option('site.logo', ''));
if ($favRel === '' || !is_file(dirname(__DIR__, 3) . $favRel)) { $favRel = '/themes/teadoc/logo.png'; }
$favAbs = dirname(__DIR__, 3) . $favRel;
$favSrc = $favRel . (is_file($favAbs) ? '?v=' . filemtime($favAbs) : '');
?>
<link rel="icon" type="image/png" href="<?= e($favSrc) ?>">
<link rel="apple-touch-icon" href="<?= e($favSrc) ?>">
<link rel="alternate" type="application/rss+xml" title="RSS" href="<?= e(ui_lp() . '/feed') ?>">
<link rel="stylesheet" href="<?= e(View::asset('style.css')) ?>">
<?php
$tw = (string)theme_opt('width', '');
if ($tw !== '') { echo '<style>:root{--wide-w:' . (int)$tw . 'px}</style>'; }
$headStat = stat_html('stats.head'); if ($headStat !== '') { echo "\n<!-- stats.head -->\n" . $headStat; }
if (!empty($GLOBALS['YC_CANONICAL'])) { echo "\n" . '<link rel="canonical" href="' . e($GLOBALS['YC_CANONICAL']) . '" />'; }
if (!empty($GLOBALS['YC_HREFLANG']) && is_array($GLOBALS['YC_HREFLANG'])) {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8082';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
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
?>
</head>
<body>
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
      $logoRel2 = trim((string)get_option('site.logo', '/themes/teadoc/logo.png'));
      $logoAbs2 = dirname(__DIR__, 3) . $logoRel2;
      if ($logoRel2 !== '' && is_file($logoAbs2)): ?>
      <img class="docs-brand-logo" src="<?= e($logoRel2 . '?v=' . filemtime($logoAbs2)) ?>" alt="logo">
      <?php endif; ?>
      <span class="docs-brand-name"><?= e(site_label()) ?></span>
    </a>
    <nav class="docs-nav" aria-label="<?= e(__('ui.main_nav')) ?>">
      <a href="<?= e($homeHref) ?>"<?= $isHomePg ? ' class="active"' : '' ?>><?= e(__('nav.home')) ?></a>
      <?php foreach ($topPages as $tp):
          $pid = (int)$tp['id'];
          $tpLabel = isset($polyPageTitles[$pid]) && $polyPageTitles[$pid] !== '' ? $polyPageTitles[$pid] : (string)$tp['title'];
          $tpSlug = (string)$tp['slug'];
          $tpHref = ($polyOn && $curLangShort !== $defaultLang) ? '/' . $curLangShort . '/' . rawurlencode($tpSlug) . '.html' : '/' . rawurlencode($tpSlug) . '.html';
          $tpPath = trim(parse_url($tpHref, PHP_URL_PATH) ?? $tpHref, '/'); ?>
      <a href="<?= e($tpHref) ?>"<?= $curPath === $tpPath ? ' class="active"' : '' ?>><?= e($tpLabel) ?></a>
      <?php endforeach; ?>
    </nav>
    <form class="docs-search" action="<?= e(ui_lp() . '/search') ?>" method="get" role="search">
      <input type="search" name="q" placeholder="<?= e(__('ui.search')) ?>…" aria-label="<?= e(__('ui.search')) ?>">
      <button type="submit" aria-label="<?= e(__('ui.search')) ?>">🔍</button>
    </form>
    <?php if (Poly::on() && count(Poly::enabled()) > 1): ?>
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
    <button type="button" class="icon-btn nav-toggle" id="navToggle" aria-label="<?= e(__('ui.menu')) ?>" aria-expanded="false">
      <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16"></path><path d="M4 12h16"></path><path d="M4 19h16"></path></svg>
    </button>
  </div>
</header>
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
        $drLinks[] = ['label' => $dlT, 'href' => $dlp . '/' . rawurlencode((string)$tp['slug']) . '.html'];
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

<?php
/* 左栏“全部文章导航”：除首页与功能页外所有页面（文章/独立页/栏目/搜索/归档…）三栏统一
   多语：当前语言下分组标题取 names_i18n、文章标题取该语言已发布译文；链接带 /{lang}/ 前缀 */
$hasLeft = false;
if (empty($GLOBALS['YC_HOME_HERO'])) {
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
        $curSlug = preg_replace('#\.html$#', '', $first) ?: '';
        $leftTree = [];
        $useLangTitles = $polyOn && $activeLang !== $defaultLang;
        $lcats = Db::rows('SELECT m.id, m.name, m.names_i18n, m.slug FROM ' . Db::t('metas') . " m WHERE m.type='category' AND m.count>0 ORDER BY m.`order` ASC, m.id ASC LIMIT 30");
        foreach ($lcats as $lc) {
            $lrows = Db::rows(
                'SELECT c.id, c.slug, c.title FROM ' . Db::t('relationships') . ' r JOIN ' . Db::t('contents') . ' c ON c.id=r.content_id '
                . "WHERE r.meta_id=? AND c.type='post' AND c.status='publish' AND c.deleted_at IS NULL ORDER BY c.published_at ASC, c.id ASC LIMIT 120",
                [(int)$lc['id']]
            );
            if ($lrows === []) { continue; }
            $items = [];
            $polyTitles = [];
            if ($useLangTitles) {
                $cids = array_column($lrows, 'id');
                $in = implode(',', array_fill(0, count($cids), '?'));
                foreach (Db::rows('SELECT group_id, title, status FROM ' . Poly::table() . " WHERE lang=? AND group_id IN ($in)", array_merge([$activeLang], array_map('intval', $cids))) as $pt) {
                    if (($pt['status'] ?? '') === 'publish') { $polyTitles[(int)$pt['group_id']] = (string)$pt['title']; }
                }
            }
            foreach ($lrows as $lr) {
                $title = $useLangTitles && isset($polyTitles[(int)$lr['id']]) ? $polyTitles[(int)$lr['id']] : (string)$lr['title'];
                $items[] = ['title' => $title, 'slug' => (string)$lr['slug'], 'on' => (string)$lr['slug'] === $curSlug];
            }
            $grpOn = false;
            foreach ($items as $it) { if (!empty($it['on'])) { $grpOn = true; break; } }
            $catName = (string)$lc['name'];
            if ($useLangTitles) { $cn = Poly::termLang($lc, $activeLang); if ($cn !== '') { $catName = $cn; } }
            $leftTree[] = ['name' => $catName, 'slug' => (string)$lc['slug'], 'on' => $grpOn, 'items' => $items];
        }
        $GLOBALS['YC_DOCS_TREE'] = $leftTree;
        $GLOBALS['YC_DOCS_TREE_LP'] = $langPrefix;
    }
}
?>
<div class="layout<?= empty($side) ? ' no-side' : '' ?><?= !empty($tocSide) ? ' toc-rail' : '' ?><?= (!empty($tocSide) && !empty($sbSticky)) ? ' sb-sticky' : '' ?><?= !empty($GLOBALS['YC_HOME_HERO']) ? ' is-home' : '' ?><?= $hasLeft ? ' has-left' : '' ?><?= ($hasLeft && !empty($side)) ? ' has-right' : '' ?>">
  <?php if ($hasLeft): ?>
  <aside class="leftnav" aria-label="<?= e(__('ui.articles')) ?>">
    <div class="ln-panel">
      <div class="ln-title"><svg class="ln-ico ln-bookopen" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg><span><?= e(__('ui.articles')) ?></span> <a href="<?= e(($GLOBALS['YC_DOCS_TREE_LP'] ?? '') . '/category/') ?>"><?= e(__('tt.mi_category')) ?> →</a></div>
      <?php foreach (($GLOBALS['YC_DOCS_TREE'] ?? []) as $grp): ?>
      <div class="ln-group">
        <span class="ln-cap<?= !empty($grp['on']) ? ' on' : '' ?>"><span class="ln-cap-ic"><svg class="ln-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg></span><span class="ln-cap-tx"><?= e((string)$grp['name']) ?></span><span class="n"><?= (int)count($grp['items']) ?></span></span>
        <?php foreach ($grp['items'] as $it): ?>
        <a class="ln-item<?= !empty($it['on']) ? ' on' : '' ?>" href="<?= e(($GLOBALS['YC_DOCS_TREE_LP'] ?? '') . '/' . rawurlencode((string)$it['slug']) . '.html') ?>"><svg class="ln-ico ln-doc" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v4a2 2 0 0 0 2 2h4"></path><path d="M16 13H8"></path><path d="M16 17H8"></path></svg><span><?= e(Str::excerpt((string)$it['title'], 30)) ?></span></a>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </aside>
  <?php endif; ?>
  <main class="main">
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
  </nav>
  <span class="foot-copy"><span>© <?= date('Y') ?> <?= e(trim((string)get_option('site.name_en', '')) !== '' ? (string)get_option('site.name_en', '') : site_label()) ?></span> · <a href="https://yecha.org/" rel="noopener" target="_blank" title="YeCha Blog System">Powered by YeCha</a> · <a class="fc-wp" href="https://yecha.org/teadoc.html" rel="noopener" target="_blank" title="TeaDoc Theme">TeaDoc</a><?php $icp = trim((string)get_option('site.icp', '')); if ($icp !== ''): ?> · <a href="<?= e((string)get_option('site.icp_link', 'https://beian.miit.gov.cn/')) ?>" rel="noopener" target="_blank"><?= e($icp) ?></a><?php endif; ?></span>
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
/* 左栏“全部文章”：打开时把当前文章条目滚动到左栏可视区域（不滚动整页） */
(function () {
  function locate() {
    var box = document.querySelector('.leftnav');
    var on = box && box.querySelector('.ln-item.on');
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
(function () {
  var d = document.documentElement, b = document.getElementById('darkToggle');
  if (b) b.addEventListener('click', function () {
    var on = d.classList.toggle('dark');
    try { localStorage.setItem('xc-dark', on ? '1' : '0'); } catch (e) {}
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
    try { localStorage.setItem('xc-pal', palNow()); } catch (e) {}
  });
  /* 字体大小：小 / 中 / 大 循环 */
  var ft = document.getElementById('fontToggle');
  var FONT = ['15px', '16.5px', '18px'];
  function setFont(idx) {
    d.style.setProperty('--reading-font-size', FONT[idx]);
    try { localStorage.setItem('xc-font', String(idx)); } catch (e) {}
  }
  var fIdx = 1; try { fIdx = parseInt(localStorage.getItem('xc-font') || '1', 10); if (isNaN(fIdx)) fIdx = 1; } catch (e) {}
  setFont(fIdx);
  if (ft) ft.addEventListener('click', function () { fIdx = (fIdx + 1) % FONT.length; setFont(fIdx); });
  /* 阅读模式：正文铺满、隐藏左右栏 */
  var rt = document.getElementById('readToggle');
  function setRead(on) {
    document.body.classList.toggle('reading-mode', on);
    try { localStorage.setItem('xc-reading', on ? '1' : '0'); } catch (e) {}
  }
  try { setRead(localStorage.getItem('xc-reading') === '1'); } catch (e) {}
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
</body>
</html>
