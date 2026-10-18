<?php
/** 搜索页（黄羽 Huangyu · 杂志风）：同款圆形头像列表
 *  $q,$posts */
require_once __DIR__ . '/../_util.php';
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
$homeHref = $lp === '' ? '/' : $lp . '/';
?>
<div class="hyu-head">
  <h1 class="hyu-head-t"><?= e(__('sr.title')) ?></h1>
</div>
<nav class="sub hyu-crumb" aria-label="breadcrumb">
  <a href="<?= e($homeHref) ?>">首页</a>
  <span class="hyu-sep">/</span><span class="hyu-crumb-t"><?= e(__('sr.sub')) ?></span>
</nav>

<form class="hyu-search" method="get" action="<?= e($lp . '/search') ?>">
  <input type="search" name="q" value="<?= e((string)$q) ?>" placeholder="<?= e(__('sr.ph')) ?>" autofocus>
  <button type="submit"><?= e(__('sr.btn')) ?></button>
</form>

<?php if ((string)$q !== ''): ?>
  <p class="hyu-search-n"><?= e(sprintf(__('sr.result'), (string)$q, count($posts))) ?></p>
  <?php if (!empty($posts)): ?>
  <div class="hyu-stream">
    <?php foreach ($posts as $p):
        $c0 = $p['cats'][0] ?? null;
        $cat = $c0 ? Poly::termName($c0) : '';
        $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
        $u = $base . e((string)$p['slug']) . '.html';
        $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
        $dateStr = $dt ? date('Y-m-d', $dt) : '';
        [$color, $ini] = hyu_avatar((string)$p['slug'], (string)($p['title'] ?? ''));
        $raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
        $exc = hyu_excerpt($raw, 96);
    ?>
    <article class="hyu-post">
      <a class="hyu-ava" href="<?= e($u) ?>" style="background:<?= e($color) ?>" aria-hidden="true"><?= e($ini) ?></a>
      <div class="hyu-body">
        <?php if ($cat !== ''): ?><a class="hyu-cat" href="<?= e($catUrl) ?>"><?= e($cat) ?></a><?php endif; ?>
        <h2 class="hyu-post-t"><a href="<?= e($u) ?>"><?= e((string)($p['title'] ?? '')) ?></a></h2>
        <p class="hyu-post-x"><?= e($exc) ?></p>
        <div class="hyu-post-m"><time><?= e($dateStr) ?></time></div>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="hyu-empty"><?= e(__('sr.none')) ?></div>
  <?php endif;
endif; ?>
