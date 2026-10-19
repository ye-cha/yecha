<?php
/** 归档总览（独一 Duyi · 仿 the0n3.top 按年份分组的紧凑列表）
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
<div class="dy-head"><h1 class="dy-h"><?= e(__('tt.arch')) ?></h1></div>
<?php if ($groups): ?>
<?php foreach ($groups as $year => $items): ?>
  <h2 class="dy-arch-year"><?= (int)$year ?> 年</h2>
  <div class="dy-arch-list">
    <?php foreach ($items as $it): ?>
    <div class="dy-arch-item">
      <a href="<?= e($it['url']) ?>"><?= e($it['date']) ?> · <?= (int)$it['n'] ?> 篇</a>
      <time><?= (int)$it['n'] ?></time>
    </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<?php else: ?>
  <div class="empty"><?= e(__('ui.empty_none')) ?></div>
<?php endif; ?>
