<?php
/** 搜索页（Astra 蓝调轻盈 风格 · 由 tools/gen_parts.py 生成，勿手改）
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
  <div class="lc lc-astra">
  <?php foreach ($posts as $p):
      $c0 = $p['cats'][0] ?? null;
      $cat = $c0 ? Poly::termName($c0) : '';
      $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
      $img = '';
      if (preg_match('/!\[[^\]]*\]\(([^)\s]+)\)/', (string)($p['content'] ?? ''), $mi)) { $img = $mi[1]; }
      $u = $base . e((string)$p['slug']) . '.html';
      $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
      $exc = Str::excerpt(Str::plain(Str::plain((string)($p['content'] ?? ''))), 80);
  ?>
  <article class="lcc lcc-astra">
    <a class="lcc-th" href="<?= e($u) ?>"><?php if ($img !== ''): ?><img src="<?= e($img) ?>" alt="<?= e((string)$p['title']) ?>" loading="lazy"><?php endif; ?></a>
    <div class="lcc-bd">
      <?php if ($cat !== ''): ?><a class="lcc-cat" href="<?= e($catUrl) ?>"><?= e($cat) ?></a><?php endif; ?>
      <h2 class="lcc-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
      <p class="lcc-x"><?= e($exc) ?></p>
      <div class="lcc-m"><span><?= e(date('Y-m-d', $dt)) ?></span><span><?= e(__('meta.reads')) ?><?= Str::num((int)($p['views'] ?? 0)) ?></span></div>
      <a class="lcc-more" href="<?= e($u) ?>"><?= e(__('ui.read_more')) ?></a>
    </div>
  </article>
  <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="empty"><?= e(__('sr.none')) ?></div>
  <?php endif; ?>
<?php endif; ?>
