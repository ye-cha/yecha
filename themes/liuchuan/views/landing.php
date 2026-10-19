<?php
/** 柳川 Liuchuan —— 三列网格首页（仿 kawayww 个人博客 · GitHub 风）
 *  无侧栏、宽容器（1200px）、等高网格卡片（标题 + 日期 + 摘要）。 */
$name = e(site_name());
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';

$feed = [];
$stat = ['post' => 0, 'cat' => 0, 'tag' => 0];
try {
    $T = Db::t('contents'); $M = Db::t('metas');
    $stat['post'] = (int)Db::one("SELECT COUNT(*) FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL");
    $stat['cat']  = (int)Db::one("SELECT COUNT(*) FROM {$M} WHERE type='category'");
    $stat['tag']  = (int)Db::one("SELECT COUNT(*) FROM {$M} WHERE type='tag'");
    $rows = Db::rows(
        "SELECT title, slug, excerpt, content, COALESCE(published_at, created_at) d"
        . " FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL"
        . " ORDER BY d DESC LIMIT 12"
    );
    foreach ($rows as $r) {
        $raw = ((string)$r['excerpt'] !== '') ? (string)$r['excerpt'] : (string)$r['content'];
        $feed[] = [
            'title'   => (string)$r['title'],
            'url'     => $base . (string)$r['slug'] . '.html',
            'excerpt' => trim(Str::plain(Str::excerpt(Str::cleanMd($raw), 92))),
            'date'    => strtotime((string)$r['d']),
        ];
    }
} catch (\Throwable $e) { $feed = []; }
?>
<div class="lc-home">
  <header class="lc-hero">
    <h1 class="lc-hero-t"><?= $name ?></h1>
    <?php $hs = trim((string)get_option('site.subtitle', '')); if ($hs === '') { $hs = '记录、整理、分享 —— 一个安静的写作角落。'; } ?>
    <p class="lc-hero-sub"><?= e($hs) ?></p>
    <p class="lc-hero-stat"><?= (int)$stat['post'] ?> 篇文章 · <?= (int)$stat['cat'] ?> 个分类 · <?= (int)$stat['tag'] ?> 个标签</p>
  </header>

  <?php if ($feed): ?>
  <div class="lc-grid lc-grid-home">
    <?php foreach ($feed as $it): ?>
    <article class="lc-card">
      <h2 class="lc-card-t"><a href="<?= e($it['url']) ?>"><?= e($it['title']) ?></a></h2>
      <time class="lc-card-d"><?= e(date('Y-m-d', (int)$it['date'])) ?></time>
      <p class="lc-card-x"><?= e($it['excerpt']) ?></p>
      <span class="lc-card-go">阅读全文</span>
    </article>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="lc-empty">还没有发布的文章。</div>
  <?php endif; ?>
</div>
