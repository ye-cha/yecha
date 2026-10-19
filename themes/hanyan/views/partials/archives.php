<?php
/** 归档总览（寒砚 Hanyan）—— 时间线形态：左侧竖线 + 圆点节点，节点 = 日期 + 文章标题
 *  $months — [(y,m,n)...] */
$lp = ui_lp();
$pfx = $lp === '' ? '/' : rtrim($lp, '/') . '/';

// 按「年-月」收集文章标题，供时间线节点展示
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
            if (!isset($byYm[$k])) { $byYm[$k] = []; }
            $byYm[$k][] = ['title' => (string)$r['title'], 'slug' => (string)$r['slug'], 'ts' => $ts];
        }
    }
} catch (\Throwable $e) { $byYm = []; }

$items = [];
$total = 0;
foreach ($months as $mm) {
    $y = (int)$mm['y']; $m = (int)$mm['m']; $n = (int)$mm['n'];
    $total += $n;
    $items[] = [
        'y'    => $y,
        'm'    => $m,
        'n'    => $n,
        'url'  => $lp . '/archives/' . $y . '/' . sprintf('%02d', $m) . '/',
        'list' => $byYm[$y . '-' . sprintf('%02d', $m)] ?? [],
    ];
}
$years = [];
foreach ($items as $it) { $years[$it['y']][] = $it; }
krsort($years);
?>
<div class="hy-head">
  <h1><?= e(__('tt.arch')) ?></h1>
  <div class="hy-head-meta">
    <span class="hy-head-count"><?= (int)$total ?> 篇</span>
    <span><?= count($years) ?> 个年份 · <?= count($items) ?> 个月份</span>
  </div>
</div>

<?php if (!$items): ?>
  <div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
<?php else: ?>
<div class="hy-timeline">
  <?php foreach ($years as $y => $ms):
      $yn = 0; foreach ($ms as $it) { $yn += (int)$it['n']; }
  ?>
    <div class="hy-tl-year"><?= (int)$y ?><em><?= (int)$yn ?> 篇</em></div>
    <?php foreach ($ms as $it):
        $head = $it['list'][0] ?? null;
        $title = $head ? (string)$head['title'] : sprintf('%02d', (int)$it['m']) . '月归档';
        $href = $head ? ($pfx . rawurlencode((string)$head['slug']) . '.html') : $it['url'];
    ?>
    <a class="hy-tl-item" href="<?= e($href) ?>">
      <span class="hy-tl-date"><?= sprintf('%02d', (int)$it['m']) ?>月</span>
      <span class="hy-tl-title"><?= e($title) ?></span>
      <span class="hy-tl-n"><?= (int)$it['n'] ?> 篇</span>
    </a>
    <?php if ((int)$it['n'] > 1): ?>
    <a class="hy-tl-more" href="<?= e($it['url']) ?>">查看 <?= sprintf('%02d', (int)$it['m']) ?> 月全部 <?= (int)$it['n'] ?> 篇 →</a>
    <?php endif; ?>
    <?php endforeach; ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>
