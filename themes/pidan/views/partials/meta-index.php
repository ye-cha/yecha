<?php
/** 皮蛋 Pidan —— 标签 / 分类索引：窄栏内的 chip 墙（标签页）/ 分类卡
 *  $type(category|tag) $items $page_title $sub $hint */
?>
<div class="pd-page">
  <header class="pd-head">
    <h1 class="pd-head-t"><?= e($page_title) ?></h1>
    <?php if (!empty($hint)): ?><p class="pd-head-d"><?= e($hint) ?></p><?php endif; ?>
    <div class="pd-head-m">
      <span class="pd-badge"><?= count((array)$items) ?> 项</span>
      <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
    </div>
  </header>

  <?php if (!empty($items)): ?>
  <div class="pd-chips">
    <?php foreach ($items as $it): $c = (int)$it['count']; ?>
    <a class="pd-chip" href="<?= e(Poly::termUrl($type, $it)) ?>">
      <span class="pd-chip-n"><?= e((string)$it['name']) ?></span>
      <span class="pd-chip-c"><?= $c ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="pd-empty"><?= e(__('ui.empty_none')) ?></div>
  <?php endif; ?>
</div>
