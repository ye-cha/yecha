<?php
/** 搜索页（绒光 Rongguang · 极简下划线输入框 + 「日期 + 标题」结果列表，关键词 <mark> 高亮）
 *  $q,$posts */
if (!function_exists('rg_mark')) {
    function rg_mark(string $s, string $q): string
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
<div class="rg-page">
  <form class="rg-search" method="get" action="<?= e($lp . '/search') ?>" role="search">
    <input type="search" name="q" value="<?= e($qq) ?>" placeholder="<?= e(__('sr.ph')) ?>" aria-label="<?= e(__('sr.ph')) ?>" autofocus>
    <button type="submit"><?= e(__('sr.btn')) ?></button>
  </form>

  <?php if ($qq !== ''): ?>
  <p class="rg-result"><?= e(sprintf(__('sr.result'), $qq, count($posts ?? []))) ?></p>
  <?php if (!empty($posts)): ?>
  <div class="rg-list">
  <?php foreach ($posts as $p):
      $u = $base . e((string)$p['slug']) . '.html';
      $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
  ?>
    <article class="rg-row">
      <time class="rg-date" datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y-m-d', $dt)) ?></time>
      <a class="rg-title" href="<?= e($u) ?>"><?= rg_mark((string)$p['title'], $qq) ?></a>
    </article>
  <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="empty"><?= e(__('sr.none')) ?></div>
  <?php endif; ?>
  <?php endif; ?>
</div>
