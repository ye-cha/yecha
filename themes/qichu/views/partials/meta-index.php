<?php
/** 标签 / 分类索引（琪初 Qichu · 仿 qitry.vip 偏方形、细边框 chip 墙，带计数）
 *  $type(category|tag) $items $page_title $sub $hint */
?>
<div class="qc-head">
  <h1 class="qc-h"><?= e($page_title) ?></h1>
  <?php if (!empty($sub)): ?><span class="sub qc-sub"><?= $sub ?></span><?php endif; ?>
</div>
<?php if (!empty($hint)): ?><p class="idx-hint"><?= e($hint) ?></p><?php endif; ?>
<?php if ($items): ?>
<div class="qc-tags">
  <?php foreach ($items as $it):
      $c = (int)$it['count'];
  ?>
    <a class="qc-tag" href="<?= e(Poly::termUrl($type, $it)) ?>">
      <span><?= e($it['name']) ?></span><b><?= $c ?></b>
    </a>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="empty"><?= e(__('ui.empty_none')) ?></div>
<?php endif; ?>
