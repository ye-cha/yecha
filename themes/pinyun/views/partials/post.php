<?php
/** 文章页区块：$post,$html,$comments,$segments,$para_n,$fav,$my_marks */
$p = $post;
$cid = (int)$p['id'];
$logged = Auth::check();
$csrf = Csrf::token();
$lp2 = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';
$back = $lp2 . '/' . e((string)$p['slug']) . '.html';
?>
<div class="py-post-wrap">
<article class="article" data-cid="<?= $cid ?>">
  <?php if (!empty($p['cats'])): ?>
  <div class="kicker">
    <?php foreach ($p['cats'] as $c): ?><a style="color:var(--accent)" href="<?= e(Poly::termUrl('category', $c)) ?>"><?= e(Poly::termName($c)) ?></a><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <h1 class="article-title"><?= e($p['title']) ?></h1>
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
    <?php foreach (Poly::enabled() as $lc2): if ($lc2 === Lang::short()) { continue; } ?>
    <a href="<?= e(($lc2 === Poly::defaultLang() ? '' : '/' . $lc2) . '/' . (string)$p['slug']) ?>.html"><?= e(Poly::label($lc2)) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="article-meta">
    <?php $pt = strtotime((string)($p['published_at'] ?? $p['created_at'])); ?>
    <span><?= e(date('Y-m-d', $pt)) ?><?= date('H:i', $pt) === '09:00' ? '' : ' ' . date('H:i', $pt) ?></span>
    <span><?= e(__('meta.reads')) ?><?= Str::num((int)$p['views']) ?></span>
    <span><?= e(__('meta.comments')) ?><?= (int)$comment_total ?></span>
  </div>
  <?php
  // 封面：取内容首图作为文章大图，并从正文移除首图避免重复
  $cover = '';
  if (preg_match('/!\[[^\]]*\]\(([^)\s]+)\)/', (string)($p['content'] ?? ''), $cm)) { $cover = $cm[1]; }
  if ($cover !== '') { $html = (string)preg_replace('/<p>\s*<img[^>]*>\s*<\/p>/i', '', $html, 1); }
  ?>
  <?php if ($cover !== ''): ?><div class="article-cover"><img src="<?= e($cover) ?>" alt="<?= e((string)$p['title']) ?>"></div><?php endif; ?>
  <?php
  // 摘要去重 v2：若摘要只是正文开头部分的原样摘抄，则不再在正文上方重复显示 lead。
  $showLead = false;
  if (($p['excerpt'] ?? '') !== '') {
      $h = (string)($html ?? '');
      $h = (string)preg_replace('#<(?:strong|em|b|i|u|s|code|a|span)[^>]*>#i', '', $h);
      $h = (string)preg_replace('#</(?:strong|em|b|i|u|s|code|a|span)>#i', '', $h);
      $plainLead = trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/<[^>]+>/', ' ', $h)));
      $excerptN = trim((string)preg_replace('/\s+/u', ' ', (string)$p['excerpt']));
      if ($excerptN === '' || $plainLead === '') {
          $showLead = false;
      } else {
          $probe = mb_substr($excerptN, 0, 80);
          $headT = mb_substr($plainLead, 0, mb_strlen($probe) + 20);
          similar_text($probe, $headT, $pct);
          $showLead = $pct < 82;
      }
  }
  if ($showLead): ?><p style="color:var(--muted)"><?= e($p['excerpt']) ?></p><?php endif; ?>

  <?php
  // 目录：解析 h2/h3 注入锚点 id，并收集 TOC 项（右侧 sticky 目录）
  $tocItems = [];
  $htmlX = preg_replace_callback('/<h([23])([^>]*)>(.*?)<\/h\1>/is', function ($m) use (&$tocItems) {
      static $nn = 0;
      $lv = (int)$m[1];
      $inner = $m[3];
      $txt = trim(strip_tags($inner));
      if ($txt === '') { return $m[0]; }
      $id = 'sec-' . ($nn++);
      $tocItems[] = ['l' => $lv, 'id' => $id, 't' => $txt];
      return '<h' . $lv . ' id="' . $id . '">' . $inner . '</h' . $lv . '>';
  }, $html);
  ?>
  <div class="article-content" id="articleBody"><?= $htmlX ?></div>

  <?php if (!empty($p['tags'])): ?>
  <div class="article-tags">
    <span class="tags-label"><?= e(__('ui.tags_label')) ?></span>
    <?php foreach ($p['tags'] as $t): ?>
      <span class="tag"><a href="<?= e(Poly::termUrl('tag', $t)) ?>"><?= e(Poly::termName($t)) ?></a></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

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
</article>
<?php if (!empty($tocItems) && count($tocItems) >= 2): ?>
<aside class="py-toc" aria-label="<?= e(__('post.toc')) ?>">
  <?= View::render('partials/toc-side', ['items' => $tocItems]) ?>
</aside>
<?php endif; ?>
</div><!-- /py-post-wrap -->

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
  // 目录 scrollspy：高亮当前阅读章节
  (function () {
    var nav = document.querySelector('.py-toc .article-toc');
    if (!nav) return;
    var links = Array.prototype.slice.call(nav.querySelectorAll('a[href^="#"]'));
    if (!links.length) return;
    var map = {};
    links.forEach(function (a) { map[a.getAttribute('href').slice(1)] = a; });
    var targets = links.map(function (a) { return document.getElementById(a.getAttribute('href').slice(1)); }).filter(Boolean);
    var cur = null;
    function setActive(id) {
      if (cur === id) return;
      if (cur && map[cur]) { map[cur].classList.remove('on'); }
      cur = id;
      if (cur && map[cur]) { map[cur].classList.add('on'); }
    }
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (es) {
        es.forEach(function (e) {
          if (e.isIntersecting) { setActive(e.target.id); }
        });
      }, { rootMargin: '-84px 0px -68% 0px', threshold: 0 });
      targets.forEach(function (t) { io.observe(t); });
    }
  })();
</script>
<script src="<?= e(View::asset('reader.js')) ?>"></script>
