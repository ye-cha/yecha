<?php
/** 标签 / 分类索引（寒砚 Hanyan）
 *  结构：页头 → 大号标签 chip 墙（带计数，全量）→ 最新文章卡片网格
 *  $type(category|tag) $items $page_title $sub $hint */
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
$pfx = $lp === '' ? '/' : $lp . '/';

$recent = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents'); $M = Db::t('metas'); $R = Db::t('relationships');
        $recent = Db::rows(
            "SELECT c.title, c.slug, c.excerpt, c.content, c.published_at, c.created_at,"
            . " (SELECT m.name FROM {$R} r JOIN {$M} m ON m.id=r.meta_id WHERE r.content_id=c.id AND m.type='category' ORDER BY m.`order` ASC LIMIT 1) AS cat,"
            . " (SELECT m.slug FROM {$R} r JOIN {$M} m ON m.id=r.meta_id WHERE r.content_id=c.id AND m.type='category' ORDER BY m.`order` ASC LIMIT 1) AS cat_slug"
            . " FROM {$T} c WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
            . " ORDER BY COALESCE(c.published_at, c.created_at) DESC LIMIT 6"
        );
    }
} catch (\Throwable $e) { $recent = []; }
?>
<div class="hy-head">
  <h1><?= e($page_title) ?></h1>
  <?php if (!empty($hint)): ?><p class="hy-head-desc"><?= e($hint) ?></p><?php endif; ?>
  <div class="hy-head-meta">
    <span class="hy-head-count"><?= count($items) ?> <?= e($type === 'tag' ? __('nav.tags') : __('nav.cats')) ?></span>
    <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
  </div>
</div>

<?php if ($items): ?>
<div class="hy-chipwall hy-chipwall-lg">
  <?php foreach ($items as $it):
      $c = (int)$it['count'];
  ?>
  <a href="<?= e(Poly::termUrl($type, $it)) ?>"><?= e($it['name']) ?><i><?= $c ?></i></a>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
<?php endif; ?>

<?php if ($recent): ?>
<div class="hy-sec-head"><h2>最新文章</h2><a class="hy-sec-more" href="<?= e($lp) ?>/archives/"><?= e(__('nav.archive')) ?></a></div>
<div class="hy-grid">
  <?php $i = 0; foreach ($recent as $p):
    $i++;
    $u = $lp . '/' . rawurlencode((string)$p['slug']) . '.html';
    $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
    $raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
    $exc = trim(Str::plain(Str::plain(Str::excerpt($raw, 96))));
  ?>
  <article class="hy-card">
    <a class="hy-cover hy-c<?= (($i + 2) % 6) + 1 ?>" href="<?= e($u) ?>" aria-hidden="true" tabindex="-1"><span><?= e(mb_substr((string)$p['title'], 0, 1)) ?></span></a>
    <div class="hy-card-body">
      <h2 class="hy-card-title"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
      <p class="hy-excerpt"><?= e($exc) ?></p>
      <div class="hy-card-meta"><time datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y-m-d', $dt)) ?></time></div>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>
