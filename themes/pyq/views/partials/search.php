<?php
/** 搜索页（朋友圈 Pyq 风格 · 手写覆盖，结果复用 .pyq-post 动态流卡片，与 list.php 统一）
 *  $q,$posts */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
// 头像：站点 Logo 优先，缺省用主题 logo
$avaRel = trim((string)get_option('site.logo', ''));
if ($avaRel === '' || !is_file(dirname(__DIR__, 3) . $avaRel)) { $avaRel = '/themes/pyq/logo.svg'; }
$avaAbs = dirname(__DIR__, 3) . $avaRel;
$ava = $avaRel . (is_file($avaAbs) ? '?v=' . filemtime($avaAbs) : '');
$nick = site_label();
?>
<div class="pyq-idx">
  <div class="pyq-idx-head">
    <h1 class="pyq-idx-title"><?= e(__('sr.title')) ?></h1>
    <span class="pyq-idx-sub"><?= e(__('sr.sub')) ?></span>
  </div>
  <form class="search-page-form" method="get" action="<?= e($lp . '/search') ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('sr.ph')) ?>" autofocus>
    <button type="submit"><?= e(__('sr.btn')) ?></button>
  </form>
  <?php if ($q !== ''): ?>
  <p class="pyq-search-hint"><?= e(sprintf(__('sr.result'), $q, count($posts))) ?></p>
  <?php if (!empty($posts)): ?>
  <div class="pyq-feed">
  <?php foreach ($posts as $p):
      $u = $base . e((string)$p['slug']) . '.html';
      $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
      $imgs = [];
      if (preg_match_all('/!\[[^\]]*\]\(([^)\s]+)/', (string)($p['content'] ?? ''), $mi)) { $imgs = array_slice($mi[1], 0, 9); }
      $excRaw = trim((string)($p['excerpt'] ?? ''));
      if ($excRaw === '') { $excRaw = Str::excerpt((string)($p['content'] ?? ''), 200); }
      $exc = Str::plain($excRaw);
      $display = ($exc !== '' && $exc !== (string)$p['title']) ? Str::excerpt($exc, 80) : '';
  ?>
  <article class="pyq-post" data-slug="<?= e((string)$p['slug']) ?>">
    <a class="pyq-post-ava" href="<?= e($base) ?>"><img src="<?= e($ava) ?>" alt="<?= e($nick) ?>" loading="lazy"></a>
    <div class="pyq-post-bd">
      <div class="pyq-post-head"><a class="pyq-post-name" href="<?= e($base) ?>"><?= e($nick) ?></a></div>
      <h2 class="pyq-post-txt"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
      <?php if ($display !== ''): ?><p class="pyq-post-x"><?= e($display) ?><span class="pyq-post-more"><a href="<?= e($u) ?>">全文 ›</a></span></p><?php else: ?><span class="pyq-post-more pyq-post-more-alone"><a href="<?= e($u) ?>">全文 ›</a></span><?php endif; ?>
      <?php if ($imgs): ?>
      <div class="pyq-post-imgs<?= count($imgs) === 1 ? ' is-single' : '' ?>">
        <?php foreach ($imgs as $g): ?><span class="pyq-img"><img src="<?= e($g) ?>" alt="" loading="lazy"></span><?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="pyq-post-time">
        <span class="pyq-time"><?= e(date('Y-m-d', $dt)) ?></span>
        <div class="pyq-acts">
          <button type="button" class="pyq-dot2" aria-label="点赞和评论" aria-expanded="false"><i></i><i></i><i></i></button>
          <div class="pyq-act-pop" role="menu">
            <button type="button" class="pyq-like-btn" role="menuitem"><svg class="pyq-ico" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 21s-7.5-4.9-10-9.2C.4 8.6 2 5 5.5 5c2 0 3.4 1.1 4.2 2.3l2.3 3 2.3-3C15.1 6.1 16.5 5 18.5 5 22 5 23.6 8.6 22 11.8 19.5 16.1 12 21 12 21z"/></svg><span class="pyq-like-label">赞</span></button>
            <a class="pyq-cmt" role="menuitem" href="<?= e($u) ?>"><svg class="pyq-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.5 8.5 0 0 1-12.3 7.6L3 21l1.9-5.7A8.5 8.5 0 1 1 21 11.5z"/></svg>评论</a>
          </div>
        </div>
      </div>
    </div>
  </article>
  <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="empty"><?= e(__('sr.none')) ?></div>
  <?php endif; ?>
  <?php endif; ?>
</div>
