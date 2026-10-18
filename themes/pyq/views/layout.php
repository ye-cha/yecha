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
if ($favRel === '' || !is_file(dirname(__DIR__, 3) . $favRel)) { $favRel = '/themes/pyq/logo-oni.png'; }
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
<body class="th-pyq<?= !empty($GLOBALS['yc_pyq_cover']) ? ' pyq-cover-on' : '' ?>">
<header class="site-header">
  <div class="wrap">
    <a class="brand" href="<?= e((Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() . '/' : '/') ?>" title="<?= e(site_label()) ?>" aria-label="<?= e(site_label()) ?>">
      <?php
      // 朋友圈皮肤：顶栏左上头像（站点 Logo 缺省回落主题 logo.svg）
      $logoRel = trim((string)get_option('site.logo', ''));
      if ($logoRel === '' || !is_file(dirname(__DIR__, 3) . $logoRel)) { $logoRel = '/themes/pyq/logo.svg'; }
      $logoAbs = dirname(__DIR__, 3) . $logoRel;
      ?>
      <img class="brand-logo" src="<?= e($logoRel . '?v=' . filemtime($logoAbs)) ?>" alt="<?= e(site_label()) ?>">
      <span class="brand-word"><?= e(site_label()) ?></span>
    </a>
    <?= View::render('partials/nav') ?>
    <div class="hd-tools">
      <button type="button" class="icon-btn pyq-search-btn" id="pyqSearchBtn" aria-haspopup="true" aria-expanded="false" aria-label="搜索" title="搜索">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><path d="m21 21-4.3-4.3"></path></svg>
      </button>
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
    </div>
  </div>
</header>
<div class="pyq-search" id="pyqSearch" hidden>
  <form class="pyq-search-form" method="get" action="<?= e((Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() . '/search' : '/search') ?>" role="search">
    <input type="search" name="q" placeholder="<?= e(__('sb.search_ph')) ?>" aria-label="<?= e(__('sb.search_aria')) ?>" autocomplete="off">
    <button type="submit" class="pyq-search-go"><?= e(__('ui.search') ?? '搜索') ?></button>
  </form>
</div>

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
// 预览条(ycpbar)在视口顶部 fixed，会盖住「封面页」的 absolute 顶栏：动态让位（仅封面页 header=absolute 时生效；非封面页 header 为 static，框架已为其预留 body 留白，无需处理）
(function () {
  var bar = document.getElementById('ycpbar'), hd = document.querySelector('.site-header');
  if (!bar || !hd) return;
  function ycpShift() {
    var abs = (getComputedStyle(hd).position === 'absolute');
    hd.style.top = abs ? (bar.offsetHeight + 'px') : '';
  }
  ycpShift();
  window.addEventListener('resize', ycpShift);
  if (window.MutationObserver) {
    new MutationObserver(ycpShift).observe(bar, { attributes: true, attributeFilter: ['style', 'class', 'hidden'] });
  }
})();
// 顶栏随文档流滚动（非 fixed/sticky）
(function () {
  // 顶栏搜索按钮：展开/收起搜索框（点击外部或 Esc 收起）
  var sb = document.getElementById('pyqSearchBtn'), sp = document.getElementById('pyqSearch');
  if (!sb || !sp) { return; }
  var input = sp.querySelector('input[name="q"]');
  function setOpen(open) {
    sp.hidden = !open;
    sb.setAttribute('aria-expanded', open ? 'true' : 'false');
    sb.classList.toggle('on', open);
    if (open) {
      var hd = document.querySelector('.site-header');
      if (hd) { sp.style.top = hd.getBoundingClientRect().bottom + 'px'; }
      if (input) { input.focus(); }
    }
  }
  sb.addEventListener('click', function (ev) {
    ev.stopPropagation();
    setOpen(sp.hidden);
  });
  document.addEventListener('click', function (ev) {
    if (!sp.hidden && !sp.contains(ev.target) && ev.target !== sb) { setOpen(false); }
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && !sp.hidden) { setOpen(false); sb.focus(); }
  });
})();
(function () {
  // 朋友圈：「⋯」弹出 赞 / 评论；赞状态本地持久化（演示站无后端点赞，按访客记忆）
  var KEY = function (slug) { return 'pyq_like_' + slug; };
  function closeAll(except) {
    document.querySelectorAll('.pyq-act-pop.open').forEach(function (p) {
      if (p === except) { return; }
      p.classList.remove('open');
      var d = p.parentNode.querySelector('.pyq-dot2');
      if (d) { d.setAttribute('aria-expanded', 'false'); }
    });
  }
  function restore() {
    document.querySelectorAll('.pyq-post').forEach(function (post) {
      var slug = post.getAttribute('data-slug');
      if (!slug || localStorage.getItem(KEY(slug)) !== '1') { return; }
      var btn = post.querySelector('.pyq-like-btn');
      if (btn) { btn.classList.add('liked'); btn.querySelector('.pyq-like-label').textContent = '已赞'; }
      var self = post.querySelector('.pyq-like-self');
      if (self) { self.hidden = false; }
    });
  }
  document.addEventListener('click', function (ev) {
    var dot = ev.target.closest('.pyq-dot2');
    if (dot) {
      ev.preventDefault();
      var pop = dot.parentNode.querySelector('.pyq-act-pop');
      if (!pop) { return; }
      var open = pop.classList.toggle('open');
      dot.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { closeAll(pop); }
      return;
    }
    if (!ev.target.closest('.pyq-act-pop')) { closeAll(null); }
    var like = ev.target.closest('.pyq-like-btn');
    if (like) {
      var post = like.closest('.pyq-post');
      var slug = post.getAttribute('data-slug');
      var liked = like.classList.toggle('liked');
      like.querySelector('.pyq-like-label').textContent = liked ? '已赞' : '赞';
      var self = post.querySelector('.pyq-like-self');
      if (self) { self.hidden = !liked; }
      if (slug) { localStorage.setItem(KEY(slug), liked ? '1' : '0'); }
      var pop2 = post.querySelector('.pyq-act-pop');
      if (pop2) { pop2.classList.remove('open'); post.querySelector('.pyq-dot2').setAttribute('aria-expanded', 'false'); }
    }
  });
  restore();
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
