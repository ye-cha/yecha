<?php
/**
 * 静读 Jingdu · layout（整页外壳）
 * 变量：$title, $page_title, $main, $sub(可选), $htmlLang
 * 可选：$GLOBALS['jd_toc']（由 partials/post|page 生成的文章目录 HTML）
 *
 * 形态：顶栏（品牌 + 导航 + 工具）+ 单栏正文；宽屏（≥1240px）右侧悬浮目录不占版心。
 * 无侧栏、无推荐位、无封面大图——这是主题的立场，不是没做完。
 */
$__lp     = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
$__home   = $__lp === '' ? '/' : $__lp . '/';
$__toc    = (string)($GLOBALS['jd_toc'] ?? '');
$__dmode  = (string)theme_opt('dark_mode', 'auto');
$__tw     = (string)theme_opt('width', '');
$__accent = (string)theme_opt('accent', '');

/* 站点 Logo / favicon（跟随后台「站点 Logo」，无则用主题默认） */
$__themeDir = '/themes/' . (string)get_option('theme.default', 'jingdu') . '/';
$__logoRel  = trim((string)get_option('site.logo', ''));
$__root     = dirname(__DIR__, 3);
if ($__logoRel === '' || !is_file($__root . $__logoRel)) { $__logoRel = $__themeDir . 'logo.svg'; }
$__logoAbs  = $__root . $__logoRel;
$__logoSrc  = $__logoRel . (is_file($__logoAbs) ? '?v=' . filemtime($__logoAbs) : '');
?><!DOCTYPE html>
<html lang="<?= e((string)($htmlLang ?? 'zh-CN')) ?>"<?= $__dmode === 'dark' ? ' class="dark"' : '' ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?></title>
<link rel="icon" type="image/png" href="<?= e($__logoSrc) ?>">
<link rel="apple-touch-icon" href="<?= e($__logoSrc) ?>">
<link rel="alternate" type="application/rss+xml" title="<?= e(site_label()) ?> · RSS" href="<?= e($__lp . '/feed') ?>">
<link rel="stylesheet" href="<?= e(View::asset('style.css')) ?>">
<?php
/* 版心 / 强调色覆盖（后台主题设置） */
if ($__tw !== '' || ($__accent !== '' && preg_match('/^#[0-9a-fA-F]{3,8}$/', $__accent))) {
    echo '<style>:root{' . ($__tw !== '' ? '--content-w:' . (int)$__tw . 'px;' : '')
        . (preg_match('/^#[0-9a-fA-F]{3,8}$/', $__accent) ? '--accent:' . $__accent . ';' : '') . '}</style>';
}
?>
<script>
/* 早于渲染决定明暗，避免刷新闪白（FOUC）。三态：跟随系统 / 固定浅 / 固定深 */
(function () {
  var mode = <?= json_encode($__dmode) ?>;
  var d = document.documentElement, saved = null;
  try { saved = localStorage.getItem('yecha-dark'); } catch (e) {}
  var dark;
  if (mode === 'dark') { dark = true; }
  else if (mode === 'light') { dark = false; }
  else if (saved !== null) { dark = saved === '1'; }
  else { dark = !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches); }
  d.classList.toggle('dark', dark);
})();
</script>
<?php $__headStat = (string)get_option('stats.head', ''); if ($__headStat !== '') { echo "\n<!-- stats.head -->\n" . $__headStat; } ?>
<?php
/* canonical + hreflang：由内核判明该内容的语言集后注入 */
if (!empty($GLOBALS['YC_CANONICAL'])) {
    echo "\n" . '<link rel="canonical" href="' . e($GLOBALS['YC_CANONICAL']) . '" />';
}
if (!empty($GLOBALS['YC_HREFLANG']) && is_array($GLOBALS['YC_HREFLANG'])) {
    $__host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $__scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $__origin = $__scheme . '://' . $__host;
    foreach (Poly::enabled() as $__lc) {
        $__rel = $GLOBALS['YC_HREFLANG'][$__lc] ?? null;
        if ($__rel === null) { continue; }
        echo "\n" . '<link rel="alternate" hreflang="' . e($__lc) . '" href="' . e($__origin . $__rel) . '" />';
    }
    $__xdef = $GLOBALS['YC_HREFLANG'][Poly::defaultLang()] ?? null;
    if ($__xdef !== null) {
        echo "\n" . '<link rel="alternate" hreflang="x-default" href="' . e($__origin . $__xdef) . '" />';
    }
}
if (!empty($GLOBALS['YC_META'])) {
    $__m = $GLOBALS['YC_META'];
    if (!empty($__m['description'])) { echo "\n" . '<meta name="description" content="' . e($__m['description']) . '">'; }
    if (!empty($__m['keywords']))    { echo "\n" . '<meta name="keywords" content="' . e($__m['keywords']) . '">'; }
}
?>
</head>
<body>
<a class="skip-link" href="#jd-main"><?= e(__('ui.skip') ?? '跳到正文') ?></a>

<header class="jd-header">
  <div class="wrap jd-header__in">
    <a class="jd-brand" href="<?= e($__home) ?>" title="<?= e(site_label()) ?>">
      <?php if (is_file($__logoAbs)): ?>
        <img src="<?= e($__logoSrc) ?>" alt="<?= e(site_label()) ?>" width="26" height="26">
      <?php else: ?>
        <span class="jd-brand__dot"></span>
      <?php endif; ?>
      <span class="jd-brand__text"><?= e(site_label()) ?></span>
    </a>

    <nav class="jd-nav" id="jdNav" aria-label="<?= e(__('ui.nav') ?? '导航') ?>">
      <?php foreach (Nav::list('top', Lang::short()) as $__n): ?>
        <a href="<?= e($__n['href']) ?>"<?= !empty($__n['active']) ? ' class="active"' : '' ?>><?= e($__n['label']) ?></a>
      <?php endforeach; ?>
      <a href="<?= e($__lp . '/archives') ?>"><?= e(__('nav.archive')) ?></a>
    </nav>

    <div class="jd-tools">
      <button type="button" class="jd-icon" id="jdSearchBtn" aria-label="<?= e(__('sb.search_aria')) ?>" title="<?= e(__('sb.search_ph')) ?>" aria-expanded="false">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.6-3.6"></path></svg>
      </button>

      <?php if (Poly::on()): ?>
      <div class="jd-lang">
        <button type="button" class="jd-icon" id="jdLangBtn" aria-haspopup="true" aria-expanded="false" aria-label="<?= e(__('ui.lang_cap')) ?>" title="<?= e(__('ui.lang_cap')) ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 8 6 6"></path><path d="m4 14 6-6 2-3"></path><path d="M2 5h12"></path><path d="m22 22-5-10-5 10"></path><path d="M14 18h6"></path></svg>
        </button>
        <div class="jd-lang__menu" id="jdLangMenu" hidden>
          <?php foreach (Poly::enabled() as $__lc): ?>
            <a href="<?= e(ui_lang_url($__lc)) ?>"<?= $__lc === Lang::short() ? ' class="cur"' : '' ?>><?= e(Poly::label($__lc)) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <button type="button" class="jd-icon" id="jdDark" aria-label="<?= e(__('ui.dark_title')) ?>" title="<?= e(__('ui.dark_title')) ?>">
        <span class="ico-moon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.985 12.486a9 9 0 1 1-9.473-9.472c.405-.022.617.46.402.803a6 6 0 0 0 8.268 8.268c.344-.215.825-.004.803.401"></path></svg></span>
        <span class="ico-sun"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2"></path><path d="M12 20v2"></path><path d="m4.93 4.93 1.41 1.41"></path><path d="m17.66 17.66 1.41 1.41"></path><path d="M2 12h2"></path><path d="M20 12h2"></path><path d="m6.34 17.66-1.41 1.41"></path><path d="m19.07 4.93-1.41 1.41"></path></svg></span>
      </button>

      <a class="jd-icon" href="<?= e($__lp . '/feed') ?>" aria-label="RSS" title="RSS">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 11a9 9 0 0 1 9 9"></path><path d="M4 4a16 16 0 0 1 16 16"></path><circle cx="5" cy="19" r="1"></circle></svg>
      </a>

      <?php if (\Auth::check()): ?>
        <a class="jd-icon" href="<?= e(ui_user()) ?>" aria-label="<?= e(__('ui.my')) ?>" title="<?= e(__('ui.my')) ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="5"></circle><path d="M20 21a8 8 0 0 0-16 0"></path></svg>
        </a>
      <?php else: ?>
        <a class="jd-icon" href="<?= e(ui_login()) ?>" aria-label="<?= e(__('ui.login')) ?>" title="<?= e(__('ui.login')) ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="5"></circle><path d="M20 21a8 8 0 0 0-16 0"></path></svg>
        </a>
      <?php endif; ?>

      <button type="button" class="jd-icon" id="jdNavBtn" aria-label="<?= e(__('ui.menu')) ?>" aria-expanded="false">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M4 7h16"></path><path d="M4 12h16"></path><path d="M4 17h16"></path></svg>
      </button>
    </div>
  </div>

  <div class="wrap jd-search" id="jdSearch" hidden>
    <form action="<?= e($__lp . '/search') ?>" method="get" role="search">
      <input type="search" name="q" placeholder="<?= e(__('sb.search_ph')) ?>" aria-label="<?= e(__('sb.search_aria')) ?>" autocomplete="off">
      <button type="submit"><?= e(__('sb.search_btn') ?? '搜索') ?></button>
    </form>
  </div>
</header>

<div class="wrap">
  <div class="main-wrap<?= $__toc !== '' ? ' has-rail' : '' ?>">
    <main class="main-col" id="jd-main"><?= $main ?></main>
    <?php if ($__toc !== ''): ?>
    <div class="main-rail">
      <nav class="jd-toc" id="jdToc" aria-label="<?= e(__('ui.toc') ?? '目录') ?>">
        <div class="jd-toc__cap"><?= e(__('ui.toc') ?? '目录') ?></div>
        <?= $__toc ?>
      </nav>
    </div>
    <?php endif; ?>
  </div>
</div>

<footer class="jd-footer">
  <div class="wrap jd-footer__in">
    <span>© <?= date('Y') ?> <?= e(site_label()) ?></span>
    <div class="jd-footer__links">
      <?php foreach (Nav::list('foot', Lang::short()) as $__f): ?>
        <a href="<?= e($__f['href']) ?>"><?= e($__f['label']) ?></a>
      <?php endforeach; ?>
      <a href="<?= e($__lp . '/feed') ?>">RSS</a>
      <a href="<?= e($__lp . '/sitemap.xml') ?>">Sitemap</a>
    </div>
    <span class="jd-footer__copy">
      <?php $__ver = defined('YC_VERSION') ? (string)YC_VERSION : ''; ?>
      <?= $__ver !== '' ? 'YeCha ' . e($__ver) . ' · ' : '' ?>静读
    </span>
  </div>
  <?php $__tag = trim((string)get_option('site.subtitle', '')); if ($__tag !== ''): ?>
  <div class="wrap" style="margin-top:10px;font-size:12.5px;color:var(--muted)"><?= e($__tag) ?></div>
  <?php endif; ?>
</footer>

<?php $__footStat = (string)get_option('stats.foot', ''); if ($__footStat !== '') { echo "\n<!-- stats.foot -->\n" . $__footStat; } ?>
<script>
/* 明暗切换（记住访客选择） */
(function () {
  var b = document.getElementById('jdDark');
  if (!b) { return; }
  b.addEventListener('click', function () {
    var on = document.documentElement.classList.toggle('dark');
    try { localStorage.setItem('yecha-dark', on ? '1' : '0'); } catch (e) {}
  });
})();
/* 搜索面板（顶栏图标；也支持按 / 打开） */
(function () {
  var btn = document.getElementById('jdSearchBtn'), box = document.getElementById('jdSearch');
  if (!btn || !box) { return; }
  function toggle(open) {
    box.hidden = !open;
    box.classList.toggle('on', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) { var i = box.querySelector('input'); if (i) { i.focus(); } }
  }
  btn.addEventListener('click', function () { toggle(box.hidden); });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test((ev.target.tagName || ''))) {
      ev.preventDefault(); toggle(true);
    }
    if (ev.key === 'Escape') { toggle(false); }
  });
})();
/* 语言下拉 */
(function () {
  var b = document.getElementById('jdLangBtn'), m = document.getElementById('jdLangMenu');
  if (!b || !m) { return; }
  function set(open) { m.hidden = !open; b.setAttribute('aria-expanded', open ? 'true' : 'false'); }
  b.addEventListener('click', function (ev) { ev.stopPropagation(); set(m.hidden); });
  document.addEventListener('click', function () { set(false); });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { set(false); } });
})();
/* 移动端导航折叠 */
(function () {
  var b = document.getElementById('jdNavBtn'), n = document.getElementById('jdNav');
  if (!b || !n) { return; }
  b.addEventListener('click', function () {
    var open = n.classList.toggle('open');
    b.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  window.addEventListener('resize', function () {
    if (window.innerWidth > 860 && n.classList.contains('open')) {
      n.classList.remove('open'); b.setAttribute('aria-expanded', 'false');
    }
  });
})();
/* 代码窗复制按钮（增强；无 JS 时代码照常可读可选中） */
(function () {
  var pres = document.querySelectorAll('.prose pre');
  for (var i = 0; i < pres.length; i++) {
    (function (pre) {
      var btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'jd-copy'; btn.textContent = 'COPY';
      btn.setAttribute('aria-label', '复制代码');
      btn.addEventListener('click', function () {
        var code = pre.querySelector('code');
        var txt = code ? code.textContent : pre.textContent;
        function done() { btn.textContent = 'COPIED'; btn.classList.add('done'); setTimeout(function () { btn.textContent = 'COPY'; btn.classList.remove('done'); }, 1600); }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(txt).then(done, function () {});
        } else {
          var ta = document.createElement('textarea');
          ta.value = txt; ta.style.position = 'fixed'; ta.style.opacity = '0';
          document.body.appendChild(ta); ta.select();
          try { document.execCommand('copy'); done(); } catch (e) {}
          document.body.removeChild(ta);
        }
      });
      pre.appendChild(btn);
    })(pres[i]);
  }
})();
/* 目录滚动高亮（增强；链接本身是服务端直出的，无 JS 也能跳） */
(function () {
  var toc = document.getElementById('jdToc');
  if (!toc || !('IntersectionObserver' in window)) { return; }
  var links = toc.querySelectorAll('a[href^="#"]');
  if (!links.length) { return; }
  var map = {};
  for (var i = 0; i < links.length; i++) {
    var id = links[i].getAttribute('href').slice(1);
    var h = document.getElementById(id);
    if (h) { map[id] = links[i]; }
  }
  var ids = Object.keys(map);
  if (!ids.length) { return; }
  var cur = null;
  var io = new IntersectionObserver(function (entries) {
    var best = null;
    for (var k = 0; k < entries.length; k++) {
      if (entries[k].isIntersecting) { best = entries[k].target.id; break; }
    }
    if (!best) { return; }
    if (cur && map[cur]) { map[cur].classList.remove('active'); }
    cur = best;
    if (map[cur]) { map[cur].classList.add('active'); }
  }, { rootMargin: '-88px 0px -68% 0px', threshold: 0 });
  for (var j = 0; j < ids.length; j++) {
    var el = document.getElementById(ids[j]);
    if (el) { io.observe(el); }
  }
})();
/* 图片加载失败降级为占位文字 */
document.addEventListener('error', function (e) {
  var t = e.target;
  if (t && t.tagName === 'IMG' && !t.dataset.jdMiss) {
    t.dataset.jdMiss = '1';
    var s = document.createElement('span');
    s.className = 'img-miss';
    s.textContent = t.getAttribute('alt') || '<?= e(__('ui.img_miss')) ?>';
    s.style.cssText = 'display:block;padding:18px;text-align:center;color:var(--muted);background:var(--panel);border-radius:8px;font-size:13px';
    t.replaceWith(s);
  }
}, true);
</script>
</body>
</html>
