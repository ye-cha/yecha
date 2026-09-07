<?php
/** 文章页区块（对齐参考站 doc-post 头部）：$post,$html,$comments,$segments,$para_n,$fav,$my_marks */
$p = $post;
$cid = (int)$p['id'];
$logged = Auth::check();
$csrf = Csrf::token();
$lp2 = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';
$back = $lp2 . '/' . e((string)$p['slug']) . '.html';
/* 左栏（全部文章导航）由 layout 统一生成 */
?>
<article class="article" data-cid="<?= $cid ?>">
  <header class="article-header">
    <h1 class="article-title"><?= e($p['title']) ?></h1>
    <?php
    $pt = strtotime((string)($p['published_at'] ?? $p['created_at']));
    /* 阅读时长：正文去 Markdown 后按 450 字/分钟估算 */
    $plainTxt = trim(preg_replace('/\s+/u', '', Str::cleanMd((string)($p['content'] ?? ''))) ?? '');
    $readMin = $plainTxt !== '' ? max(1, (int)ceil(mb_strlen($plainTxt) / 450)) : 1;
    $cat0 = $p['cats'][0] ?? null;
    ?>
    <div class="article-meta">
      <span class="meta-item">
        <svg class="meta-icon" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z"/></svg>
        <time class="meta-date"><?= e(lang_date($pt)) ?></time>
      </span>
      <?php if ($cat0): ?>
      <span class="meta-item">
        <svg class="meta-icon" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M10 4H4c-1.11 0-2 .89-2 2v12c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2h-8l-2-2z"/></svg>
        <a class="meta-category" href="<?= e(Poly::termUrl('category', $cat0)) ?>"><?= e(Poly::termName($cat0)) ?></a>
      </span>
      <?php endif; ?>
      <span class="meta-item">
        <svg class="meta-icon" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
        <span class="meta-reading"><?= e(__('ui.read_min')) ?><?= (int)$readMin ?><?= e(__('ui.read_min_unit')) ?></span>
      </span>
      <?php if (!empty($p['tags'])): ?>
      <span class="meta-item meta-tags"><?php foreach ($p['tags'] as $t): ?><a href="<?= e(Poly::termUrl('tag', $t)) ?>">#<?= e(Poly::termName($t)) ?></a><?php endforeach; ?></span>
      <?php endif; ?>
    </div>
  </header>
  <?php if (Poly::on() && Lang::short() !== Poly::defaultLang()): ?>
  <?php if (!empty($fallback)): ?>
    <div class="poly-notice"><?= e(__('poly.fallback')) ?></div>
  <?php elseif (!empty($translated)): ?>
    <div class="poly-notice ok">🌐 <?= e(__('poly.has_trans')) ?> · <a href="/<?= e((string)$p['slug']) ?>.html"><?= e(__('poly.view_orig')) ?></a></div>
  <?php endif; ?>
  <?php if (!empty($p['stale'])): ?>
    <div class="poly-notice stale"><?= e(__('poly.stale')) ?></div>
  <?php endif; ?>
  <div class="poly-langs">
    <?= e(__('post.lang_label')) ?>
    <?php foreach (Poly::enabled() as $lc2): if ($lc2 === Poly::defaultLang()) { continue; } ?>
    <a href="<?= e(($lc2 === Poly::defaultLang() ? '' : '/' . $lc2) . '/' . (string)$p['slug']) ?>.html"><?= e(Poly::label($lc2)) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="article-content" id="articleBody"><?= $html ?></div>

  <!-- 上一篇 / 下一篇 -->
  <nav class="adjacent">
    <?php if (!empty($prev)): ?><a href="<?= e($lp2 . '/' . (string)$prev['slug']) ?>.html"><b><?= e(__('post.prev')) ?></b><?= e($prev['title']) ?></a>
    <?php else: ?><span></span><?php endif; ?>
    <?php if (!empty($next)): ?><a href="<?= e($lp2 . '/' . (string)$next['slug']) ?>.html" class="nx"><b><?= e(__('post.next')) ?></b><?= e($next['title']) ?></a>
    <?php else: ?><span></span><?php endif; ?>
  </nav>

  <div class="reader-bar">
    <?php if ($logged): ?>
      <form class="inline" method="post" action="/user/favorite">
        <input type="hidden" name="content_id" value="<?= $cid ?>">
        <input type="hidden" name="redirect" value="<?= $back ?>">
        <?= Csrf::field() ?>
        <button class="btn btn-sm fav-btn <?= $fav ? 'on' : '' ?>" type="submit"><?= $fav ? e(__('post.fav_on')) : e(__('post.fav_off')) ?></button>
      </form>
      <span class="hint"><?php
          $hintMd = sprintf(__('post.mark_hint'), (int)$my_marks);
          $myLp = ui_lp();
          if ($myLp !== '') { $hintMd = str_replace('href="/user/', 'href="' . $myLp . '/user/', $hintMd); }
          echo $hintMd;
      ?></span>
      <span class="hint"><?= e(__('post.seg_hint')) ?></span>
    <?php else: ?>
      <a class="btn btn-sm" href="<?= e(ui_login($back)) ?>"><?= e(__('post.login_cta')) ?></a>
    <?php endif; ?>
  </div>

  <div class="author-card">
    <?php
    $aNick = (string)get_option('blogger.nick', site_name());
    $aBio = (string)get_option('blogger.bio', '');
    if (Poly::on()) {
        $aLang = Lang::short();
        if ($aLang !== Poly::defaultLang()) {
            $aNickL = (string)get_option('blogger.nick_' . $aLang, '');
            $aBioL = (string)get_option('blogger.bio_' . $aLang, '');
            if ($aNickL !== '') { $aNick = $aNickL; }
            if ($aBioL !== '') { $aBio = $aBioL; }
        }
    }
    ?>
    <span class="author-avatar"><?= e(mb_substr($aNick, 0, 1)) ?></span>
    <div class="author-info">
      <div class="author-name"><?= e($aNick) ?></div>
      <p class="author-bio"><?= e($aBio) ?></p>
    </div>
    <a class="author-follow" href="<?= e($back) ?>#doc-comments"><?= e(__('post.follow')) ?></a>
  </div>

  <?= View::render('partials/comments', [
      'cid' => $cid, 'logged' => $logged, 'back' => $back,
      'comments' => $comments, 'segments' => $segments, 'para_n' => $para_n, 'csrf' => $csrf,
      'comment_page' => $comment_page ?? 1, 'comment_pages' => $comment_pages ?? 1,
      'comment_total' => (int)$comment_total,
  ]) ?>
</article>
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
  // 阅读计数：访客+文章+自然日 去重上报（不阻塞渲染；服务端 append ye_views）
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
