<?php
/** 案台 Antai —— 标签 / 分类索引：「文件夹窗口」+ 窗口内的图标网格
 *  $type(category|tag) $items $page_title $sub $hint */
$glyph = ($type === 'category') ? '📁' : '🏷';
?>
<div class="at-page">
<div class="at-win">
  <div class="at-win-bar">
    <span class="at-dots" aria-hidden="true"><i class="at-dot at-dot-r"></i><i class="at-dot at-dot-y"></i><i class="at-dot at-dot-g"></i></span>
    <span class="at-win-title"><?= e($page_title) ?></span>
    <span class="at-win-x" aria-hidden="true">×</span>
  </div>
  <div class="at-win-body">
    <div class="at-head">
      <h1 class="at-head-t"><?= e($page_title) ?></h1>
      <?php if (!empty($hint)): ?><p class="at-head-d"><?= e($hint) ?></p><?php endif; ?>
      <div class="at-head-m">
        <span class="at-badge"><?= count((array)$items) ?> 项</span>
        <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
      </div>
    </div>

    <?php if (!empty($items)): ?>
    <div class="at-desk at-desk-in">
      <?php foreach ($items as $it): ?>
      <article class="at-icon">
        <a class="at-icon-a" href="<?= e(Poly::termUrl($type, $it)) ?>" title="<?= e((string)$it['name']) ?>">
          <span class="at-icon-box"><span class="at-icon-glyph"><?= e($glyph) ?></span></span>
          <span class="at-icon-label"><?= e((string)$it['name']) ?></span>
          <span class="at-icon-n"><?= (int)$it['count'] ?></span>
        </a>
      </article>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="at-empty"><?= e(__('ui.empty_none')) ?></div>
    <?php endif; ?>
  </div>
</div>
</div>
