<?php
/** 搜索页（云库 Yunku · 知识库风格）
 *  $q,$posts */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<div class="page-head"><h1><?= e(__('sr.title')) ?></h1><span class="sub"><?= e(__('sr.sub')) ?></span></div>
<form class="search-page-form" method="get" action="<?= e($lp . '/search') ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('sr.ph')) ?>" autofocus>
  <button type="submit"><?= e(__('sr.btn')) ?></button>
</form>
<?php if ($q !== ''): ?>
  <p class="hint" style="margin:6px 0 16px;"><?= e(sprintf(__('sr.result'), $q, count($posts))) ?></p>
  <?php if (!empty($posts)): ?>
  <div class="yk-list">
  <?php foreach ($posts as $p):
      $c0 = $p['cats'][0] ?? null;
      $cat = $c0 ? Poly::termName($c0) : '';
      $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
      $u = $base . e((string)$p['slug']) . '.html';
      $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
      $exc = Str::plain(Str::plain(Str::excerpt((string)($p['content'] ?? ''), 80)));
  ?>
  <article class="yk-item">
    <?php if ($cat !== ''): ?><a class="yk-cat" href="<?= e($catUrl) ?>"><?= e($cat) ?></a><?php endif; ?>
    <h2 class="yk-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
    <p class="yk-x"><?= e($exc) ?></p>
    <div class="yk-m"><span><?= e(date('Y-m-d', $dt)) ?></span><span><?= e(__('meta.reads')) ?><?= Str::num((int)($p['views'] ?? 0)) ?></span></div>
    <a class="yk-more" href="<?= e($u) ?>"><?= e(__('ui.read_more')) ?></a>
  </article>
  <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="empty"><?= e(__('sr.none')) ?></div>
  <?php endif; ?>
<?php endif; ?>
