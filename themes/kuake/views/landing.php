<?php
/** kuake 夸克 —— 红 + 主列表双栏密集列表 + 右侧双 widget（仿 quarkblog 双 aside 博客布局）
 *  顶部站点标题/简介 → 下方双栏：左主列=密集列表（标题+日期+短摘要），右列=两个 widget 卡片纵向堆叠。 */
$name = e(site_name());
$sub = trim((string)get_option('site.subtitle', ''));
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
$recent = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents'); $M = Db::t('metas'); $R = Db::t('relationships');
        $recent = Db::rows(
            "SELECT c.id,c.title,c.slug,c.excerpt,c.content,c.published_at,c.created_at,c.views,"
            . " (SELECT m.name FROM {$R} r JOIN {$M} m ON m.id=r.meta_id WHERE r.content_id=c.id AND m.type='category' ORDER BY m.`order` ASC LIMIT 1) AS cat,"
            . " (SELECT m.slug FROM {$R} r JOIN {$M} m ON m.id=r.meta_id WHERE r.content_id=c.id AND m.type='category' ORDER BY m.`order` ASC LIMIT 1) AS cat_slug"
            . " FROM {$T} c WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
            . " ORDER BY COALESCE(c.published_at, c.created_at) DESC LIMIT 50"
        );
        foreach ($recent as &$p) {
            if (!empty($p['cat_slug'])) { $p['cat_url'] = $base . 'category/' . rawurlencode((string)$p['cat_slug']) . '/'; }
        }
        unset($p);
    }
} catch (\Throwable $e) { $recent = []; }
?>
<div class="kk-home">
  <header class="kk-mast">
    <h1 class="kk-site"><?= $name ?></h1>
    <?php if ($sub !== ''): ?><p class="kk-tagline"><?= e($sub) ?></p><?php endif; ?>
  </header>

  <div class="kk-shell">
    <main class="kk-stream">
      <?php if ($recent): ?>
        <?php foreach ($recent as $p): ?>
          <?= View::render('partials/kk-entry', ['p' => $p, 'lp' => $lp, 'base' => $base]) ?>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty"><?= e(__('list.empty')) ?></div>
      <?php endif; ?>
    </main>
    <?= View::render('partials/kk-widgets') ?>
  </div>
</div>
