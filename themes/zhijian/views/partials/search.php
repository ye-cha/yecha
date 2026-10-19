<?php
/** 搜索页（纸笺 Zhijian · 衬线搜索框 + 卡片结果，关键词 <mark> 高亮）
 *  $q,$posts */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
$qq = (string)($q ?? '');
?>
<div class="zj-page zj-search">
  <header class="zj-page-h">
    <h1 class="zj-page-t"><?= e(__('sr.title')) ?></h1>
    <p class="zj-page-d"><?= e(__('sr.sub')) ?></p>
  </header>

  <form class="zj-sform" method="get" action="<?= e($lp . '/search') ?>" role="search">
    <svg class="zj-sform-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m21 21-4.3-4.3"></path></svg>
    <input type="search" name="q" value="<?= e($qq) ?>" placeholder="<?= e(__('sr.ph')) ?>" aria-label="<?= e(__('sr.ph')) ?>" autofocus>
    <button type="submit"><?= e(__('sr.btn')) ?></button>
  </form>

  <?php if ($qq !== ''): ?>
  <p class="zj-sresult"><?= e(sprintf(__('sr.result'), $qq, count($posts ?? []))) ?></p>
  <?php if (!empty($posts)): ?>
  <div class="zj-cards">
  <?php foreach ($posts as $p):
      $u = $base . e((string)$p['slug']) . '.html';
      $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
      $raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
      $exc = Str::plain(Str::excerpt(Str::cleanMd($raw), 118));
      $tg = $p['tags'] ?? [];
  ?>
    <article class="zj-card">
      <h2 class="zj-card-t"><a href="<?= e($u) ?>"><?= Str::mark((string)$p['title'], $qq) ?></a></h2>
      <p class="zj-card-meta"><time class="zj-date" datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y 年 n 月 j 日', $dt)) ?></time></p>
      <?php if ($exc !== ''): ?><p class="zj-card-x"><?= Str::mark($exc, $qq) ?></p><?php endif; ?>
      <?php if ($tg): ?>
      <div class="zj-chips">
        <?php foreach (array_slice($tg, 0, 4) as $t): ?>
        <a class="zj-chip" href="<?= e(Poly::termUrl('tag', $t)) ?>">#<?= e(Poly::termName($t)) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="empty"><?= e(__('sr.none')) ?></div>
  <?php endif; ?>
  <?php endif; ?>
</div>
