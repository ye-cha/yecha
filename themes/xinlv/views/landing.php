<?php
/** yecha.org 首页 · 新绿 Xinlv 演示站主题
 * 大色块 Hero（站点名 + 标语 + 搜索 + 统计色块 + 分类色块） → 最新文章卡片网格。
 * 演示站门面：只呈现站点内容（文章 / 评论 / 分类），不出现主题与插件市场入口。 */
$name = e(site_name());
$postsIn = $posts ?? [];

/* ---- 统计色块 ---- */
$stPost = 0; $stCmt = 0; $stCat = 0; $stTag = 0;
$cats = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents'); $C = Db::t('comments'); $M = Db::t('metas'); $R = Db::t('relationships');
        $stPost = (int)Db::one("SELECT COUNT(*) FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL");
        $stCmt  = (int)Db::one("SELECT COUNT(*) FROM {$C} WHERE status='approved'");
        $stCat  = (int)Db::one("SELECT COUNT(*) FROM {$M} WHERE type='category'");
        $stTag  = (int)Db::one("SELECT COUNT(*) FROM {$M} WHERE type='tag'");
        $cats   = Db::rows(
            "SELECT m.name, m.slug, (SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) AS n"
            . " FROM {$M} m WHERE m.type='category' ORDER BY n DESC, m.`order` ASC LIMIT 8"
        );
    }
} catch (\Throwable $e) { $cats = []; }

/* ---- 最新文章 ---- */
$posts = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents'); $R = Db::t('relationships'); $M = Db::t('metas');
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
                'title' => $p['title'], 'slug' => $p['slug'],
                'published_at' => (string)($p['published_at'] ?: $p['created_at']),
                'cat' => $p['cat'] ?? '', 'cat_slug' => $p['cat_slug'] ?? '', 'cover' => $cover,
            ];
        }
    }
} catch (\Throwable $e) { $posts = []; }
if (empty($posts)) { $posts = $postsIn; }

$postCards = '';
foreach ($posts as $p) {
    $slug    = rawurlencode((string)$p['slug']);
    $title   = e((string)$p['title']);
    $date    = e(substr((string)($p['published_at'] ?? ''), 0, 10));
    $cover   = e((string)($p['cover'] ?? ''));
    $cat     = e((string)($p['cat'] ?? ''));
    $catSlug = rawurlencode((string)($p['cat_slug'] ?? ''));
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

$catChips = '';
foreach ($cats as $c) {
    $catChips .= '<a href="/category/' . rawurlencode((string)$c['slug']) . '/">' . e((string)$c['name'])
        . '<i>' . (int)$c['n'] . '</i></a>';
}
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
    <div class="xinlv-stats">
      <div class="xinlv-stat"><b><?= $stPost ?></b><span>篇文章</span></div>
      <div class="xinlv-stat"><b><?= $stCmt ?></b><span>条评论</span></div>
      <div class="xinlv-stat"><b><?= $stCat ?></b><span>个分类</span></div>
      <div class="xinlv-stat"><b><?= $stTag ?></b><span>个标签</span></div>
    </div>
    <?php if ($catChips !== ''): ?>
    <div class="xinlv-cats"><?= $catChips ?></div>
    <?php endif; ?>
  </section>

  <div class="astra-sec-head">
    <h2>最新文章</h2>
    <a class="astra-sec-more" href="/archives/">全部文章 →</a>
  </div>
  <div class="astra-grid"><?= $postCards ?></div>
</div>
