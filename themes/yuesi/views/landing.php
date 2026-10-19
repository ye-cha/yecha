<?php
/** yecha.org 跃思 Yuesi 演示站 —— 仿 blog.fooleap.org
 *  结构：h1 站名 → 纯列表 post-item（第一行 标题 + 右侧日期；第二行 灰色摘要）。
 *  极简扁平：无卡片、无边框、无图片、无阴影、无圆角。 */
$name = e(site_name());
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';

$recent = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents');
        $recent = Db::rows(
            "SELECT title, slug, excerpt, content, COALESCE(published_at, created_at) AS d"
            . " FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL"
            . " ORDER BY d DESC LIMIT 10"
        );
    }
} catch (\Throwable $e) { $recent = []; }

$tagline = trim((string)get_option('site.subtitle', ''));
if ($tagline === '') { $tagline = trim((string)get_option('site.description', '')); }
?>
<div class="ys-home">

  <header class="ys-masthead">
    <h1 class="ys-site"><?= $name ?></h1>
    <?php if ($tagline !== ''): ?><p class="ys-tagline"><?= e($tagline) ?></p><?php endif; ?>
  </header>

  <div class="ys-list">
    <?php if ($recent): ?>
      <?php foreach ($recent as $p): ?>
        <?= View::render('partials/ys-item', ['p' => $p, 'base' => $base]) ?>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="empty"><?= e(__('list.empty')) ?></div>
    <?php endif; ?>
  </div>

  <p class="ys-more"><a href="<?= e($lp) ?>/archives/">查看全部文章</a></p>
</div>
