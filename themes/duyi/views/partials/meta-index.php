<?php
/** 标签 / 分类索引（独一 Duyi · 仿 the0n3.top 紧凑 chip 墙，带计数）
 *  $type(category|tag) $items $page_title $sub $hint */
?>
<div class="dy-head">
  <h1 class="dy-h"><?= e($page_title) ?></h1>
  <?php if (!empty($sub)): ?><span class="sub dy-sub"><?= $sub ?></span><?php endif; ?>
</div>
<?php if (!empty($hint)): ?><p class="idx-hint"><?= e($hint) ?></p><?php endif; ?>
<?php if ($items): ?>
<div class="dy-tags">
  <?php foreach ($items as $it):
      $c = (int)$it['count'];
  ?>
    <a class="dy-tag" href="<?= e(Poly::termUrl($type, $it)) ?>">
      <span><?= e($it['name']) ?></span><b><?= $c ?></b>
    </a>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="empty"><?= e(__('ui.empty_none')) ?></div>
<?php endif; ?>
