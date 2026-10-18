<?php
/** 码语 Mayu —— 归档总览：双栏骨架 + 按年份分组
 *  $months — [(y,m,n)…] ; $page_title */
$lp = ui_lp();
$years = [];
$total = 0;
foreach ((array)$months as $mm) {
    $y = (int)$mm['y']; $m = (int)$mm['m']; $n = (int)$mm['n'];
    $total += $n;
    if (!isset($years[$y])) { $years[$y] = ['n' => 0, 'ms' => []]; }
    $years[$y]['n'] += $n;
    $years[$y]['ms'][] = [
        'm'  => $m,
        'n'  => $n,
        'url' => $lp . '/archives/' . $y . '/' . sprintf('%02d', $m) . '/',
    ];
}
krsort($years);
?>
<div class="my-page">
  <header class="my-page-h">
    <h1 class="my-page-t"><?= e($page_title ?: __('tt.arch')) ?></h1>
    <div class="my-page-meta">
      <span class="my-badge"><?= $total ?> 篇</span>
      <span class="my-page-note"><?= count($years) ?> 个年份 · <?= count((array)$months) ?> 个月份</span>
    </div>
  </header>

  <div class="my-shell">
    <div class="my-feed">
      <?php if ($years): ?>
      <?php foreach ($years as $y => $yd): ?>
      <section class="my-year">
        <h2 class="my-year-h"><span class="my-year-n"><?= (int)$y ?></span><span class="my-year-c"><?= (int)$yd['n'] ?> 篇</span></h2>
        <div class="my-year-grid">
          <?php foreach ($yd['ms'] as $mo): ?>
          <a class="my-month" href="<?= e($mo['url']) ?>">
            <span class="my-month-m"><?= e(sprintf('%02d', (int)$mo['m'])) ?>月</span>
            <b><?= (int)$mo['n'] ?> 篇</b>
          </a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endforeach; ?>
      <?php else: ?>
      <div class="my-empty"><?= e(__('ui.empty_none')) ?></div>
      <?php endif; ?>
    </div>

    <?= View::render('partials/my-side') ?>
  </div>
</div>
