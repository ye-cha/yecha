<?php
/** 派记 Paiji —— 归档总览：按年分组的笔记列表
 *  $months — [(y,m,n)...] */
$lp = ui_lp();
$years = [];
foreach (($months ?? []) as $mm) {
    $y = (int)$mm['y']; $m = (int)$mm['m'];
    $years[$y][] = ['m' => $m, 'n' => (int)$mm['n'], 'url' => $lp . '/archives/' . $y . '/' . sprintf('%02d', $m) . '/'];
}
krsort($years);
$grand = 0;
foreach ($years as $yv) { foreach ($yv as $mv) { $grand += $mv['n']; } }
?>
<header class="pj-list-head">
  <h1 class="pj-list-t"><?= e(__('tt.arch')) ?></h1>
  <div class="pj-list-meta"><span class="pj-list-count">共 <?= (int)$grand ?> 则</span></div>
</header>

<?php if (!$years): ?>
<div class="empty"><?= e(__('ui.empty_none')) ?></div>
<?php else: ?>
<div class="pj-arch">
  <?php foreach ($years as $y => $ms):
      $yn = 0; foreach ($ms as $mv) { $yn += $mv['n']; }
  ?>
  <section class="pj-arch-year">
    <h2 class="pj-arch-y"><span class="pj-arch-ynum"><?= (int)$y ?></span><span class="pj-arch-yn"><?= (int)$yn ?> 则</span></h2>
    <ul class="pj-arch-months">
      <?php foreach ($ms as $mv): ?>
      <li>
        <a href="<?= e($mv['url']) ?>">
          <span class="pj-mark" aria-hidden="true">✎</span>
          <span class="pj-arch-mm"><?= (int)$mv['m'] ?> 月</span>
          <i><?= (int)$mv['n'] ?> 则</i>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endforeach; ?>
</div>
<?php endif; ?>
