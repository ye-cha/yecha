<?php
/** 归档总览（文枢 Wenshu · 文档站风）：左导航(layout) + 按年分组列表
 *  $months — [(y,m,n)...] */
$lp = ui_lp();
$pfx = $lp === '' ? '/' : rtrim($lp, '/') . '/';

// 按年-月收集最新文章标题，供各月条目展示
$byYm = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents');
        foreach (Db::rows(
            "SELECT c.title, c.slug, COALESCE(c.published_at, c.created_at) AS dt"
            . " FROM {$T} c WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
            . " ORDER BY dt DESC LIMIT 300"
        ) as $r) {
            $ts = strtotime((string)$r['dt']);
            $k = date('Y', $ts) . '-' . date('m', $ts);
            if (!isset($byYm[$k])) { $byYm[$k] = ['title' => (string)$r['title'], 'slug' => (string)$r['slug']]; }
        }
    }
} catch (\Throwable $e) { $byYm = []; }

$items = [];
$total = 0;
foreach ($months as $mm) {
    $y = (int)$mm['y']; $m = (int)$mm['m']; $n = (int)$mm['n'];
    $total += $n;
    $items[] = [
        'y'   => $y,
        'm'   => $m,
        'n'   => $n,
        'url' => $lp . '/archives/' . $y . '/' . sprintf('%02d', $m) . '/',
        'head' => $byYm[$y . '-' . sprintf('%02d', $m)] ?? null,
    ];
}
$years = [];
foreach ($items as $it) { $years[$it['y']][] = $it; }
krsort($years);
?>
<nav class="ws-crumb" aria-label="面包屑">
  <a href="<?= e($pfx) ?>">首页</a><span>/</span><b><?= e(__('tt.arch')) ?></b>
</nav>

<div class="ws-doc-head">
  <h1><?= e(__('tt.arch')) ?></h1>
  <div class="ws-doc-headmeta">
    <span class="ws-count"><?= (int)$total ?> 篇</span>
    <span><?= count($years) ?> 个年份</span>
  </div>
</div>

<?php if (!$items): ?>
  <div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
<?php else: ?>
<div class="ws-arch">
  <?php foreach ($years as $y => $ms):
      $yn = 0; foreach ($ms as $it) { $yn += (int)$it['n']; }
  ?>
  <section class="ws-arch-year">
    <h2 class="ws-arch-y"><span><?= (int)$y ?></span><em><?= (int)$yn ?> 篇</em></h2>
    <div class="ws-arch-list">
      <?php foreach ($ms as $it):
          $head = $it['head'];
          $title = $head ? (string)$head['title'] : sprintf('%02d', (int)$it['m']) . ' 月更新';
          $href = $head ? ($pfx . rawurlencode((string)$head['slug']) . '.html') : $it['url'];
      ?>
      <div class="ws-arch-row">
        <a class="ws-arch-m" href="<?= e($it['url']) ?>"><?= sprintf('%02d', (int)$it['m']) ?> 月</a>
        <a class="ws-arch-t" href="<?= e($href) ?>"><?= e($title) ?></a>
        <span class="ws-arch-n"><?= (int)$it['n'] ?> 篇</span>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>
<?php endif; ?>
