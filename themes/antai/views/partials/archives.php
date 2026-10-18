<?php
/** 案台 Antai —— 归档总览：「归档窗口」+ 窗口内按年分组的月份图标
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
<div class="at-page">
<div class="at-win">
  <div class="at-win-bar">
    <span class="at-dots" aria-hidden="true"><i class="at-dot at-dot-r"></i><i class="at-dot at-dot-y"></i><i class="at-dot at-dot-g"></i></span>
    <span class="at-win-title"><?= e(__('tt.arch')) ?></span>
    <span class="at-win-x" aria-hidden="true">×</span>
  </div>
  <div class="at-win-body">
    <div class="at-head">
      <h1 class="at-head-t"><?= e(__('tt.arch')) ?></h1>
      <div class="at-head-m"><span class="at-badge"><?= count($months) ?> 个月份</span></div>
    </div>

    <?php if (!$years): ?>
    <div class="at-empty"><?= e(__('ui.empty_none')) ?></div>
    <?php else: ?>
    <?php foreach ($years as $y => $ms):
        $yn = 0; foreach ($ms as $mm) { $yn += (int)$mm['n']; }
    ?>
    <section class="at-year">
      <h2 class="at-year-h"><span class="at-year-n"><?= (int)$y ?></span><span class="at-year-c"><?= (int)$yn ?> 篇</span></h2>
      <div class="at-desk at-desk-in">
        <?php foreach ($ms as $mm): ?>
        <article class="at-icon at-icon-sm">
          <a class="at-icon-a" href="<?= e($mm['url']) ?>">
            <span class="at-icon-box"><span class="at-icon-glyph">🗂</span></span>
            <span class="at-icon-label"><?= e(sprintf('%02d', (int)$mm['m'])) ?> 月</span>
            <span class="at-icon-n"><?= (int)$mm['n'] ?></span>
          </a>
        </article>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
</div>
