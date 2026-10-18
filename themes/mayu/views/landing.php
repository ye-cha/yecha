<?php
/** 码语 Mayu —— 技术博客双栏首页
 *  左侧文章流（卡片：标题 + 摘要 + 标签 chips + 阅读时长）＋ 右侧小侧栏（标签云 / 归档 / 最新文章）。
 *  演示站门面：只呈现站点内容（文章 / 分类 / 标签 / 归档），不出现主题与插件市场入口。 */
$name = e(site_name());
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';

/** 阅读时长（按 400 字/分钟 估算） */
$rt = function (string $raw): int {
    $n = mb_strlen(trim((string)preg_replace('/\s+/u', '', strip_tags($raw))));
    return max(1, (int)ceil($n / 400));
};

$feed = [];
$stat = ['post' => 0, 'cat' => 0, 'tag' => 0];
try {
    $T = Db::t('contents'); $M = Db::t('metas'); $R = Db::t('relationships');
    $stat['post'] = (int)Db::one("SELECT COUNT(*) FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL");
    $stat['cat']  = (int)Db::one("SELECT COUNT(*) FROM {$M} WHERE type='category'");
    $stat['tag']  = (int)Db::one("SELECT COUNT(*) FROM {$M} WHERE type='tag'");

    $rows = Db::rows(
        "SELECT c.id, c.title, c.slug, c.excerpt, c.content, c.published_at, c.created_at"
        . " FROM {$T} c WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
        . " ORDER BY COALESCE(c.published_at, c.created_at) DESC LIMIT 8"
    );
    $ids = [];
    foreach ($rows as $r) { $ids[] = (int)$r['id']; }
    $tagMap = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (Db::rows(
            "SELECT r.content_id, m.name, m.slug FROM {$R} r JOIN {$M} m ON m.id=r.meta_id"
            . " WHERE r.content_id IN ({$in}) AND m.type='tag' ORDER BY m.`count` DESC",
            $ids
        ) as $t) {
            $tagMap[(int)$t['content_id']][] = ['name' => (string)$t['name'], 'slug' => (string)$t['slug']];
        }
        foreach (Db::rows(
            "SELECT r.content_id, m.name, m.slug FROM {$R} r JOIN {$M} m ON m.id=r.meta_id"
            . " WHERE r.content_id IN ({$in}) AND m.type='category' ORDER BY m.`order` ASC",
            $ids
        ) as $t) {
            if (!isset($tagMap[(int)$t['content_id'] . '_cat'])) { $tagMap[(int)$t['content_id'] . '_cat'] = ['name' => (string)$t['name'], 'slug' => (string)$t['slug']]; }
        }
    }
    foreach ($rows as $r) {
        $cid = (int)$r['id'];
        $cat = $tagMap[$cid . '_cat'] ?? null;
        $raw = ((string)$r['excerpt'] !== '') ? (string)$r['excerpt'] : (string)$r['content'];
        $feed[] = [
            'title'   => (string)$r['title'],
            'url'     => $base . (string)$r['slug'] . '.html',
            'excerpt' => trim(Str::plain(Str::excerpt(Str::cleanMd($raw), 118))),
            'date'    => strtotime((string)($r['published_at'] ?: $r['created_at'])),
            'min'     => $rt((string)$r['content']),
            'cat'     => $cat,
            'tags'    => array_slice($tagMap[$cid] ?? [], 0, 3),
        ];
    }
} catch (\Throwable $e) { $feed = []; }
?>
<div class="my-home">
  <section class="my-hero">
    <p class="my-hero-tag">&lt;/&gt; <?= e(site_label()) ?></p>
    <h1 class="my-hero-title"><?= $name ?></h1>
    <?php $hs = trim((string)get_option('site.subtitle', '')); if ($hs === '') { $hs = '写代码、记笔记、整理思路 —— 一篇篇把技术细节讲清楚。'; } ?>
    <p class="my-hero-sub"><?= e($hs) ?></p>
    <div class="my-hero-stat">
      <span><b><?= $stat['post'] ?></b> 篇文章</span>
      <span><b><?= $stat['cat'] ?></b> 个分类</span>
      <span><b><?= $stat['tag'] ?></b> 个标签</span>
    </div>
  </section>

  <div class="my-shell">
    <div class="my-feed">
      <div class="my-feed-head">
        <h2 class="my-feed-title">最新文章</h2>
        <a class="my-feed-all" href="<?= e($lp) ?>/archives/">全部归档 →</a>
      </div>

      <?php if ($feed): ?>
      <?php foreach ($feed as $it): ?>
      <article class="my-card">
        <h3 class="my-card-t"><a href="<?= e($it['url']) ?>"><?= e($it['title']) ?></a></h3>
        <p class="my-card-x"><?= e($it['excerpt']) ?></p>
        <div class="my-card-foot">
          <?php if (!empty($it['tags'])): ?>
          <div class="my-chips">
            <?php foreach ($it['tags'] as $t): ?>
            <a class="my-chip" href="<?= e($lp) ?>/tag/<?= rawurlencode((string)$t['slug']) ?>/"># <?= e((string)$t['name']) ?></a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <div class="my-card-m">
            <?php if (!empty($it['cat'])): ?><a class="my-card-cat" href="<?= e($lp) ?>/category/<?= rawurlencode((string)$it['cat']['slug']) ?>/"><?= e((string)$it['cat']['name']) ?></a><?php endif; ?>
            <time><?= e(date('Y-m-d', (int)$it['date'])) ?></time>
            <span class="my-min">约 <?= (int)$it['min'] ?> 分钟</span>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
      <div class="my-feed-more"><a href="<?= e($lp) ?>/archives/">浏览全部文章</a></div>
      <?php else: ?>
      <div class="my-empty">还没有发布的文章。</div>
      <?php endif; ?>
    </div>

    <?= View::render('partials/my-side') ?>
  </div>
</div>
