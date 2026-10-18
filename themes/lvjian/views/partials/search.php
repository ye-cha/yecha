<?php
/**
 * 搜索页（工坊 Toolbox 绿条主题风格）
 * 复用首页 .astra-hero 标题区 + .astra-search 搜索框 + .astra-card 无图文本卡结果。
 *  $q,$posts
 */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<div class="astra-home tb-home">
  <section class="astra-hero">
    <p class="astra-eyebrow">SEARCH</p>
    <h1 class="astra-hero-title"><?= e(__('sr.title')) ?></h1>
  </section>
  <form class="astra-search" action="<?= e($lp . '/search') ?>" method="get" role="search">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m21 21-4.3-4.3"></path></svg>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('sr.ph')) ?>" aria-label="搜索文章" autofocus>
  </form>

  <?php if ($q !== ''): ?>
    <p class="tb-sr-count"><?= e(sprintf(__('sr.result'), $q, count($posts))) ?></p>
    <?php if (!empty($posts)): ?>
    <div class="astra-grid">
    <?php foreach ($posts as $p):
        $c0 = $p['cats'][0] ?? null;
        $cat = $c0 ? Poly::termName($c0) : '';
        $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
        $u = $base . e((string)$p['slug']) . '.html';
        $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
        $exc = Str::excerpt(Str::plain(Str::plain((string)($p['content'] ?? ''))), 80);
    ?>
      <article class="astra-card">
        <div class="astra-card-body">
          <?php if ($cat !== ''): ?><span class="astra-card-cat"><?= e($cat) ?></span><?php endif; ?>
          <h3 class="astra-card-title"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h3>
          <p class="astra-card-excerpt"><?= e($exc) ?></p>
          <a class="astra-readmore" href="<?= e($u) ?>"><?= e(__('ui.read_more')) ?></a>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty"><?= e(__('sr.none')) ?></div>
    <?php endif; ?>
  <?php endif; ?>
</div>
