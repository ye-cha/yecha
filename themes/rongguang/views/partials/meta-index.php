<?php
/** 标签 / 分类索引（绒光 Rongguang · 与列表同构的「计数 + 名称」纯文本列表）
 *  $type(category|tag) $items $page_title $sub $hint */
?>
<div class="rg-page">
  <header class="rg-page-h">
    <h1 class="rg-page-t"><?= e($page_title) ?></h1>
    <?php if (!empty($sub)): ?><p class="sub rg-page-crumb"><?= $sub ?></p><?php endif; ?>
    <?php if (!empty($hint)): ?><p class="rg-page-d"><?= e($hint) ?></p><?php endif; ?>
  </header>

  <?php if ($items): ?>
  <div class="rg-list">
    <?php foreach ($items as $it): ?>
      <article class="rg-row">
        <span class="rg-date"><?= str_pad((string)(int)$it['count'], 2, '0', STR_PAD_LEFT) ?> 篇</span>
        <a class="rg-title" href="<?= e(Poly::termUrl($type, $it)) ?>"><?= e($it['name']) ?></a>
      </article>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
  <?php endif; ?>
</div>
