<?php
/** 归档总览 · 墨白 Mobai v2（对标 u.sb /archives/ 形态）
 *  形态：居中页头 → 按年分组（年份 24px 弱化标题 + divide-y 文章行：等宽月份 + 标题 + 篇数 chip）。
 *  清单 ①：归档页与全站同一套卡片/分隔线语言，无 astra 残留。
 *  手维文件，勿用 tools/gen_parts.py 覆盖。 */
$lp = ui_lp();
$years = [];
$total = 0;
foreach (($months ?? []) as $mm) {
    $y = (int)$mm['y'];
    $years[$y][] = $mm;
    $total += (int)$mm['n'];
}
krsort($years);
$sp = '<i class="dot" style="width:4px;height:4px;top:36%;right:27%"></i><i style="width:12px;height:12px;top:58%;right:22%"></i>';
?>
<section class="us-hero">
  <div class="us-sparkles" aria-hidden="true"><?= $sp ?></div>
  <div class="us-hero-in">
    <h1><?= e(__('tt.arch')) ?></h1>
    <div class="us-meta"><span>共 <?= count($years) ?> 年</span><span><?= count($months ?? []) ?> 个月</span><span><?= $total ?> 篇</span></div>
  </div>
</section>

<?php if (!$years): ?>
<div class="us-empty"><?= e(__('ui.empty_none')) ?></div>
<?php else: ?>
<div class="us-arch">
  <?php foreach ($years as $y => $ms): ?>
  <section class="us-arch-sec">
    <h2><?= (int)$y ?></h2>
    <div class="us-arch-list">
      <?php foreach ($ms as $mm):
          $m = (int)$mm['m']; $n = (int)$mm['n'];
          $url = $lp . '/archives/' . $y . '/' . sprintf('%02d', $m) . '/';
      ?>
      <div class="us-arch-row">
        <time><?= sprintf('%02d', $m) ?> 月</time>
        <a href="<?= e($url) ?>"><?= (int)$y ?> 年 <?= sprintf('%02d', $m) ?> 月</a>
        <span class="us-chip"><?= $n ?> 篇</span>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>
<?php endif; ?>
