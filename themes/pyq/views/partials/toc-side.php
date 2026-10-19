<?php
/** 右侧栏顶部目录（服务端生成）：入参 $items=[['l'=>2|3,'id'=>'sec-0','t'=>'标题文本'],…] */
?>
<nav class="article-toc" aria-label="<?= e(__('post.toc')) ?>">
  <span class="toc-cap"><?= e(__('post.toc')) ?></span>
  <ul>
    <?php foreach ($items as $it): ?>
    <?php $lv = (int)$it['l']; $lvCls = $lv === 3 ? ' class="l3"' : ($lv === 4 ? ' class="l4"' : ($lv === 5 ? ' class="l5"' : '')); ?>
    <li<?= $lvCls ?>><a href="#<?= e($it['id']) ?>"><?= e($it['t']) ?></a></li>
    <?php endforeach; ?>
  </ul>
</nav>
