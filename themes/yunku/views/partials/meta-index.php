<?php
/** 标签 / 分类索引（云库 Yunku · 知识库风格）
 *  $type(category|tag) $items $page_title $sub $hint */
?>
<div class="page-head"><h1><?= e($page_title) ?></h1><?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?></div>
<?php if (!empty($hint)): ?><p class="idx-hint"><?= e($hint) ?></p><?php endif; ?>
<?php if ($items): ?>
<div class="yk-tags">
  <?php foreach ($items as $it):
      $c = (int)$it['count'];
  ?>
    <a class="yk-tag" href="<?= e(Poly::termUrl($type, $it)) ?>">
      <span class="yk-tag-n"><?= e($it['name']) ?></span>
      <b><?= $c ?></b>
    </a>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
<?php endif; ?>
