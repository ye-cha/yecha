<?php
/** 皮蛋 Pidan —— 归档总览：窄栏内按年分组的卡片
 *  $months — [(y,m,n)...] */
$lp = ui_lp();
$years = [];
foreach ($months as $mm) {
    $y = (int)$mm['y'];
    $years[$y][] = [
        'm'   => (int)$mm['m'],
        'n'   => (int)$mm['n'],
        'url' => $lp . '/archives/' . $y . '/' . sprintf('%02d', (int)$mm['m']) . '/',
    ];
}
krsort($years);
?>
<div class="pd-page">
  <header class="pd-head">
    <h1 class="pd-head-t"><?= e(__('tt.arch')) ?></h1>
    <div class="pd-head-m"><span class="pd-badge"><?= count($months) ?> 个月份</span></div>
  </header>

  <?php if (!$years): ?>
  <div class="pd-empty"><?= e(__('ui.empty_none')) ?></div>
  <?php else: ?>
  <?php foreach ($years as $y => $ms):
      $yn = 0; foreach ($ms as $mm) { $yn += (int)$mm['n']; }
  ?>
  <section class="pd-year">
    <h2 class="pd-year-h">
      <span class="pd-year-n"><?= (int)$y ?></span>
      <span class="pd-year-c"><?= (int)$yn ?> 篇</span>
    </h2>
    <div class="pd-year-grid">
      <?php foreach ($ms as $mm): ?>
      <a class="pd-month" href="<?= e($mm['url']) ?>">
        <span class="pd-month-m"><?= e(sprintf('%02d', (int)$mm['m'])) ?> 月</span>
        <span class="pd-month-c"><b><?= (int)$mm['n'] ?></b> 篇</span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
