<?php
/** 寒砚 Hanyan · 首页：卡片列表（左侧封面渐变块 + 右侧 标题/摘要/日期）+ 右侧栏 widget 区
 *  顶部 masthead（站名 + 标语 + 统计）→ 主体两栏（左：卡片列表 / 右：最新文章·标签云·归档 三个 widget） */
$name = e(site_name());
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
$pfx = $lp === '' ? '/' : $lp . '/';

$stats = ['post' => 0, 'cmt' => 0, 'cat' => 0, 'tag' => 0];
$recent = [];
$tags   = [];
$months = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents'); $C = Db::t('comments'); $M = Db::t('metas'); $R = Db::t('relationships');
        $stats['post'] = (int)Db::one("SELECT COUNT(*) FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL");
        $stats['cmt']  = (int)Db::one("SELECT COUNT(*) FROM {$C} WHERE status='approved'");
        $stats['cat']  = (int)Db::one("SELECT COUNT(*) FROM {$M} WHERE type='category'");
        $stats['tag']  = (int)Db::one("SELECT COUNT(*) FROM {$M} WHERE type='tag'");
        $recent = Db::rows(
            "SELECT c.title, c.slug, c.excerpt, c.content, c.published_at, c.created_at,"
            . " (SELECT m.name FROM {$R} r JOIN {$M} m ON m.id=r.meta_id WHERE r.content_id=c.id AND m.type='category' ORDER BY m.`order` ASC LIMIT 1) AS cat,"
            . " (SELECT m.slug FROM {$R} r JOIN {$M} m ON m.id=r.meta_id WHERE r.content_id=c.id AND m.type='category' ORDER BY m.`order` ASC LIMIT 1) AS cat_slug"
            . " FROM {$T} c WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
            . " ORDER BY COALESCE(c.published_at, c.created_at) DESC LIMIT 10"
        );
        $tags = Db::rows(
            "SELECT m.id, m.name, m.slug, (SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) AS n"
            . " FROM {$M} m WHERE m.type='tag'"
            . " AND (SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) > 0"
            . " ORDER BY n DESC, m.name ASC LIMIT 18"
        );
        $months = Db::rows(
            "SELECT DATE_FORMAT(COALESCE(c.published_at, c.created_at), '%Y') AS y,"
            . " DATE_FORMAT(COALESCE(c.published_at, c.created_at), '%m') AS m, COUNT(*) AS n"
            . " FROM {$T} c WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
            . " GROUP BY y, m ORDER BY y DESC, m DESC LIMIT 8"
        );
    }
} catch (\Throwable $e) { $recent = []; $tags = []; $months = []; }

$heroSub = trim((string)get_option('site.subtitle', ''));
if ($heroSub === '') { $heroSub = trim((string)get_option('site.description', '')); }
if ($heroSub === '') { $heroSub = '一方砚台，几行字。把读过的、想过的、喝过的，慢慢地写下来。'; }
?>
<div class="hy-home">

  <section class="hy-masthead">
    <p class="hy-eyebrow"><?= e(site_label()) ?></p>
    <h1><?= $name ?></h1>
    <p><?= e($heroSub) ?></p>
    <div class="hy-stats">
      <div class="hy-stat"><b><?= $stats['post'] ?></b><span>ARTICLES</span></div>
      <div class="hy-stat"><b><?= $stats['cat'] ?></b><span>CATEGORIES</span></div>
      <div class="hy-stat"><b><?= $stats['tag'] ?></b><span>TAGS</span></div>
      <div class="hy-stat"><b><?= $stats['cmt'] ?></b><span>COMMENTS</span></div>
    </div>
  </section>

  <div class="hy-shell">
    <div class="hy-feed">
      <div class="hy-sec-head">
        <h2>最近更新</h2>
        <a class="hy-sec-more" href="<?= e($lp) ?>/archives/"><?= e(__('nav.archive')) ?></a>
      </div>

      <?php if ($recent): ?>
        <?php $i = 0; foreach ($recent as $p):
          $i++;
          $u = $lp . '/' . rawurlencode((string)$p['slug']) . '.html';
          $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
          $raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
          $exc = trim(Str::plain(Str::plain(Str::excerpt($raw, 110))));
          $cat = (string)($p['cat'] ?? '');
          $catSlug = (string)($p['cat_slug'] ?? '');
        ?>
        <article class="hy-card">
          <a class="hy-cover hy-c<?= (($i - 1) % 6) + 1 ?>" href="<?= e($u) ?>" aria-hidden="true" tabindex="-1"><span><?= e(mb_substr((string)$p['title'], 0, 1)) ?></span></a>
          <div class="hy-card-body">
            <h2 class="hy-card-title"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
            <p class="hy-excerpt"><?= e($exc) ?></p>
            <div class="hy-card-meta">
              <time datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y-m-d', $dt)) ?></time>
              <?php if ($cat !== '' && $catSlug !== ''): ?>
              <a class="hy-chip" href="<?= e($lp) ?>/category/<?= rawurlencode($catSlug) ?>/"><?= e($cat) ?></a>
              <?php elseif ($cat !== ''): ?>
              <span class="hy-chip"><?= e($cat) ?></span>
              <?php endif; ?>
            </div>
          </div>
        </article>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty"><?= e(__('list.empty')) ?></div>
      <?php endif; ?>
    </div>

    <aside class="hy-aside">
      <?php if ($recent): ?>
      <section class="hy-widget">
        <h3 class="hy-w-title">最新文章</h3>
        <ul class="hy-w-list">
          <?php $k = 0; foreach (array_slice($recent, 0, 6) as $p): $k++; ?>
          <li>
            <span class="hy-w-num"><?= sprintf('%02d', $k) ?></span>
            <a href="<?= e($lp . '/' . rawurlencode((string)$p['slug']) . '.html') ?>"><?= e((string)$p['title']) ?></a>
          </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <?php if ($tags): ?>
      <section class="hy-widget">
        <h3 class="hy-w-title">标签云</h3>
        <div class="hy-cloud">
          <?php foreach ($tags as $t): ?>
          <a href="<?= e(Poly::termUrl('tag', $t)) ?>"><?= e((string)$t['name']) ?><i><?= (int)$t['n'] ?></i></a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($months): ?>
      <section class="hy-widget">
        <h3 class="hy-w-title">归档</h3>
        <div class="hy-w-arch">
          <?php foreach ($months as $mm): ?>
          <a href="<?= e($lp) ?>/archives/<?= (int)$mm['y'] ?>/<?= sprintf('%02d', (int)$mm['m']) ?>/">
            <span><?= (int)$mm['y'] ?>-<?= sprintf('%02d', (int)$mm['m']) ?></span><b><?= (int)$mm['n'] ?></b>
          </a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>
    </aside>
  </div>
</div>
