<?php
/** yecha.org 星澈 Xinglan 演示站 —— 卡片网格 + 右侧栏（仿 blog.aistar.cool）
 *  结构：顶部 hero（站名 + 一句话描述 + 搜索框）→ 主体两栏（左：两列卡片网格 / 右：aside 侧栏）→ 版权
 *  卡片 = 渐变封面块 + 标题 + 摘要 + 日期 + 分类 chip。容器 max-width 768px 居中。 */
$name = e(site_name());
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';

$recent = [];
$tags   = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents'); $M = Db::t('metas'); $R = Db::t('relationships');
        $recent = Db::rows(
            "SELECT c.title, c.slug, c.excerpt, c.content, c.published_at, c.created_at,"
            . " (SELECT m.name FROM {$R} r JOIN {$M} m ON m.id=r.meta_id WHERE r.content_id=c.id AND m.type='category' ORDER BY m.`order` ASC LIMIT 1) AS cat,"
            . " (SELECT m.slug FROM {$R} r JOIN {$M} m ON m.id=r.meta_id WHERE r.content_id=c.id AND m.type='category' ORDER BY m.`order` ASC LIMIT 1) AS cat_slug"
            . " FROM {$T} c WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
            . " ORDER BY COALESCE(c.published_at, c.created_at) DESC LIMIT 8"
        );
        $tags = Db::rows(
            "SELECT m.name, m.slug, (SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) AS n"
            . " FROM {$M} m WHERE m.type='tag'"
            . " AND (SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) > 0"
            . " ORDER BY n DESC, m.name ASC LIMIT 18"
        );
    }
} catch (\Throwable $e) { $recent = []; $tags = []; }

$heroSub = trim((string)get_option('site.subtitle', ''));
if ($heroSub === '') { $heroSub = trim((string)get_option('site.description', '')); }
if ($heroSub === '') { $heroSub = '写点什么，留下点什么 —— 关于茶、关于生活、关于那些值得记下来的瞬间。'; }
?>
<div class="xl-home">

  <section class="xl-hero">
    <h1 class="xl-hero-title"><?= $name ?></h1>
    <p class="xl-hero-desc"><?= e($heroSub) ?></p>
    <form class="xl-hero-search" action="<?= e($lp) ?>/search" method="get" role="search">
      <svg class="xl-hero-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m21 21-4.3-4.3"></path></svg>
      <input type="search" name="q" placeholder="搜索文章、标签…" aria-label="<?= e(__('sr.ph')) ?>">
      <button type="submit"><?= e(__('sr.btn')) ?></button>
    </form>
  </section>

  <div class="xl-shell">
    <div class="xl-body">
      <div class="xl-sec-head">
        <h2 class="xl-sec-title">最新文章</h2>
        <a class="xl-sec-more" href="<?= e($lp) ?>/archives/"><?= e(__('nav.archive')) ?></a>
      </div>

      <?php if ($recent): ?>
      <div class="xl-grid">
        <?php $i = 0; foreach ($recent as $p):
          $i++;
          $u = $lp . '/' . rawurlencode((string)$p['slug']) . '.html';
          $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
          $raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
          $exc = Str::plain(Str::plain(Str::excerpt($raw, 92)));
          $cat = (string)($p['cat'] ?? '');
          $catSlug = (string)($p['cat_slug'] ?? '');
        ?>
        <article class="xl-card">
          <a class="xl-cover xl-c<?= (($i - 1) % 6) + 1 ?>" href="<?= e($u) ?>" aria-hidden="true" tabindex="-1">
            <span class="xl-cover-letter"><?= e(mb_substr((string)$p['title'], 0, 1)) ?></span>
          </a>
          <div class="xl-card-body">
            <h3 class="xl-card-title"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h3>
            <p class="xl-card-exc"><?= e($exc) ?></p>
            <div class="xl-card-foot">
              <time class="xl-card-date" datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y-m-d', $dt)) ?></time>
              <?php if ($cat !== '' && $catSlug !== ''): ?>
              <a class="xl-chip" href="<?= e($lp) ?>/category/<?= rawurlencode($catSlug) ?>/"><?= e($cat) ?></a>
              <?php elseif ($cat !== ''): ?>
              <span class="xl-chip"><?= e($cat) ?></span>
              <?php endif; ?>
            </div>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="empty"><?= e(__('list.empty')) ?></div>
      <?php endif; ?>
    </div>

    <aside class="xl-aside">
      <section class="xl-widget">
        <h3 class="xl-w-title">最新发布</h3>
        <ol class="xl-w-list">
          <?php $k = 0; foreach (array_slice($recent, 0, 6) as $p): $k++; ?>
          <li>
            <span class="xl-w-num"><?= sprintf('%02d', $k) ?></span>
            <a href="<?= e($lp . '/' . rawurlencode((string)$p['slug']) . '.html') ?>"><?= e((string)$p['title']) ?></a>
          </li>
          <?php endforeach; ?>
        </ol>
      </section>

      <?php if ($tags): ?>
      <section class="xl-widget">
        <h3 class="xl-w-title">标签云</h3>
        <div class="xl-cloud">
          <?php foreach ($tags as $t): ?>
          <a class="xl-cloud-i" href="<?= e(Poly::termUrl('tag', $t)) ?>"><?= e((string)$t['name']) ?><i><?= (int)$t['n'] ?></i></a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <section class="xl-widget xl-w-sub">
        <h3 class="xl-w-title">订阅</h3>
        <p class="xl-w-text">通过 RSS 订阅最新文章，无需注册。</p>
        <a class="xl-w-btn" href="<?= e($lp) ?>/feed">RSS 订阅</a>
      </section>
    </aside>
  </div>
</div>
