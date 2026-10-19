<?php
/** 搜索页（跃思 Yuesi · 扁平下划线输入框 + 同款 post-item 结果列表，关键词高亮）
 *  $q,$posts */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
$qq = (string)($q ?? '');
?>
<div class="ys-page ys-search">
  <header class="ys-page-h">
    <h1 class="ys-page-t"><?= e(__('sr.title')) ?></h1>
    <?php if ($qq === ''): ?><p class="ys-page-d"><?= e(__('sr.sub')) ?></p><?php endif; ?>
  </header>

  <form class="ys-sform" method="get" action="<?= e($lp . '/search') ?>" role="search">
    <input type="search" name="q" value="<?= e($qq) ?>" placeholder="<?= e(__('sr.ph')) ?>" aria-label="<?= e(__('sr.ph')) ?>" autofocus>
    <button type="submit"><?= e(__('sr.btn')) ?></button>
  </form>

  <?php if ($qq !== ''): ?>
  <p class="ys-sresult"><?= e(sprintf(__('sr.result'), $qq, count($posts ?? []))) ?></p>
  <?php if (!empty($posts)): ?>
  <div class="ys-list">
    <?php foreach ($posts as $p): ?>
      <?= View::render('partials/ys-item', ['p' => $p, 'base' => $base, 'q' => $qq]) ?>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="empty"><?= e(__('sr.none')) ?></div>
  <?php endif; ?>
  <?php endif; ?>
</div>
