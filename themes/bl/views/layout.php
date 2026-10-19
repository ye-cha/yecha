<?php
/** layout：整页外壳（★ lqalign2 2026-10-05 —— 形态对齐 `www.liulanqi.com`）
 * ===========================================================================
 * 参照 = 线上 www.liulanqi.com（预渲染 React + Tailwind）。本外壳逐块对齐它：
 *   <body><div class="flex min-h-screen flex-col">
 *     <header class="sticky top-0 z-40 w-full border-b bg-background/80 backdrop-blur">   ← lqwww_header()
 *     <main class="container flex-1 py-8">                                               ← 本文件
 *     <footer class="mt-16 border-t bg-muted/40">                                        ← lqwww_footer()
 * 样式产物 = www 自己的 `assets/index-cAFfNupP.css`，**逐字节复制**为
 *   `themes/bl/lqwww/www.css`；`lqwww/lq-align.css` 只做"把实例既有功能件（评论/阅读条/
 *   工具本体/分页/搜索/图库/目录）接到 www 的令牌与圆角阴影体系上"的桥接。
 * 老站 DedeCMS 层（lqoldskin.css / lqold/*.css / parts/lq-old.php / home-old*）**已撤除**，
 * 本文件不再引用它们（回滚见 /root/rollback_lqalign2.sh）。
 * ==== 保留（一个都不删）====
 *   站内搜索（真 <form action="/search">）· 评论 · 阅读数 · 上下篇 · 目录 TOC · 朗读/打印
 *   （parts/lq-mech.php）· 分页 · feed · /en/ 多语言切换 · 深色模式 · 登录/用户中心 ·
 *   结构化数据（每页恰 1 个 ld+json 块）· 面包屑。
 * 变量：$title, $page_title, $main, $sub(可选), $htmlLang, $side(可选), $tocSide, $sbSticky
 */
require_once __DIR__ . '/../parts/lq-mech.php';
require_once __DIR__ . '/../parts/lq-www.php';
?><!DOCTYPE html>
<html lang="<?= e((string)($htmlLang ?? 'zh-CN')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php
/* ★★ [liulanqi 2026-10-11 站主裁定 · 批3] /learn/<浏览器专题> 的 SEO 标题与参照一一对应
   参照（seo/learn/{chrome,edge,firefox,opera,quark,yandex}.html）的
     <title> / og:title / twitter:title 三处**同源**，逐字为「X 从入门到精通 · 图文教程 - liulanqi」；
   我方原为「X 从入门到精通 · 浏览工坊」⇒ **少了 ` · 图文教程`**。三处都取自 $title ⇒ **改这里一处就够**。
   ★ 只动 SEO 标题；★ 不动 h1 / 列表卡 / 面包屑（实测那几处两边已一致；kicker 与 8 处卡片描述我方已有）。
   ★ 其余任何页：$__seoTitle === $title ⇒ 逐字节零回归。
   ★ 数据来源 6 个 slug 与参照页一一对应（见上）；★ 回滚 = 删本段（把 <title> 与 lqwww_head 换回 $title）。 */
/* ★ 守卫改成【自持白名单】：不依赖 $_lqMech（★ 它在第 96 行才赋值，本块在第 35 行 ⇒ 原来那条 preg_match 永远不命中）。
   6 条核心串**逐字取自老站 seo/learn/{chrome,edge,firefox,opera,quark,yandex}.html 的 <title> 去掉品牌后缀**，
   例如老站 `<title>Chrome 从入门到精通 · 图文教程 - liulanqi</title>` ⇒ 核心 `Chrome 从入门到精通 · 图文教程`。
   ⇒ 我方 $title（= `X 从入门到精通 · {site_label}`）与白名单「X 从入门到精通 · {site_label}」**精确相等**时，
     才把品牌前插入 ` · 图文教程`。★ 精确相等 ⇒ 其余任何页逐字节零回归。 */
$__brand = site_label();
$__seoTitle = (string)$title;
$__learnFaq = ['Chrome 从入门到精通', 'Edge 从入门到精通', 'Firefox 从入门到精通',
               'Opera 从入门到精通', '夸克从入门到精通', 'Yandex 从入门到精通'];
foreach ($__learnFaq as $__core) {
    if ($__seoTitle === $__core . ' · ' . $__brand) {
        $__seoTitle = $__core . ' · 图文教程 · ' . $__brand;
        break;
    }
}
?>
<title><?= e($__seoTitle) ?></title><?php
/* 图标三件套（沿用 2026-09-24 定稿：跟随当前主题 + ?v=<filemtime> 防 30 天 immutable 缓存） */
$favRoot = dirname(__DIR__, 3);
$favRel = trim((string)get_option('site.logo', ''));
$favAbs = $favRoot . $favRel;
if ($favRel === '' || !is_file($favAbs)) { $favRel = ''; }
$favVer = static function (string $rel) use ($favRoot): string {
    $abs = $favRoot . $rel;
    return (is_file($abs) ? $rel . '?v=' . filemtime($abs) : $rel);
};
$favIco = $favRel !== '' ? $favVer($favRel) : $favVer(View::asset('favicon.ico'));
$favPng = $favRel !== '' ? $favVer($favRel) : $favVer(View::asset('favicon-32.png'));
$fav16  = $favRel !== '' ? $favVer($favRel) : $favVer(View::asset('favicon-16.png'));
$favApp = $favRel !== '' ? $favVer($favRel) : $favVer(View::asset('apple-touch-icon.png'));
$favSvg = $favVer(View::asset('favicon.svg'));
?>
<link rel="icon" type="image/svg+xml" href="<?= e($favSvg) ?>">
<link rel="icon" href="<?= e($favIco) ?>" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="<?= e($favPng) ?>">
<link rel="icon" type="image/png" sizes="16x16" href="<?= e($fav16) ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= e($favApp) ?>">
<link rel="manifest" href="/site.webmanifest">
<?php /* ★ [lqalign2 2026-10-05] 样式顺序 = ① www 的 Tailwind 产物（令牌 + 预置 + 工具类）
        ② 实例既有 style.css（评论/阅读条/工具件/图库/目录等**功能件**的真实样式，必须留）
        ③ lqwww/lq-align.css（桥接：把功能件接到 www 的圆角/阴影/令牌上）
        ★ 冲突面只有两个令牌名（--accent / --muted）：style.css 里已改名成 --lq-accent / --lq-muted，
          故 www 的 hsl(var(--accent)) 与 style.css 的 var(--lq-accent) 互不干扰。 */ ?>
<link rel="stylesheet" href="<?= e(View::asset('lqwww/www.css')) ?>">
<link rel="stylesheet" href="<?= e(View::asset('style.css')) ?>">
<link rel="stylesheet" href="<?= e(View::asset('lqwww/lq-align.css')) ?>">
<link rel="stylesheet" href="<?= e(View::asset('tip-fig.css')) ?>">
<?= lq_mech_style() ?>
<?php
$tw = (string)theme_opt('width', '');
$tac = (string)theme_opt('accent', '');
if ($tw !== '' || ($tac !== '' && preg_match('/^#[0-9a-fA-F]{3,8}$/', $tac))) {
    echo '<style>:root{' . ($tw !== '' ? '--wide-w:' . (int)$tw . 'px;' : '')
        . (preg_match('/^#[0-9a-fA-F]{3,8}$/', $tac) ? '--lq-accent:' . $tac . ';' : '') . '}</style>';
}
?>
<?php $headStat = stat_html('stats.head'); if ($headStat !== '') { echo "\n<!-- stats.head -->\n" . $headStat; } ?>
<?php
/* JSON-LD：站点级 Organization + WebSite（每页输出；首页由静态产物直答，故不能"只在首页"）
   + parts/lq-mech.php 的按页节点（BreadcrumbList / Article / CollectionPage+ItemList /
   SoftwareApplication+ItemList）并进**同一个** @graph —— 全页恰 1 个 ld+json 块。 */
$_ycAlts = json_decode((string)get_option('site.alternate_names', ''), true);
$_ycAlts = is_array($_ycAlts)
    ? array_values(array_filter(array_map('strval', $_ycAlts), fn($v) => $v !== '')) : [];
$_ycBase = lqwww_origin();
$_ycNode = static function (string $type, string $id, string $inLang = '') use ($_ycBase, $_ycAlts): array {
    $n = ['@type' => $type, '@id' => $_ycBase . '/#' . $id, 'name' => site_name(), 'url' => $_ycBase . '/'];
    if ($_ycAlts !== []) { $n['alternateName'] = $_ycAlts; }
    if ($inLang !== '') { $n['inLanguage'] = $inLang; }
    return $n;
};
$_ycOrg = $_ycNode('Organization', 'org');
$_ycWeb = $_ycNode('WebSite', 'website', 'zh-CN');
$_ycWeb['publisher'] = ['@id' => $_ycOrg['@id']];
$_lqMech  = lq_mech_ctx((string)($main ?? ''), $_ycBase);
/* ★ lrlearn 2026-10-06：/learn 改用**参照字节骨架**（themes/bl/lqwww/skel/page_learn.html）。
   骨架自带 `.mx-auto.max-w-6xl` 包裹 + 面包屑 + `.grid.lg:grid-cols-[230px_1fr]` + 侧栏 + hero 卡；
   ★ `<main>` 仍由本 layout 发（唯一一处），骨架只负责它内部那段 —— 否则两个 <main> 嵌套。
   ⇒ 本 layout 对 /learn 让出「面包屑 + 内容列」，改为：骨架 → 正文 → 收口 3 个 </div>。
   开关由 partials/page.php 的 /learn 分支置位；其余页型一律走原逻辑（本块之外零改动）。
   回滚 = 删本块 + 删 page.php 里置位那一行。 */
if (($GLOBALS['YC_LQ_SHELL'] ?? '') === 'learn') {
    $__shell = '';
    if (function_exists('lqpg_learn_shell')) {
        $__h1   = trim((string)($GLOBALS['YC_LQ_LEARN_H1'] ?? ''));
        $__lead = (string)($GLOBALS['YC_LQ_LEARN_LEAD'] ?? '');
        if ($__h1 === '')   { $__h1 = trim((string)($_lqMech['h1'] ?? '')); }
        if ($__h1 === '')   { $__h1 = '浏览器学堂'; }
        if ($__lead === '') { $__lead = lqwww_lead((array)($_lqMech['row'] ?? []), 200); }
        $__shell = lqpg_learn_shell(
            function_exists('lqpg_crumbs_nav') ? lqpg_crumbs_nav([['首页', '/'], [$__h1, '']]) : '',
            function_exists('lqpg_sidebar') ? lqpg_sidebar('浏览器学堂', lqpg_sidebar_ctx('浏览器学堂', '/learn')) : '',
            e($__h1),
            e($__lead)
        );
    }
}
/* ★ lrlearn-one 2026-10-06：/learn/<slug> 的外壳**由 partials/post.php 自己发**
   （它已在正文里输出 包裹 + 面包屑 + 两栏 + 侧栏 + hero 卡 + 正文，并在阅读条之后收口）。
   ⇒ 本 layout 只做两件事：不套自己的「面包屑 + 内容列」，也不套 /learn 主页的骨架（两者都会重复）。
   开关值区分：'learn' = 主页（走上面的 page_learn 骨架）；其它非空值 = 完全让位、直接透传。 */
$__rawShell = !empty($GLOBALS['YC_LQ_SHELL']) && ($GLOBALS['YC_LQ_SHELL'] !== 'learn');
$_lqGraph = array_merge([$_ycOrg, $_ycWeb], lq_mech_ld($_lqMech, $_ycBase));
echo "\n" . '<script type="application/ld+json">'
    . json_encode(['@context' => 'https://schema.org', '@graph' => $_lqGraph],
                  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    . '</script>';
?>
<?php /* ★ [B3] 全站 SEO 头：canonical（恰 1 · 自指）· hreflang（有真译文才出）·
        description / keywords · OGP · Twitter Card —— **只在这一处**发，各页 view 不再发同类 meta。 */ ?>
<?= lqwww_head((string)$__seoTitle, !empty($_lqMech['is_entry']) ? 'article' : 'website') ?>
</head>
<body>
<div id="root">
<div class="flex min-h-screen flex-col">
<?= lqwww_header() ?>
<?php /* lqart: the reference /download/<slug> has nav.lq-crumbs + div.lq-meta BETWEEN the header and
   <main> (measured y=128 / y=153.5), which pushes <article> to y=181.6.  Emit them there —
   ONLY for the download family (tips/learn/gallery still have neither). */ ?>
<?php if (!empty($GLOBALS['YC_LQ_ART'])): ?>
<?= lq_mech_crumb_html($_lqMech) ?>
<?= lq_mech_meta_html($_lqMech) ?>
<?php endif; ?>
<?php /* ★ lqart（站主裁定②）：/download/<slug> 的参照外壳是 `<main class="main">`；其余页型
   一律 `<main class="container flex-1 py-8">`。只换这一个类名，不动别的。 */ ?>
<main class="<?= !empty($GLOBALS['YC_LQ_ART']) ? 'main' : 'container flex-1 py-8' ?>">
<?php if (!empty($GLOBALS['YC_LQ_SHELL']) && ($GLOBALS['YC_LQ_SHELL'] ?? '') === 'learn'): ?>
<?= $__shell ?><?= $main ?>
</div></div></div>
<?php elseif ($__rawShell): ?>
<?php /* /learn/<slug>：post.php 已把「包裹 + 面包屑 + 两栏 + 侧栏 + hero 卡 + 正文 + 收口」整幅发完 */ ?>
<?= $main ?>
<?php elseif (!empty($side)): ?>
<div class="mx-auto max-w-6xl px-4 py-6">
  <?php /* ★ lqart 2026-10-06：/tips · /learn · /gallery 详情页 = 参照里**没有**面包屑与
         文章元信息条（参照的面包屑由它自己的骨架发；元信息条参照完全没有）。
         layout 这两件为「实例老壳」遗留 ⇒ 由 post.php 置 YC_LQ_BARE 后在此让位。
         其余页型（置位为空）**一个字节不变**。回滚 = bash /root/lqart_rollback.sh */ ?>
  <?php if (empty($GLOBALS['YC_LQ_BARE'])): ?>
  <?= lq_mech_crumb_html($_lqMech) ?>
  <?php /* ★ 机制层不可丢：词条页的「最后更新」+ 朗读 / 打印（parts/lq-mech.php）。
         页面自身已有「最后更新」字样时，lq-mech 只补朗读/打印，不会出现第二个日期。 */ ?>
  <?= lq_mech_meta_html($_lqMech) ?>
  <?php endif; /* ★ lqart：YC_LQ_BARE 让位结束 */ ?>
  <div class="grid gap-8 lg:grid-cols-[230px_1fr]">
    <aside class="hidden lg:block">
      <div class="sticky top-20 space-y-4"><?= $side ?></div>
    </aside>
    <div class="min-w-0">
      <?= $main ?>
      <?= lq_mech_download_html($_lqMech) ?>
      <?= lq_mech_related_html($_lqMech) ?>
    </div>
  </div>
</div>
<?php else: ?>
<?php /* ★ lqart 2026-10-06（第二批）：post.php 置了 YC_NO_SIDE ⇒ $side 为空 ⇒ 详情页走的是
   **本 else 分支**（不是上面那条）。参照的 /tips/<slug>、/learn/<slug>、/gallery/<cat> 里
   没有布局层的面包屑（参照面包屑在它自己的骨架里）也没有 lq-meta 元信息条
   ⇒ 同样按 YC_LQ_BARE 让位；其余页型（未置位）**一个字节不变**。
   回滚 = bash /root/lqart_rollback.sh */ ?>
<?php if (!empty($GLOBALS['YC_LQ_NOSIDE'])): ?>
<?php /* lqart: page.php already emitted the single wrapper div (with the breadcrumb inside it,
   when the reference has it there) plus the read-count beacon; hand it out verbatim. */ ?>
<?= $GLOBALS['YC_LQ_PAGE_ONECOL'] ?? $main ?>
<?php else: ?>
<?php if (empty($GLOBALS['YC_LQ_BARE'])): ?>
<?= lq_mech_crumb_html($_lqMech) ?>
<?= lq_mech_meta_html($_lqMech) ?>
<?php endif; ?>
<?= $main ?>
<?php endif; ?>
<?= lq_mech_download_html($_lqMech) ?>
<?php endif; ?>
</main>

<?php /* ★ lqart 2026-10-06（站主裁定①）：去的是「看得见的文章形态」，**不是统计口径**。
   tips/learn/gallery 三族已无 reader-bar / __READER / reader.js，这里只补回**同源** POST /count
   的阅读数上报（与工具页 tool.php 末尾那份同口径）；不渲染任何可见内容、不建 window.__READER。
   放在 </main> **之后** ⇒ main 里一个字节不加（首子链与参照仍逐字符相同）。
   ★ 注意：post.php 的 `$__lqBare`/`$cid` 是**文件作用域局部变量**（View::render 的 include 作用域），
     layout 拿不到 ⇒ 必须先经 post.php 落到 `$GLOBALS['YC_LQ_BARE']` / `$GLOBALS['YC_LQ_CID']`。 */ ?>
<?php if (!empty($GLOBALS['YC_LQ_BARE']) && empty($GLOBALS['YC_LQ_ART']) && (int)($GLOBALS['YC_LQ_CID'] ?? 0) > 0): ?>
<script>
  (function () {
    try {
      var key = 'yc_view_' + <?= (int)$GLOBALS['YC_LQ_CID'] ?> + '_' + new Date().toISOString().slice(0, 10);
      if (localStorage.getItem(key)) { return; }
      localStorage.setItem(key, '1');
      var fd = new FormData();
      fd.append('content_id', String(<?= (int)$GLOBALS['YC_LQ_CID'] ?>));
      fetch('/count', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .catch(function () {});
    } catch (e) {}
  })();
</script>
<?php endif; ?>
<?php /* ★ lqart（站主裁定①）：/download/<slug> 也有 article-meta 的「阅读 N」 ⇒ 同样补上报
   （与上面 tips/learn/gallery 那条同口径，只是主体换成 download 一族）。 */ ?>
<?php if (!empty($GLOBALS['YC_LQ_ART']) && (int)($GLOBALS['YC_LQ_CID'] ?? 0) > 0): ?>
<script>
  (function () {
    try {
      var key = 'yc_view_' + <?= (int)$GLOBALS['YC_LQ_CID'] ?> + '_' + new Date().toISOString().slice(0, 10);
      if (localStorage.getItem(key)) { return; }
      localStorage.setItem(key, '1');
      var fd = new FormData();
      fd.append('content_id', String(<?= (int)$GLOBALS['YC_LQ_CID'] ?>));
      fetch('/count', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { var el = document.getElementById('lqViews'); if (el && d && d.views) { el.textContent = d.views; } })
        .catch(function () {});
    } catch (e) {}
  })();
</script>
<?php endif; ?>
<?php /* ★ lqart（站主裁定②）：参照 `/download/<slug>` 的 `<aside class="side">相关技巧</aside>`
   在 `</main>` **之后**（main 的直接子只有 article + aside.lq-rel）⇒ 这里补发。 */ ?>
<?php if (!empty($GLOBALS['YC_LQ_ART']) && !empty($GLOBALS['YC_LQ_DL_SIDE'])): ?>
<?= $GLOBALS['YC_LQ_DL_SIDE'] ?>
<?php endif; ?>
<?= lqwww_footer() ?>
<?= lqwww_fab() ?>
</div>
</div>
<?= lqwww_tail() ?>
<?php /* ★★ 2026-10-11 站主裁定 S2：深色开关已【搬进页头骨架】lqwww/skel/home_header.html（页头可见、id/aria/title 对齐老站）
    ⇒ 这里不再放 body 末尾的 hidden 占位按钮（★ 全页 #darkToggle 只能有 1 个）。
    ★ 页脚 <a class="js-dark-toggle">（lq-www.php）保留 —— 它靠 JS 转发到页头那个按钮。 */ ?>
<script>
(function () {
  var b = document.getElementById('darkToggle');
  var all = document.querySelectorAll('.js-dark-toggle');
  if (b && all.length) {
    for (var i = 0; i < all.length; i++) {
      all[i].addEventListener('click', function (e) { e.preventDefault(); b.click(); });
    }
  }
  var m = document.getElementById('lqMenuBtn'), p = document.getElementById('lqMenuPanel');
  if (m && p) {
    m.addEventListener('click', function () {
      var on = p.hasAttribute('hidden');
      if (on) { p.removeAttribute('hidden'); } else { p.setAttribute('hidden', ''); }
      m.setAttribute('aria-expanded', on ? 'true' : 'false');
    });
  }
})();
</script>
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
<?php $footStat = stat_html('stats.foot'); if ($footStat !== '') { echo "\n<!-- stats.foot -->\n" . $footStat; } ?>
<?php /* ★ [t22 d6a] 匿名访客**不加载**本脚本：它一进页就 fetch /api/auth/me（匿名必 401），
   浏览器把 401 记成一条控制台 error（t21 普查：1198 页各 1 条；JS 侧 catch 拦不住网络层响应码）。
   该提示只对「已登录 + 邮箱仍是迁移占位邮箱」的用户有意义 ⇒ 未登录时连 script 标签都不下发。
   登录访客带 yc_es_dyn=1 会绕过静态直出走动态渲染 ⇒ 命中本分支，功能不变。 */ ?>
<?php if (class_exists('Auth') && \Auth::check()): ?>
<script src="/themes/bl/lqlegacy/email-nudge.js?v=<?= substr(md5_file(__DIR__ . '/../lqlegacy/email-nudge.js'), 0, 12) ?>" defer></script>
<?php endif; ?>
</body>
</html>
