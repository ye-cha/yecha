<?php
/** 琪初 Qichu —— 仿 qitry.vip：窄栏居中、衬线、书卷气
 *  站名 + 描述 → 文章列表（标题 + 摘要 + 日期），衬线贯穿。 */
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
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';

$recent = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents');
        $recent = Db::rows(
            "SELECT c.title, c.slug, c.excerpt, c.content, c.published_at, c.created_at"
            . " FROM {$T} c WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
            . " ORDER BY COALESCE(c.published_at, c.created_at) DESC LIMIT 20"
        );
    }
} catch (\Throwable $e) { $recent = []; }

$name = e(site_name());
$desc = trim((string)get_option('site.subtitle', ''));
if ($desc === '') { $desc = '慢读、细想、随手记下——一方安静的书页。'; }
?>
<div class="qc-home">
  <header class="qc-masthead">
    <h1 class="qc-site"><?= $name ?></h1>
    <p class="qc-desc"><?= e($desc) ?></p>
  </header>
  <?php if ($recent): ?>
  <div class="qc-list">
    <?php foreach ($recent as $p):
      $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
      $u = $base . e((string)$p['slug']) . '.html';
      $raw = ($p['excerpt'] ?? '') ?: ($p['content'] ?? '');
      $exc = mb_substr(qc_strip(Str::plain(Str::excerpt($raw, 100))), 0, 84);
    ?>
    <article class="qc-item">
      <a class="qc-item-t" href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a>
      <?php if ($exc !== ''): ?><p class="qc-item-x"><?= e($exc) ?></p><?php endif; ?>
      <time class="qc-item-d"><?= e(date('Y-m-d', $dt)) ?></time>
    </article>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="empty">暂无文章</div>
  <?php endif; ?>
</div>
