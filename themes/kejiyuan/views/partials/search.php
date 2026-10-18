<?php
/** 搜索页（kejiyuan 科技原 · 蓝 · 标题带 + 卡片网格结果）
 *  $q,$posts */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<div class="kj-band">
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
  <div class="kj-grid">
    <?php foreach ($posts as $p): ?>
      <?= View::render('partials/kj-card', ['p' => $p, 'lp' => $lp, 'base' => $base]) ?>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="empty"><?= e(__('sr.none')) ?></div>
  <?php endif; ?>
<?php endif; ?>
