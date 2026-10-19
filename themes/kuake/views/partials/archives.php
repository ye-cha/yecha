<?php
/** 归档总览（kuake 夸克 · 红 · 双栏 + 按年份分组）
 *  $months — [(y,m,n)...] */
$lp = ui_lp();
$byYear = [];
foreach (($months ?? []) as $mm) {
    $byYear[(int)$mm['y']][] = $mm;
}
krsort($byYear);
?>
<div class="kk-archhead"><h1><?= e(__('tt.arch')) ?></h1></div>
<?php if (!$byYear): ?>
  <div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
<?php else: ?>
<div class="kk-shell">
  <div class="kk-archbody">
    <?php foreach ($byYear as $y => $ms): ?>
    <section class="kk-arch-year">
      <h2 class="kk-arch-yh"><?= (int)$y ?> 年</h2>
      <ul class="kk-arch-list">
        <?php foreach ($ms as $mm): $m = (int)$mm['m']; ?>
        <li><a href="<?= e($lp . '/archives/' . (int)$y . '/' . sprintf('%02d', $m) . '/') ?>"><span><?= sprintf('%02d', $m) ?> 月</span><b><?= (int)$mm['n'] ?> 篇</b></a></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endforeach; ?>
  </div>
  <?= View::render('partials/kk-widgets') ?>
</div>
<?php endif; ?>
