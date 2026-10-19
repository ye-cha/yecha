<?php
/** 归档总览（星澈 Xinglan · 按年份分组：年份大标题 + 该年文章列表）
 *  $months — [(y,m,n)...] */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';

// 各年篇数（来自 $months）
$yearCount = [];
foreach (($months ?? []) as $mm) {
    $y = (int)$mm['y'];
    $yearCount[$y] = ($yearCount[$y] ?? 0) + (int)$mm['n'];
}

// 各年文章（标题 + 日期）
$rows = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents');
        $rows = Db::rows(
            "SELECT title, slug, COALESCE(published_at, created_at) AS d"
            . " FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL"
            . " ORDER BY d DESC LIMIT 120"
        );
    }
} catch (\Throwable $e) { $rows = []; }

$byYear = [];
foreach ($rows as $r) {
    $y = (int)date('Y', strtotime((string)$r['d']));
    $byYear[$y][] = $r;
}
$years = array_unique(array_merge(array_keys($byYear), array_keys($yearCount)));
rsort($years);
?>
<div class="xl-ar">
  <div class="xl-ar-head">
    <h1 class="xl-ar-title"><?= e(__('tt.arch')) ?></h1>
    <?php if ($years): ?><span class="xl-ar-total"><?= count($years) ?> 个年份 · <?= array_sum($yearCount) ?: count($rows) ?> 篇文章</span><?php endif; ?>
  </div>

  <?php if (!$years): ?>
  <div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
  <?php else: ?>
  <?php foreach ($years as $y):
      $list = $byYear[$y] ?? [];
  ?>
  <section class="xl-ar-year">
    <div class="xl-ar-yhead">
      <h2 class="xl-ar-ynum"><?= (int)$y ?></h2>
      <span class="xl-ar-ycount"><?= (int)($yearCount[$y] ?? count($list)) ?> 篇</span>
    </div>
    <?php if ($list): ?>
    <ul class="xl-ar-list">
      <?php foreach ($list as $p):
        $dt = strtotime((string)$p['d']);
      ?>
      <li>
        <time class="xl-ar-date" datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('m-d', $dt)) ?></time>
        <a class="xl-ar-link" href="<?= e($base . rawurlencode((string)$p['slug']) . '.html') ?>"><?= e((string)$p['title']) ?></a>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="xl-ar-empty"><?= e(__('ui.empty_none')) ?></p>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
