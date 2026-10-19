<?php
/** yecha.org 首页 · 工坊 Toolbox v2
 *  深度还原 tools.02405.com：左对齐 Hero + 分类区块 + 纯文本卡片 + 特性区块。
 */
$lp  = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';

/* ---- 分类及其文章 ---- */
$catGroups = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents'); $R = Db::t('relationships'); $M = Db::t('metas');
        $cats = Db::rows(
            "SELECT m.id, m.name, m.slug, (SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) AS n"
            . " FROM {$M} m WHERE m.type='category' ORDER BY m.`order` ASC, n DESC LIMIT 12"
        );
        foreach ($cats as $c) {
            $rows = Db::rows(
                "SELECT c.title, c.slug, c.published_at, c.created_at, c.content"
                . " FROM {$T} c JOIN {$R} r ON r.content_id=c.id"
                . " WHERE r.meta_id=? AND c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
                . " ORDER BY COALESCE(c.published_at, c.created_at) DESC LIMIT 6",
                [(int)$c['id']]
            );
            if (!empty($rows)) { $catGroups[] = ['cat' => $c, 'posts' => $rows]; }
        }
    }
} catch (\Throwable $e) { $catGroups = []; }

/* ---- Hero 文案 ---- */
$eyebrow = trim((string)get_option('site.label', ''));
if ($eyebrow === '') { $eyebrow = 'TOOLBOX'; }
$eyebrow = mb_strtoupper($eyebrow, 'UTF-8');
$heroSub = trim((string)get_option('site.subtitle', ''));
if ($heroSub === '') { $heroSub = '写作、记录、生活 —— 在喧嚣里为你留一寸可以慢下来的光。'; }

/* ---- 特性区块 ---- */
$features = [
    ['k' => '本地优先', 'v' => '静态 SSR 输出，首屏直出，无需等待 JS 渲染。'],
    ['k' => '可配置',   'v' => '后台可调整版心宽度、强调色、每页条数，适配不同内容。'],
    ['k' => '响应式',   'v' => '从 1440px 到 375px，顶栏、卡片、搜索自适应。'],
];

function tb_excerpt($content, $len = 72) {
    $plain = trim((string)$content);
    $plain = preg_replace('/!\[[^\]]*\]\([^\)]+\)/', '', $plain);
    $plain = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $plain);
    $plain = strip_tags($plain);
    $plain = Str::plain($plain);
    return Str::excerpt($plain, $len);
}
?>
<div class="astra-home tb-home">
  <section class="astra-hero">
    <p class="astra-eyebrow"><?= e($eyebrow) ?></p>
    <h1 class="astra-hero-title"><?= e(site_name()) ?></h1>
    <p class="astra-hero-sub"><?= e($heroSub) ?></p>
    <form class="astra-search" action="<?= e($lp . '/search') ?>" method="get" role="search">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m21 21-4.3-4.3"></path></svg>
      <input type="search" name="q" placeholder="搜索文章…" aria-label="搜索文章">
    </form>
  </section>

  <?php foreach ($catGroups as $g):
        $c = $g['cat'];
        $catName = e((string)$c['name']);
        $catUrl  = e($lp . '/category/' . rawurlencode((string)$c['slug']) . '/');
  ?>
  <section class="tb-sec">
    <div class="astra-sec-head">
      <h2><?= $catName ?></h2>
      <span class="sub"><?= (int)$c['n'] ?> 个</span>
    </div>
    <div class="astra-grid">
      <?php foreach ($g['posts'] as $p):
            $u = $lp . '/' . rawurlencode((string)$p['slug']) . '.html';
            $desc = tb_excerpt((string)($p['content'] ?? ''), 76);
      ?>
      <article class="astra-card">
        <div class="astra-card-body">
          <span class="astra-card-cat"><?= $catName ?></span>
          <h3 class="astra-card-title"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h3>
          <p class="astra-card-excerpt"><?= e($desc) ?></p>
          <a class="astra-readmore" href="<?= e($u) ?>"><?= e(__('ui.read_more')) ?></a>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>

  <?php if (empty($catGroups)): ?>
  <section class="tb-sec">
    <div class="astra-sec-head"><h2>最新文章</h2></div>
    <p class="empty">还没有发布的文章。</p>
  </section>
  <?php endif; ?>

  <section class="toolbox-why">
    <h2>为什么选择这个主题</h2>
    <div class="toolbox-whys">
      <?php foreach ($features as $f): ?>
      <div class="toolbox-feature">
        <div class="k"><?= e($f['k']) ?></div>
        <div class="v"><?= e($f['v']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>
