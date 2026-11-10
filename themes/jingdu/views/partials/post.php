<?php
/**
 * 静读 · 文章页
 * 入参：$post,$html,$comments,$segments,$para_n,$fav,$my_marks,$comment_total,
 *      $comment_page,$comment_pages,$translated,$fallback
 *
 * 只做一件事：让人从第一行读到最后一行，中途别被打断。
 *  · 目录：服务端从 $post['toc'] 生成（内核已给 h2~h5 注入锚点），宽屏吸附右侧、不占版心
 *  · 正文：宽松行距 + 波浪链接 + 代码窗
 *  · 评论：复用内核 partials/comments（本主题不提供，View 自动回落到默认主题实现）
 */
$p       = $post;
$cid     = (int)$p['id'];
$logged  = Auth::check();
$csrf    = Csrf::token();
$lp2     = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';
$back    = $lp2 . '/' . (string)$p['slug'] . '.html';
$pt      = strtotime((string)($p['published_at'] ?? $p['created_at'] ?? 'now'));

/* ---------- 服务端目录（无小标题时不占位） ---------- */
$tocOn = (string)theme_opt('toc', '1') !== '0';
if ($tocOn && !empty($p['toc']) && is_array($p['toc'])) {
    $items = [];
    foreach ($p['toc'] as $it) {
        $lv = (int)($it['l'] ?? 2);
        if ($lv < 2 || $lv > 4) { continue; }
        if ((string)($it['t'] ?? '') === '') { continue; }
        $items[] = $it;
    }
    if (count($items) >= 2) {
        $htmlToc = '<ol>';
        foreach ($items as $it) {
            $lv = (int)$it['l'];
            $cls = $lv === 3 ? ' class="lv3"' : ($lv === 4 ? ' class="lv4"' : '');
            $htmlToc .= '<li><a' . $cls . ' href="#' . e((string)$it['id']) . '">' . e((string)$it['t']) . '</a></li>';
        }
        $htmlToc .= '</ol>';
        $GLOBALS['jd_toc'] = $htmlToc;
    }
}
?>
<article class="article" data-cid="<?= $cid ?>">

  <nav class="crumbs" aria-label="breadcrumb">
    <a href="<?= e($lp2 === '' ? '/' : $lp2 . '/') ?>"><?= e(__('nav.home') ?? '首页') ?></a>
    <?php if (!empty($p['cats'])): $c0 = $p['cats'][0]; ?>
      <span class="sep">/</span>
      <a href="<?= e(Poly::termUrl('category', $c0)) ?>"><?= e(Poly::termName($c0)) ?></a>
    <?php endif; ?>
    <span class="sep">/</span>
    <span><?= e(mb_substr((string)$p['title'], 0, 28)) ?><?= mb_strlen((string)$p['title']) > 28 ? '…' : '' ?></span>
  </nav>

  <?php if (Poly::on() && Lang::short() !== Poly::defaultLang()): ?>
    <?php if (!empty($fallback)): ?>
      <div class="poly-notice"><?= e(__('poly.fallback')) ?> · <a href="/<?= e((string)$p['slug']) ?>.html"><?= e(__('poly.view_orig')) ?></a></div>
    <?php elseif (!empty($translated)): ?>
      <div class="poly-notice ok">🌐 <?= e(__('poly.has_trans')) ?> · <a href="/<?= e((string)$p['slug']) ?>.html"><?= e(__('poly.view_orig')) ?></a></div>
    <?php endif; ?>
    <?php if (!empty($p['stale'])): ?><div class="poly-notice stale"><?= e(__('poly.stale')) ?></div><?php endif; ?>
  <?php endif; ?>

  <h1 class="article-title"><?= e((string)$p['title']) ?></h1>

  <div class="article-meta">
    <span>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M8 3v4"></path><path d="M16 3v4"></path><path d="M3 10h18"></path></svg>
      <time datetime="<?= e(date('Y-m-d', $pt)) ?>"><?= e(date('Y-m-d', $pt)) ?></time>
    </span>
    <span>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"></path><circle cx="12" cy="12" r="2.6"></circle></svg>
      <i class="yc-views" data-cid="<?= $cid ?>" style="font-style:normal"><?= Str::num((int)$p['views']) ?></i>
    </span>
    <?php if ((int)$comment_total > 0): ?>
    <span>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-8 8H7l-4 3V12a8 8 0 0 1 8-8h2a8 8 0 0 1 8 8Z"></path></svg>
      <a href="#comments" style="color:inherit"><?= (int)$comment_total ?></a>
    </span>
    <?php endif; ?>
    <?php if (!empty($p['words'])): ?>
    <span class="num"><?= (int)$p['words'] ?> 字</span>
    <?php endif; ?>
  </div>

  <div class="prose"><?= $html ?></div>

  <?php if (!empty($p['tags'])): ?>
  <div class="article-tags">
    <span class="tags-label"><?= e(__('ui.tags_label')) ?></span>
    <?php foreach ($p['tags'] as $t): ?>
      <span class="tag"><a href="<?= e(Poly::termUrl('tag', $t)) ?>" rel="tag"><?= e(Poly::termName($t)) ?></a></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($p['prev']) || !empty($p['next'])): ?>
  <nav class="post-nav" aria-label="文章导航">
    <?php if (!empty($p['prev'])): ?>
    <a class="prev" href="<?= e($lp2 . '/' . rawurlencode((string)$p['prev']['slug']) . '.html') ?>">
      <span class="dir"><?= e(__('ui.prev_post') ?? '上一篇') ?></span>
      <span class="ttl"><?= e((string)$p['prev']['title']) ?></span>
    </a>
    <?php else: ?><span></span><?php endif; ?>
    <?php if (!empty($p['next'])): ?>
    <a class="next" href="<?= e($lp2 . '/' . rawurlencode((string)$p['next']['slug']) . '.html') ?>">
      <span class="dir"><?= e(__('ui.next_post') ?? '下一篇') ?></span>
      <span class="ttl"><?= e((string)$p['next']['title']) ?></span>
    </a>
    <?php endif; ?>
  </nav>
  <?php endif; ?>

  <?= View::render('partials/comments', [
      'cid' => $cid, 'logged' => $logged, 'back' => $back,
      'comments' => $comments, 'segments' => $segments, 'para_n' => $para_n, 'csrf' => $csrf,
      'comment_page' => $comment_page ?? 1, 'comment_pages' => $comment_pages ?? 1,
      'comment_total' => (int)$comment_total,
  ]) ?>

  <script>
  (function () {
    try {
      var key = 'yc_view_' + <?= $cid ?> + '_' + new Date().toISOString().slice(0, 10);
      if (localStorage.getItem(key)) { return; }
      localStorage.setItem(key, '1');
      var fd = new FormData();
      fd.append('content_id', String(<?= $cid ?>));
      fetch('/count', { method: 'POST', body: fd, credentials: 'same-origin' });
    } catch (e) {}
  })();
  </script>
</article>
