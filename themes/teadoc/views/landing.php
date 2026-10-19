<?php
/** xuecha 首页（参考站 vuecho doc-home 结构）：
 *  docs-header（layout 提供）→ hero（左文右大 logo）→ 特性卡 → 学习目录（左树 + 右编号文章列表）
 *  文案取 xuecha.* 选项，缺省回落合理默认。无“最新文章”板块（对齐参考站）。
 */
$GLOBALS['YC_HOME_HERO'] = true;
$sub = trim((string)get_option('site.subtitle', ''));
/* 后台「主题设置」可改（theme.options hero.* 优先）→ 迁移保留 xuecha.* → 默认宣传 YeCha 博客系统 */
$xo = fn(string $k, string $d) => trim((string)theme_opt($k, (string)get_option('xuecha.' . $k, $d)));
$heroBadge = $xo('hero_badge', 'YeCha 博客系统');
$heroTitle = $xo('hero_title', 'YeCha 夜叉');
$heroSub   = $xo('hero_subtitle', '');
if ($heroSub === '') { $heroSub = $sub !== '' ? $sub : '极简博客系统：纯 PHP + MySQL，多语言 / 插件市场 / 评论先审后发，全页缓存毫秒响应。'; }
$btn1 = $xo('hero_btn1_text', '下载 YeCha');
$btn1u = $xo('hero_btn1_url', 'https://yecha.org/download.html');
$btn2 = $xo('hero_btn2_text', '应用市场');
$btn2u = $xo('hero_btn2_url', 'https://yecha.org/market.html');
$feat = [];
for ($i = 1; $i <= 3; $i++) {
    $t = $xo("feature_{$i}_title", '');
    $d = $xo("feature_{$i}_desc", '');
    if ($t !== '') { $feat[] = ['t' => $t, 'd' => $d]; }
}
if (!$feat) {
    $feat = [
        ['t' => '轻快省资源', 'd' => '纯 PHP + MySQL，无框架依赖；全页缓存下公开页毫秒级返回，一台小机器即可支撑。'],
        ['t' => '多语言与生态', 'd' => '内容按语言成组、/en 双语切换；插件 / 主题 / 云市场一键安装，SHA-256 强校验。'],
        ['t' => '克制与可信', 'd' => '评论先审后发 + 垃圾关键词黑名单；阅读量按访客自然日去重；全库备份还原，数据永远是你自己的。'],
    ];
}
$docsTitle = trim((string)get_option('xuecha.docs_section_title', '学习目录'));
$docsSide  = trim((string)get_option('xuecha.docs_sidebar_title', $docsTitle));

/* Logo：站点 logo 优先，其次主题 logo */
$logoRel = trim((string)get_option('site.logo', '/themes/teadoc/logo.png'));
if (!is_file(dirname(__DIR__, 2) . $logoRel)) { $logoRel = '/themes/teadoc/logo.png'; }
$logoAbs = dirname(__DIR__, 2) . $logoRel;
$logoUrl = $logoRel . (is_file($logoAbs) ? '?v=' . filemtime($logoAbs) : '');

/* 分类（左侧树）+ 每类文章 */
$cats = Db::rows('SELECT m.id, m.name, m.slug, m.count FROM ' . Db::t('metas') . ' m WHERE m.type=? AND m.count>0 ORDER BY m.`order` ASC, m.id ASC LIMIT 30', ['category']);
$catPosts = [];
foreach ($cats as $c) {
    $catPosts[(int)$c['id']] = Db::rows(
        'SELECT c.slug, c.title FROM ' . Db::t('relationships') . ' r JOIN ' . Db::t('contents') . ' c ON c.id=r.content_id '
        . "WHERE r.meta_id=? AND c.type='post' AND c.status='publish' AND c.deleted_at IS NULL ORDER BY c.published_at ASC, c.id ASC LIMIT 120",
        [(int)$c['id']]
    );
}
/* 扁平化（按分类顺序 = 参考站编号列表） + 每篇标签名 */
$flat = [];
$ids = [];
foreach ($cats as $c) {
    foreach ($catPosts[(int)$c['id']] as $p) {
        $k = (string)$p['slug'];
        $flat[] = ['slug' => $k, 'title' => (string)$p['title'], 'cat' => (string)$c['name'], 'cat_slug' => (string)$c['slug']];
        $ids[] = $k;
    }
}
$tagsBySlug = [];
if ($ids) {
    $slugsIn = [];
    foreach ($ids as $k) { $slugsIn[] = '?'; }
    $tagRows = Db::rows(
        'SELECT c.slug AS cs, m.name AS tn FROM ' . Db::t('relationships') . ' r '
        . 'JOIN ' . Db::t('contents') . ' c ON c.id=r.content_id '
        . 'JOIN ' . Db::t('metas') . " m ON m.id=r.meta_id WHERE c.slug IN (" . implode(',', $slugsIn) . ") AND m.type='tag' ORDER BY m.count DESC, m.id ASC",
        $ids
    );
    foreach ($tagRows as $tr) { $tagsBySlug[(string)$tr['cs']][] = (string)$tr['tn']; }
}
/* 首页右列文章：默认只列前 10 篇，?all=1 展开全部；左树不受影响 */
$showAll = (($_GET['all'] ?? '') === '1');
$homeLimit = $showAll ? PHP_INT_MAX : 10;
$flatTotal = count($flat);
?>
<div class="home">
  <section class="hero-section">
    <div class="hero-container">
      <div class="hero-logo">
        <img src="<?= e($logoUrl) ?>" alt="Logo" class="logo-image">
      </div>
      <div class="hero-content">
        <div class="hero-text">
          <span class="hero-badge"><?= e($heroBadge) ?></span>
          <h1 class="hero-title"><?= e($heroTitle) ?></h1>
          <p class="hero-description"><?= e($heroSub) ?></p>
          <div class="hero-actions">
            <a href="<?= e($btn1u) ?>" class="btn btn-primary"><?= e($btn1) ?></a>
            <a href="<?= e($btn2u) ?>" class="btn btn-secondary"<?= str_starts_with($btn2u, 'http') ? ' rel="noopener" target="_blank"' : '' ?>><?= e($btn2) ?></a>
          </div>
        </div>
      </div>
    </div>
  </section>

  <?php if ($feat): ?>
  <section class="features-section">
    <div class="features-container">
      <div class="features-grid">
        <?php foreach ($feat as $f): ?>
        <div class="feature-item">
          <h3 class="feature-title"><?= e($f['t']) ?></h3>
          <p class="feature-description"><?= e($f['d']) ?></p>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <section class="docs-section">
    <div class="docs-section-inner">
      <h2 class="docs-section-title"><?= e($docsTitle) ?></h2>
      <div class="docs-home-grid">
        <aside class="docs-sidebar" id="docs-sidebar">
          <div class="sidebar-content">
            <div class="sidebar-section">
              <h3 class="sidebar-title"><?= e($docsSide) ?></h3>
              <div class="docs-tree">
                <?php foreach ($cats as $ci => $c): $rows = $catPosts[(int)$c['id']] ?? []; ?>
                <div class="tree-node">
                  <div class="node-content">
                    <span class="node-toggle" role="button" tabindex="0" aria-expanded="<?= $ci === 0 ? 'true' : 'false' ?>"><?= $ci === 0 ? '▾' : '▸' ?></span>
                    <span class="node-label">
                      <span class="node-name"><?= e((string)$c['name']) ?></span>
                      <span class="node-count"><?= (int)count($rows) ?></span>
                    </span>
                  </div>
                  <ul class="tree-children"<?= $ci === 0 ? '' : ' style="display:none"' ?>>
                    <?php foreach ($rows as $p): ?>
                    <li class="tree-leaf">
                      <div class="leaf-content">
                        <span class="leaf-spacer"></span>
                        <div class="leaf-label"><a class="leaf-link" href="/<?= e((string)$p['slug']) ?>.html"><?= e($p['title']) ?></a></div>
                      </div>
                    </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </aside>
        <div class="docs-content">
          <div class="doc-list">
            <?php $shown = 0; foreach ($flat as $i => $it): if ($shown >= $homeLimit) { break; } $shown++; $tg = $tagsBySlug[$it['slug']] ?? []; ?>
            <a class="doc-list-item" href="/<?= e($it['slug']) ?>.html">
              <span class="doc-list-index"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
              <span class="doc-list-main">
                <span class="doc-list-title"><?= e($it['title']) ?></span>
                <span class="doc-list-meta"><i class="doc-list-cat"><?= e($it['cat']) ?></i><?php foreach ($tg as $tn): ?><i class="doc-list-tag"><?= e($tn) ?></i><?php endforeach; ?></span>
              </span>
              <span class="doc-list-arrow">→</span>
            </a>
            <?php endforeach; ?>
          </div>
          <?php if (!$showAll && $flatTotal > $homeLimit): ?>
          <a class="doc-list-more" href="/?all=1" style="display:inline-flex;align-items:center;gap:6px;margin-top:14px;color:var(--accent,#2F7D46);font-weight:600;text-decoration:none;">更多文章（共 <?= $flatTotal ?> 篇）→</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>
</div>
<script>
/* 学习目录左侧树：点分类展开/收起（对齐参考站交互） */
(function () {
  var tree = document.querySelector('.docs-tree');
  if (!tree) return;
  tree.addEventListener('click', function (e) {
    var t = e.target.closest('.node-content');
    if (!t) return;
    var node = t.closest('.tree-node');
    var kids = node ? node.querySelector('.tree-children') : null;
    if (!node || !kids) return;
    var open = kids.style.display !== 'none';
    kids.style.display = open ? 'none' : '';
    var tg = t.querySelector('.node-toggle');
    if (tg) { tg.textContent = open ? '▸' : '▾'; tg.setAttribute('aria-expanded', open ? 'false' : 'true'); }
  });
})();
</script>
