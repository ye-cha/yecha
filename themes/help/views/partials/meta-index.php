<?php
/** 标签 / 分类索引（素简 Sujian v2 · doku 风格 chip 云）
 *  $type(category|tag) $items $page_title $sub $hint */
?>
<div class="suj-page-head">
  <h1><?= e($page_title) ?></h1>
  <?php if (!empty($sub)): ?><span class="suj-sub"><?= e($sub) ?></span><?php endif; ?>
</div>
<?php if (!empty($hint)): ?><p class="suj-hint"><?= e($hint) ?></p><?php endif; ?>
<?php if ($items): ?>
<div class="suj-mi">
  <?php foreach ($items as $it):
      $c = (int)$it['count'];
      $w = $c >= 12 ? 5 : ($c >= 6 ? 4 : ($c >= 3 ? 3 : ($c >= 2 ? 2 : 1))); ?>
    <a class="suj-mi-i w<?= $w ?>" href="<?= e(Poly::termUrl($type, $it)) ?>">
      <span class="suj-mi-n"><?= e($it['name']) ?></span>
      <span class="suj-mi-c"><?= $c ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="suj-empty"><?= e(__('ui.empty_none')) ?></div>
<?php endif; ?>