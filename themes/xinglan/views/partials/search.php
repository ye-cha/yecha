<?php
/** 搜索页（星澈 Xinglan · 顶部大搜索框 + 结果列表，关键词 <mark> 高亮）
 *  $q,$posts */
if (!function_exists('xl_mark')) {
    function xl_mark(string $s, string $q): string
    {
        if ($q === '') { return e($s); }
        $parts = preg_split('/(' . preg_quote($q, '/') . ')/iu', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) { return e($s); }
        $out = '';
        foreach ($parts as $i => $seg) {
            $out .= ($i % 2 === 1) ? '<mark>' . e($seg) . '</mark>' : e($seg);
        }
        return $out;
    }
}
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
$qq = (string)($q ?? '');
?>
<div class="xl-sr">
  <form class="xl-sr-box" method="get" action="<?= e($lp . '/search') ?>" role="search">
    <svg class="xl-sr-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m21 21-4.3-4.3"></path></svg>
    <input type="search" name="q" value="<?= e($qq) ?>" placeholder="<?= e(__('sr.ph')) ?>" aria-label="<?= e(__('sr.ph')) ?>" autofocus>
    <button type="submit"><?= e(__('sr.btn')) ?></button>
  </form>

  <?php if ($qq !== ''): ?>
  <p class="xl-sr-count"><?= e(sprintf(__('sr.result'), $qq, count($posts ?? []))) ?></p>
  <?php if (!empty($posts)): ?>
  <div class="xl-sr-list">
  <?php foreach ($posts as $p):
      $c0 = $p['cats'][0] ?? null;
      $cat = $c0 ? Poly::termName($c0) : '';
      $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
      $u = $base . e((string)$p['slug']) . '.html';
      $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
      $exc = Str::plain(Str::plain(Str::excerpt((string)($p['content'] ?? ''), 110)));
  ?>
    <article class="xl-sr-item">
      <h2 class="xl-sr-t"><a href="<?= e($u) ?>"><?= xl_mark((string)$p['title'], $qq) ?></a></h2>
      <p class="xl-sr-x"><?= xl_mark($exc, $qq) ?></p>
      <div class="xl-sr-m">
        <time datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y-m-d', $dt)) ?></time>
        <?php if ($cat !== '' && $catUrl !== ''): ?><a class="xl-chip" href="<?= e($catUrl) ?>"><?= e($cat) ?></a><?php elseif ($cat !== ''): ?><span class="xl-chip"><?= e($cat) ?></span><?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="empty"><?= e(__('sr.none')) ?></div>
  <?php endif; ?>
  <?php endif; ?>
</div>
