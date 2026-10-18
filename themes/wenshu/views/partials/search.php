<?php
/** 搜索页（文枢 Wenshu · 文档站风）：左导航(layout) + 搜索框 + 结果列表
 *  $q,$posts */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<nav class="ws-crumb" aria-label="面包屑">
  <a href="<?= e($base) ?>">首页</a><span>/</span><b><?= e(__('sr.title')) ?></b>
</nav>

<div class="ws-doc-head">
  <h1><?= e(__('sr.title')) ?></h1>
  <div class="ws-doc-headmeta">
    <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
    <span><?= e(__('sr.sub')) ?></span>
  </div>
</div>

<form class="ws-search" method="get" action="<?= e($lp . '/search') ?>">
  <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m21 21-4.3-4.3"></path></svg>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('sr.ph')) ?>" autofocus>
  <button type="submit"><?= e(__('sr.btn')) ?></button>
</form>

<?php if ($q !== ''): ?>
  <p class="ws-result-n"><?= e(sprintf(__('sr.result'), $q, count($posts))) ?></p>
  <?php if (!empty($posts)): ?>
  <div class="ws-doclist">
  <?php foreach ($posts as $p):
      $c0 = $p['cats'][0] ?? null;
      $cat = $c0 ? Poly::termName($c0) : '';
      $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
      $u = $base . e((string)$p['slug']) . '.html';
      $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
      $exc = trim(Str::plain(Str::plain(Str::excerpt((string)($p['content'] ?? ''), 96))));
  ?>
    <article class="ws-docrow">
      <h2 class="ws-docrow-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
      <p class="ws-docrow-x"><?= e($exc) ?></p>
      <div class="ws-docrow-m">
        <time datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y-m-d', $dt)) ?></time>
        <?php if ($cat !== ''): ?><a class="ws-docrow-cat" href="<?= e($catUrl) ?>"><?= e($cat) ?></a><?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="empty"><?= e(__('sr.none')) ?></div>
  <?php endif; ?>
<?php endif; ?>
