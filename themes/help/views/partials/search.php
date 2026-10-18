<?php
/** 搜索页（素简 Sujian v2 · doku 风格：顶部居中搜索框 + 标题行式结果）
 *  $q,$posts */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<div class="suj-page-head">
  <h1><?= e(__('sr.title')) ?></h1>
  <?php if (Lang::isEn()): ?><span class="suj-sub"><?= e(__('sr.sub')) ?></span><?php endif; ?>
</div>

<form class="suj-search-page" method="get" action="<?= e($lp . '/search') ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('sr.ph')) ?>" aria-label="<?= e(__('sr.ph')) ?>" autofocus>
  <button type="submit"><?= e(__('sr.btn')) ?></button>
</form>

<?php if ($q !== ''): ?>
<?php if (!empty($posts)): ?>
<p class="suj-hint" style="margin:14px 0 18px;"><?= e(sprintf(__('sr.result'), $q, count($posts))) ?></p>
<div class="suj-doc-list">
<?php foreach ($posts as $p):
    $c0 = $p['cats'][0] ?? null;
    $cat = $c0 ? Poly::termName($c0) : '';
    $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
    $u = $base . e((string)$p['slug']) . '.html';
    $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
    $exc = Str::excerpt(Str::plain(Str::plain((string)($p['content'] ?? ''))), 110);
?>
  <article class="suj-doc-item">
    <h2 class="suj-doc-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
    <div class="suj-doc-m">
      <time><?= e(date('Y-m-d', $dt)) ?></time>
      <?php if ($cat !== ''): ?><span class="suj-devide-dot"></span><a class="suj-doc-cat" href="<?= e($catUrl) ?>"><?= e($cat) ?></a><?php endif; ?>
    </div>
    <?php if ($exc !== ''): ?><p class="suj-doc-x"><?= e($exc) ?></p><?php endif; ?>
  </article>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="suj-empty"><?= e(__('sr.none')) ?></div>
<?php endif; ?>
<?php endif; ?>