<?php
/** 首页 · 墨白 Mobai v2（对标 u.sb 烧饼博客）
 *  形态：居中大字 Hero（站名 + 描述 + 星点场） → 1080px 两列卡片网格（首篇横跨大卡）。
 *  首篇大卡：左「标题 + 等宽 meta + 标签 chips」/ 右「摘要」（≥1024px 时）；其余普通卡：标题 + meta + 2 行摘要 + chips。
 *  主内容全部服务端输出，无 JS 依赖；演示站门面只呈现站点内容。
 *  手维文件，勿用 tools/gen_parts.py 覆盖。 */
$name  = e(site_name());
$subIn = trim((string)get_option('site.subtitle', ''));
if ($subIn === '') { $subIn = trim((string)get_option('site.description', '')); }
if ($subIn === '') { $subIn = '写作、记录、生活 —— 在喧嚣里为你留一寸可以慢下来的光。'; }

/* ---- 取最新文章 + 分类 + 标签（一次性批量查，避免 N+1）---- */
$rows = [];
$tagMap = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents'); $R = Db::t('relationships'); $M = Db::t('metas');
        $rows = Db::rows(
            'SELECT c.id, c.title, c.slug, c.excerpt, c.content, c.views, c.published_at, c.created_at,'
            . " (SELECT m.name FROM {$R} r JOIN {$M} m ON m.id=r.meta_id WHERE r.content_id=c.id AND m.type='category' ORDER BY m.`order` ASC LIMIT 1) AS cat,"
            . " (SELECT m.slug FROM {$R} r JOIN {$M} m ON m.id=r.meta_id WHERE r.content_id=c.id AND m.type='category' ORDER BY m.`order` ASC LIMIT 1) AS cat_slug"
            . " FROM {$T} c"
            . " WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
            . ' ORDER BY COALESCE(c.published_at, c.created_at) DESC LIMIT 13'
        );
        $ids = [];
        foreach ($rows as $r) { $ids[] = (int)$r['id']; }
        if ($ids) {
            $in = implode(',', $ids);
            foreach (Db::rows(
                "SELECT r.content_id, m.name, m.slug FROM {$R} r JOIN {$M} m ON m.id=r.meta_id"
                . " WHERE r.content_id IN ($in) AND m.type='tag' ORDER BY m.`order` ASC, m.id ASC"
            ) as $t) { $tagMap[(int)$t['content_id']][] = $t; }
        }
    }
} catch (\Throwable $e) { $rows = []; $GLOBALS['US_LANDING_ERR'] = $e->getMessage(); }

if (empty($rows) && !empty($posts)) { $rows = $posts; }
if (!empty($GLOBALS['US_LANDING_ERR'])) { echo '<!-- LANDING_SQL_ERR: ' . e((string)$GLOBALS['US_LANDING_ERR']) . ' -->'; }

/* ---- 星点场（纯 CSS 装饰，位置固定，不随内容变化）---- */
$sparkles = [
    ['w' => 18, 't' => 24, 'r' => 10], ['w' => 12, 't' => 58, 'r' => 22], ['w' => 10, 't' => 14, 'r' => 34],
    ['w' => 8,  't' => 72, 'r' => 7],  ['w' => 4,  't' => 36, 'r' => 27, 'dot' => 1],
    ['w' => 3,  't' => 48, 'r' => 13, 'dot' => 1], ['w' => 3, 't' => 20, 'r' => 18, 'dot' => 1],
    ['w' => 2,  't' => 66, 'r' => 32, 'dot' => 1], ['w' => 2, 't' => 10, 'r' => 46, 'dot' => 1],
];
$sparkHtml = '';
foreach ($sparkles as $i => $sp) {
    $sparkHtml .= '<i class="' . (empty($sp['dot']) ? '' : 'dot ') . 'sp' . ($i + 1) . '" style="width:' . (int)$sp['w']
        . 'px;height:' . (int)$sp['w'] . 'px;top:' . (int)$sp['t'] . '%;right:' . (int)$sp['r']
        . '%;animation-delay:' . ($i * 0.45) . 's"></i>';
}

$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<section class="us-hero">
  <div class="us-sparkles" aria-hidden="true"><?= $sparkHtml ?></div>
  <div class="us-hero-in">
    <h1><?= $name ?></h1>
    <p class="us-sub"><?= e($subIn) ?></p>
  </div>
</section>

<?php if (empty($rows)): ?>
<div class="us-empty">还没有发布的文章。</div>
<?php else: ?>
<div class="us-grid">
<?php foreach ($rows as $i => $p):
    $u   = $base . e((string)($p['slug'] ?? '')) . '.html';
    $ts  = strtotime((string)($p['published_at'] ?: ($p['created_at'] ?? '')));
    $raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
    $exc = Str::excerpt(Str::plain(Str::plain($raw)), 110);
    $cat = (string)($p['cat'] ?? '');
    $catSlug = (string)($p['cat_slug'] ?? '');
    $tg  = $tagMap[(int)($p['id'] ?? 0)] ?? [];
    $lead = $i === 0;
?>
  <article class="us-card<?= $lead ? ' lead' : '' ?>">
    <h2><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
    <div class="us-m">
      <time datetime="<?= e(date('Y-m-d', $ts)) ?>"><?= e(date('Y-m-d', $ts)) ?></time>
      <?php if ($cat !== ''): ?><span><a href="<?= e($base . 'category/' . rawurlencode($catSlug) . '/') ?>"><?= e($cat) ?></a></span><?php endif; ?>
      <span><?= e(__('meta.reads')) ?><?= Str::num((int)($p['views'] ?? 0)) ?></span>
    </div>
    <p class="us-x"><?= e($exc) ?></p>
    <?php if ($tg): ?>
    <div class="us-tags">
      <?php foreach (array_slice($tg, 0, 6) as $t): ?>
      <a class="us-chip" href="<?= e($base . 'tag/' . rawurlencode((string)$t['slug']) . '/') ?>"><?= e((string)$t['name']) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </article>
<?php endforeach; ?>
</div>
<?php endif; ?>
