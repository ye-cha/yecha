<?php
/** 搜索页（寒砚 Hanyan）—— 搜索框 + 结果列表（标题 + 摘要）
 *  $q,$posts */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<div class="hy-head">
  <h1><?= e(__('sr.title')) ?></h1>
  <div class="hy-head-meta">
    <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
    <span><?= e(__('sr.sub')) ?></span>
  </div>
</div>

<form class="hy-search-form" method="get" action="<?= e($lp . '/search') ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('sr.ph')) ?>" aria-label="<?= e(__('sr.ph')) ?>">
  <button type="submit"><?= e(__('sr.btn')) ?></button>
</form>

<?php if ($q !== ''): ?>
  <p class="hy-result-n"><?= e(sprintf(__('sr.result'), $q, count($posts))) ?></p>
  <?php if (!empty($posts)): ?>
  <ul class="hy-res">
  <?php foreach ($posts as $p):
      $c0 = $p['cats'][0] ?? null;
      $cat = $c0 ? Poly::termName($c0) : '';
      $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
      $u = $base . e((string)$p['slug']) . '.html';
      $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
      $exc = trim(Str::plain(Str::plain(Str::excerpt((string)($p['content'] ?? ''), 96))));
  ?>
    <li>
      <h3><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h3>
      <p><?= e($exc) ?></p>
      <div class="hy-res-meta">
        <span><?= e(date('Y-m-d', $dt)) ?></span>
        <?php if ($cat !== ''): ?><a class="hy-chip" href="<?= e($catUrl) ?>"><?= e($cat) ?></a><?php endif; ?>
        <span><?= e(__('meta.reads')) ?><?= Str::num((int)($p['views'] ?? 0)) ?></span>
      </div>
    </li>
  <?php endforeach; ?>
  </ul>
  <?php else: ?>
  <div class="empty"><?= e(__('sr.none')) ?></div>
  <?php endif; ?>
<?php endif; ?>
