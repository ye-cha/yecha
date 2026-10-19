<?php
/** 归档总览（跃思 Yuesi · 按年份分组的扁平纯列表）
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
            "SELECT title, slug, excerpt, content, COALESCE(published_at, created_at) AS d"
            . " FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL"
            . " ORDER BY d DESC LIMIT 200"
        );
    }
} catch (\Throwable $e) { $rows = []; }

$byYear = [];
foreach ($rows as $r) { $byYear[(int)date('Y', strtotime((string)$r['d']))][] = $r; }
$years = array_unique(array_merge(array_keys($byYear), array_keys($yearCount)));
rsort($years);
?>
<div class="ys-page ys-arch">
  <header class="ys-page-h">
    <h1 class="ys-page-t"><?= e(__('tt.arch')) ?></h1>
    <?php if ($years): ?>
    <p class="ys-page-n"><span><?= (int)count($rows) ?> 篇</span><span class="ys-dot">/</span><span><?= count($years) ?> 个年份</span></p>
    <?php endif; ?>
  </header>

  <?php if (!$years): ?>
  <div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
  <?php else: ?>
  <?php foreach ($years as $y): ?>
  <section class="ys-year">
    <h2 class="ys-year-n"><?= (int)$y ?></h2>
    <?php if (!empty($byYear[$y])): ?>
    <div class="ys-list">
      <?php foreach ($byYear[$y] as $p): ?>
        <?= View::render('partials/ys-item', [
            'p' => ['title' => $p['title'], 'slug' => $p['slug'], 'excerpt' => $p['excerpt'], 'content' => $p['content'], 'd' => $p['d']],
            'base' => $base,
        ]) ?>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="ys-year-empty"><?= e(__('ui.empty_none')) ?></p>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
