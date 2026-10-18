<?php
/** 品云 Pinyun —— 归档总览：按年分组
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
<section class="py-cat-banner py-cat-banner-idx">
  <div class="py-cat-in">
    <h1 class="py-cat-title"><?= e(__('tt.arch')) ?></h1>
    <div class="py-cat-meta"><span class="py-cat-count"><?= (int)$grand ?> 篇</span></div>
  </div>
</section>

<?php if (!$years): ?>
<div class="empty"><?= e(__('ui.empty_none')) ?></div>
<?php else: ?>
<div class="py-arch">
  <?php foreach ($years as $y => $ms):
      $yn = 0; foreach ($ms as $mv) { $yn += $mv['n']; }
  ?>
  <section class="py-arch-year">
    <div class="py-arch-yhead">
      <h2 class="py-arch-y"><?= (int)$y ?></h2>
      <span class="py-arch-yn"><?= (int)$yn ?> 篇</span>
    </div>
    <div class="py-arch-months">
      <?php foreach ($ms as $mv): ?>
      <a class="py-arch-m" href="<?= e($mv['url']) ?>">
        <span class="py-arch-mm"><?= sprintf('%02d', (int)$mv['m']) ?> 月</span>
        <i><?= (int)$mv['n'] ?></i>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>
<?php endif; ?>
