<?php
/** 文章页（素简 Sujian v2 · doku 形态：hero 标题 + columns 双列正文+右侧目录）
 *  $post,$html,$comments,$segments,$para_n,$fav,$my_marks,$prev,$next,$comment_total,$comment_page,$comment_pages */
$p = $post;
$cid = (int)$p['id'];
$logged = Auth::check();
$csrf = Csrf::token();
$lp2 = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';
$back = $lp2 . '/' . e((string)$p['slug']) . '.html';
/* 本页 Markdown 目录：直接解析正文 h2~h4，补锚点 id 并生成目录（≥1 个标题即出右栏）。
   注意：不能只依赖 $GLOBALS['YC_TOC']（核心仅在标题 ≥3 个时才注入），否则多数文章目录为空。 */
$tocItems = [];
$htmlX = preg_replace_callback('/<h([234])([^>]*)>(.*?)<\/h\1>/is', function ($m) use (&$tocItems): string {
    $lvl = (int)$m[1]; $attrs = (string)$m[2]; $inner = (string)$m[3];
    $text = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    if ($text === '') { return $m[0]; }
    if (preg_match('/\bid=["\']([^"\']+)["\']/', $attrs, $mm)) { $id = $mm[1]; }
    else { $id = 'sec-' . count($tocItems); $attrs .= ' id="' . $id . '"'; }
    $tocItems[] = ['l' => $lvl, 'id' => $id, 't' => $text];
    return '<h' . $lvl . $attrs . '>' . $inner . '</h' . $lvl . '>';
}, (string)$html);
$showToc = theme_opt('show_toc', '1') !== '0' && $tocItems !== [];
?>
<article class="suj-document-container clearfix" data-cid="<?= $cid ?>">

  <header class="suj-hero" id="suj-document-hero">
    <div class="suj-hero-body">
      <?php if (!empty($p['cats'])): ?>
      <div class="suj-kicker">
        <?php foreach ($p['cats'] as $c): ?><a href="<?= e(Poly::termUrl('category', $c)) ?>"><?= e(Poly::termName($c)) ?></a><?php endforeach; ?>
      </div>
      <?php endif; ?>
      <h1 class="suj-hero-title"><?= e($p['title']) ?></h1>
      <?php if (!empty($p['excerpt'])): ?>
      <p class="suj-hero-sub"><?= e(Str::excerpt(Str::plain($p['excerpt']), 160)) ?></p>
      <?php endif; ?>
      <?php if (Poly::on() && Lang::short() !== Poly::defaultLang()): ?>
      <?php if (!empty($fallback)): ?>
        <div class="suj-poly-notice"><?= e(__('poly.fallback')) ?></div>
      <?php elseif (!empty($translated)): ?>
        <div class="suj-poly-notice ok">🌐 <?= e(__('poly.has_trans')) ?> · <a href="/<?= e((string)$p['slug']) ?>.html"><?= e(__('poly.view_orig')) ?></a></div>
      <?php endif; ?>
      <?php if (!empty($p['stale'])): ?>
        <div class="suj-poly-notice stale"><?= e(__('poly.stale')) ?></div>
      <?php endif; ?>
      <?php endif; ?>
      <?php $pt = strtotime((string)($p['published_at'] ?? $p['created_at'])); ?>
      <div class="suj-hero-meta">
        <span class="suj-date"><?= e(date('Y-m-d', $pt)) ?><?= date('H:i', $pt) === '09:00' ? '' : ' ' . date('H:i', $pt) ?></span>
        <span class="suj-devide-dot"></span>
        <span><?= e(__('meta.reads')) ?><?= Str::num((int)$p['views']) ?></span>
        <span class="suj-devide-dot"></span>
        <span><?= e(__('meta.comments')) ?><?= (int)$comment_total ?></span>
      </div>
    </div>
  </header>

  <div class="suj-columns">
    <div class="suj-doc-wrapper<?= $showToc ? ' has-toc' : '' ?>">
      <div class="suj-content" id="articleBody">
        <?= $htmlX ?>
      </div>

      <?php if (!empty($p['tags'])): ?>
      <div class="suj-tags">
        <span class="suj-tags-label"><?= e(__('ui.tags_label')) ?></span>
        <?php foreach ($p['tags'] as $t): ?>
          <a class="suj-tag-chip" href="<?= e(Poly::termUrl('tag', $t)) ?>"><?= e(Poly::termName($t)) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <ul class="suj-doku-pagination">
        <?php if (!empty($prev)): ?>
        <li class="suj-page-item suj-page-prev"><a href="<?= e($lp2 . '/' . (string)$prev['slug']) ?>.html">
          <div class="suj-page-item-subtitle"><span class="ico">←</span> <?= e(__('post.prev')) ?></div>
          <div class="suj-page-item-title"><?= e($prev['title']) ?></div>
        </a></li>
        <?php endif; ?>
        <?php if (!empty($next)): ?>
        <li class="suj-page-item suj-page-next"><a href="<?= e($lp2 . '/' . (string)$next['slug']) ?>.html">
          <div class="suj-page-item-subtitle"><?= e(__('post.next')) ?> <span class="ico">→</span></div>
          <div class="suj-page-item-title"><?= e($next['title']) ?></div>
        </a></li>
        <?php endif; ?>
      </ul>

      <div class="suj-reader-bar">
        <?php if ($logged): ?>
          <form class="suj-inline" method="post" action="/user/favorite">
            <input type="hidden" name="content_id" value="<?= $cid ?>">
            <input type="hidden" name="redirect" value="<?= $back ?>">
            <?= Csrf::field() ?>
            <button class="suj-btn-sm fav-btn <?= $fav ? 'on' : '' ?>" type="submit"><?= $fav ? e(__('post.fav_on')) : e(__('post.fav_off')) ?></button>
          </form>
          <span class="suj-hint"><?php
              $hintMd = sprintf(__('post.mark_hint'), (int)$my_marks);
              $myLp = ui_lp();
              if ($myLp !== '') { $hintMd = str_replace('href="/user/', 'href="' . $myLp . '/user/', $hintMd); }
              echo $hintMd;
          ?></span>
          <span class="suj-hint"><?= e(__('post.seg_hint')) ?></span>
        <?php else: ?>
          <a class="suj-btn-sm" href="<?= e(ui_login($back)) ?>"><?= e(__('post.login_cta')) ?></a>
        <?php endif; ?>
      </div>

      <?= View::render('partials/comments', [
          'cid' => $cid, 'logged' => $logged, 'back' => $back,
          'comments' => $comments, 'segments' => $segments, 'para_n' => $para_n, 'csrf' => $csrf,
          'comment_page' => $comment_page ?? 1, 'comment_pages' => $comment_pages ?? 1,
          'comment_total' => (int)$comment_total,
      ]) ?>
    </div>

    <?php if ($showToc): ?>
    <aside class="suj-doc-toc" id="sujDocToc" aria-label="目录">
      <div class="suj-doc-toc-in">
        <p class="suj-doc-toc-title"><?= e(__('post.toc')) ?></p>
        <?php if ($tocItems !== []): ?>
        <ul>
          <?php foreach ($tocItems as $it):
              $lv = (int)($it['l'] ?? 2); ?>
          <li class="l<?= $lv ?>"><a href="#<?= e((string)($it['id'] ?? '')) ?>"><?= e((string)($it['t'] ?? '')) ?></a></li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="suj-doc-toc-empty"><?= e(__('post.toc_empty')) ?></p>
        <?php endif; ?>
      </div>
    </aside>
    <?php endif; ?>
  </div>
</article>

<?php if ($logged): ?>
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
<?php else: ?>
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
<?php endif; ?>