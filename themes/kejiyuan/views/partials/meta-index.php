<?php
/** 标签 / 分类索引（kejiyuan 科技原 · 蓝 · 标签云芯片墙）
 *  $type(category|tag) $items $page_title $sub $hint */
?>
<div class="kj-band">
  <h1><?= e($page_title) ?></h1>
  <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
</div>
<?php if (!empty($hint)): ?><p class="kj-idxhint"><?= e($hint) ?></p><?php endif; ?>
<?php if ($items): ?>
<div class="kj-tagindex">
  <?php foreach ($items as $it):
      $c = (int)$it['count'];
  ?>
    <a href="<?= e(Poly::termUrl($type, $it)) ?>"><span><?= e($it['name']) ?></span><b><?= $c ?></b></a>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
<?php endif; ?>
