<?php
/** 标签 / 分类索引（朋友圈 Pyq 风格 · 手写覆盖，与 list.php 动态流统一视觉）
 *  $type(category|tag) $items $page_title $sub $hint */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<div class="pyq-idx">
  <div class="pyq-idx-head">
    <h1 class="pyq-idx-title"><?= e($page_title) ?></h1>
    <?php if (!empty($sub)): ?><span class="pyq-idx-sub"><?= e($sub) ?></span><?php endif; ?>
  </div>
  <?php if (!empty($hint)): ?><p class="pyq-idx-hint"><?= e($hint) ?></p><?php endif; ?>
  <?php if ($items): ?>
  <div class="pyq-tags">
    <?php foreach ($items as $it):
      $c = (int)$it['count'];
      $u = Poly::termUrl($type, $it);
    ?>
    <a class="pyq-tag" href="<?= e($u) ?>">
      <span class="pyq-tag-n"><?= e($it['name']) ?></span>
      <span class="pyq-tag-c"><?= Str::num($c) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="empty"><?= e(__('ui.empty_none')) ?></div>
  <?php endif; ?>
</div>
