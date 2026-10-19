<?php
/** 标签 / 分类索引（黄羽 Huangyu · chip 墙 + 列表）
 *  $type(category|tag) $items $page_title $sub $hint */
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
$homeHref = $lp === '' ? '/' : $lp . '/';
?>
<div class="hyu-head">
  <h1 class="hyu-head-t"><?= e($page_title) ?></h1>
</div>
<nav class="sub hyu-crumb" aria-label="breadcrumb">
  <a href="<?= e($homeHref) ?>">首页</a>
  <?php if (!empty($sub)): ?><span class="hyu-sep">/</span><span class="hyu-crumb-t"><?= $sub ?></span><?php endif; ?>
</nav>

<?php if (!empty($hint)): ?><p class="hyu-hint"><?= e((string)$hint) ?></p><?php endif; ?>

<?php if ($items): ?>
<div class="hyu-chips">
  <?php foreach ($items as $it):
      $c = (int)$it['count'];
  ?>
  <a class="hyu-chip" href="<?= e(Poly::termUrl($type, $it)) ?>">
    <span class="hyu-chip-n"><?= e((string)$it['name']) ?></span>
    <b class="hyu-chip-c"><?= $c ?></b>
  </a>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="hyu-empty"><?= e(__('ui.empty_none')) ?></div>
<?php endif; ?>
