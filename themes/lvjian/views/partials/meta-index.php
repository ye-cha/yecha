<?php
/**
 * 标签 / 分类索引（工坊 Toolbox 绿条主题风格）
 * 复用首页 .astra-hero 标题区 + 绿描边药丸索引，与 toolbox 视觉统一。
 *  $type(category|tag) $items $page_title $sub $hint
 */
$lp = ui_lp();
$eyebrow = $type === 'category' ? 'CATEGORIES' : 'TAGS';
?>
<div class="astra-home tb-home">
  <section class="astra-hero">
    <p class="astra-eyebrow"><?= e($eyebrow) ?></p>
    <h1 class="astra-hero-title"><?= e($page_title) ?></h1>
    <?php if (!empty($sub)): ?><p class="astra-hero-sub"><?= e($sub) ?></p><?php endif; ?>
  </section>

  <?php if (!empty($hint)): ?><p class="tb-hint"><?= e($hint) ?></p><?php endif; ?>

  <?php if ($items): ?>
  <div class="tb-idx">
    <?php foreach ($items as $it):
        $c = (int)$it['count']; ?>
      <a class="tb-chip" href="<?= e(Poly::termUrl($type, $it)) ?>">
        <span class="tb-chip-n"><?= e($it['name']) ?></span>
        <span class="tb-chip-c"><?= $c ?></span>
      </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
  <?php endif; ?>
</div>
