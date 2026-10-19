<?php
/** 朋友圈动态流（朋友圈 Pyq）：标签页 / 分类页 / 搜索 / 首页列表共用
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$empty,$empty_link,$link_prefix */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
$GLOBALS['yc_pyq_cover'] = true;

// 头像：站点 Logo 优先，缺省用主题 logo
$avaRel = trim((string)get_option('site.logo', ''));
if ($avaRel === '' || !is_file(dirname(__DIR__, 3) . $avaRel)) { $avaRel = '/themes/pyq/logo.svg'; }
$avaAbs = dirname(__DIR__, 3) . $avaRel;
$ava = $avaRel . (is_file($avaAbs) ? '?v=' . filemtime($avaAbs) : '');
$nick = site_label();
$sig  = trim((string)get_option('site.subtitle', ''));

// 朋友圈灰泡：每条动态的评论（真实数据），仿目标站展示在日期/操作下方
$pyqCmtMap = []; $pyqCmtTotal = [];
if (!empty($posts)) {
    $pyqIds = array_filter(array_map(fn($p) => (int)($p['id'] ?? 0), $posts), fn($v) => $v > 0);
    if ($pyqIds) {
        $pyqPh = implode(',', array_fill(0, count($pyqIds), '?'));
        $pyqRows = Db::rows("SELECT id, content_id, author_name, body, created_at FROM " . Db::t('comments') . " WHERE content_id IN ($pyqPh) AND status='approved' AND kind='normal' ORDER BY id ASC", $pyqIds);
        foreach ($pyqRows as $pyqR) { $pyqCmtMap[(int)$pyqR['content_id']][] = $pyqR; }
        $pyqTot = Db::rows("SELECT content_id, COUNT(*) n FROM " . Db::t('comments') . " WHERE content_id IN ($pyqPh) AND status='approved' AND kind='normal' GROUP BY content_id", $pyqIds);
        foreach ($pyqTot as $pyqT) { $pyqCmtTotal[(int)$pyqT['content_id']] = (int)$pyqT['n']; }
    }
}
?>
<div class="pyq-cover" aria-hidden="true"></div>
<div class="pyq-me">
  <div class="pyq-me-row">
    <h4 class="pyq-me-name"><?= e($nick) ?></h4>
    <a class="pyq-me-ava" href="<?= e($base) ?>"><img src="<?= e($ava) ?>" alt="<?= e($nick) ?>"></a>
  </div>
  <?php if ($sig !== ''): ?><p class="pyq-me-sign"><?= e($sig) ?></p><?php endif; ?>
</div>

<?php if (!empty($posts)): ?>
<div class="pyq-feed">
<?php foreach ($posts as $p):
    $u = $base . e((string)$p['slug']) . '.html';
    $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
    // 取正文图片（最多 9 张，九宫格）
    $imgs = [];
    if (preg_match_all('/!\[[^\]]*\]\(([^)\s]+)/', (string)($p['content'] ?? ''), $mi)) { $imgs = array_slice($mi[1], 0, 9); }
    $excRaw = trim((string)($p['excerpt'] ?? ''));
    if ($excRaw === '' && empty($imgs)) { $excRaw = Str::excerpt((string)($p['content'] ?? ''), 200); }
    $exc = Str::plain($excRaw);
    $display = ($exc !== '' && $exc !== (string)$p['title']) ? Str::excerpt($exc, 80) : '';
    $views = (int)($p['views'] ?? 0);
    $cmts = array_slice($pyqCmtMap[(int)($p['id'] ?? 0)] ?? [], 0, 5);
    $cmtTotal = $pyqCmtTotal[(int)($p['id'] ?? 0)] ?? 0;
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
      <div class="pyq-bubble">
        <?php if (!empty($cmts)): ?>
        <ul class="pyq-cmt-list">
          <?php foreach ($cmts as $cm): ?>
          <li><b class="pyq-cmt-name"><?= e((string)($cm['author_name'] ?? '')) ?></b><span class="pyq-cmt-sep">：</span><span class="pyq-cmt-body"><?= e((string)($cm['body'] ?? '')) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <?php if ($cmtTotal > count($cmts)): ?><a class="pyq-cmt-more" href="<?= e($u) ?>">查看全部 <?= Str::num($cmtTotal) ?> 条评论 ›</a><?php endif; ?>
        <?php endif; ?>
        <div class="pyq-like-self" hidden><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 21s-7.5-4.9-10-9.2C.4 8.6 2 5 5.5 5c2 0 3.4 1.1 4.2 2.3l2.3 3 2.3-3C15.1 6.1 16.5 5 18.5 5 22 5 23.6 8.6 22 11.8 19.5 16.1 12 21 12 21z"/></svg>你赞过</div>
      </div>
    </div>
  </article>
<?php endforeach; ?>
</div>
<?php if ($total_pages > 1): ?>
<div class="pager">
  <?php if ($page > 1): ?><a href="<?= e(str_replace('{n}', (string)($page - 1), $pager_base)) ?>"><?= e(__('ui.prev')) ?></a><?php endif; ?>
  <span><?= $page ?> / <?= $total_pages ?></span>
  <?php if ($page < $total_pages): ?><a href="<?= e(str_replace('{n}', (string)($page + 1), $pager_base)) ?>"><?= e(__('ui.next')) ?></a><?php endif; ?>
</div>
<?php endif;
else:
$emptyTxt = isset($empty) && $empty !== '' ? $empty : __('list.empty'); ?>
<div class="empty"><?= e($emptyTxt) ?><?php if (!empty($empty_link)): ?><br><a href="<?= e($empty_link) ?>"><?= e(__('list.empty_alt')) ?></a><?php endif; ?></div>
<?php endif; ?>
