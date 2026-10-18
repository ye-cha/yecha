<?php
/** 柳川 Liuchuan —— 归档总览：网格卡片 + 按年份分组标题
 *  $months — [(y,m,n)…] ; $page_title */
$lp = ui_lp();
$years = [];
$total = 0;
foreach ((array)$months as $mm) {
    $y = (int)$mm['y']; $m = (int)$mm['m']; $n = (int)$mm['n'];
    $total += $n;
    if (!isset($years[$y])) { $years[$y] = ['n' => 0, 'ms' => []]; }
    $years[$y]['n'] += $n;
    $years[$y]['ms'][] = ['m' => $m, 'n' => $n, 'url' => $lp . '/archives/' . $y . '/' . sprintf('%02d', $m) . '/'];
}
krsort($years);
?>
<div class="lc-page">
  <header class="lc-head">
    <h1 class="lc-head-t"><?= e($page_title ?: __('tt.arch')) ?></h1>
    <div class="lc-head-m">
      <span class="lc-badge"><?= $total ?> 篇</span>
      <span class="lc-head-note"><?= count($years) ?> 个年份</span>
    </div>
  </header>

  <?php if ($years): ?>
  <?php foreach ($years as $y => $yd): ?>
  <section class="lc-year">
    <h2 class="lc-year-h"><span class="lc-year-n"><?= (int)$y ?></span><span class="lc-year-c"><?= (int)$yd['n'] ?> 篇</span></h2>
    <div class="lc-grid lc-grid-tight">
      <?php foreach ($yd['ms'] as $mo): ?>
      <a class="lc-card lc-month" href="<?= e($mo['url']) ?>">
        <span class="lc-month-m"><?= e(sprintf('%02d', (int)$mo['m'])) ?>月</span>
        <span class="lc-month-c"><b><?= (int)$mo['n'] ?></b> 篇</span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
  <?php else: ?>
  <div class="lc-empty"><?= e(__('ui.empty_none')) ?></div>
  <?php endif; ?>
</div>
