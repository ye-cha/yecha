<?php
/** 顶部导航（Astra 蓝调轻盈 风格 · 由 tools/gen_parts.py 生成，勿手改） */
$nv = Nav::list('top', Lang::short());
?>
<nav class="nav thn thn-astra">
<?php foreach ($nv as $ni): ?>
  <a href="<?= e($ni['href']) ?>" class="thn-a<?= !empty($ni['active']) ? ' on' : '' ?><?= !empty($ni['class']) ? ' ' . e($ni['class']) : '' ?>"><?= e($ni['label']) ?></a>
<?php endforeach; ?>
</nav>
