<?php
/** 独一 Duyi —— 仿 the0n3.top：以「最近」为大标题的紧凑列表
 *  无卡片、无阴影、无边框块；每行 = 标题（左）+ 日期（右，灰小字），细分隔线。 */
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';

$recent = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents');
        $recent = Db::rows(
            "SELECT c.title, c.slug, c.published_at, c.created_at"
            . " FROM {$T} c WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
            . " ORDER BY COALESCE(c.published_at, c.created_at) DESC LIMIT 24"
        );
    }
} catch (\Throwable $e) { $recent = []; }
?>
<div class="dy-home">
  <h1 class="dy-recent-h">最近</h1>
  <?php if ($recent): ?>
  <div class="dy-list">
    <?php foreach ($recent as $p):
      $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
      $u = $base . e((string)$p['slug']) . '.html';
    ?>
    <article class="dy-item">
      <a class="dy-item-t" href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a>
      <time class="dy-item-d"><?= e(date('Y-m-d', $dt)) ?></time>
    </article>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="empty">暂无文章</div>
  <?php endif; ?>
</div>
