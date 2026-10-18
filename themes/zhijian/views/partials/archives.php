<?php
/** 归档总览（纸笺 Zhijian · 按年份分组的卡片列表）
 *  $months — [(y,m,n)...] */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';

$yearCount = [];
foreach (($months ?? []) as $mm) {
    $y = (int)$mm['y'];
    $yearCount[$y] = ($yearCount[$y] ?? 0) + (int)$mm['n'];
}

$rows = [];
$tagMap = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents');
        $rows = Db::rows(
            "SELECT c.id, c.title, c.slug, c.excerpt, c.content, COALESCE(c.published_at, c.created_at) AS d"
            . " FROM {$T} c WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
            . " ORDER BY d DESC LIMIT 120"
        );
        if ($rows) {
            $ids = implode(',', array_map('intval', array_column($rows, 'id')));
            $M = Db::t('metas'); $R = Db::t('relationships');
            $rels = Db::rows(
                "SELECT r.content_id, m.name, m.slug, m.name_en, m.names_i18n, m.slug_i18n"
                . " FROM {$R} r JOIN {$M} m ON m.id=r.meta_id"
                . " WHERE r.content_id IN ($ids) AND m.type='tag' ORDER BY m.`order` ASC, m.id ASC"
            );
            foreach ($rels as $r) { $tagMap[(int)$r['content_id']][] = $r; }
        }
    }
} catch (\Throwable $e) { $rows = []; $tagMap = []; }

$byYear = [];
foreach ($rows as $r) { $byYear[(int)date('Y', strtotime((string)$r['d']))][] = $r; }
$years = array_unique(array_merge(array_keys($byYear), array_keys($yearCount)));
rsort($years);
?>
<div class="zj-page zj-arch">
  <header class="zj-page-h">
    <h1 class="zj-page-t"><?= e(__('tt.arch')) ?></h1>
    <?php if ($years): ?><p class="zj-page-n"><span><?= (int)count($rows) ?> 篇</span><span class="zj-sep">·</span><span><?= count($years) ?> 个年份</span></p><?php endif; ?>
  </header>

  <?php if (!$years): ?>
  <div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
  <?php else: ?>
  <?php foreach ($years as $y): ?>
  <section class="zj-year">
    <h2 class="zj-year-n"><?= (int)$y ?></h2>
    <?php if (!empty($byYear[$y])): ?>
    <div class="zj-cards">
      <?php foreach ($byYear[$y] as $p):
        $dt = strtotime((string)$p['d']);
        $u = $base . rawurlencode((string)$p['slug']) . '.html';
        $raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
        $exc = Str::plain(Str::excerpt(Str::cleanMd($raw), 118));
        $tg = $tagMap[(int)$p['id']] ?? [];
      ?>
      <article class="zj-card">
        <h3 class="zj-card-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h3>
        <p class="zj-card-meta"><time class="zj-date" datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('n 月 j 日', $dt)) ?></time></p>
        <?php if ($exc !== ''): ?><p class="zj-card-x"><?= e($exc) ?></p><?php endif; ?>
        <?php if ($tg): ?>
        <div class="zj-chips">
          <?php foreach (array_slice($tg, 0, 4) as $t): ?>
          <a class="zj-chip" href="<?= e(Poly::termUrl('tag', $t)) ?>">#<?= e(Poly::termName($t)) ?></a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="zj-year-empty"><?= e(__('ui.empty_none')) ?></p>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
