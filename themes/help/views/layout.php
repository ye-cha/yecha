<?php
/** layout：整页外壳（帮助 Help v2.3 · 在线帮助中心形态：顶栏 + 左文档目录 + 主区）
 *  变量：$title, $page_title, $main, $sub(可选), $htmlLang
 *  手维文件，勿用 gen_parts.py 覆盖。 */
?><!DOCTYPE html>
<html lang="<?= e((string)($htmlLang ?? 'zh-CN')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?></title>
<?php
// favicon 跟随"站点 Logo"（后台上传/URL 设置；无则用主题默认）
$favRel = trim((string)get_option('site.logo', ''));
if ($favRel === '' || !is_file(dirname(__DIR__, 3) . $favRel)) { $favRel = '/themes/help/logo-oni.png'; }
$favAbs = dirname(__DIR__, 3) . $favRel;
$favSrc = $favRel . (is_file($favAbs) ? '?v=' . filemtime($favAbs) : '');
?>
<link rel="icon" type="image/png" href="<?= e($favSrc) ?>">
<link rel="apple-touch-icon" href="<?= e($favSrc) ?>">
<link rel="stylesheet" href="<?= e(View::asset('style.css')) ?>">
<?php
$tw = (string)theme_opt('width', '');
$tac = (string)theme_opt('accent', '');
if ($tw !== '' || ($tac !== '' && preg_match('/^#[0-9a-fA-F]{3,8}$/', $tac))) {
    echo '<style>:root{' . ($tw !== '' ? '--wide-w:' . (int)$tw . 'px;' : '')
        . (preg_match('/^#[0-9a-fA-F]{3,8}$/', $tac) ? '--accent:' . $tac . ';' : '') . '}</style>';
}
?>
<?php $headStat = (string)get_option('stats.head', ''); if ($headStat !== '') { echo "\n<!-- stats.head -->\n" . $headStat; } ?>
<?php
// canonical + hreflang：仅当 index.php 判明"该内容语言集"后输出（文章/页面，且只列真有 publish 译文的语言）
if (!empty($GLOBALS['YC_CANONICAL'])) {
    echo "\n" . '<link rel="canonical" href="' . e($GLOBALS['YC_CANONICAL']) . '" />';
}
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
    if ($xdef !== null) {
        echo "\n" . '<link rel="alternate" hreflang="x-default" href="' . e($origin . $xdef) . '" />';
    }
}
if (!empty($GLOBALS['YC_META'])) {
    $ym = $GLOBALS['YC_META'];
    if (!empty($ym['description'])) {
        echo "\n" . '<meta name="description" content="' . e($ym['description']) . '">';
    }
    if (!empty($ym['keywords'])) {
        echo "\n" . '<meta name="keywords" content="' . e($ym['keywords']) . '">';
    }
}
?>
<noscript><style>
  /* 无 JS：搜索浮层常显为顶栏下方的静态搜索条（SSR 优先） */
  .suj-searchpanel,.suj-searchpanel[hidden]{display:block!important;position:static!important;background:var(--suj-nav,#fff);border-bottom:1px solid var(--suj-line,#d4dadf)}
  .suj-searchpanel .ssp-mask{display:none!important}
  .suj-searchpanel .ssp-form{position:static!important;box-shadow:none!important;border:0!important;background:transparent!important;max-width:1180px}
  .suj-searchpanel .ssp-x{display:none!important}
</style></noscript>
</head>
<body class="th-help th-sujian">
<nav class="suj-navbar" id="sujNavbar">
  <div class="suj-nav-inner">
    <button type="button" class="suj-iconbtn suj-burger" id="sujSidebarToggle" aria-label="<?= e(__('ui.menu')) ?>" aria-expanded="false">
      <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16"></path><path d="M4 12h16"></path><path d="M4 19h16"></path></svg>
    </button>
    <a class="suj-nav-brand" href="<?= e((Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() . '/' : '/') ?>" title="<?= e(site_label()) ?>" aria-label="<?= e(site_label()) ?>">
      <?php
      $logoRel = trim((string)get_option('site.logo', '/themes/help/logo.svg'));
      $logoAbs = dirname(__DIR__, 3) . $logoRel;
      if ($logoRel !== '' && is_file($logoAbs)): ?>
      <img class="suj-nav-logo" src="<?= e($logoRel . '?v=' . filemtime($logoAbs)) ?>" alt="<?= e(site_label()) ?>">
      <?php endif; ?>
      <span class="suj-nav-text"><?= e(site_label()) ?></span>
    </a>
    <?= View::render('partials/nav') ?>
    <div class="suj-nav-tools">
      <button type="button" class="suj-iconbtn suj-search-btn" id="sujSearchBtn" aria-haspopup="true" aria-expanded="false" aria-label="<?= e(__('ui.search')) ?>" title="<?= e(__('ui.search')) ?>">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
      </button>
      <?php if (Poly::on()): ?>
      <button type="button" class="suj-iconbtn" id="langBtn" aria-haspopup="true" aria-expanded="false" aria-label="<?= e(__('ui.lang_cap')) ?>" title="<?= e(__('ui.lang_cap')) ?>">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 8 6 6"></path><path d="m4 14 6-6 2-3"></path><path d="M2 5h12"></path><path d="M7 2h1"></path><path d="m22 22-5-10-5 10"></path><path d="M14 18h6"></path></svg>
      </button>
      <div class="suj-lang-menu" id="langMenu" hidden>
        <?php foreach (Poly::enabled() as $lc): ?>
        <a href="<?= e(ui_lang_url($lc)) ?>"<?= $lc === Lang::short() ? ' class="cur"' : '' ?>><?= e(Poly::label($lc)) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <button type="button" class="suj-iconbtn" id="darkToggle" aria-label="<?= e(__('ui.dark_title')) ?>" title="<?= e(__('ui.dark_title')) ?>">
        <span class="ico-moon"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.985 12.486a9 9 0 1 1-9.473-9.472c.405-.022.617.46.402.803a6 6 0 0 0 8.268 8.268c.344-.215.825-.004.803.401"/></svg></span>
        <span class="ico-sun" hidden><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2"></path><path d="M12 20v2"></path><path d="m4.93 4.93 1.41 1.41"></path><path d="m17.66 17.66 1.41 1.41"></path><path d="M2 12h2"></path><path d="M20 12h2"></path><path d="m6.34 17.66-1.41 1.41"></path><path d="m19.07 4.93-1.41 1.41"></path></svg></span>
      </button>
      <button type="button" class="suj-iconbtn suj-burger" id="sujSidebarToggle" aria-label="<?= e(__('ui.menu')) ?>" aria-expanded="false">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16"></path><path d="M4 12h16"></path><path d="M4 19h16"></path></svg>
      </button>
      <?php if (\Auth::check()): ?>
        <a class="suj-btn-login" href="<?= e(ui_user()) ?>" title="<?= __('ui.my') ?>"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="5"></circle><path d="M20 21a8 8 0 0 0-16 0"></path></svg></a>
        <?php if (\Auth::isAdmin()): ?><a class="suj-mini" href="/admin/"><?= __('ui.admin') ?></a><?php endif; ?>
        <a class="suj-mini" href="/logout"><?= __('ui.logout') ?></a>
      <?php else: ?>
        <a class="suj-btn-login" href="<?= e(ui_login()) ?>" title="<?= __('ui.login') ?>"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="5"></circle><path d="M20 21a8 8 0 0 0-16 0"></path></svg></a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<?php
/* 搜索浮层：必须是 body 直接子级 —— 顶栏 border-bottom 与 .suj-navbar 会成为 fixed 包含块（清单 ⑦）。 */
$__slp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
/* 侧栏菜单（doku menu-list level 2：分类→文章） */
$__sbSticky = ((string)get_option('theme.sidebar_mode', 'flow') === 'sticky');
$__sb = (function () {
    $cats = $latest = [];
    try { $cats = Front::sidebar()['cats'] ?? []; } catch (\Throwable $e) {}
    try { $latest = Poly::publishedList(Lang::short(), 8); } catch (\Throwable $e) {}
    return ['cats' => $cats, 'latest' => $latest];
})();
?>
<div class="suj-searchpanel" id="sujSearchPanel" hidden>
  <div class="ssp-mask" data-ssp-close></div>
  <form class="ssp-form" method="get" action="<?= e($__slp . '/search') ?>" role="search">
    <svg class="ssp-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
    <input type="search" name="q" id="sujSearchInput" placeholder="<?= e(__('sb.search_ph')) ?>" aria-label="<?= e(__('sb.search_aria')) ?>" autocomplete="off">
    <button type="submit" class="ssp-go"><?= e(__('ui.search')) ?></button>
    <button type="button" class="ssp-x" id="sujSearchClose" aria-label="Close" title="Close">
      <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg>
    </button>
  </form>
</div>

<div class="suj-container">
  <aside class="suj-sidebar<?= $__sbSticky ? ' is-sticky' : '' ?>" id="sujSidebar" aria-label="目录导航">
    <div class="suj-sidebar-content">
      <?php
      // 窄屏侧栏顶部导航（doku .menu-list.is-hidden-desktop）
      $sujNavItems = $GLOBALS['yc_help_nav'] ?? [];
      ?>
      <?php if (!empty($sujNavItems)): ?>
      <ul class="suj-menu-list suj-mobile-nav">
        <?php foreach ($sujNavItems as $ni): ?>
        <li><a href="<?= e($ni['href']) ?>" class="<?= e(($ni['active'] ?? false) ? 'on' : '') ?>"><?= e($ni['label']) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <hr class="suj-hidden-desktop">
      <?php endif; ?>
      <?php if (!empty($__sb['cats'])): ?>
      <p class="suj-menu-label"><?= e(__('nav.category')) ?></p>
      <ul class="suj-menu-list">
        <?php foreach ($__sb['cats'] as $cat): ?>
        <li><a href="<?= e(Poly::termUrl('category', $cat)) ?>"><?= e(Poly::termName($cat)) ?><span class="suj-cnt"><?= (int)($cat['count'] ?? 0) ?></span></a>
          <?php
          // 该分类下的最新文章（doku menu-list level 2：嵌套子菜单）
          $catSlug = (string)($cat['slug'] ?? '');
          $kids = [];
          if ($catSlug !== '') {
              try { $kids = Front::metaList('category', $catSlug, 1)['posts'] ?? []; } catch (\Throwable $e) {}
              $kids = array_slice((array)$kids, 0, 6);
          }
          ?>
          <?php if (!empty($kids)): ?>
          <ul class="suj-menu-list suj-children">
            <?php foreach ($kids as $kp): ?>
            <li><a href="<?= e(ui_lp() . rawurlencode((string)$kp['slug']) . '.html') ?>"><?= e(Str::excerpt((string)($kp['title'] ?? ''), 28)) ?></a></li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>

    </div>
  </aside>
  <main class="suj-main" id="sujMain">
    <?= $main ?>
    <?= View::render('partials/footer') ?>
  </main>
</div>

<div class="suj-drawer" id="sujDrawer" hidden>
  <div class="suj-drawer-mask" data-drawer-close></div>
  <div class="suj-drawer-in">
    <button type="button" class="suj-drawer-close" id="sujDrawerClose" aria-label="Close">
      <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg>
    </button>
    <div class="suj-drawer-nav">
      <?php if (!empty($__sb['cats'])): ?>
      <p class="suj-menu-label"><?= e(__('nav.category')) ?></p>
      <ul class="suj-menu-list">
        <?php foreach ($__sb['cats'] as $cat): ?>
        <li><a href="<?= e(Poly::termUrl('category', $cat)) ?>"><?= e(Poly::termName($cat)) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
    <div class="suj-drawer-foot">
      <a href="<?= e($__slp . '/search') ?>"><?= e(__('ui.search')) ?></a>
    </div>
  </div>
</div>

<?php $footStat = (string)get_option('stats.foot', ''); if ($footStat !== '') { echo "\n<!-- stats.foot -->\n" . $footStat; } ?>
<script>
(function () {
  var d = document.documentElement, b = document.getElementById('darkToggle');
  function apply(on){ d.classList.toggle('dark', on); }
  var saved = null;
  try { saved = localStorage.getItem('yecha-dark'); } catch (e) {}
  if (saved === null) { apply(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches); }
  else { apply(saved === '1'); }
  if (b) b.addEventListener('click', function () {
    var on = d.classList.toggle('dark');
    try { localStorage.setItem('yecha-dark', on ? '1' : '0'); } catch (e) {}
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
  var sb = document.getElementById('sujSearchBtn'), sp = document.getElementById('sujSearchPanel'),
      sc = document.getElementById('sujSearchClose'), si = document.getElementById('sujSearchInput');
  if (!sb || !sp) return;
  function setSearch(open) {
    sp.hidden = !open;
    sb.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open && si) { try { si.focus(); } catch (e) {} }
  }
  sb.addEventListener('click', function (ev) { ev.stopPropagation(); setSearch(sp.hidden); });
  if (sc) { sc.addEventListener('click', function () { setSearch(false); }); }
  sp.addEventListener('click', function (ev) {
    var t = ev.target;
    if (t === sp || (t && t.getAttribute && t.getAttribute('data-ssp-close') !== null)) { setSearch(false); }
  });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { setSearch(false); } });
})();
(function () {
  var tb = document.getElementById('sujSidebarToggle'), sb = document.getElementById('sujSidebar'),
      dr = document.getElementById('sujDrawer'), dc = document.getElementById('sujDrawerClose');
  if (!tb || !dr) return;
  function setDrawer(open) {
    dr.hidden = !open;
    tb.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.style.overflow = open ? 'hidden' : '';
  }
  tb.addEventListener('click', function () { setDrawer(dr.hidden); });
  if (dc) { dc.addEventListener('click', function () { setDrawer(false); }); }
  dr.addEventListener('click', function (ev) {
    var t = ev.target;
    if (t === dr || (t && t.getAttribute && t.getAttribute('data-drawer-close') !== null)) { setDrawer(false); }
  });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { setDrawer(false); } });
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
</script>
</body>
</html>