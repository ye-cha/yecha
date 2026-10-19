<?php
/** 搜索页（琪初 Qichu · 仿 qitry.vip 窄栏衬线列表）
 *  $q,$posts */
if (!function_exists('qc_strip')) {
  function qc_strip($s) {
    $s = (string)$s;
    $s = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $s);
    $s = preg_replace('/\[!\[[^\]]*\]\([^)]*\)\]\([^)]*\)/', '', $s);
    $s = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $s);
    $s = preg_replace('/^[>\s]+/m', '', $s);
    $s = str_replace('---', '', $s);
    return trim($s);
  }
}
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<div class="qc-head">
  <h1 class="qc-h"><?= e(__('sr.title')) ?></h1>
  <span class="sub qc-sub"><?= e(__('sr.sub')) ?></span>
</div>
<form class="search-page-form" method="get" action="<?= e($lp . '/search') ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('sr.ph')) ?>" autofocus>
  <button type="submit"><?= e(__('sr.btn')) ?></button>
</form>
<?php if ($q !== ''): ?>
  <p class="hint"><?= e(sprintf(__('sr.result'), $q, count($posts))) ?></p>
  <?php if (!empty($posts)): ?>
  <div class="qc-list">
  <?php foreach ($posts as $p):
      $u = $base . e((string)$p['slug']) . '.html';
      $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
      $raw = ($p['excerpt'] ?? '') ?: ($p['content'] ?? '');
      $exc = mb_substr(qc_strip(Str::plain(Str::excerpt($raw, 90))), 0, 76);
  ?>
  <article class="qc-item">
    <a class="qc-item-t" href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a>
    <?php if ($exc !== ''): ?><p class="qc-item-x"><?= e($exc) ?></p><?php endif; ?>
    <time class="qc-item-d"><?= e(date('Y-m-d', $dt)) ?></time>
  </article>
  <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="empty"><?= e(__('sr.none')) ?></div>
  <?php endif; ?>
<?php endif; ?>
