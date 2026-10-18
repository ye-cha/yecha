<?php
/** 标签 / 分类索引（纸笺 Zhijian · 标签 chip 墙（带计数）+ 最新文章卡片列表）
 *  $type(category|tag) $items $page_title $sub $hint */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';

$recent = [];
$tagMap = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents');
        $recent = Db::rows(
            "SELECT c.id, c.title, c.slug, c.excerpt, c.content, COALESCE(c.published_at, c.created_at) AS d"
            . " FROM {$T} c WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
            . " ORDER BY d DESC LIMIT 5"
        );
        if ($recent) {
            $ids = implode(',', array_map('intval', array_column($recent, 'id')));
            $M = Db::t('metas'); $R = Db::t('relationships');
            $rels = Db::rows(
                "SELECT r.content_id, m.name, m.slug, m.name_en, m.names_i18n, m.slug_i18n"
                . " FROM {$R} r JOIN {$M} m ON m.id=r.meta_id"
                . " WHERE r.content_id IN ($ids) AND m.type='tag' ORDER BY m.`order` ASC, m.id ASC"
            );
            foreach ($rels as $r) { $tagMap[(int)$r['content_id']][] = $r; }
        }
    }
} catch (\Throwable $e) { $recent = []; $tagMap = []; }

$total = 0;
foreach (($items ?? []) as $it) { $total += (int)$it['count']; }
?>
<div class="zj-page zj-idx">
  <header class="zj-page-h">
    <h1 class="zj-page-t"><?= e($page_title) ?></h1>
    <?php if (!empty($hint)): ?><p class="zj-page-d"><?= e($hint) ?></p><?php endif; ?>
    <p class="zj-page-n">
      <?php if (!empty($sub)): ?>
        <span class="sub"><?= $sub ?></span>
      <?php else: ?>
        <span><?= count($items ?? []) ?> 个<?= $type === 'tag' ? '标签' : '分类' ?></span>
        <?php if ($total > 0): ?><span class="zj-sep">·</span><span><?= $total ?> 篇</span><?php endif; ?>
      <?php endif; ?>
    </p>
  </header>

  <?php if ($items): ?>
  <div class="zj-wall">
    <?php foreach ($items as $it): ?>
      <a class="zj-wall-i" href="<?= e(Poly::termUrl($type, $it)) ?>">
        <span class="zj-wall-n"><?= e($it['name']) ?></span>
        <span class="zj-wall-c"><?= (int)$it['count'] ?></span>
      </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
  <?php endif; ?>

  <?php if ($recent): ?>
  <h2 class="zj-feed-cap">最新文章</h2>
  <div class="zj-cards">
    <?php foreach ($recent as $p):
      $dt = strtotime((string)$p['d']);
      $u = $base . rawurlencode((string)$p['slug']) . '.html';
      $raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
      $exc = Str::plain(Str::excerpt(Str::cleanMd($raw), 118));
      $tg = $tagMap[(int)$p['id']] ?? [];
    ?>
    <article class="zj-card">
      <h3 class="zj-card-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h3>
      <p class="zj-card-meta"><time class="zj-date" datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y 年 n 月 j 日', $dt)) ?></time></p>
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
  <?php endif; ?>
</div>
