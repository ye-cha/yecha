<?php
/** layout：整页外壳；变量：$title, $page_title, $main, $sub(可选), $htmlLang
 *  v2.1 anshumo「三栏形态」：左固定品牌栏(hs-left) + 顶栏工具条(hs-topbar，不吸顶)
 *  + 主区 layout-grid（内容 1fr + 右栏 264px）。≤1100px 左栏隐藏，用顶部 drawer。 */
$__lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
$__homeHref = $__lp === '' ? '/' : $__lp . '/';
/* 左栏品牌数据 */
$__logoRel = trim((string)get_option('site.logo', '/themes/xinlv/logo.svg'));
if ($__logoRel === '' || !is_file(dirname(__DIR__, 3) . $__logoRel)) { $__logoRel = '/themes/xinlv/logo-oni.png'; }
$__tagline = trim((string)get_option('site.subtitle', ''));
/* 左栏统计（文章/分类/标签） */
$__stPost = 0; $__stCat = 0; $__stTag = 0;
try {
    if (class_exists('Db', false)) {
        $__stPost = (int)Db::one("SELECT COUNT(*) FROM " . Db::t('contents') . " WHERE type='post' AND status='publish' AND deleted_at IS NULL");
        $__stCat  = (int)Db::one("SELECT COUNT(*) FROM " . Db::t('metas') . " WHERE type='category'");
        $__stTag  = (int)Db::one("SELECT COUNT(*) FROM " . Db::t('metas') . " WHERE type='tag'");
    }
} catch (\Throwable $e) { }
?><!DOCTYPE html>
<html lang="<?= e((string)($htmlLang ?? 'zh-CN')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?></title>
<?php
// favicon 跟随“站点 Logo”（后台上传/URL 设置；无则用主题默认）
$favSrc = $__logoRel . '?v=' . (is_file(dirname(__DIR__, 3) . $__logoRel) ? filemtime(dirname(__DIR__, 3) . $__logoRel) : 0);
?>
<link rel="icon" type="image/png" href="<?= e($__logoRel . '?v=2') ?>">
<link rel="apple-touch-icon" href="<?= e($__logoRel . '?v=2') ?>">
<link rel="stylesheet" href="<?= e(View::asset('style.css')) ?>">
<?php /* 目标站 kwa.cc 字体：霞鹜文楷（LXGW WenKai Screen）+ MiSans 回退；异步加载不阻塞渲染 */ ?>
<link rel="stylesheet" href="https://fastly.jsdelivr.net/npm/lxgw-wenkai-screen-webfont@1.7.0/style.css" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fastly.jsdelivr.net/npm/lxgw-wenkai-screen-webfont@1.7.0/style.css"></noscript>
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
// canonical + hreflang：仅当 index.php 判明“该内容语言集”后输出（文章/页面，且只列真有 publish 译文的语言）
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
</head>
<body class="th-xinlv hs-mode">

<!-- 左侧固定品牌栏（≤1100px 隐藏，走顶部 drawer） -->
<aside class="hs-left">
  <a class="hs-left-brand" href="<?= e($__homeHref) ?>" title="<?= e(site_label()) ?>">
    <span class="hs-left-avatar"><img src="<?= e($__logoRel) ?>" alt="<?= e(site_label()) ?>"></span>
    <span class="hs-left-name"><?= e(site_label()) ?></span>
    <?php if ($__tagline !== ''): ?><span class="hs-left-tagline"><?= e($__tagline) ?></span><?php endif; ?>
  </a>
  <div class="hs-left-stats">
    <a href="<?= e($__lp . '/archives') ?>"><b><?= $__stPost ?></b><span>文章</span></a>
    <a href="<?= e($__lp . '/category/') ?>"><b><?= $__stCat ?></b><span>分类</span></a>
    <a href="<?= e($__lp . '/tag/') ?>"><b><?= $__stTag ?></b><span>标签</span></a>
  </div>
  <?= View::render('partials/nav') ?>
  <div class="hs-left-tools">
    <a href="<?= e($__lp . '/feed') ?>" title="RSS" aria-label="RSS"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 11a9 9 0 0 1 9 9"></path><path d="M4 4a16 16 0 0 1 16 16"></path><circle cx="5" cy="19" r="1"></circle></svg></a>
    <a href="<?= e($__lp . '/archives') ?>" title="<?= __('nav.archive') ?>" aria-label="<?= __('nav.archive') ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="5" rx="1"></rect><path d="M4 9v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9"></path><path d="M10 13h4"></path></svg></a>
  </div>
</aside>

<!-- 顶栏工具条（不吸顶） -->
<header class="hs-topbar">
  <form class="hs-search" action="<?= e($__lp . '/search') ?>" method="get" role="search">
    <input type="search" name="q" placeholder="<?= e(__('sb.search_ph')) ?>" aria-label="<?= e(__('sb.search_aria')) ?>">
    <button type="submit" aria-label="搜索"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg></button>
  </form>
  <span class="hs-spacer"></span>
  <div class="hd-tools">
    <?php if (Poly::on()): ?>
    <div class="lang-sw">
      <button type="button" class="icon-btn" id="langBtn" aria-haspopup="true" aria-expanded="false" aria-label="<?= e(__('ui.lang_cap')) ?>" title="<?= e(__('ui.lang_cap')) ?>">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 8 6 6"></path><path d="m4 14 6-6 2-3"></path><path d="M2 5h12"></path><path d="M7 2h1"></path><path d="m22 22-5-10-5 10"></path><path d="M14 18h6"></path></svg>
      </button>
      <div class="lang-menu" id="langMenu" hidden>
        <?php foreach (Poly::enabled() as $lc): ?>
        <a href="<?= e(ui_lang_url($lc)) ?>"<?= $lc === Lang::short() ? ' class="cur"' : '' ?>><?= e(Poly::label($lc)) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <button type="button" class="icon-btn dark-toggle" id="darkToggle" aria-label="<?= e(__('ui.dark_title')) ?>" title="<?= e(__('ui.dark_title')) ?>">
      <span class="ico-moon"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.985 12.486a9 9 0 1 1-9.473-9.472c.405-.022.617.46.402.803a6 6 0 0 0 8.268 8.268c.344-.215.825-.004.803.401"/></svg></span>
      <span class="ico-sun" hidden><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2"></path><path d="M12 20v2"></path><path d="m4.93 4.93 1.41 1.41"></path><path d="m17.66 17.66 1.41 1.41"></path><path d="M2 12h2"></path><path d="M20 12h2"></path><path d="m6.34 17.66-1.41 1.41"></path><path d="m19.07 4.93-1.41 1.41"></path></svg></span>
    </button>
    <?php if (\Auth::check()): ?>
      <a class="btn-login" href="<?= e(ui_user()) ?>"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="5"></circle><path d="M20 21a8 8 0 0 0-16 0"></path></svg><?= __('ui.my') ?></a>
      <?php if (\Auth::isAdmin()): ?><a class="hd-mini" href="/admin/"><?= __('ui.admin') ?></a><?php endif; ?>
      <a class="hd-mini" href="/logout"><?= __('ui.logout') ?></a>
    <?php else: ?>
      <a class="btn-login" href="<?= e(ui_login()) ?>"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="5"></circle><path d="M20 21a8 8 0 0 0-16 0"></path></svg><?= __('ui.login') ?></a>
    <?php endif; ?>
    <button type="button" class="icon-btn nav-toggle" id="navToggle" aria-label="<?= e(__('ui.menu')) ?>" aria-expanded="false">
      <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16"></path><path d="M4 12h16"></path><path d="M4 19h16"></path></svg>
    </button>
  </div>
</header>

<!-- 移动端抽屉（≤1100px 汉堡展开） -->
<div class="drawer" id="drawer">
  <div class="drawer-in">
    <button type="button" class="drawer-close" id="drawerClose" aria-label="Close">
      <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg>
    </button>
    <nav class="drawer-nav">
      <?php foreach (($GLOBALS['yc_xinlv_nav'] ?? []) as $ni): ?>
      <a href="<?= e($ni['href']) ?>" class="<?= e(trim(($ni['class'] ?? '') . (($ni['active'] ?? false) ? ' active' : ''))) ?>"><?= e($ni['label']) ?></a>
      <?php endforeach; ?>
    </nav>
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
/* 主区：内容 1fr + 右栏 264px（$side 由内核 render_page() 拼好：文章页 TOC 在前、侧栏块在后） */
$_ycSide = (string)($side ?? '');
?>
<main class="site-main">
  <div class="hs-container">
    <div class="layout-grid<?= $_ycSide === '' ? ' no-side' : '' ?>">
      <div class="content-area"><?= $main ?></div>
      <?php if ($_ycSide !== ''): ?><aside class="sidebar"><?= $_ycSide ?></aside><?php endif; ?>
    </div>
  </div>
</main>

<?= View::render('partials/footer') ?>
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
  window.addEventListener('resize', function () { if (window.innerWidth > 1100) { setDrawer(false); } });
})();
/* 页脚订阅：演示站无订阅后端，前端拦截给出反馈（不跳转、不 404） */
(function () {
  var f = document.getElementById('hsSubscribe');
  if (!f) return;
  var note = document.getElementById('hsSubscribeNote');
  f.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var inp = f.querySelector('input[type=email]');
    var v = inp ? (inp.value || '') : '';
    if (!note) { return; }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) {
      note.className = 'footer-subscribe-note';
      note.textContent = '请填写有效的邮箱地址';
      if (inp) { inp.focus(); }
      return;
    }
    note.className = 'footer-subscribe-note ok';
    note.textContent = '订阅成功（演示站，不会真的发送邮件）';
    f.reset();
  });
})();
/* 首页 hero 轮播：内容全部已在 HTML 中，这里只做切换增强（无 JS 时所有 slide 平铺可见） */
(function () {
  var c = document.getElementById('hsCarousel');
  if (!c) return;
  var slides = c.querySelectorAll('.carousel-slide');
  var dots = c.querySelectorAll('.carousel-dot');
  if (slides.length < 2) return;
  var idx = 0, timer = null;
  function show(n) {
    idx = (n + slides.length) % slides.length;
    for (var i = 0; i < slides.length; i++) {
      var on = (i === idx);
      slides[i].classList.toggle('active', on);
      if (on) { slides[i].removeAttribute('aria-hidden'); } else { slides[i].setAttribute('aria-hidden', 'true'); }
    }
    for (var j = 0; j < dots.length; j++) {
      var don = (j === idx);
      dots[j].classList.toggle('active', don);
      dots[j].setAttribute('aria-selected', don ? 'true' : 'false');
    }
  }
  function stop() { if (timer) { clearInterval(timer); timer = null; } }
  function play() { stop(); timer = setInterval(function () { show(idx + 1); }, 5000); }
  c.classList.add('ready');
  show(0);
  var pv = c.querySelector('.carousel-prev'), nx = c.querySelector('.carousel-next');
  if (pv) { pv.addEventListener('click', function () { show(idx - 1); play(); }); }
  if (nx) { nx.addEventListener('click', function () { show(idx + 1); play(); }); }
  for (var k = 0; k < dots.length; k++) {
    (function (kk) { dots[kk].addEventListener('click', function () { show(kk); play(); }); })(k);
  }
  c.addEventListener('mouseenter', stop);
  c.addEventListener('mouseleave', play);
  play();
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
