<?php
/** 归档总览：$months（链接带当前语言前缀，译文界点击仍留在该语言） */
$lp = ui_lp();
?>
<div class="page-head"><h1><?= e(__('tt.arch')) ?></h1></div>
<ul class="side-list" style="margin:14px 0;">
<?php foreach ($months as $mm): ?>
  <li><a href="<?= e($lp) ?>/archives/<?= (int)$mm['y'] ?>/<?= (int)$mm['m'] ?>/"><?= e(lang_month_item((int)$mm['y'], (int)$mm['m'], (int)$mm['n'])) ?></a></li>
<?php endforeach; ?>
</ul>
