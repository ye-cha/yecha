<?php
/** 归档总览（琪初 Qichu · 仿 qitry.vip 窄栏按年份分组）
 *  $months — [(y,m,n)...] */
$lp = ui_lp();
$groups = [];
foreach (($months ?? []) as $mm) {
    $y = (int)$mm['y']; $m = (int)$mm['m']; $n = (int)$mm['n'];
    $groups[$y][] = [
        'date' => sprintf('%02d', $m) . ' 月',
        'url'  => $lp . '/archives/' . $y . '/' . sprintf('%02d', $m) . '/',
        'n'    => $n,
    ];
}
krsort($groups);
?>
<div class="qc-head"><h1 class="qc-h"><?= e(__('tt.arch')) ?></h1></div>
<?php if ($groups): ?>
<?php foreach ($groups as $year => $items): ?>
  <h2 class="qc-arch-year"><?= (int)$year ?> 年</h2>
  <div class="qc-arch-list">
    <?php foreach ($items as $it): ?>
    <div class="qc-arch-item">
      <a href="<?= e($it['url']) ?>"><?= e($it['date']) ?> · <?= (int)$it['n'] ?> 篇</a>
      <time><?= (int)$it['n'] ?></time>
    </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<?php else: ?>
  <div class="empty"><?= e(__('ui.empty_none')) ?></div>
<?php endif; ?>
