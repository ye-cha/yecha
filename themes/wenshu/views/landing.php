<?php
/** 文枢 Wenshu · 首页：文档站风（面包屑 + 文档 Hero + 分类入口卡片网格 + 最新更新列表）
 *  左侧文档导航树由 layout 常驻渲染。 */
$name = e(site_name());
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
$pfx = $lp === '' ? '/' : $lp . '/';

$stats = ['post' => 0];
$cats = [];
$recent = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents'); $M = Db::t('metas'); $R = Db::t('relationships');
        $stats['post'] = (int)Db::one("SELECT COUNT(*) FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL");
        $cats = Db::rows(
            "SELECT m.id, m.name, m.slug, m.description,"
            . " (SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) AS n"
            . " FROM {$M} m WHERE m.type='category'"
            . " AND (SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) > 0"
            . " ORDER BY n DESC, m.`order` ASC LIMIT 9"
        );
        foreach ($cats as &$c) {
            $cid = (int)$c['id'];
            $c['latest'] = Db::rows(
                "SELECT c.title, c.slug, c.published_at, c.created_at"
                . " FROM {$T} c JOIN {$R} r ON r.content_id=c.id"
                . " WHERE r.meta_id={$cid} AND c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
                . " ORDER BY COALESCE(c.published_at, c.created_at) DESC LIMIT 3"
            );
        }
        unset($c);
        $recent = Db::rows(
            "SELECT c.title, c.slug, c.published_at, c.created_at,"
            . " (SELECT m.name FROM {$R} r JOIN {$M} m ON m.id=r.meta_id WHERE r.content_id=c.id AND m.type='category' ORDER BY m.`order` ASC LIMIT 1) AS cat,"
            . " (SELECT m.slug FROM {$R} r JOIN {$M} m ON m.id=r.meta_id WHERE r.content_id=c.id AND m.type='category' ORDER BY m.`order` ASC LIMIT 1) AS cat_slug"
            . " FROM {$T} c WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
            . " ORDER BY COALESCE(c.published_at, c.created_at) DESC LIMIT 8"
        );
    }
} catch (\Throwable $e) { $cats = []; $recent = []; }

$intro = trim((string)get_option('site.subtitle', ''));
if ($intro === '') { $intro = trim((string)get_option('site.description', '')); }
if ($intro === '') { $intro = '这里是以文档形式组织的知识库：左侧是目录树，右侧是内容，按分类索引、按时间归档。'; }
$letters = ['A','B','C','D','E','F','G','H','I'];
?>
<div class="ws-home">

  <nav class="ws-crumb" aria-label="面包屑">
    <a href="<?= e($pfx) ?>">首页</a><span>/</span><b>文档中心</b>
  </nav>

  <header class="ws-doc-hero">
    <h1><?= $name ?><span class="ws-hero-badge">DOCS</span></h1>
    <p class="ws-doc-intro"><?= e($intro) ?></p>
    <div class="ws-doc-meta">
      <span><?= (int)$stats['post'] ?> 篇文档</span>
      <span><?= count($cats) ?> 个分类</span>
      <a href="<?= e($lp) ?>/archives/">按时间归档</a>
    </div>
  </header>

  <h2 class="ws-sec-title">文档入口</h2>
  <?php if ($cats): ?>
  <div class="ws-cards">
    <?php $i = 0; foreach ($cats as $c):
        $i++;
        $u = $pfx . 'category/' . rawurlencode((string)$c['slug']) . '/';
        $posts = $c['latest'] ?? [];
    ?>
    <article class="ws-card">
      <a class="ws-card-head" href="<?= e($u) ?>">
        <span class="ws-card-ico"><?= e($letters[($i - 1) % count($letters)]) ?></span>
        <span class="ws-card-name"><?= e((string)$c['name']) ?></span>
        <span class="ws-card-count"><?= (int)$c['n'] ?></span>
      </a>
      <?php if (!empty($c['description'])): ?><p class="ws-card-desc"><?= e(Str::excerpt((string)$c['description'], 60)) ?></p><?php endif; ?>
      <?php if ($posts): ?>
      <ul class="ws-card-list">
        <?php foreach ($posts as $p2): ?>
        <li><a href="<?= e($pfx . rawurlencode((string)$p2['slug']) . '.html') ?>"><?= e(Str::excerpt((string)$p2['title'], 26)) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <a class="ws-card-more" href="<?= e($u) ?>">查看该分类</a>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($recent): ?>
  <h2 class="ws-sec-title">最新更新</h2>
  <div class="ws-updates">
    <?php foreach ($recent as $p):
        $u = $pfx . rawurlencode((string)$p['slug']) . '.html';
        $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
        $cat = (string)($p['cat'] ?? '');
        $catSlug = (string)($p['cat_slug'] ?? '');
    ?>
    <article class="ws-update">
      <div class="ws-update-main">
        <h3><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h3>
        <?php if ($cat !== '' && $catSlug !== ''): ?><a class="ws-update-cat" href="<?= e($pfx) ?>category/<?= rawurlencode($catSlug) ?>/"><?= e($cat) ?></a>
        <?php elseif ($cat !== ''): ?><span class="ws-update-cat"><?= e($cat) ?></span><?php endif; ?>
      </div>
      <time datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y-m-d', $dt)) ?></time>
    </article>
    <?php endforeach; ?>
  </div>
  <p class="ws-updates-more"><a href="<?= e($lp) ?>/archives/">查看全部 <?= (int)$stats['post'] ?> 篇 →</a></p>
  <?php endif; ?>
</div>
