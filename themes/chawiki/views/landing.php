<?php
/**
 * ChaWiki · 首页（词条库门户）
 * 三类页型之一：首页 = Hero（搜索 + 规模数字）+ 分类总览 + 最新/推荐词条卡片
 * 走 landing.php 需选项 home.landing=1（本实例已置 1；插件 home-static 会把它渲成静态 HTML 直出）
 */
$GLOBALS['YC_HOME_HERO'] = true;
require_once __DIR__ . '/../inc.php';

$lp = chawiki_prefix();
$siteName = site_name();
$tagline  = trim((string)get_option('site.desc', ''));
if ($tagline === '') { $tagline = '茶百科：覆盖茶类、产区、工艺、器具、健康与茶史的茶知识词条库。'; }

/* 规模数字（只读本站自己的库） */
$nEntries = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('contents') . " WHERE type='post' AND status='publish' AND deleted_at IS NULL");
$nCats    = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('metas') . " WHERE type='category' AND count>0");
$nImgs    = 0;
$upDir = dirname(__DIR__, 3) . '/uploads';
if (is_dir($upDir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($upDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { if ($f->isFile() && preg_match('/\.(webp|jpe?g|png|gif|svg)$/i', $f->getFilename())) { $nImgs++; } }
}

/* 分类总览（按旧站分类顺序，count 回填后有序） */
$cats = Db::rows(
    'SELECT id, name, slug, `count` FROM ' . Db::t('metas') . " WHERE type='category' AND count>0 ORDER BY `count` DESC, id ASC LIMIT 24"
);

/* 最新词条（首页推荐位） */
$latest = Db::rows(
    'SELECT c.id, c.slug, c.title, c.excerpt, c.description, c.content, c.published_at, c.created_at '
    . 'FROM ' . Db::t('contents') . ' c '
    . "WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL "
    . 'ORDER BY c.published_at DESC, c.id DESC LIMIT 12'
);
$latCats = [];
if ($latest) {
    $ids = array_map('intval', array_column($latest, 'id'));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $rows = Db::rows(
        'SELECT r.content_id, m.name, m.slug FROM ' . Db::t('relationships') . ' r JOIN ' . Db::t('metas') . " m ON m.id=r.meta_id "
        . "WHERE r.content_id IN ($in) AND m.type='category'",
        $ids
    );
    foreach ($rows as $r) { $latCats[(int)$r['content_id']] = ['name' => (string)$r['name'], 'slug' => (string)$r['slug']]; }
}
/** 从正文里取第一张图作卡片缩略图（失败则不显示图） */
$thumb = function (string $content): string {
    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m)) {
        $u = $m[1];
        if (str_starts_with($u, 'http') || str_starts_with($u, '/')) { return $u; }
    }
    return '';
};

/* 特色分类（带中文说明，与旧站 13 类对应） */
$catDesc = [
    'tea-type' => '绿茶、红茶、乌龙、黑茶、白茶、黄茶等茶类词条',
    'region'   => '各省产区、山头、村寨与地理环境档案',
    'company'  => '茶企与品牌：历史、代表产品与特色',
    'variety'  => '茶树品种与名丛资源',
    'people'   => '茶人、制茶师与茶史人物',
    'custom'   => '茶俗、礼俗与民俗茶事',
    'review'   => '评茶：审评方法、术语与品质特征',
    'health'   => '茶与健康：成分、功效与饮用禁忌',
    'history'  => '茶史：源流、断代与文献',
    'craft'    => '制茶工艺：杀青、揉捻、发酵、焙火等',
    'storage'  => '仓储与收藏：存放、陈化与辨伪',
    'brew'     => '冲泡：水温、器具、冲泡手法',
    'tool'     => '器具：壶、盖碗、杯与配件',
];
?>
<div class="home chawiki-home">

  <section class="chawiki-hero">
    <div class="hero-container">
      <h1 class="hero-title"><?= e($siteName) ?></h1>
      <p class="hero-description"><?= e($tagline) ?></p>
      <div class="hero-stats">
        <span class="hero-stat"><b><?= number_format($nEntries) ?></b><span>词条</span></span>
        <span class="hero-stat"><b><?= number_format($nCats) ?></b><span>分类</span></span>
        <?php if ($nImgs > 0): ?><span class="hero-stat"><b><?= number_format($nImgs) ?></b><span>本地配图</span></span><?php endif; ?>
      </div>
      <form class="hero-search" action="<?= e($lp . '/search') ?>" method="get" role="search">
        <input type="search" name="q" placeholder="搜索词条：茶名 / 产区 / 工艺 / 器具…" aria-label="<?= e(__('ui.search')) ?>">
        <button type="submit"><?= e(__('ui.search')) ?></button>
      </form>
    </div>
  </section>

  <?php if ($cats): ?>
  <section class="chawiki-section">
    <h2>分类总览</h2>
    <div class="cat-grid">
      <?php foreach ($cats as $c): $sl = (string)$c['slug']; ?>
      <a class="cat-card" href="<?= e($lp . '/category/' . rawurlencode($sl) . '/') ?>">
        <span>
          <span class="cc-name"><?= e((string)$c['name']) ?></span>
          <?php if (isset($catDesc[$sl])): ?><span class="cc-desc"><?= e($catDesc[$sl]) ?></span><?php endif; ?>
        </span>
        <span class="cc-n"><?= number_format((int)$c['count']) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <a class="sec-more" href="<?= e($lp . '/category/') ?>">全部分类 →</a>
  </section>
  <?php endif; ?>

  <?php if ($latest): ?>
  <section class="chawiki-section">
    <h2>最新词条</h2>
    <div class="entry-grid">
      <?php foreach ($latest as $p):
          $im = $thumb((string)($p['content'] ?? ''));
          $cid = (int)$p['id'];
          $c0 = $latCats[$cid] ?? null;
          $ex = trim((string)($p['excerpt'] ?? ''));
          if ($ex === '') { $ex = Str::excerpt((string)($p['content'] ?? ''), 90); }
      ?>
      <a class="entry-card" href="<?= e(chawiki_url((string)$p['slug'])) ?>">
        <?php if ($im !== ''): ?><img class="ec-thumb" src="<?= e($im) ?>" alt="<?= e((string)$p['title']) ?>" loading="lazy" width="268" height="168"><?php endif; ?>
        <span class="ec-body">
          <?php if ($c0): ?><span class="ec-cat"><?= e($c0['name']) ?></span><?php endif; ?>
          <span class="ec-title"><?= e((string)$p['title']) ?></span>
          <span class="ec-ex"><?= e(Str::excerpt($ex, 72)) ?></span>
        </span>
      </a>
      <?php endforeach; ?>
    </div>
    <a class="sec-more" href="<?= e($lp . '/latest') ?>">更多词条 →</a>
  </section>
  <?php endif; ?>

</div>
