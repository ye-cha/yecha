<?php
/** 归档总览（新绿 Xinlv · 手维，勿用 tools/gen_parts.py 覆盖）
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
<div class="ar ar-astra">
  <?php foreach ($items as $it): ?>
    <a class="ar-i" href="<?= e($it['url']) ?>">
      <span class="ar-d"><?= e($it['date']) ?></span>
      <span class="ar-n"><?= (int)$it['n'] ?> 篇</span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
