<?php
/** yecha.org 首页 · Astra 风格博客首页
 * 居中 Hero（站点名 + 标语 + 搜索框） → 最新文章图文卡片网格 → 可选「博客圈」模块。
 * 右栏由 layout.php 的 $side 提供（分类 / 最新 / 标签 / 归档），landing 模式默认不显示。
 * 文章列表直接从库中取（最多 12 篇），并带封面图与分类；router 传入的 $posts 作为兜底。 */
$name = e(site_name());
$postsIn = $posts ?? [];
$showCircle = (string)get_option('home.show_circle', '0') === '1';
$circleBlogs = (int)($circle_blogs ?? 0);
$circlePosts = (int)($circle_posts ?? 0);

$posts = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents');
        $R = Db::t('relationships');
        $M = Db::t('metas');
        $rows = Db::rows(
            'SELECT c.title, c.slug, c.published_at, c.created_at, c.content,'
            . " (SELECT m.name FROM {$R} r JOIN {$M} m ON m.id=r.meta_id WHERE r.content_id=c.id AND m.type='category' ORDER BY m.`order` ASC LIMIT 1) AS cat,"
            . " (SELECT m.slug FROM {$R} r JOIN {$M} m ON m.id=r.meta_id WHERE r.content_id=c.id AND m.type='category' ORDER BY m.`order` ASC LIMIT 1) AS cat_slug"
            . " FROM {$T} c"
            . " WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
            . ' ORDER BY COALESCE(c.published_at, c.created_at) DESC LIMIT 12'
        );
        foreach ($rows as $p) {
            $cover = '';
            if (preg_match('/!\[[^\]]*\]\(([^)\s]+)\)/', (string)($p['content'] ?? ''), $m)) { $cover = $m[1]; }
            $posts[] = [
                'title'         => $p['title'],
                'slug'         => $p['slug'],
                'published_at' => (string)($p['published_at'] ?: $p['created_at']),
                'cat'          => $p['cat'] ?? '',
                'cat_slug'     => $p['cat_slug'] ?? '',
                'cover'        => $cover,
            ];
        }
    }
} catch (\Throwable $e) {
    $posts = [];
}
if (empty($posts)) { $posts = $postsIn; }

$postCards = '';
foreach ($posts as $p) {
    $slug     = rawurlencode((string)$p['slug']);
    $title    = e((string)$p['title']);
    $date     = e(substr((string)($p['published_at'] ?? ''), 0, 10));
    $cover    = e((string)($p['cover'] ?? ''));
    $cat      = e((string)($p['cat'] ?? ''));
    $catSlug  = rawurlencode((string)($p['cat_slug'] ?? ''));
    $th = $cover !== ''
        ? '<a class="astra-th" href="/' . $slug . '.html"><img src="' . $cover . '" alt="' . $title . '" loading="lazy"></a>'
        : '<a class="astra-th" href="/' . $slug . '.html" aria-hidden="true" tabindex="-1"></a>';
    $catChip = $cat !== ''
        ? '<a class="astra-card-cat" href="/category/' . $catSlug . '/">' . $cat . '</a>'
        : '';
    $postCards .= '<article class="astra-card">'
        . $th
        . '<div class="astra-card-body">'
        . $catChip
        . '<h3 class="astra-card-title"><a href="/' . $slug . '.html">' . $title . '</a></h3>'
        . '<div class="astra-card-meta"><time>' . $date . '</time></div>'
        . '<a class="astra-readmore" href="/' . $slug . '.html">阅读全文 →</a>'
        . '</div>'
        . '</article>';
}
if ($postCards === '') { $postCards = '<p class="empty">还没有发布的文章。</p>'; }
?>
<div class="astra-home">
  <section class="astra-hero">
    <p class="astra-eyebrow"><?= e(site_label()) ?></p>
    <h1 class="astra-hero-title"><?= $name ?></h1>
    <?php
      $heroSub = trim((string)get_option('site.subtitle', ''));
      if ($heroSub === '') { $heroSub = '写作、记录、生活 —— 在喧嚣里为你留一寸可以慢下来的光。'; }
    ?>
    <p class="astra-hero-sub"><?= e($heroSub) ?></p>
    <form class="astra-search" action="/search" method="get" role="search">
      <input type="search" name="q" placeholder="搜索文章…" aria-label="搜索文章">
      <button type="submit">搜索</button>
    </form>
    <div class="astra-hero-cta">
      <a class="astra-cta" href="/store">浏览主题与插件 →</a>
      <a class="astra-cta ghost" href="/square">主题演示广场</a>
    </div>
  </section>

  <div class="astra-sec-head">
    <h2>最新文章</h2>
    <a class="astra-sec-more" href="/archives/">全部文章 →</a>
  </div>
  <div class="astra-grid"><?= $postCards ?></div>

  <?php if ($showCircle): ?>
  <section class="astra-circle">
    <h2>博客圈</h2>
    <p>已收录 <b><?= $circleBlogs ?></b> 个博客、<b><?= $circlePosts ?></b> 篇文章。<a href="/blog-circle.html">去看看 →</a></p>
  </section>
  <?php endif; ?>
</div>
