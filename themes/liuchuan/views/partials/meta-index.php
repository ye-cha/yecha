<?php
/** 柳川 Liuchuan —— 标签 / 分类索引：三列网格卡片
 *  $type(category|tag) $items $page_title $sub $hint */
?>
<div class="lc-page">
  <header class="lc-head">
    <h1 class="lc-head-t"><?= e($page_title) ?></h1>
    <?php if (!empty($hint)): ?><p class="lc-head-d"><?= e($hint) ?></p><?php endif; ?>
    <div class="lc-head-m">
      <span class="lc-badge"><?= count((array)$items) ?> 项</span>
      <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
    </div>
  </header>

  <?php if (!empty($items)): ?>
  <div class="lc-grid lc-grid-tight">
    <?php foreach ($items as $it): $c = (int)$it['count']; ?>
    <a class="lc-card lc-tile" href="<?= e(Poly::termUrl($type, $it)) ?>">
      <span class="lc-tile-n"><?= e((string)$it['name']) ?></span>
      <span class="lc-tile-c"><b><?= $c ?></b> 篇文章</span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="lc-empty"><?= e(__('ui.empty_none')) ?></div>
  <?php endif; ?>
</div>
