<?php
/** 码语 Mayu —— 标签 / 分类索引：双栏骨架，左侧索引网格 + 右侧小侧栏
 *  $type(category|tag) $items $page_title $sub $hint */
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';
$max = 1;
foreach ((array)$items as $it) { $max = max($max, (int)$it['count']); }
?>
<div class="my-page">
  <header class="my-page-h">
    <h1 class="my-page-t"><?= e($page_title) ?></h1>
    <?php if (!empty($hint)): ?><p class="my-page-desc"><?= e($hint) ?></p><?php endif; ?>
    <div class="my-page-meta">
      <span class="my-badge"><?= count((array)$items) ?> 项</span>
      <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
    </div>
  </header>

  <div class="my-shell">
    <div class="my-feed">
      <?php if (!empty($items)): ?>
      <div class="my-tiles">
        <?php foreach ($items as $it):
          $c = (int)$it['count'];
          $w = $max > 0 ? (int)round($c / $max * 100) : 0;
        ?>
        <a class="my-tile" href="<?= e(Poly::termUrl($type, $it)) ?>">
          <span class="my-tile-n"><?= e((string)$it['name']) ?></span>
          <span class="my-tile-b"><b><?= $c ?></b> 篇</span>
          <span class="my-tile-bar" style="--w:<?= $w ?>%"></span>
        </a>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="my-empty"><?= e(__('ui.empty_none')) ?></div>
      <?php endif; ?>
    </div>

    <?= View::render('partials/my-side') ?>
  </div>
</div>
