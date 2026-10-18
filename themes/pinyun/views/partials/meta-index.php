<?php
/** 品云 Pinyun —— 标签 / 分类索引：chip 墙
 *  $type(category|tag) $items $page_title $sub $hint */
$max = 0;
foreach (($items ?? []) as $it) { $max = max($max, (int)($it['count'] ?? 0)); }
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';
?>
<section class="py-cat-banner py-cat-banner-idx">
  <div class="py-cat-in">
    <h1 class="py-cat-title"><?= e($page_title) ?></h1>
    <?php if (!empty($hint)): ?><p class="py-cat-desc"><?= e((string)$hint) ?></p><?php endif; ?>
    <div class="py-cat-meta">
      <span class="py-cat-count"><?= count($items ?? []) ?> <?= $type === 'category' ? '个分类' : '个标签' ?></span>
      <?php if (!empty($sub)): ?><span class="sub"><?= e((string)$sub) ?></span><?php endif; ?>
    </div>
  </div>
</section>

<?php if (!empty($items)): ?>
<div class="py-chipwall">
  <?php foreach ($items as $it):
      $c = (int)($it['count'] ?? 0);
      $w = $max > 0 ? 1 + round(2 * ($c / $max)) : 1;
  ?>
    <a class="py-chip py-chip-w<?= (int)$w ?>" href="<?= e(Poly::termUrl($type, $it)) ?>">
      <span class="py-chip-n"><?= e((string)$it['name']) ?></span>
      <i class="py-chip-c"><?= $c ?></i>
    </a>
  <?php endforeach; ?>
</div>
<div class="py-idx-foot">
  <a class="py-sec-more" href="<?= e($lp) ?>/archives">按时间浏览全部文章</a>
</div>
<?php else: ?>
<div class="empty"><?= e(__('ui.empty_none')) ?></div>
<?php endif; ?>
