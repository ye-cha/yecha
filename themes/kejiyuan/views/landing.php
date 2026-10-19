<?php
/** kejiyuan 科技原 —— 蓝 + hero（标语+搜索框+主色蓝）+ 卡片网格（图标+标题+摘要）+ 侧栏（热门技术/标签云）
 *  科技资讯风。 */
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
            . " ORDER BY COALESCE(c.published_at, c.created_at) DESC LIMIT 18"
        );
        foreach ($recent as &$p) {
            if (!empty($p['cat_slug'])) { $p['cat_url'] = $base . 'category/' . rawurlencode((string)$p['cat_slug']) . '/'; }
        }
        unset($p);
    }
} catch (\Throwable $e) { $recent = []; }
?>
<div class="kj-home">
  <section class="kj-hero">
    <h1 class="kj-hero-title"><?= $name ?></h1>
    <p class="kj-hero-sub"><?= $sub !== '' ? e($sub) : '追踪科技前沿，记录开发实践 —— 把技术文章整理得像知识库一样清晰。' ?></p>
    <form class="kj-search" action="<?= e($lp) ?>/search" method="get" role="search">
      <svg class="kj-search-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m21 21-4.3-4.3"></path></svg>
      <input type="search" name="q" placeholder="搜索技术文章…" aria-label="搜索技术文章">
      <button type="submit">搜索</button>
    </form>
  </section>

  <div class="kj-shell">
    <main class="kj-grid">
      <?php if ($recent): ?>
        <?php foreach ($recent as $p): ?>
          <?= View::render('partials/kj-card', ['p' => $p, 'lp' => $lp, 'base' => $base]) ?>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty" style="grid-column:1/-1"><?= e(__('list.empty')) ?></div>
      <?php endif; ?>
    </main>
    <?= View::render('partials/kj-widgets') ?>
  </div>
</div>
