<?php
/** 柳川 Liuchuan —— 搜索：网格形式的结果卡片
 *  $q,$posts */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<div class="lc-page">
  <header class="lc-head">
    <h1 class="lc-head-t"><?= e(__('sr.title')) ?></h1>
    <div class="lc-head-m"><span class="sub"><?= e(__('sr.sub')) ?></span></div>
  </header>

  <form class="lc-search" method="get" action="<?= e($lp . '/search') ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('sr.ph')) ?>" aria-label="<?= e(__('sr.ph')) ?>">
    <button type="submit"><?= e(__('sr.btn')) ?></button>
  </form>

  <?php if ($q !== ''): ?>
  <p class="lc-result"><?= e(sprintf(__('sr.result'), $q, count($posts))) ?></p>
  <?php if (!empty($posts)): ?>
  <div class="lc-grid">
    <?php foreach ($posts as $p):
      $c0 = $p['cats'][0] ?? null;
      $cat = $c0 ? Poly::termName($c0) : '';
      $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
      $u = $base . e((string)$p['slug']) . '.html';
      $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
      $exc = trim(Str::plain(Str::excerpt(Str::cleanMd((string)($p['content'] ?? '')), 92)));
    ?>
    <article class="lc-card">
      <?php if ($cat !== ''): ?><a class="lc-card-cat" href="<?= e($catUrl) ?>"><?= e($cat) ?></a><?php endif; ?>
      <h2 class="lc-card-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
      <time class="lc-card-d"><?= e(date('Y-m-d', $dt)) ?></time>
      <p class="lc-card-x"><?= e($exc) ?></p>
      <span class="lc-card-go">阅读全文</span>
    </article>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="lc-empty"><?= e(__('sr.none')) ?></div>
  <?php endif; ?>
  <?php endif; ?>
</div>
