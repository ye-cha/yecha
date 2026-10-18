<?php
/** 搜索页（独一 Duyi · 仿 the0n3.top 紧凑列表）
 *  $q,$posts */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<div class="dy-head">
  <h1 class="dy-h"><?= e(__('sr.title')) ?></h1>
  <span class="sub dy-sub"><?= e(__('sr.sub')) ?></span>
</div>
<form class="search-page-form" method="get" action="<?= e($lp . '/search') ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('sr.ph')) ?>" autofocus>
  <button type="submit"><?= e(__('sr.btn')) ?></button>
</form>
<?php if ($q !== ''): ?>
  <p class="hint"><?= e(sprintf(__('sr.result'), $q, count($posts))) ?></p>
  <?php if (!empty($posts)): ?>
  <div class="dy-list">
  <?php foreach ($posts as $p):
      $u = $base . e((string)$p['slug']) . '.html';
      $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
  ?>
  <article class="dy-item">
    <a class="dy-item-t" href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a>
    <time class="dy-item-d"><?= e(date('Y-m-d', $dt)) ?></time>
  </article>
  <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="empty"><?= e(__('sr.none')) ?></div>
  <?php endif; ?>
<?php endif; ?>
