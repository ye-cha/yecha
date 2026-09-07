<?php
/** 索引页组件：$type(category|tag) $items $page_title $sub $hint */
?>
<div class="page-head"><h1><?= e($page_title) ?></h1><span class="sub"><?= e($sub) ?></span></div>
<?php if (!empty($hint)): ?><p class="idx-hint"><?= e($hint) ?></p><?php endif; ?>
<?php if ($items): ?>
<div class="meta-index">
  <?php foreach ($items as $it):
      $c = (int)$it['count'];
      $w = $c >= 12 ? 5 : ($c >= 6 ? 4 : ($c >= 3 ? 3 : ($c >= 2 ? 2 : 1))); ?>
    <a class="meta-chip tw tw<?= $w ?>" href="<?= e(Poly::termUrl($type, $it)) ?>">
      <span class="mi-name"><?= e($it['name']) ?></span>
      <span class="mi-count"><?= $c ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
<?php endif; ?>
