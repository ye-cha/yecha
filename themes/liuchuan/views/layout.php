<?php
/** layout：整页外壳；变量：$title, $page_title, $main, $sub(可选), $htmlLang */
?><!DOCTYPE html>
<html lang="<?= e((string)($htmlLang ?? 'zh-CN')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?></title>
<?php
// favicon 跟随“站点 Logo”（后台上传/URL 设置；无则用主题默认）
$favRel = trim((string)get_option('site.logo', ''));
if ($favRel === '' || !is_file(dirname(__DIR__, 3) . $favRel)) { $favRel = '/themes/liuchuan/logo-oni.png'; }
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
<body class="th-liuchuan">
<header class="site-header">
  <div class="wrap">
    <a class="brand" href="<?= e((Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() . '/' : '/') ?>" title="<?= e(site_label()) ?>" aria-label="<?= e(site_label()) ?>">
      <?php
      $logoRel = trim((string)get_option('site.logo', '/themes/liuchuan/logo.svg'));
      $logoAbs = dirname(__DIR__, 3) . $logoRel;
      if ($logoRel !== '' && is_file($logoAbs)): ?>
      <img class="brand-logo" src="<?= e($logoRel . '?v=' . filemtime($logoAbs)) ?>" alt="<?= e(site_label()) ?>">
      <?php endif; ?>
      <span class="brand-word"><?= e(site_label()) ?></span>
    </a>
    <?= View::render('partials/nav') ?>
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
  </div>
  <div class="drawer" id="drawer">
    <div class="drawer-in">
      <button type="button" class="drawer-close" id="drawerClose" aria-label="Close">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg>
      </button>
      <nav class="drawer-nav">
        <?php foreach (($GLOBALS['yc_liuchuan_nav'] ?? Nav::list('top', Lang::short())) as $ni): ?>
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
</header>

<div class="layout">
  <main class="main">
    <?= $main ?>
  </main>
</div>

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
  window.addEventListener('resize', function () { if (window.innerWidth > 760) { setDrawer(false); } });
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
// 预览条 ycpbar 让位：sticky 顶栏下移，避免被演示站预览条遮挡导航（清单⑤）
(function () {
  var header = document.querySelector('.site-header');
  var ycp = document.querySelector('.ycpbar, #ycpbar, .ycpbar-t');
  if (!header) return;
  function shift() {
    if (!ycp) { header.style.top = ''; return; }
    var pos = getComputedStyle(header).position;
    header.style.top = (pos === 'static') ? '' : (ycp.offsetHeight + 'px');
  }
  shift();
  window.addEventListener('resize', shift);
  if (ycp) { try { new MutationObserver(shift).observe(ycp, { attributes: true, childList: true, subtree: true }); } catch (e) {} }
})();
</script>
</body>
</html>
