<?php
/** 标签 / 分类索引（kuake 夸克 · 红 · 标签云 chip 墙）
 *  $type(category|tag) $items $page_title $sub $hint */
?>
<div class="kk-idxhead"><h1><?= e($page_title) ?></h1><?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?></div>
<?php if (!empty($hint)): ?><p class="kk-idxhint"><?= e($hint) ?></p><?php endif; ?>
<?php if ($items): ?>
<div class="kk-tagcloud">
  <?php foreach ($items as $it):
      $c = (int)$it['count'];
  ?>
    <a class="kk-cloud" href="<?= e(Poly::termUrl($type, $it)) ?>"><span><?= e($it['name']) ?></span><b><?= $c ?></b></a>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
<?php endif; ?>
