<?php
/** 森林茶 Forest theme · layout：整页外壳；变量：$title, $page_title, $main, $sub(可选), $htmlLang
 *  视觉：苔纸底 × 松绿强调 × 衬线标题（森林气质，明暗切换记忆为 sf-dark）
 */
?><!DOCTYPE html>
<html lang="<?= e((string)($htmlLang ?? 'zh-CN')) ?>" dir="<?= e(ui_dir()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?></title>
<?php
// favicon 跟随“站点 Logo”（后台上传/URL 设置；无则用主题默认 forest/favicon.svg）
$favRel = trim((string)get_option('site.logo', ''));
$favIsSvg = str_ends_with(strtolower($favRel), '.svg');
if ($favRel === '' || !is_file(dirname(__DIR__, 3) . $favRel)) {
    $favRel = '/themes/forest/favicon.svg'; $favIsSvg = true;
}
$favAbs = dirname(__DIR__, 3) . $favRel;
$favSrc = $favRel . (is_file($favAbs) ? '?v=' . filemtime($favAbs) : '');
?>
<link rel="icon" type="<?= $favIsSvg ? 'image/svg+xml' : 'image/png' ?>" href="<?= e($favSrc) ?>">
<link rel="apple-touch-icon" href="<?= e($favSrc) ?>">
<link rel="alternate" type="application/rss+xml" title="RSS" href="<?= e(ui_lp() . '/feed') ?>">
<link rel="stylesheet" href="<?= e(View::asset('style.css')) ?>">
<?php
$tw = (string)theme_opt('width', '');
$tac = (string)theme_opt('accent', '');
if ($tw !== '' || ($tac !== '' && preg_match('/^#[0-9a-fA-F]{3,8}$/', $tac))) {
    echo '<style>:root{' . ($tw !== '' ? '--wide-w:' . (int)$tw . 'px;' : '')
        . (preg_match('/^#[0-9a-fA-F]{3,8}$/', $tac) ? '--accent:' . $tac . ';' : '') . '}</style>';
}
?>
<?php $headStat = stat_html('stats.head'); if ($headStat !== '') { echo "\n<!-- stats.head -->\n" . $headStat; } ?>
<?php
// canonical + hreflang（内容语言集判明后输出）
if (!empty($GLOBALS['YC_CANONICAL'])) {
    echo "\n" . '<link rel="canonical" href="' . e($GLOBALS['YC_CANONICAL']) . '" />';
}
if (!empty($GLOBALS['YC_HREFLANG']) && is_array($GLOBALS['YC_HREFLANG'])) {
    $host = $_SERVER['HTTP_HOST'] ?? 'senlincha.com';
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
} else {
    // 首页/栏目/搜索等列表页：站点级 description/keywords（SEO 首轮）
    $sd = trim((string)get_option('site.description', ''));
    if ($sd !== '') { echo "\n" . '<meta name="description" content="' . e($sd) . '">'; }
    $sk = trim((string)get_option('site.keywords', ''));
    if ($sk !== '') { echo "\n" . '<meta name="keywords" content="' . e($sk) . '">'; }
}
/* OpenGraph / Twitter Card（forest 统一头：og:image 取 site.logo 或主题 logo） */
$ogHost = $_SERVER['HTTP_HOST'] ?? 'senlincha.com';
$ogScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$ogUrl = $GLOBALS['YC_CANONICAL'] ?? '';
if ($ogUrl === '') {
    $ogUrl = $ogScheme . '://' . $ogHost . (string)($_SERVER['REQUEST_URI'] ?? '/');
}
$ogDesc = !empty($GLOBALS['YC_META']['description']) ? $GLOBALS['YC_META']['description'] : trim((string)get_option('site.description', ''));
$ogImgRel = trim((string)get_option('site.logo', ''));
$ogImgAbs = dirname(__DIR__, 3) . $ogImgRel;
if ($ogImgRel === '' || !is_file($ogImgAbs)) { $ogImgRel = '/themes/forest/logo.svg'; }
$ogTitle = trim((string)$title);
echo "\n" . '<meta property="og:site_name" content="' . e(site_label()) . '">';
echo "\n" . '<meta property="og:type" content="' . e(!empty($GLOBALS['YC_META']) ? 'article' : 'website') . '">';
echo "\n" . '<meta property="og:title" content="' . e($ogTitle) . '">';
if ($ogDesc !== '') { echo "\n" . '<meta property="og:description" content="' . e($ogDesc) . '">'; }
echo "\n" . '<meta property="og:url" content="' . e($ogUrl) . '">';
echo "\n" . '<meta property="og:image" content="' . e($ogScheme . '://' . $ogHost . $ogImgRel) . '">';
echo "\n" . '<meta name="twitter:card" content="summary">';
echo "\n" . '<meta name="twitter:title" content="' . e($ogTitle) . '">';
if ($ogDesc !== '') { echo "\n" . '<meta name="twitter:description" content="' . e($ogDesc) . '">'; }
echo "\n" . '<meta name="twitter:image" content="' . e($ogScheme . '://' . $ogHost . $ogImgRel) . '">';
/* JSON-LD：站点级 Organization + WebSite */
$ld = [
    '@context' => 'https://schema.org',
    '@graph' => [
        ['@type' => 'Organization', '@id' => $ogUrl . '#org', 'name' => site_label(), 'url' => $ogScheme . '://' . $ogHost . '/', 'logo' => ['@type' => 'ImageObject', 'url' => $ogScheme . '://' . $ogHost . $ogImgRel]],
        ['@type' => 'WebSite', '@id' => $ogUrl . '#site', 'url' => $ogScheme . '://' . $ogHost . '/', 'name' => site_label(), 'description' => trim((string)get_option('site.description', ''))],
    ],
];
echo "\n" . '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
?>
</head>
<body class="<?= !empty($GLOBALS['YC_HOME_HERO']) ? 'home-landing' : '' ?>">
<?php
/* 顶栏：brand(logo+站名) + 栏目导航(首页+核心分类/页面) + 语言/明暗/搜索/抽屉
   多语：非基准语言下链接带 /{lang}/ 前缀；当前语言高亮 */
$curLangShort = Lang::short();
$polyOn = Poly::on();
$defaultLang = Poly::defaultLang();
$lp = ($polyOn && $curLangShort !== $defaultLang) ? '/' . $curLangShort : '';
$homeHref = $lp !== '' ? $lp . '/' : '/';
$curPath = trim(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/', '/');
$curPathCore = preg_replace('#^(?:[a-z]{2})/#', '', $curPath); // 去掉 /en/ 之类语言前缀后再做高亮匹配
$coreT = rtrim($curPathCore, '/'); // 无尾斜杠统一形式
$isHomePg = $curPath === '' || $curPath === ltrim($lp, '/')
    || preg_match('#^(?:[a-z]{2}/)?page/\d+$#', $curPath) === 1
    || preg_match('#^[a-z]{2}/?$#', $curPath) === 1;
/* 核心栏目（forest 专属一级导航）：首页 + 分类 + 独立页；词条 = [label, href, chk(相对路径前缀)]
   双语：非基准语言（当前为 en）下用英文标签；与分类 name_en / 页面译名保持一致 */
$navIsEn = ($curLangShort === 'en');
$forestNavRaw = [
    ['zh' => '老森专栏', 'en' => "Lao Sen's Column", 'href' => $lp . '/category/laosen/', 'chk' => 'category/laosen/'],
    ['zh' => '森林茶百科', 'en' => 'Forest Tea Encyclopedia', 'href' => $lp . '/category/baike/', 'chk' => 'category/baike/'],
    ['zh' => '森林来信', 'en' => 'Letters from the Forest', 'href' => $lp . '/category/letters/', 'chk' => 'category/letters/'],
    ['zh' => '森林的味道', 'en' => 'Flavors of the Forest', 'href' => $lp . '/flavors.html', 'chk' => 'flavors.html'],
    ['zh' => '关于老森', 'en' => 'About Lao Sen', 'href' => $lp . '/laosen.html', 'chk' => 'laosen.html'],
];
$forestNav = array_map(static function (array $v) use ($navIsEn): array {
    $v['label'] = $navIsEn ? $v['en'] : $v['zh'];
    unset($v['zh'], $v['en']);
    return $v;
}, $forestNavRaw);
$navActive = [];
foreach ($forestNav as $fk => $fv) {
    $ck = rtrim((string)$fv['chk'], '/');
    $navActive[$fk] = ($coreT === $ck) || ($ck !== '' && str_starts_with($coreT, $ck . '/'));
}
/* 顶栏降噪：栏目（分类）折叠为一个下拉；独立页保持直链；LOGO 词去掉尾部的 “· 域名” */
$catNav = [];
$pageNav = [];
foreach ($forestNav as $fv) {
    if (str_starts_with((string)$fv['chk'], 'category/')) { $catNav[] = $fv; } else { $pageNav[] = $fv; }
}
$navCatActive = false;
foreach ($catNav as $fv) {
    $ck = rtrim((string)$fv['chk'], '/');
    if (($coreT === $ck) || ($ck !== '' && str_starts_with($coreT, $ck . '/'))) { $navCatActive = true; break; }
}
$catBtnLabel = $navIsEn ? 'Columns' : '栏目';
foreach ($catNav as $fv) {
    $ck = rtrim((string)$fv['chk'], '/');
    if (($coreT === $ck) || ($ck !== '' && str_starts_with($coreT, $ck . '/'))) { $catBtnLabel = $fv['label']; break; }
}
$brandWord = trim((string)site_label());
if ($navIsEn) {
    $brandShort = trim((string)preg_replace('/\s*[·•]\s*\S+\.[A-Za-z]{2,}\s*$/u', '', $brandWord));
    if ($brandShort !== '') { $brandWord = $brandShort; }
}
$navCatPrefix = [];
foreach ($catNav as $fv) { $navCatPrefix[(string)$fv['chk']] = $fv; }
?>
<header class="site-header">
  <div class="wrap">
    <a class="brand" href="<?= e($homeHref) ?>" title="<?= e($brandWord) ?>" aria-label="<?= e($brandWord) ?>">
      <?php
      $logoRel = trim((string)get_option('site.logo', ''));
      if ($logoRel === '' || !is_file(dirname(__DIR__, 3) . $logoRel)) { $logoRel = '/themes/forest/logo.svg'; }
      $logoAbs = dirname(__DIR__, 3) . $logoRel;
      if ($logoRel !== '' && is_file($logoAbs)): ?>
      <img class="brand-logo" src="<?= e($logoRel . '?v=' . filemtime($logoAbs)) ?>" alt="<?= e($brandWord) ?>">
      <?php endif; ?>
      <span class="brand-word"><?= e($brandWord) ?></span>
    </a>
    <nav class="nav" aria-label="<?= e(__('ui.main_nav')) ?>">
      <a href="<?= e($homeHref) ?>"<?= $isHomePg ? ' class="active"' : '' ?>><?= e(__('nav.home')) ?></a>
      <div class="nav-dd<?= $navCatActive ? ' has-active' : '' ?>" id="navDd">
        <button type="button" class="nav-dd-btn" aria-haspopup="true" aria-expanded="false"><?= e($catBtnLabel) ?>
          <svg class="ico caret" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"></path></svg>
        </button>
        <div class="nav-dd-menu" hidden>
          <?php foreach ($catNav as $fv): ?>
          <a href="<?= e($fv['href']) ?>"<?= !empty($navActive[array_search($fv, $forestNav, true)]) ? ' class="active"' : '' ?>><?= e($fv['label']) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php foreach ($pageNav as $fv): ?>
      <a href="<?= e($fv['href']) ?>"<?= !empty($navActive[array_search($fv, $forestNav, true)]) ? ' class="active"' : '' ?>><?= e($fv['label']) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="hd-tools">
      <a class="icon-btn sr-open" href="<?= e($lp . '/search') ?>" aria-label="<?= e(__('ui.search')) ?>" title="<?= e(__('ui.search')) ?>">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><path d="m21 21-4.3-4.3"></path></svg>
      </a>
      <?php if (Poly::on()): ?>
      <div class="lang-sw">
        <button type="button" class="icon-btn" id="langBtn" aria-haspopup="true" aria-expanded="false" aria-label="<?= e(__('ui.lang_cap')) ?>" title="<?= e(__('ui.lang_cap')) ?>">
          <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 8 6 6"></path><path d="m4 14 6-6 2-3"></path><path d="M2 5h12"></path><path d="M7 2h1"></path><path d="m22 22-5-10-5 10"></path><path d="M14 18h6"></path></svg>
        </button>
        <div class="lang-menu" id="langMenu" hidden>
          <?php foreach (Poly::enabled() as $lc): ?>
          <a href="<?= e(ui_lang_url($lc)) ?>"<?= $lc === $curLangShort ? ' class="cur"' : '' ?>><?= e(Poly::label($lc)) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      <button type="button" class="icon-btn dark-toggle" id="darkToggle" aria-label="<?= e(__('ui.dark_title')) ?>" title="<?= e(__('ui.dark_title')) ?>">
        <span class="ico-moon"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z"></path></svg></span>
        <span class="ico-sun" hidden><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2"></path><path d="M12 20v2"></path><path d="M4.9 4.9l1.4 1.4"></path><path d="M17.7 17.7l1.4 1.4"></path><path d="M2 12h2"></path><path d="M20 12h2"></path><path d="M6.3 17.7l1.4-1.4"></path><path d="M17.7 6.3l1.4-1.4"></path></svg></span>
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
</header>
<div class="drawer" id="drawer" aria-label="<?= e(__('ui.drawer')) ?>">
  <div class="drawer-in">
    <button type="button" class="drawer-close" id="drawerClose" aria-label="Close">
      <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg>
    </button>
    <nav class="drawer-nav">
      <a href="<?= e($homeHref) ?>"<?= $isHomePg ? ' class="active"' : '' ?>><?= e(__('nav.home')) ?></a>
      <?php foreach ($forestNav as $fv): ?>
      <a href="<?= e($fv['href']) ?>"><?= e($fv['label']) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php if (Poly::on()): ?>
    <div class="drawer-group">
      <div class="drawer-cap"><?= e(__('ui.lang_cap')) ?></div>
      <div class="drawer-langs">
        <?php foreach (Poly::enabled() as $lc): ?>
        <a href="<?= e(ui_lang_url($lc)) ?>"<?= $lc === $curLangShort ? ' class="cur"' : '' ?>><?= e(Poly::label($lc)) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <div class="drawer-group">
      <div class="drawer-cap"><?= e(__('ui.articles')) ?></div>
      <div class="drawer-langs">
        <a href="<?= e($lp . '/category/') ?>"><?= e(__('tt.mi_category')) ?></a>
        <a href="<?= e($lp . '/tag/') ?>"><?= e(__('nav.tags')) ?></a>
        <a href="<?= e($lp . '/archives') ?>"><?= e(__('nav.archive')) ?></a>
        <a href="<?= e($lp . '/search') ?>"><?= e(__('nav.search')) ?></a>
      </div>
    </div>
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

<div class="layout<?= empty($side) ? ' no-side' : '' ?><?= !empty($tocSide) ? ' toc-rail' : '' ?><?= (!empty($tocSide) && !empty($sbSticky)) ? ' sb-sticky' : '' ?>">
  <main class="main">
    <?= $main ?>
  </main>
  <aside class="side">
    <?php if (!empty($side)) { echo $side; } ?>
  </aside>
</div>

<footer class="site-footer"><div class="wrap">
  <?php $privacyHref = isset($lp) ? $lp . '/privacy-policy.html' : '/privacy-policy.html';
        /* 页脚「订阅」入口：仅当站点装了并启用了邮件订阅插件时出现（没装的站不受影响） */
        $subNav = (class_exists('Plugins') && Plugins::isOn('subscribe'));
        $subIsEn = (isset($curLangShort) ? $curLangShort : (class_exists('Lang') ? Lang::short() : 'zh')) === 'en';
        $subHref = ($lp ?? '') . '/subscribe'; ?>
<span class="foot-copy"><span>© <?= date('Y') ?> <?= e(trim((string)get_option('site.name_en', '')) !== '' ? (string)get_option('site.name_en', '') : site_label()) ?></span> · <a href="<?= e($privacyHref) ?>"><?= e(__('nav.privacy')) ?></a><?php if ($subNav): ?> · <a href="<?= e($subHref) ?>"><?= e($subIsEn ? 'Subscribe' : '订阅') ?></a><?php endif; ?> · <a href="https://yecha.org/" rel="noopener" target="_blank" title="YeCha Blog System">Powered by YeCha</a> · <a class="fc-wp" href="https://yecha.org/" rel="noopener" target="_blank" title="Forest Theme">Forest</a><?php $icp = trim((string)get_option('site.icp', '')); if ($icp !== ''): ?> · <a href="<?= e((string)get_option('site.icp_link', 'https://beian.miit.gov.cn/')) ?>" rel="noopener" target="_blank"><?= e($icp) ?></a><?php endif; ?></span>
</div></footer>
<script>
(function () {
  var d = document.documentElement, b = document.getElementById('darkToggle');
  function apply(on){ d.classList.toggle('dark', on); }
  var saved = null;
  try { saved = localStorage.getItem('sf-dark'); } catch (e) {}
  if (saved === null) { apply(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches); }
  else { apply(saved === '1'); }
  if (b) b.addEventListener('click', function () {
    var on = d.classList.toggle('dark');
    try { localStorage.setItem('sf-dark', on ? '1' : '0'); } catch (e) {}
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
  var dd = document.getElementById('navDd');
  if (!dd) return;
  var b = dd.querySelector('.nav-dd-btn'), m = dd.querySelector('.nav-dd-menu');
  function set(o) { m.hidden = !o; b.setAttribute('aria-expanded', o ? 'true' : 'false'); dd.classList.toggle('open', o); }
  b.addEventListener('click', function (ev) { ev.stopPropagation(); set(m.hidden); });
  document.addEventListener('click', function () { set(false); });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { set(false); } });
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
