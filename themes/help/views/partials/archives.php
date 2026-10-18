<?php
/** 归档总览（素简 Sujian v2 · doku 风格：按年分组 divide-y 月份链接）
 *  $months — [(y,m,n)...] */
$lp = ui_lp();
$byYear = [];
foreach ($months as $mm) {
    $y = (int)$mm['y']; $m = (int)$mm['m']; $n = (int)$mm['n'];
    $byYear[$y][] = ['m' => $m, 'n' => $n];
}
ksort($byYear, SORT_NUMERIC);
$byYear = array_reverse($byYear, true);
?>
<div class="suj-page-head"><h1><?= e(__('tt.arch')) ?></h1></div>
<?php if (!$byYear): ?>
  <div class="suj-empty"><?= e(__('ui.empty_none')) ?></div>
<?php else: ?>
<div class="suj-arch">
  <?php foreach ($byYear as $y => $months): ?>
  <section class="suj-arch-year">
    <h2 class="suj-arch-yh"><?= (int)$y ?></h2>
    <ul class="suj-arch-list">
      <?php foreach ($months as $mm): $n = (int)$mm['n']; $m = (int)$mm['m']; ?>
      <li>
        <a class="suj-arch-a" href="<?= e($lp) ?>/archives/<?= (int)$y ?>/<?= sprintf('%02d', $m) ?>/">
          <span class="suj-arch-m"><?= sprintf('%02d', $m) ?>月</span>
          <span class="suj-arch-c"><?= $n ?> 篇</span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endforeach; ?>
</div>
<?php endif; ?>