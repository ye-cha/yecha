<?php
/** 独立页面（about / privacy-policy / links / pages … 等 type=page）
 *  ★ lqalign2 2026-10-05 —— 形态对齐 `www.liulanqi.com` 的静态页
 *  ★★ lq4 2026-10-06 —— ④ `/download` `/learn` 换壳（只换呈现层；DB 内容行一个字节不改）
 *     依据（本批实测参照 seo/download.html 与 seo/learn.html 的 <main>）：
 *       /download：main = `.space-y-8` + 面包屑 + h1 + lead + 18 卡，**无 `<aside>`**
 *       /learn   ：main = `.mx-auto.max-w-6xl.px-4.py-6` + 面包屑 + `.grid.lg:grid-cols-[230px_1fr]`
 *                  + aside（卡片式侧栏 `rounded-xl border bg-card p-3 shadow-sm`）+ `min-w-0`
 *  ★★ lrlearn 2026-10-06 —— `/learn` 改用**参照字节骨架**（与首页/列表页同一套"精仿"机制）：
 *     骨架 = `lqwww/skel/page_learn.html`，由 `_lqpix/src/mk_learn.py` 从参照
 *     `liulanqi.com/learn` 的真实 HTML **按字节区间**切出（不过任何序列化器），
 *     只把 4 个动态点换成占位符：{{crumb}} {{side}} {{h1}} {{lead}}。
 *     ⇒ /learn 的壳（mx-auto 包裹 · 面包屑 · 两栏网格 · 侧栏 · hero 卡 · h1/lead 位置）
 *       与参照逐字节同形；**DB 行（id=790）的富版正文一个字节不改**，
 *       原样接在骨架末尾（收口 3 个 </div> 由本文件补）。
 *     反假象断言见 mk_learn.py 末尾：参照字面量 / 任何 <section> / 任何 <h2> 都不得烘进骨架。
 *  ★★ lqn 2026-10-06 —— `/notes` 也走通栏壳（云笔记应用整幅，不要 `article` 圆角窄栏、不要老右栏）
 *     参照 = 线上 www.liulanqi.com/seo/notes.html 的 <main>：main 直接是
 *       `.space-y-3` + 面包屑（首页 > 云笔记）+ h1「Markdown 云笔记」+ 两栏应用（240px 侧栏 + 编辑器）。
 *     ⇒ 本分支只多输出一条面包屑（`lqwww_crumbs`，与参照同形），其余全部来自 DB 内容行自身。
 *     入参：$post $html（$fallback 可选）
 */
require_once __DIR__ . '/../../parts/lq-www.php';

$p  = $post;
$pt = strtotime((string)(($p['updated_at'] ?? '') ?: (($p['published_at'] ?? '') ?: ($p['created_at'] ?? ''))));
$crumb = [['首页', '/'], [(string)$p['title'], '']];

/* ★ ④ 壳选择：只对这两条 slug 生效（其余 type=page 走原样，例如 /about /privacy-policy） */
$__slug = (string)($p['slug'] ?? '');
$__lq4  = in_array($__slug, ['download', 'learn'], true);
$__lqn  = ($__slug === 'notes');           // ★ lqn：云笔记通栏壳
/* ★ lqart 2026-10-06（站主裁定④）—— 逐页对照参照 `liulanqi.com/seo/*.html` 实测：
   下面这些**顶层静态/功能页**在参照那边**都没有右侧栏**，而实例这边还挂着老右栏
   （搜索 / 最新文章 / 标签 / 归档）⇒ 在这里关掉，与参照一致。
   参照侧栏（如 `/share` 的 `aside.rounded-xl.border.bg-muted/30`）在它自己页面内部，
   不在本开关的范围内，单独处理。 */
$__lqNoSide = in_array($__slug, ['about', 'privacy-policy', 'bookmark', 'mobile', 'markdown',
                                 'browser-plugins', 'herontool', 'jizhang', 'share'], true);
if ($__lqNoSide) { $GLOBALS['YC_NO_SIDE'] = true; $GLOBALS['YC_LQ_NOSIDE'] = 1; }
/* lqart: main-first-child chain.  The reference wraps BOTH the breadcrumb and the body
   inside ONE div whose class is per-page (see the map below).  So for these 9 slugs this
   file emits the single wrapper (crumb inside when the reference has it there) and layout
   passes $main through untouched (YC_LQ_NOSIDE). */
$__lqWrapCls = ['about' => 'mx-auto max-w-3xl space-y-6', 'privacy-policy' => 'mx-auto max-w-3xl space-y-6',
                'bookmark' => 'space-y-6', 'mobile' => 'mx-auto max-w-6xl px-4 py-6',
                'markdown' => 'space-y-12', 'browser-plugins' => 'space-y-8',
                'herontool' => 'space-y-6', 'jizhang' => 'space-y-6',
                'share' => 'space-y-12'];
$__lqWrapCrumb = ['bookmark' => false];
/* ★ 2026-10-11 站主裁定①：/wallpaper 栏目已删（ye_contents id=713 → trash）⇒ 本专属分支一并摘除。 */
if ($__lqNoSide && $__slug === 'herontool') {
    /* reference /herontool: main -> div.space-y-6 -> sections (grid 1248px wide = 4 cols).
       Our instance wrapped the same body in an article card whose p-6/sm:p-8 padding shrank the
       grid to 1182px => it fell back to 3 columns and the page grew by ~7390px.
       Emit crumb + the body's own div.space-y-6 verbatim, no article shell. */
    $__htBody = trim((string)$html);
    if (preg_match('#<div class="space-y-6">.*</div>\s*$#s', $__htBody, $__htm)) { $__htBody = trim($__htm[0]); }
    $__htTitle = (string)preg_replace('/\s*[-\x{2014}\x{2013}]\s*liulanqi.*$/u', '', (string)$p['title']);
    ob_start();
    ?>
<div class="space-y-6">
<?= lqwww_crumbs([['首页', '/'], [$__htTitle, '']]) ?>
<?= $__htBody ?>
</div>
<?php
    $GLOBALS['YC_LQ_PAGE_ONECOL'] = ob_get_clean();
    return;
}
if ($__lqNoSide && $__slug === 'mobile') {
    /* reference /mobile: div.mx-auto.max-w-6xl.px-4.py-6 -> nav.crumb -> div.space-y-10 -> sections.
       The DB row body already holds that div.space-y-10 (wrapped in an extra .mx-auto div);
       emit crumb + that div verbatim, no article shell. */
    $__mbBody = trim((string)$html);
    if (preg_match('#<div class="space-y-10">.*</div>\s*$#s', $__mbBody, $__mbm)) { $__mbBody = trim($__mbm[0]); }
    $__mbTitle = (string)preg_replace('/\s*[-\x{2014}\x{2013}]\s*liulanqi.*$/u', '', (string)$p['title']);
    $__mbTitle = (string)preg_replace('/\s*[-\x{2014}\x{2013}]\s*.*$/u', '', $__mbTitle);
    ob_start();
    ?>
<div class="mx-auto max-w-6xl px-4 py-6">
<?= lqwww_crumbs([['首页', '/'], [$__mbTitle, '']]) ?>
<?= $__mbBody ?>
</div>
<?php
    $GLOBALS['YC_LQ_PAGE_ONECOL'] = ob_get_clean();
    return;
}
if ($__lqNoSide && $__slug === 'share') {
    /* reference /share: div.space-y-12 -> nav.crumb -> the 6 sections.
       The DB row body already contains those 6 sections (incl. the lg:grid-cols-[1fr_320px]
       grid and its aside) => emit crumb + raw body, no article shell, no extra lead. */
    ob_start();
    ?>
<div class="space-y-12">
<?= lqwww_crumbs([['首页', '/'], ['云笔记', '/notes'], [(string)preg_replace('/\s*[-\x{2014}\x{2013}]\s*liulanqi.*$/u', '', (string)$p['title']), '']]) ?>
<?php
    $__shBody = trim((string)$html);
    if (preg_match('#^<div class="space-y-12">(.*)</div>$#s', $__shBody, $__shm)) { $__shBody = trim($__shm[1]); }
    echo $__shBody;
?>
</div>
<?php
    $GLOBALS['YC_LQ_PAGE_ONECOL'] = ob_get_clean();
    return;
}
/* ★★ [lqmd2-20261011] `/markdown/syntax` 与 `/markdown/tools`（ye_contents 791/792）
 *   站主两次点名：「左侧还是文章模式」。参照实测（真浏览器 + seo 件按字节）：
 *     REF <main> 的**直接子**只有 1 个 `div.space-y-10` / `div.space-y-8`，
 *     面包屑 nav 与 h1 都在**它里面**；全页无 aside / article / 归档块。
 *   而本实例这两页的 DB 行 content **本身就是那个 div**（标签数 661/661、835/835 与参照相同）
 *   ⇒ 根因不在内容行，而在本文件的通用 `$__lqNoSide` 分支：它把正文再套进
 *     `div.space-y-6 > article.rounded-2xl.… > div#articleBody`，并在 layout 里带出
 *     老右栏（搜索 / 最新文章 / **文章归档** ⇒ `/archives/2026/10/` `/archives/2026/9/` 两条内链）。
 *   处置：**不套任何壳**，把 DB 行 content 整段原样输出（唯一 <main> 直接子 = 那个 div），
 *         与 `/notes`（同族通栏页）和参照逐项对齐。不动 DB 行一个字节。
 *   回滚：`cp -p <本文件>.lqmd2bak-* <本文件>` + 清 pages 缓存 + 站点构建器（见报告「一条命令回退」）。
 */
if ($__slug === 'markdown__syntax' || $__slug === 'markdown__tools') {
    $GLOBALS['YC_NO_SIDE']   = true;
    $GLOBALS['YC_LQ_NOSIDE'] = 1;
    $GLOBALS['YC_LQ_PAGE_ONECOL'] = (string)$html;
    return;
}
if ($__lqNoSide) {
    $__cls   = $__lqWrapCls[$__slug] ?? 'space-y-6';
    $__inner = (!isset($__lqWrapCrumb[$__slug]) || $__lqWrapCrumb[$__slug] !== false) ? lqwww_crumbs($crumb) : '';
    ob_start();
    if ($__slug === 'privacy-policy') {
        /* ★ t10（2026-10-14）：参照 /privacy = `div.mx-auto max-w-3xl space-y-6` + 面包屑 +
           `h1.text-3xl.font-bold`「隐私政策」+ `div.mt-8.space-y-6`（6 段卡）；行 768 已换成参照那 6 段
           ⇒ 这里只补外壳三件（不套 article 卡、不发日期行、h1 用参照层级）。 */
        $__pvT = (string)((string)$p['title']);
        echo '<div class="mx-auto max-w-3xl space-y-6">'
           . lqwww_crumbs([['首页', ui_lp() . '/'], [$__pvT, '']])
           . '<h1 class="text-3xl font-bold">' . e($__pvT) . '</h1>'
           . (string)$html
           . '</div>';
    } elseif ($__slug === 'browser-plugins') {        /* ★ t10（2026-10-14）：参照 /plugins 的 <main> 只有 1 个直接子 `div.space-y-8`，里面 =
           面包屑 + `<div><h1 class="text-3xl font-bold">浏览器插件</h1><p class="mt-2 …">…</p></div>` + 卡片网格；
           而 DB 行 721 的 content **本身就是那个 div**（只差面包屑与 h1）⇒ 不套 article 卡、不发日期行，
           把面包屑与 h1 注进行 content 里整段原样输出（行 content 一字节不改）。
           锚点 `^(<div class="space-y-8">)(<div>)` 必须恰 1 次，否则原样输出（fail-open）。 */
        $__bpT = (string)preg_replace('/\s*[-\x{2014}\x{2013}]\s*liulanqi.*$/u', '', (string)$p['title']);
        $__bpC = lqwww_crumbs([['首页', ui_lp() . '/'], [$__bpT, '']]);
        $__bpIn = (string)$html; $__bpN = 0;
        $__bpOut = preg_replace_callback('~^(<div class="space-y-8">)(<div>)~', function ($m) use ($__bpC, $__bpT) {
            return $m[1] . $__bpC . $m[2] . '<h1 class="text-3xl font-bold">' . e($__bpT) . '</h1>';
        }, $__bpIn, 1, $__bpN);
        echo (($__bpN === 1 && is_string($__bpOut)) ? $__bpOut : $__bpIn);
    } else {
    ?>
<div class="<?= e($__cls) ?>">
<?= $__inner ?>
  <article class="rounded-2xl border bg-card p-6 shadow-sm sm:p-8">
    <?php if (!empty($fallback) && Poly::on() && Lang::short() !== Poly::defaultLang()): ?>
    <div class="poly-notice"><?= e(__('poly.fallback')) ?> · <a class="text-primary hover:underline" href="/<?= e((string)$p['slug']) ?>.html"><?= e(__('poly.view_orig')) ?></a></div>
    <?php endif; ?>
    <header class="border-b pb-6">
      <h1 class="text-2xl font-bold leading-snug sm:text-3xl"><?= e($p['title']) ?></h1>
      <?php if ($pt): ?>
      <p class="mt-4 text-xs text-muted-foreground"><?= e(__('pg.updated_at')) ?><?= e(date('Y-m-d', $pt)) ?></p>
      <?php endif; ?>
    </header>
    <div class="tip-article mt-6 space-y-4 text-foreground" id="articleBody"><?= $html ?></div>
    <?php if (!empty($p['tags'])): ?>
    <div class="mt-6 flex flex-wrap items-center gap-2 border-t pt-6">
      <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground"><?= e(__('ui.tags_label')) ?></span>
      <?php foreach ($p['tags'] as $t): ?>
      <a class="inline-flex items-center rounded-full border px-3 py-1 text-xs text-muted-foreground transition hover:border-primary hover:text-primary" href="<?= e(Poly::termUrl('tag', $t)) ?>"><?= e(Poly::termName($t)) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </article>
</div>
<?php } /* ★ t10：browser-plugins 分支结束（该 slug 由上面直接输出行 content） */ if ((int)$p['id'] > 0): ?>
<script>
(function () {
  try {
    var key = 'yc_view_' + <?= (int)$p['id'] ?> + '_' + new Date().toISOString().slice(0, 10);
    if (localStorage.getItem(key)) { return; }
    localStorage.setItem(key, '1');
    var fd = new FormData();
    fd.append('content_id', String(<?= (int)$p['id'] ?>));
    fetch('/count', { method: 'POST', body: fd, credentials: 'same-origin' });
  } catch (e) {}
})();
</script>
<?php endif; ?>
<?php
    $GLOBALS['YC_LQ_PAGE_ONECOL'] = ob_get_clean();
    return;
}
if ($__lq4 || $__lqn) {
    require_once __DIR__ . '/../../parts/lq-page.php';
    $GLOBALS['YC_NO_SIDE'] = true; // 关掉 layout 的老右栏（老右栏在其它页型仍在）
}
?>
<?php if ($__lqn): ?>
<?php /* ★★ [lqmd6-notes-crumb 2026-10-11 · 站主已批] 参照 /notes 的 <main> 只有 **1 个直接子**
   `div.space-y-3`，面包屑 nav 在它**里面**；我方原来把面包屑放在 <main> 的直接子位
   ⇒ 直接子链是 2 项、首子不是同一个元素。这里把两条包进参照那个 `div.space-y-3`。
   ★ 视觉不动（h1 两侧同 class 同 y=141）；DB 行 716 一个字节不改。
   回滚：cp -p <本文件>.lqmd6bak-* <本文件> + 清 pages 缓存 + 构建器 sync。 */ ?>
<div class="space-y-3">
<?= lqwww_crumbs([['首页', '/'], ['云笔记', '']]) ?>
<?= $html ?>
</div>
<?php elseif ($__lq4): ?>
<?php if ($__slug === 'learn' && function_exists('lqpg_learn_shell') && lqpg_learn_shell_ready()): ?>
<?php /* ★ lrlearn：/learn = 参照字节骨架（mx-auto 包裹 + 面包屑 + 两栏网格 + 侧栏 + hero 卡）
       + DB 行（id=790）的富版正文，原样接在骨架之后。
       ① 骨架由 _lqpix/src/mk_learn.py 从参照 liulanqi.com/learn 的真实 HTML**按字节区间**切出；
       ② **不在这里发 h1/日期**（hero 卡里的 h1 就是参照原位那一个）——发了就重复；
       ③ 开关 YC_LQ_SHELL 让 layout.php 整段让位：由它发唯一的 <main>、输出外壳、收口 3 个 </div>
          （mx-auto 包裹 / grid / 内容列）——否则 layout 自己的 lq-crumbs + main 会和参照外壳
          **嵌套成两个 <main> + 两条面包屑 + 两个 h1**（第一轮真踩到）。 */ ?>
<?php $GLOBALS['YC_LQ_SHELL'] = 'learn'; ?>
<?php /* h1 / lead 由本文件（数据侧）决定，经全局交给 layout 填进参照骨架的 {{h1}}/{{lead}}；
       这样 /learn 的标题与导语仍走 DB 行自身的 title/description（不烘参照字面量进骨架）。 */ ?>
<?php $GLOBALS['YC_LQ_LEARN_H1']   = lqwww_tool_title($__slug, (string)$p['title']); ?>
<?php /* hero 导语：参照的 lead 是**一行**（55 字）；用 DB 行自己的 description，
       但压到与参照同级的一行长度（否则 hero 卡高 130→154，后面整页下移 24px）。
       ★ 只影响 hero 那一个数据点，DB 行内容一个字节不动。 */ ?>
<?php $GLOBALS['YC_LQ_LEARN_LEAD'] = lqwww_lead((array)$p, 44); ?>
<?= $html ?>
<?php else: ?>
<?php if ($__slug !== 'download'): ?>
<h1 class="text-3xl font-bold"><?= e(lqwww_tool_title($__slug, (string)$p['title'])) ?></h1>
<?php if ($pt): ?><p class="mt-2 text-sm text-muted-foreground">最后更新 <?= e(date('Y-m-d', $pt)) ?></p><?php endif; ?>
<?php endif; ?>
<?php if ($__slug === 'learn'): ?>
<div class="mt-6 grid gap-8 lg:grid-cols-[230px_1fr]">
  <?= lqpg_sidebar('浏览器学堂', lqpg_sidebar_ctx('浏览器学堂', '/learn')) ?>
  <div class="min-w-0"><?= $html ?></div>
</div>
<?php elseif ($__slug === 'download'): ?>
<?php
    /* ★ t10（2026-10-14）：参照 /download（type=page 行 711）的 <main> 只有 1 个直接子
       `div.space-y-8`，面包屑 nav 与 h1 都**在它里面**；且参照**没有**「最后更新」那行。
       做法：① 关掉 layout 的 lq-crumbs + lq-meta（YC_LQ_BARE）；
             ② 把站级同形面包屑 + h1 插进 DB 行 content 开头 `div.space-y-8` 的第一个子 div 之内。
       锚点 `^(<div class="space-y-8">)(<div>)` 必须恰 1 次，否则原样输出（fail-open，绝不半改）。
       DB 行一个字节不改。回滚 = cp -p 备份 本文件 + 清 pages 缓存 + 构建器 sync。 */
    $GLOBALS['YC_LQ_BARE'] = 1;
    $__dlT  = lqwww_tool_title($__slug, (string)$p['title']);
    $__dlNv = '<nav class="mb-6 flex items-center gap-1 text-sm text-muted-foreground" aria-label="breadcrumb">'
            . lqpg_crumbs([['首页', ui_lp() . '/'], [$__dlT, '']]) . '</nav>';
    $__dlIn = (string)$html; $__dlCnt = 0;
    $__dlOut = preg_replace_callback('~^(<div class="space-y-8">)(<div>)~', function ($m) use ($__dlNv, $__dlT) {
        return $m[1] . $__dlNv . $m[2] . '<h1 class="text-3xl font-bold">' . e($__dlT) . '</h1>';
    }, $__dlIn, 1, $__dlCnt);
    echo (($__dlCnt === 1 && is_string($__dlOut)) ? $__dlOut : $__dlIn);
?>
<?php else: ?>
<div class="mt-6 space-y-8"><?= $html ?></div>
<?php endif; ?><?php endif; ?>
<?php else: ?>
<?= lqwww_crumbs($crumb) ?>
<div class="min-w-0">
  <article class="rounded-2xl border bg-card p-6 shadow-sm sm:p-8">
    <?php if (!empty($fallback) && Poly::on() && Lang::short() !== Poly::defaultLang()): ?>
    <div class="poly-notice"><?= e(__('poly.fallback')) ?> · <a class="text-primary hover:underline" href="/<?= e((string)$p['slug']) ?>.html"><?= e(__('poly.view_orig')) ?></a></div>
    <?php endif; ?>
    <header class="border-b pb-6">
      <h1 class="text-2xl font-bold leading-snug sm:text-3xl"><?= e($p['title']) ?></h1>
      <?php if ($pt): ?>
      <p class="mt-4 text-xs text-muted-foreground"><?= e(__('pg.updated_at')) ?><?= e(date('Y-m-d', $pt)) ?></p>
      <?php endif; ?>
    </header>
    <div class="tip-article mt-6 space-y-4 text-foreground" id="articleBody"><?= $html ?></div>
    <?php if (!empty($p['tags'])): ?>
    <div class="mt-6 flex flex-wrap items-center gap-2 border-t pt-6">
      <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground"><?= e(__('ui.tags_label')) ?></span>
      <?php foreach ($p['tags'] as $t): ?>
      <a class="inline-flex items-center rounded-full border px-3 py-1 text-xs text-muted-foreground transition hover:border-primary hover:text-primary" href="<?= e(Poly::termUrl('tag', $t)) ?>"><?= e(Poly::termName($t)) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ((int)$p['id'] > 0): ?>
    <script>
    (function () {
      try {
        var key = 'yc_view_' + <?= (int)$p['id'] ?> + '_' + new Date().toISOString().slice(0, 10);
        if (localStorage.getItem(key)) { return; }
        localStorage.setItem(key, '1');
        var fd = new FormData();
        fd.append('content_id', String(<?= (int)$p['id'] ?>));
        fetch('/count', { method: 'POST', body: fd, credentials: 'same-origin' });
      } catch (e) {}
    })();
    </script>
    <?php endif; ?>
  </article>
</div>
<?php endif; ?>
