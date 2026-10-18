<?php
/** 归档总览（kejiyuan 科技原 · 蓝 · 按年分组的卡片网格）
 *  $months — [(y,m,n)...] */
$lp = ui_lp();
$byYear = [];
foreach (($months ?? []) as $mm) {
    $byYear[(int)$mm['y']][] = $mm;
}
krsort($byYear);
?>
<div class="kj-band">
  <h1><?= e(__('tt.arch')) ?></h1>
</div>
<?php if (!$byYear): ?>
  <div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
<?php else: ?>
<div class="kj-arch-years">
  <?php foreach ($byYear as $y => $ms): ?>
  <section class="kj-arch-year">
    <h2><?= (int)$y ?> 年</h2>
    <div class="kj-arch-grid">
      <?php foreach ($ms as $mm): $m = (int)$mm['m']; ?>
      <a href="<?= e($lp . '/archives/' . (int)$y . '/' . sprintf('%02d', $m) . '/') ?>"><span><?= sprintf('%02d', $m) ?> 月</span><b><?= (int)$mm['n'] ?> 篇</b></a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>
<?php endif; ?>
