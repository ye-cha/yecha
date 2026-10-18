<?php
/**
 * 标签 / 分类索引（新绿 Xinlv · 手维，勿用 tools/gen_parts.py 覆盖）
 * 输出 .meta-chip 芯片结构（.mi-name + .mi-count），权重 tw1..tw5，
 * 由 style.css 的 body.th-xinlv .mi-astra .meta-chip 套用 b1~b6 绿色调色板。
 * $type(category|tag) $items $page_title $sub $hint
 */
?>
<div class="page-head"><h1><?= e($page_title) ?></h1><span class="sub"><?= e($sub) ?></span></div>
<?php if (!empty($hint)): ?><p class="idx-hint"><?= e($hint) ?></p><?php endif; ?>
<?php if ($items): ?>
<div class="mi mi-astra">
  <?php foreach ($items as $it):
      $c = (int)$it['count'];
      $w = $c >= 12 ? 5 : ($c >= 6 ? 4 : ($c >= 3 ? 3 : ($c >= 2 ? 2 : 1))); ?>
    <a class="meta-chip tw<?= $w ?>" href="<?= e(Poly::termUrl($type, $it)) ?>">
      <span class="mi-name"><?= e($it['name']) ?></span>
      <span class="mi-count"><?= $c ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
<?php endif; ?>
