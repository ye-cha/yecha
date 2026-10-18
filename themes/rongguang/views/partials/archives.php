<?php
/** 归档总览（绒光 Rongguang · 按年份分组的纯文本列表）
 *  $months — [(y,m,n)...] */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';

$yearCount = [];
foreach (($months ?? []) as $mm) {
    $y = (int)$mm['y'];
    $yearCount[$y] = ($yearCount[$y] ?? 0) + (int)$mm['n'];
}

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
    $byYear[(int)date('Y', strtotime((string)$r['d']))][] = $r;
}
$years = array_unique(array_merge(array_keys($byYear), array_keys($yearCount)));
rsort($years);
?>
<div class="rg-page">
  <header class="rg-page-h">
    <h1 class="rg-page-t"><?= e(__('tt.arch')) ?></h1>
    <?php if ($years): ?><p class="rg-page-n"><?= array_sum($yearCount) ?: count($rows) ?> 篇 · <?= count($years) ?> 个年份</p><?php endif; ?>
  </header>

  <?php if (!$years): ?>
  <div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
  <?php else: ?>
  <?php foreach ($years as $y): ?>
  <section class="rg-year">
    <h2 class="rg-year-n"><?= (int)$y ?></h2>
    <?php if (!empty($byYear[$y])): ?>
    <div class="rg-list">
      <?php foreach ($byYear[$y] as $p):
        $dt = strtotime((string)$p['d']);
      ?>
      <article class="rg-row">
        <time class="rg-date" datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('m-d', $dt)) ?></time>
        <a class="rg-title" href="<?= e($base . rawurlencode((string)$p['slug']) . '.html') ?>"><?= e((string)$p['title']) ?></a>
      </article>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="rg-year-empty"><?= e(__('ui.empty_none')) ?></p>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
