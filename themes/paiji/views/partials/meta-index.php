<?php
/** 派记 Paiji —— 标签 / 分类索引：chip 墙（纸片标签）
 *  $type(category|tag) $items $page_title $sub $hint */
$max = 0;
foreach (($items ?? []) as $it) { $max = max($max, (int)($it['count'] ?? 0)); }
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';
?>
<header class="pj-list-head">
  <h1 class="pj-list-t"><?= e($page_title) ?></h1>
  <?php if (!empty($hint)): ?><p class="pj-list-desc"><?= e((string)$hint) ?></p><?php endif; ?>
  <div class="pj-list-meta">
    <span class="pj-list-count"><?= count($items ?? []) ?> <?= $type === 'category' ? '个分类' : '个标签' ?></span>
    <?php if (!empty($sub)): ?><span class="sub"><?= e((string)$sub) ?></span><?php endif; ?>
  </div>
</header>

<?php if (!empty($items)): ?>
<div class="pj-chips">
  <?php foreach ($items as $it):
      $c = (int)($it['count'] ?? 0);
      $w = $max > 0 ? 1 + round(2 * ($c / $max)) : 1;
  ?>
    <a class="pj-chip pj-chip-w<?= (int)$w ?>" href="<?= e(Poly::termUrl($type, $it)) ?>">
      <span class="pj-chip-n"><?= e((string)$it['name']) ?></span><i><?= $c ?></i>
    </a>
  <?php endforeach; ?>
</div>
<div class="pj-notes-foot"><a href="<?= e($lp) ?>/archives">按时间浏览全部笔记</a></div>
<?php else: ?>
<div class="empty"><?= e(__('ui.empty_none')) ?></div>
<?php endif; ?>
