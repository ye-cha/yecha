<?php
/** 文章页（黄羽 Huangyu · 居中单栏，圆润卡片）
 *  保留验收必需类：.article-title / .article-content / .article-tags .tag / .adjacent a
 *  $post,$html,$comments,$segments,$para_n,$fav,$my_marks */
$p = $post;
$cid = (int)$p['id'];
$logged = Auth::check();
$csrf = Csrf::token();
$lp2 = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';
$back = $lp2 . '/' . e((string)$p['slug']) . '.html';
?>
<div class="hyu-post-wrap">
<article class="hyu-article" data-cid="<?= $cid ?>">
  <?php if (!empty($p['cats'])): ?>
  <div class="hyu-kicker">
    <?php foreach ($p['cats'] as $c): ?><a href="<?= e(Poly::termUrl('category', $c)) ?>"><?= e(Poly::termName($c)) ?></a><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <h1 class="article-title hyu-title"><?= e($p['title']) ?></h1>

  <?php if (Poly::on() && Lang::short() !== Poly::defaultLang()): ?>
  <?php if (!empty($fallback)): ?>
    <div class="hyu-poly poly-notice"><?= e(__('poly.fallback')) ?></div>
  <?php elseif (!empty($translated)): ?>
    <div class="hyu-poly ok">🌐 <?= e(__('poly.has_trans')) ?> · <a href="/<?= e((string)$p['slug']) ?>.html"><?= e(__('poly.view_orig')) ?></a></div>
  <?php endif; ?>
  <?php if (!empty($p['stale'])): ?>
    <div class="hyu-poly stale"><?= e(__('poly.stale')) ?></div>
  <?php endif; ?>
  <?php endif; ?>

  <div class="hyu-meta">
    <?php $pt = strtotime((string)($p['published_at'] ?? $p['created_at'])); ?>
    <time><?= e(date('Y-m-d', $pt)) ?><?= date('H:i', $pt) === '09:00' ? '' : ' ' . date('H:i', $pt) ?></time>
    <span class="hyu-dot">·</span><span><?= e(__('meta.reads')) ?><?= Str::num((int)$p['views']) ?></span>
    <span class="hyu-dot">·</span><span><?= e(__('meta.comments')) ?><?= (int)$comment_total ?></span>
  </div>

  <?php
  // 封面：取内容首图作为文章大图，并从正文移除首图避免重复
  $cover = '';
  if (preg_match('/!\[[^\]]*\]\(([^)\s]+)\)/', (string)($p['content'] ?? ''), $cm)) { $cover = $cm[1]; }
  if ($cover !== '') { $html = (string)preg_replace('/<p>\s*<img[^>]*>\s*<\/p>/i', '', $html, 1); }
  ?>
  <?php if ($cover !== ''): ?><div class="hyu-cover"><img src="<?= e($cover) ?>" alt="<?= e((string)$p['title']) ?>"></div><?php endif; ?>

  <?php
  // 目录：解析 h2/h3 注入锚点 id
  $htmlX = preg_replace_callback('/<h([23])([^>]*)>(.*?)<\/h\1>/is', function ($m) {
      static $nn = 0;
      $lv = (int)$m[1];
      $inner = $m[3];
      $txt = trim(strip_tags($inner));
      if ($txt === '') { return $m[0]; }
      $id = 'sec-' . ($nn++);
      return '<h' . $lv . ' id="' . $id . '">' . $inner . '</h' . $lv . '>';
  }, $html);
  ?>
  <div class="article-content hyu-content" id="articleBody"><?= $htmlX ?></div>

  <?php if (!empty($p['tags'])): ?>
  <div class="article-tags hyu-tags">
    <span class="hyu-tags-label"><?= e(__('ui.tags_label')) ?></span>
    <?php foreach ($p['tags'] as $t): ?>
      <span class="tag"><a href="<?= e(Poly::termUrl('tag', $t)) ?>"><?= e(Poly::termName($t)) ?></a></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <nav class="adjacent hyu-adj">
    <?php if (!empty($prev)): ?><a class="hyu-adj-p" href="<?= e($lp2 . '/' . (string)$prev['slug']) ?>.html"><b><?= e(__('post.prev')) ?></b><span><?= e($prev['title']) ?></span></a>
    <?php else: ?><span class="hyu-adj-p hyu-adj-empty"></span><?php endif; ?>
    <?php if (!empty($next)): ?><a class="hyu-adj-n" href="<?= e($lp2 . '/' . (string)$next['slug']) ?>.html"><b><?= e(__('post.next')) ?></b><span><?= e($next['title']) ?></span></a>
    <?php else: ?><span class="hyu-adj-n hyu-adj-empty"></span><?php endif; ?>
  </nav>

  <div class="hyu-bar">
    <?php if ($logged): ?>
      <form class="inline" method="post" action="/user/favorite">
        <input type="hidden" name="content_id" value="<?= $cid ?>">
        <input type="hidden" name="redirect" value="<?= $back ?>">
        <?= Csrf::field() ?>
        <button class="hyu-btn <?= $fav ? 'on' : '' ?>" type="submit"><?= $fav ? e(__('post.fav_on')) : e(__('post.fav_off')) ?></button>
      </form>
    <?php else: ?>
      <a class="hyu-btn" href="<?= e(ui_login($back)) ?>"><?= e(__('post.login_cta')) ?></a>
    <?php endif; ?>
  </div>
</article>
</div><!-- /hyu-post-wrap -->

<?= View::render('partials/comments', [
    'cid' => $cid, 'logged' => $logged, 'back' => $back,
    'comments' => $comments, 'segments' => $segments, 'para_n' => $para_n, 'csrf' => $csrf,
    'comment_page' => $comment_page ?? 1, 'comment_pages' => $comment_pages ?? 1,
    'comment_total' => (int)$comment_total,
]) ?>
<script>
  window.__READER = {
    cid: <?= $cid ?>,
    logged: <?= $logged ? 'true' : 'false' ?>,
    csrf: <?= json_encode($csrf) ?>,
    t: {
      mt: <?= json_encode(__('rd.mark_title'), JSON_UNESCAPED_UNICODE) ?>,
      tip: <?= json_encode(__('rd.mark_tip'), JSON_UNESCAPED_UNICODE) ?>,
      add: <?= json_encode(__('rd.add_note'), JSON_UNESCAPED_UNICODE) ?>,
      noteTip: <?= json_encode(__('rd.note_tip'), JSON_UNESCAPED_UNICODE) ?>,
      prompt: <?= json_encode(__('rd.prompt'), JSON_UNESCAPED_UNICODE) ?>,
      saved: <?= json_encode(__('rd.saved'), JSON_UNESCAPED_UNICODE) ?>
    }
  };
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
<script src="<?= e(View::asset('reader.js')) ?>"></script>
