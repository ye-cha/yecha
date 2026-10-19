<?php
/** 工具分类 chip 条（★ 单一真源：数据与计数都取自 parts/cat-def.php，与首页分类卡、筛选页同一份）
 *
 *  为什么要有这个文件：筛选项只从首页可达 ⇒ 访客落在 /tool 时**没有任何途径发现那 11 个分类**，
 *  等于"分类页存在但不可达"。本行把 11 个分类铺在列表页顶部。
 *
 *  用法：调用方先设 $GLOBALS['YC_CAT_CHIP']（'all' 或分类键），再 include 本文件。
 *  ★ 计数 = count(yc_tools_by_cat()[key]) —— 与 /tool/c/{key}/page/{n} 的 total 是**同一次调用**的结果。
 *  回滚 = 删本文件 + 删调用方那两处设置 + 删 style.css 里的 .cat-chips 块。
 */
$__byCat = yc_tools_by_cat();
$__cur   = (string)($GLOBALS['YC_CAT_CHIP'] ?? '');
$__total = 0;
foreach (yc_cat_table() as $__g) { $__total += count($__byCat[$__g[0]] ?? []); }

/* ★ 与首页同一道闸（阈值 15/10 在首页那段注释里；改一处要改两处，已登记在文档） */
$CAT_CAP = 15; $CAT_SHOWTOP = 10;
$__shown = [];
$__nz = 0;
foreach (yc_cat_table() as $__g) { if (count($__byCat[$__g[0]] ?? []) > 0) { $__nz++; } }
if ($__nz > $CAT_CAP) {
    foreach (yc_cat_table() as $__g) { $__shown[] = (string)$__g[0]; }
    usort($__shown, fn($a, $b) => [count($__byCat[$b] ?? []), $a] <=> [count($__byCat[$a] ?? []), $b]);
    $__shown = array_slice($__shown, 0, $CAT_SHOWTOP);
}
?>
<nav class="cat-chips" aria-label="工具分类">
  <a class="cat-chip<?= $__cur === 'all' ? ' on' : '' ?>" href="/tool">全部 <b><?= (int)$__total ?></b></a>
  <?php /* ★ 2026-10-11 站主裁定 P1：/tool/c/* 撤除 ⇒ 分类 chips 摘除（旧址 301 → /tool） */ ?>
</nav>
<?php
$__byCat = null; $__cur = null; $__total = null; $__g = null; $__k = null; $__n = null;
