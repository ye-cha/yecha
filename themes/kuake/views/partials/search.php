<?php
/** 搜索页（kuake 夸克 · 红 · 结果列表 + 右侧双 widget）
 *  $q,$posts */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<div class="kk-searchhead">
  <h1><?= e(__('sr.title')) ?></h1>
  <span class="sub"><a href="/">首页</a> / <?= e(__('sr.sub')) ?></span>
</div>
<form class="search-page-form" method="get" action="<?= e($lp . '/search') ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('sr.ph')) ?>" autofocus>
  <button type="submit"><?= e(__('sr.btn')) ?></button>
</form>
<?php if ($q !== ''): ?>
  <p class="hint" style="margin:6px 0 16px;"><?= e(sprintf(__('sr.result'), $q, count($posts))) ?></p>
  <?php if (!empty($posts)): ?>
  <div class="kk-shell">
    <div class="kk-stream">
      <?php foreach ($posts as $p): ?>
        <?= View::render('partials/kk-entry', ['p' => $p, 'lp' => $lp, 'base' => $base]) ?>
      <?php endforeach; ?>
    </div>
    <?= View::render('partials/kk-widgets') ?>
  </div>
  <?php else: ?>
  <div class="empty"><?= e(__('sr.none')) ?></div>
  <?php endif; ?>
<?php endif; ?>
