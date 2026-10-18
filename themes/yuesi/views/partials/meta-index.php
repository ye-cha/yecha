<?php
/** 标签 / 分类索引（跃思 Yuesi · 同款扁平 post-item 列表：名词 + 右侧计数，下方最近文章同款列表）
 *  $type(category|tag) $items $page_title $sub $hint */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';

$recent = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents');
        $recent = Db::rows(
            "SELECT title, slug, excerpt, content, COALESCE(published_at, created_at) AS d"
            . " FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL"
            . " ORDER BY d DESC LIMIT 6"
        );
    }
} catch (\Throwable $e) { $recent = []; }

$total = 0;
foreach (($items ?? []) as $it) { $total += (int)$it['count']; }
?>
<div class="ys-page">
  <header class="ys-page-h">
    <h1 class="ys-page-t"><?= e($page_title) ?></h1>
    <p class="ys-page-n">
      <?php if (!empty($sub)): ?>
        <span class="sub"><?= $sub ?></span>
      <?php else: ?>
        <span><?= count($items ?? []) ?> 个<?= $type === 'tag' ? '标签' : '分类' ?></span>
        <?php if ($total > 0): ?><span class="ys-dot">/</span><span><?= $total ?> 篇</span><?php endif; ?>
      <?php endif; ?>
    </p>
    <?php if (!empty($hint)): ?><p class="ys-page-d"><?= e($hint) ?></p><?php endif; ?>
  </header>

  <?php if ($items): ?>
  <div class="ys-list">
    <?php foreach ($items as $it): ?>
    <article class="ys-item ys-item-term">
      <div class="ys-item-top">
        <h2 class="ys-item-t"><a href="<?= e(Poly::termUrl($type, $it)) ?>"><?= e($it['name']) ?></a></h2>
        <span class="ys-item-d"><?= (int)$it['count'] ?> 篇</span>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
  <?php endif; ?>

  <?php if ($recent): ?>
  <h2 class="ys-sec">最近文章</h2>
  <div class="ys-list">
    <?php foreach ($recent as $p): ?>
      <?= View::render('partials/ys-item', ['p' => $p, 'base' => $base]) ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
