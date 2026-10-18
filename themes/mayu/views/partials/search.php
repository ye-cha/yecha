<?php
/** 码语 Mayu —— 搜索：双栏骨架，左侧搜索结果卡流 + 右侧小侧栏
 *  $q,$posts */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<div class="my-page">
  <header class="my-page-h">
    <h1 class="my-page-t"><?= e(__('sr.title')) ?></h1>
    <div class="my-page-meta"><span class="sub"><?= e(__('sr.sub')) ?></span></div>
  </header>

  <form class="my-search" method="get" action="<?= e($lp . '/search') ?>">
    <svg class="my-search-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><path d="m21 21-4.3-4.3"></path></svg>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('sr.ph')) ?>" aria-label="<?= e(__('sr.ph')) ?>">
    <button type="submit"><?= e(__('sr.btn')) ?></button>
  </form>

  <?php if ($q !== ''): ?>
  <?php if (!empty($posts)): ?>
  <div class="my-shell">
    <div class="my-feed">
      <p class="my-result"><?= e(sprintf(__('sr.result'), $q, count($posts))) ?></p>
      <?php foreach ($posts as $p):
        $c0 = $p['cats'][0] ?? null;
        $cat = $c0 ? Poly::termName($c0) : '';
        $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
        $u = $base . e((string)$p['slug']) . '.html';
        $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
        $exc = trim(Str::plain(Str::excerpt(Str::cleanMd((string)($p['content'] ?? '')), 120)));
      ?>
      <article class="my-card">
        <h2 class="my-card-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
        <p class="my-card-x"><?= e($exc) ?></p>
        <div class="my-card-foot">
          <?php if (!empty($p['tags'])): ?>
          <div class="my-chips">
            <?php foreach (array_slice((array)$p['tags'], 0, 3) as $t): ?>
            <a class="my-chip" href="<?= e(Poly::termUrl('tag', $t)) ?>"># <?= e(Poly::termName($t)) ?></a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <div class="my-card-m">
            <?php if ($cat !== ''): ?><a class="my-card-cat" href="<?= e($catUrl) ?>"><?= e($cat) ?></a><?php endif; ?>
            <time><?= e(date('Y-m-d', $dt)) ?></time>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?= View::render('partials/my-side') ?>
  </div>
  <?php else: ?>
  <div class="my-empty"><?= e(__('sr.none')) ?></div>
  <?php endif; ?>
  <?php endif; ?>
</div>
