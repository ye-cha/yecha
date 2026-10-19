<?php

/** 工具页（★ lqta 2026-10-06 —— 按参照 `/tool/<slug>` 的**真实外形**重做）
 * ===========================================================================
 * 参照 137 页全量实测（逐页 curl，`--resolve <host>:443:127.0.0.1`）只有四段，136/136 同形：
 *   #root > div.flex.min-h-screen.flex-col
 *     header（站头，nav 9 项）
 *     main.container.flex-1.py-8
 *       div.space-y-6                                  ← main 的唯一子元素
 *         nav.mb-6 … aria-label="breadcrumb"           ← 3 段：首页 / 工具箱 / <本页标题>
 *         div > h1.text-3xl.font-bold + p.mt-2.max-w-2xl.text-muted-foreground
 *         工具块（99 页 = div.rounded-xl.border.bg-card.shadow-sm.mx-auto.w-full.max-w-3xl）
 *     footer（4 块，已对齐）
 *  ⇒ 参照工具页**没有**：侧栏 / 文章元信息条（更新于 · 使用N次 · 朗读打印）/ 上下**文章** /
 *    reader-bar / 评论区 · 段评 / 标签框 / 相关阅读。
 *  站主 2026-10-06 批准：**工具页上的文章形态一律去掉**；★ 文章页（/tips/* /learn/*）一个都不动。
 *  ★ 工具本体（DB content）一个字节不削 —— 我们控件比参照多是"数据差"，不是缺陷。
 *  ★ 上下【工具】：同分类里的上一个 / 下一个；到头停住不跨分类（理由见下）。
 *  ★ 分类导航仍然可达：面包屑的「工具箱」→ /tool，加一条「回该分类」→ /tool/c/<key>，
 *    再加最贴参照的 上/下一个工具。
 */
require_once __DIR__ . '/../../parts/lq-www.php';
require_once __DIR__ . '/../../parts/lq-page.php';

$p      = $post;
$cid    = (int)$p['id'];
$lp2    = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';
$store  = lqwww_store_slug((string)$p['slug']);
$tail   = str_starts_with($store, 'tool__') ? substr($store, 6) : $store;
$title  = lqwww_tool_title($store, (string)$p['title']);
$url    = yc_entry_url($store);
$lead   = lqwww_tool_lead((array)$p, 200);

/* 分类：真源与 /tool/c/{key} 完全同一份（themes/bl/parts/cat-def.php） */
$catKey = $catName = '';
if (!function_exists('yc_cat_table')) { require_once __DIR__ . '/../cat-def.php'; }
if (function_exists('yc_tool_cat') && function_exists('yc_cat_table')) {
    $catKey = yc_tool_cat($tail, (string)($p['description'] ?? ''));
    foreach (yc_cat_table() as $g) { if ($g[0] === $catKey) { $catName = (string)$g[1]; break; } }
}

/* 参照工具页只有一列 ⇒ 关掉 layout 的老右栏（老右栏的搜索 / 最新文章 / 归档在别的页型仍在） */
$GLOBALS['YC_NO_SIDE'] = true;
/* 参照工具页没有「更新于 / 朗读 / 打印」⇒ 关掉机制层的那一条。
   ★ 只置这个开关，机制层本身（给 /tips/* /learn/* 用的）一行没改逻辑，只多了一个 early-return。 */
$GLOBALS['YC_NO_MECH_META'] = true;

/* ★ 面包屑 = 参照的**3 段**：[首页, 工具箱, 本工具标题]（参照逐页如此，没有中间的分类那一级） */
$crumb = [['首页', '/'], ['工具箱', $lp2 . '/tool'], [$title, '']];

/* ★★ 上下【工具】（站主 2026-10-06：「上下工具，不是上下文章」）
 *   顺序口径：`parts/cat-def.php` 的 `yc_tools_by_cat()` —— 行按 `slug ASC` 取库，
 *   ⇒ 分类内的次序就是 slug 升序（与 /tool/c/{key} 筛出来的次序**同一次调用**，不会漂）。
 *   到头停住、**不跨分类**：标签写的是"同分类"，跨到别的分类会让标签说谎（站主已认可此理由）。
 *   ★ 文章详情页的上下篇在 views/partials/post.php 里，本文件**不碰**。 */
$prevT = $nextT = null; $catN = 0; $catPos = 0;
if ($catKey !== '' && function_exists('yc_tools_by_cat')) {
    $list = (array)(yc_tools_by_cat()[$catKey] ?? []);
    $catN = count($list);
    foreach ($list as $i => $it) {
        if ((string)$it['slug'] === $tail) {
            $catPos = (int)$i + 1;
            if ($i > 0) { $prevT = $list[$i - 1]; }
            if ($i + 1 < $catN) { $nextT = $list[$i + 1]; }
            break;
        }
    }
}
$en     = (Lang::short() === 'en');
$lbPrev = $en ? 'Previous tool' : '← 上一个工具';
$lbNext = $en ? 'Next tool' : '下一个工具 →';
$lbBack = $en ? 'Back to this category' : '回该分类';

$nav = '';
if ($prevT || $nextT || $catKey !== '') {
    $nav .= '<nav class="mt-6 grid gap-2 sm:grid-cols-2" aria-label="' . e($lbPrev) . ' / ' . e($lbNext) . '">';
    if ($prevT) {
        $nav .= '<a class="group flex items-start gap-2 rounded-xl border bg-card p-3 shadow-sm transition hover:border-primary" rel="prev" href="' . e($lp2 . yc_entry_url('tool__' . (string)$prevT['slug'])) . '">'
              . '<span class="mt-0.5 text-primary">' . lqwww_ico('arrow-left', 'h-3.5 w-3.5') . '</span>'
              . '<span class="min-w-0"><span class="block text-xs text-muted-foreground">' . e($lbPrev) . '</span>'
              . '<span class="line-clamp-2 text-sm">' . e((string)$prevT['title']) . '</span></span></a>';
    }
    if ($nextT) {
        $nav .= '<a class="group flex items-start gap-2 rounded-xl border bg-card p-3 shadow-sm transition hover:border-primary" rel="next" href="' . e($lp2 . yc_entry_url('tool__' . (string)$nextT['slug'])) . '">'
              . '<span class="mt-0.5 text-primary">' . lqwww_ico('arrow-right', 'h-3.5 w-3.5') . '</span>'
              . '<span class="min-w-0"><span class="block text-xs text-muted-foreground">' . e($lbNext) . '</span>'
              . '<span class="line-clamp-2 text-sm">' . e((string)$nextT['title']) . '</span></span></a>';
    }
    $nav .= '</nav>';
    /* ★ 2026-10-11 站主裁定 P1：/tool/c/* 撤除 ⇒ 面包屑「回该分类」摘除（旧址 301 → /tool） */
}
?>
<?= lqskel_fill(lqskel('detail_tool'), [
    'crumb'     => lqpg_crumbs($crumb),
    'h1'        => e($title),
    'lead'      => e($lead),
    'tool_body' => lq4_tool_fragment((string)$html, $tail),
    'tail'      => $nav,
], 'detail_tool') ?>

<?php /* 浏览量上报（★ 保留"阅读数/使用次数"这项功能；它原来挂在被去掉的那段 __READER 里，
         这里只留**同源** POST /count，不引第三方、不额外发请求给外部域名） */ ?>
<script>
  (function () {
    try {
      var key = 'yc_view_' + <?= $cid ?> + '_' + new Date().toISOString().slice(0, 10);
      if (localStorage.getItem(key)) { return; }
      localStorage.setItem(key, '1');
      var fd = new FormData();
      fd.append('content_id', String(<?= $cid ?>));
      fetch('/count', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d && d.views) { var el = document.getElementById('lqViews'); if (el) { el.textContent = d.views; } } })
        .catch(function () {});
    } catch (e) {}
  })();
</script>
