<?php
/** 归档总览（云库 Yunku · 知识库风格）
 *  $months — [(y,m,n)...] */
$lp = ui_lp();
$items = [];
foreach ($months as $mm) {
    $y = (int)$mm['y']; $m = (int)$mm['m']; $n = (int)$mm['n'];
    $items[] = [
        'date' => $y . '年' . sprintf('%02d', $m) . '月',
        'url'  => $lp . '/archives/' . $y . '/' . sprintf('%02d', $m) . '/',
        'n'    => $n,
    ];
}
?>
<div class="page-head"><h1><?= e(__('tt.arch')) ?></h1></div>
<?php if (!$items): ?>
  <div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
<?php else: ?>
<div class="yk-arch">
  <?php foreach ($items as $it): ?>
    <a class="yk-arch-i" href="<?= e($it['url']) ?>">
      <span class="yk-arch-d"><?= e($it['date']) ?></span>
      <span class="yk-arch-n"><?= (int)$it['n'] ?> 篇</span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
