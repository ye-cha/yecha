<?php
/** 归档总览（黄羽 Huangyu · 按年份分组）
 *  $months — [(y,m,n)...] */
$lp = ui_lp();
$homeHref = $lp === '' ? '/' : $lp . '/';

$years = [];
foreach (($months ?? []) as $mm) {
    $y = (int)$mm['y']; $m = (int)$mm['m']; $n = (int)$mm['n'];
    $years[$y][] = [
        'date' => $y . '年' . sprintf('%02d', $m) . '月',
        'url'  => $lp . '/archives/' . $y . '/' . sprintf('%02d', $m) . '/',
        'n'    => $n,
    ];
}
krsort($years);
?>
<nav class="sub hyu-crumb" aria-label="breadcrumb">
  <a href="<?= e($homeHref) ?>">首页</a>
  <span class="hyu-sep">/</span><span class="hyu-crumb-t"><?= e(__('tt.arch')) ?></span>
</nav>

<div class="hyu-head">
  <h1 class="hyu-head-t"><?= e(__('tt.arch')) ?></h1>
</div>

<?php if (!$years): ?>
  <div class="hyu-empty"><?= e(__('ui.empty_none')) ?></div>
<?php else: ?>
<div class="hyu-arch">
  <?php foreach ($years as $y => $ms): ?>
  <section class="hyu-arch-y">
    <h2 class="hyu-arch-yr"><?= (int)$y ?></h2>
    <div class="hyu-arch-list">
      <?php foreach ($ms as $it): ?>
      <a class="hyu-arch-i" href="<?= e($it['url']) ?>">
        <span class="hyu-arch-d"><?= e($it['date']) ?></span>
        <span class="hyu-arch-n"><?= (int)$it['n'] ?> 篇</span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>
<?php endif; ?>
