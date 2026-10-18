<?php
/** yecha 黄羽 Huangyu · 杂志阅读风首页
 *  顶部站点标题 / 简介（左对齐）→ 圆形封面头像文章列表（圆润、绿色强调、留白舒适）。 */
require_once __DIR__ . '/_util.php';

$name = e(site_name());
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
$sub = trim((string)get_option('site.subtitle', ''));
if ($sub === '') { $sub = 'Code & Lifestyle & AI'; }

// 自取最近文章：含摘要 / 分类 / 时间，供杂志列表展示
$items = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents'); $R = Db::t('relationships'); $M = Db::t('metas');
        $rows = Db::rows(
            "SELECT c.id, c.title, c.slug, c.excerpt, c.content, c.published_at, c.created_at"
            . " FROM {$T} c WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
            . " ORDER BY COALESCE(c.published_at, c.created_at) DESC LIMIT 12"
        );
        foreach ($rows as $r) {
            $sid = (int)$r['id'];
            $catRow = Db::rows("SELECT m.slug, m.name FROM {$R} r JOIN {$M} m ON m.id=r.meta_id WHERE r.content_id={$sid} AND m.type='category' ORDER BY m.`order` ASC LIMIT 1");
            $catSlug = $catRow ? (string)$catRow[0]['slug'] : '';
            $catName = $catRow ? (string)$catRow[0]['name'] : '';
            $items[] = [
                'title' => (string)$r['title'],
                'slug'  => (string)$r['slug'],
                'excerpt' => (string)$r['excerpt'],
                'content' => (string)$r['content'],
                'date'  => (string)($r['published_at'] ?: $r['created_at']),
                'cat'   => $catName,
                'cat_slug' => $catSlug,
            ];
        }
    }
} catch (\Throwable $e) { $items = []; }
if (empty($items) && !empty($posts)) {
    // 兜底：控制器传入的精简 posts
    foreach (($posts ?? []) as $p) {
        $items[] = [
            'title' => (string)($p['title'] ?? ''),
            'slug'  => (string)($p['slug'] ?? ''),
            'excerpt' => '', 'content' => '',
            'date'  => (string)($p['published_at'] ?? ''),
            'cat'   => '',
        ];
    }
}
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<div class="hyu-home">
  <header class="hyu-masthead">
    <p class="hyu-kicker">黄羽博客</p>
    <h1 class="hyu-title"><?= $name ?></h1>
    <p class="hyu-lead"><?= e($sub) ?></p>
    <div class="hyu-mast-meta">
      <a class="hyu-pill" href="<?= e($base) ?>category/">分类</a>
      <a class="hyu-pill" href="<?= e($base) ?>tag/">标签</a>
      <a class="hyu-pill" href="<?= e($base) ?>archives">归档</a>
      <a class="hyu-pill" href="<?= e($base) ?>feed">RSS</a>
    </div>
  </header>

  <section class="hyu-stream" aria-label="<?= e(__('ui.recent')) ?>">
    <?php if (empty($items)): ?>
      <div class="hyu-empty"><?= e(__('list.empty')) ?></div>
    <?php else: ?>
      <?php foreach ($items as $it):
          $u = $base . e((string)$it['slug']) . '.html';
          $dt = strtotime((string)$it['date']);
          $dateStr = $dt ? date('Y-m-d', $dt) : '';
          [$color, $ini] = hyu_avatar((string)$it['slug'], (string)$it['title']);
          $raw = ((string)($it['excerpt'] ?? '') !== '') ? (string)$it['excerpt'] : (string)($it['content'] ?? '');
          $exc = hyu_excerpt($raw, 96);
          $cat = (string)($it['cat'] ?? '');
          $catUrl = $cat !== '' ? Poly::termUrl('category', ['slug' => (string)($it['cat_slug'] ?? ''), 'name' => $cat]) : '';
      ?>
      <article class="hyu-post">
        <a class="hyu-ava" href="<?= e($u) ?>" style="background:<?= e($color) ?>" aria-hidden="true"><?= e($ini) ?></a>
        <div class="hyu-body">
          <h2 class="hyu-post-t"><a href="<?= e($u) ?>"><?= e((string)$it['title']) ?></a></h2>
          <p class="hyu-post-x"><?= e($exc) ?></p>
          <div class="hyu-post-m">
            <?php if ($cat !== ''): ?><a class="hyu-cat" href="<?= e($catUrl) ?>"><?= e($cat) ?></a><?php endif; ?>
            <time><?= e($dateStr) ?></time>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>
</div>
