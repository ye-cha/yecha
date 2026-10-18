<?php
/**
 * 页脚（新绿 Xinlv v2.2 · 多区域 + 订阅，对标 kwa.cc / anshumo）
 *  brand（logo/站名/标语/简介/社交） + middle（统计 8 格卡） + subscribe（邮箱订阅）
 *  + legal（关于/友链/隐私/RSS） + bottom（版权 / Powered）
 *  手写，勿用 tools/gen_parts.py 覆盖。
 */
$icp     = trim((string)get_option('site.icp', ''));
$icpLink = (string)get_option('site.icp_link', 'https://beian.miit.gov.cn/');
$lp      = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
$homeHref = $lp === '' ? '/' : $lp . '/';

/* 站点品牌 */
$siteName = site_label();
$tagline  = trim((string)get_option('site.subtitle', ''));
$desc     = trim((string)get_option('site.description', ''));
if ($desc === '') { $desc = $tagline; }
$logoRel  = trim((string)get_option('site.logo', '/themes/xinlv/logo.svg'));
if ($logoRel === '' || !is_file(dirname(__DIR__, 3) . $logoRel)) { $logoRel = '/themes/xinlv/logo-oni.png'; }
$email    = trim((string)get_option('site.email', ''));
$github   = trim((string)get_option('site.github', ''));

/* 统计（全部容错，失败显示 —） */
$stPost = 0; $stCat = 0; $stTag = 0; $stCmt = 0; $stViews = 0; $stUser = 0; $stSince = ''; $stUpd = '';
try {
    if (class_exists('Db', false)) {
        $stPost  = (int)Db::one("SELECT COUNT(*) FROM " . Db::t('contents') . " WHERE type='post' AND status='publish' AND deleted_at IS NULL");
        $stCat   = (int)Db::one("SELECT COUNT(*) FROM " . Db::t('metas') . " WHERE type='category'");
        $stTag   = (int)Db::one("SELECT COUNT(*) FROM " . Db::t('metas') . " WHERE type='tag'");
        $stViews = (int)Db::one("SELECT COALESCE(SUM(views),0) FROM " . Db::t('contents') . " WHERE type='post' AND status='publish' AND deleted_at IS NULL");
        try { $stCmt = (int)Db::one("SELECT COUNT(*) FROM " . Db::t('comments') . " WHERE status='approved' OR status='publish' OR status=1"); } catch (\Throwable $e2) { try { $stCmt = (int)Db::one("SELECT COUNT(*) FROM " . Db::t('comments')); } catch (\Throwable $e3) { $stCmt = 0; } }
        try { $stUser = (int)Db::one("SELECT COUNT(*) FROM " . Db::t('users')); } catch (\Throwable $e2) { $stUser = 0; }
        try {
            $r = Db::row("SELECT MIN(published_at) a, MAX(published_at) b FROM " . Db::t('contents') . " WHERE type='post' AND status='publish' AND deleted_at IS NULL");
            if ($r) {
                $stSince = $r['a'] ? date('Y', strtotime((string)$r['a'])) : '';
                $stUpd   = $r['b'] ? date('m-d', strtotime((string)$r['b'])) : '';
            }
        } catch (\Throwable $e2) { }
    }
} catch (\Throwable $e) { }

$statCells = [
    ['visit',    '访问量',      Str::num($stViews)],
    ['comment',  '评论',        Str::num($stCmt)],
    ['post',     '文章',        Str::num($stPost), $lp . '/archives'],
    ['category', '分类',        Str::num($stCat),  $lp . '/category/'],
    ['tag',      '标签',        Str::num($stTag),  $lp . '/tag/'],
    ['join',     '用户',        Str::num($stUser)],
    ['location', '始于',        $stSince !== '' ? $stSince : '—'],
    ['like',     '更新',        $stUpd !== '' ? $stUpd : '—'],
];
$icons = [
    'visit'    => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
    'comment'  => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'post'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
    'category' => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
    'tag'      => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1"/>',
    'join'     => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'like'     => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l8.84 8.84 8.84-8.84a5.5 5.5 0 0 0 0-7.78z"/>',
    'location' => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
];
/* 独立页存在才显示对应 legal 链接 */
$has = [];
try {
    if (class_exists('Db', false)) {
        foreach (Db::rows('SELECT slug FROM ' . Db::t('contents') . " WHERE type='page' AND status='publish' AND deleted_at IS NULL") as $r) {
            $has[(string)$r['slug']] = true;
        }
    }
} catch (\Throwable $e) { $has = []; }
$legal = [];
if (isset($has['about']))          { $legal[$lp . '/about.html']          = __('nav.about'); }
if (isset($has['links']))          { $legal[$lp . '/links.html']          = __('nav.links'); }
if (isset($has['privacy-policy'])) { $legal[$lp . '/privacy-policy.html'] = __('nav.privacy'); }
$legal[$lp . '/feed'] = 'RSS';
$legal[$lp . '/sitemap.xml'] = 'Sitemap';
?>
<footer class="site-footer thf thf-xinlv"><div class="wrap">
  <div class="footer-main">

    <div class="footer-brand">
      <a class="footer-logo" href="<?= e($homeHref) ?>" title="<?= e($siteName) ?>">
        <img class="footer-logo-image" src="<?= e($logoRel) ?>" width="36" height="36" alt="<?= e($siteName) ?>">
        <span class="footer-logo-text">
          <span class="footer-logo-name"><?= e($siteName) ?></span>
          <?php if ($tagline !== ''): ?><span class="footer-logo-tagline"><?= e($tagline) ?></span><?php endif; ?>
        </span>
      </a>
      <?php if ($desc !== ''): ?><p class="footer-desc"><?= e($desc) ?></p><?php endif; ?>
      <div class="footer-social">
        <?php if ($github !== ''): ?>
        <a class="footer-social-link" href="<?= e($github) ?>" aria-label="GitHub" target="_blank" rel="noopener">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/></svg>
        </a>
        <?php endif; ?>
        <?php if ($email !== ''): ?>
        <a class="footer-social-link" href="mailto:<?= e($email) ?>" aria-label="Email" target="_blank" rel="noopener">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><path d="m22 6-10 7L2 6"/></svg>
        </a>
        <?php endif; ?>
        <a class="footer-social-link" href="<?= e($lp . '/feed') ?>" aria-label="RSS" target="_blank" rel="noopener">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 11a9 9 0 0 1 9 9"></path><path d="M4 4a16 16 0 0 1 16 16"></path><circle cx="5" cy="19" r="1"></circle></svg>
        </a>
      </div>
    </div>

    <div class="footer-middle">
      <div class="footer-card footer-profile-card">
        <div class="footer-profile-stats">
          <?php foreach ($statCells as $sc):
              $key = $sc[0]; $label = $sc[1]; $val = $sc[2]; $href = $sc[3] ?? '';
              $tagA = $href !== '' ? 'a' : 'div';
          ?>
          <<?= $tagA ?> class="footer-stat-cell"<?= $href !== '' ? ' href="' . e($href) . '"' : '' ?>>
            <span class="footer-stat-cell-icon footer-stat-icon-<?= e($key) ?>" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icons[$key] ?? '' ?></svg>
            </span>
            <span class="footer-stat-cell-value"><?= e((string)$val) ?></span>
            <span class="footer-stat-cell-label"><?= e($label) ?></span>
          </<?= $tagA ?>>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="footer-subscribe">
      <h4 class="footer-subscribe-title">订阅本站</h4>
      <p class="footer-subscribe-desc">留下邮箱，新文章发布时第一时间通知你。</p>
      <form class="footer-subscribe-form" id="hsSubscribe" method="post" action="<?= e($homeHref) ?>">
        <input type="email" name="email" required placeholder="输入你的邮箱" aria-label="邮箱地址" autocomplete="email">
        <button type="submit" class="footer-subscribe-btn">订阅</button>
      </form>
      <p class="footer-subscribe-note" id="hsSubscribeNote"></p>
    </div>

  </div>

  <?php if (!empty($legal)): ?>
  <div class="footer-legal">
    <?php $__n = 0; foreach ($legal as $u => $t): if ($__n > 0): ?><span>·</span><?php endif; ?>
    <a class="footer-legal-link" href="<?= e($u) ?>"><?= e((string)$t) ?></a>
    <?php $__n++; endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="footer-bottom">
    <div class="footer-copy">
      <span class="footer-copy-text">&copy; <?= date('Y') ?> <?= e($siteName) ?></span>
      <span>·</span><span>本站为演示站，站内内容均为演示数据</span>
      <?php if ($icp !== ''): ?><span>·</span><a href="<?= e($icpLink) ?>" rel="noopener" target="_blank"><?= e($icp) ?></a><?php endif; ?>
    </div>
    <div class="footer-powered">
      <span class="footer-powered-item"><span>Powered by</span> <a href="<?= e($homeHref) ?>"><?= e($siteName) ?></a></span>
      <span class="footer-powered-divider">·</span>
      <span class="footer-powered-item"><span>Theme</span> <a href="<?= e($homeHref) ?>">新绿</a></span>
    </div>
  </div>
</div></footer>
