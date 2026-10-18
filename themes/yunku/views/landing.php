<?php
/** yecha.org 云库 Yunku 演示站 —— 知识库门户风格
 *  大搜索 Hero（站名 + 标语 + 搜索 + 统计）→ 知识库分类板块（每分类：图标 + 名 + 篇数 + 最新文章 + 查看全部）→ 最新文章。
 *  演示站门面：只呈现站点内容（文章 / 评论 / 分类），不出现主题与插件市场入口。 */
$name = e(site_name());
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';

$stats = ['post' => 0, 'cmt' => 0, 'cat' => 0, 'tag' => 0];
$cats = [];
$recent = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents'); $C = Db::t('comments'); $M = Db::t('metas'); $R = Db::t('relationships');
        $stats['post'] = (int)Db::one("SELECT COUNT(*) FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL");
        $stats['cmt']  = (int)Db::one("SELECT COUNT(*) FROM {$C} WHERE status='approved'");
        $stats['cat']  = (int)Db::one("SELECT COUNT(*) FROM {$M} WHERE type='category'");
        $stats['tag']  = (int)Db::one("SELECT COUNT(*) FROM {$M} WHERE type='tag'");
        $cats = Db::rows(
            "SELECT m.id, m.name, m.slug, m.description,"
            . " (SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) AS n"
            . " FROM {$M} m WHERE m.type='category'"
            . " AND (SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) > 0"
            . " ORDER BY n DESC, m.`order` ASC LIMIT 12"
        );
        foreach ($cats as &$c) {
            $cid = (int)$c['id'];
            $c['posts'] = Db::rows(
                "SELECT c.title, c.slug, c.published_at, c.created_at"
                . " FROM {$T} c JOIN {$R} r ON r.content_id=c.id"
                . " WHERE r.meta_id={$cid} AND c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
                . " ORDER BY COALESCE(c.published_at, c.created_at) DESC LIMIT 5"
            );
        }
        unset($c);
        $recent = Db::rows(
            "SELECT c.title, c.slug, c.published_at, c.created_at,"
            . " (SELECT m.name FROM {$R} r JOIN {$M} m ON m.id=r.meta_id WHERE r.content_id=c.id AND m.type='category' ORDER BY m.`order` ASC LIMIT 1) AS cat,"
            . " (SELECT m.slug FROM {$R} r JOIN {$M} m ON m.id=r.meta_id WHERE r.content_id=c.id AND m.type='category' ORDER BY m.`order` ASC LIMIT 1) AS cat_slug"
            . " FROM {$T} c WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
            . " ORDER BY COALESCE(c.published_at, c.created_at) DESC LIMIT 10"
        );
    }
} catch (\Throwable $e) { $cats = []; $recent = []; }
?>
<div class="yk-home">
  <section class="yk-hero">
    <p class="yk-eyebrow"><?= e(site_label()) ?></p>
    <h1 class="yk-hero-title"><?= $name ?></h1>
    <?php $heroSub = trim((string)get_option('site.subtitle', '')); if ($heroSub === '') { $heroSub = '一个干净、好读的知识库 —— 把文章、笔记与经验，整理得像书架一样清楚。'; } ?>
    <p class="yk-hero-sub"><?= e($heroSub) ?></p>
    <form class="yk-search" action="<?= e($lp) ?>/search" method="get" role="search">
      <svg class="yk-search-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><path d="m21 21-4.3-4.3"></path></svg>
      <input type="search" name="q" placeholder="搜索知识库…" aria-label="搜索知识库">
      <button type="submit">搜索</button>
    </form>
    <div class="yk-stats">
      <div class="yk-stat"><b><?= $stats['post'] ?></b><span>篇文章</span></div>
      <div class="yk-stat"><b><?= $stats['cmt'] ?></b><span>条评论</span></div>
      <div class="yk-stat"><b><?= $stats['cat'] ?></b><span>个分类</span></div>
      <div class="yk-stat"><b><?= $stats['tag'] ?></b><span>个标签</span></div>
    </div>
  </section>

  <section class="yk-kb">
    <div class="yk-sec-head"><h2>知识库分类</h2><a class="yk-sec-more" href="<?= e($lp) ?>/category/">全部分类 →</a></div>
    <div class="yk-kb-grid">
      <?php foreach ($cats as $c):
        $slug = rawurlencode((string)$c['slug']);
        $count = (int)$c['n'];
        $posts = $c['posts'] ?? [];
      ?>
      <article class="yk-kb-sec">
        <div class="yk-kb-head">
          <span class="yk-kb-ico">📚</span>
          <a class="yk-kb-name" href="<?= e($lp) ?>/category/<?= $slug ?>/"><?= e((string)$c['name']) ?></a>
          <span class="yk-kb-count"><?= $count ?> 篇</span>
        </div>
        <?php if (!empty($c['description'])): ?><p class="yk-kb-desc"><?= e((string)$c['description']) ?></p><?php endif; ?>
        <?php if ($posts): ?>
        <ul class="yk-kb-list">
          <?php foreach ($posts as $p):
            $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
          ?>
          <li><a href="<?= e($lp) ?>/<?= rawurlencode((string)$p['slug']) ?>.html"><?= e((string)$p['title']) ?></a><time><?= e(date('Y-m-d', $dt)) ?></time></li>
          <?php endforeach; ?>
        </ul>
        <a class="yk-kb-all" href="<?= e($lp) ?>/category/<?= $slug ?>/">查看全部 <?= $count ?> 篇 →</a>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
  </section>

  <?php if ($recent): ?>
  <section class="yk-recent">
    <div class="yk-sec-head"><h2>最新文章</h2><a class="yk-sec-more" href="<?= e($lp) ?>/archives/">全部文章 →</a></div>
    <ul class="yk-recent-list">
      <?php foreach ($recent as $p):
        $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
        $cat = e((string)($p['cat'] ?? ''));
        $catSlug = rawurlencode((string)($p['cat_slug'] ?? ''));
      ?>
      <li>
        <a class="yk-recent-t" href="<?= e($lp) ?>/<?= rawurlencode((string)$p['slug']) ?>.html"><?= e((string)$p['title']) ?></a>
        <div class="yk-recent-meta">
          <?php if ($cat !== '' && $catSlug !== ''): ?><a class="yk-recent-cat" href="<?= e($lp) ?>/category/<?= $catSlug ?>/"><?= $cat ?></a>
          <?php elseif ($cat !== ''): ?><span class="yk-recent-cat"><?= $cat ?></span><?php endif; ?>
          <time><?= e(date('Y-m-d', $dt)) ?></time>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>
</div>
