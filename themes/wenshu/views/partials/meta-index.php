<?php
/** 标签 / 分类索引（文枢 Wenshu · 文档站风）
 *  骨架：左导航(layout) + 右侧内容（面包屑 + 索引卡片网格，带计数）
 *  $type(category|tag) $items $page_title $sub $hint */
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
$pfx = $lp === '' ? '/' : $lp . '/';
?>
<nav class="ws-crumb" aria-label="面包屑">
  <a href="<?= e($pfx) ?>">首页</a><span>/</span><b><?= e($page_title) ?></b>
</nav>

<div class="ws-doc-head">
  <h1><?= e($page_title) ?></h1>
  <?php if (!empty($hint)): ?><p class="ws-doc-desc"><?= e($hint) ?></p><?php endif; ?>
  <div class="ws-doc-headmeta">
    <span class="ws-count"><?= count($items) ?> 项</span>
    <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
  </div>
</div>

<?php if ($items): ?>
<div class="ws-idxgrid">
  <?php foreach ($items as $it):
      $c = (int)$it['count'];
  ?>
  <a class="ws-idxcard" href="<?= e(Poly::termUrl($type, $it)) ?>">
    <span class="ws-idxname"><?= e($it['name']) ?></span>
    <span class="ws-idxn"><?= $c ?> 篇</span>
  </a>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
<?php endif; ?>
