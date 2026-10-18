<?php
/**
 * Tales · 文章页区块
 * 变量（与 forest 一致）：$post,$html,$comments,$segments,$para_n,$fav,$my_marks,$comment_total,$comment_page,$comment_pages,$prev,$next,$fallback,$translated
 * 布局：正文在 .lay 里；左栏由本主题的 partials/sidebar.php 提供（layout 中 body.tales-left 把侧栏排到左侧）
 */
require_once __DIR__ . '/../../lib.php';
$p = $post;
$cid = (int)$p['id'];
$logged = Auth::check();
$csrf = Csrf::token();
$lp2 = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';
$back = $lp2 . '/' . e((string)$p['slug']) . '.html';
$L  = (Lang::short() === 'en');
$T_ = function (string $zh, string $en) use ($L): string { return $L ? $en : $zh; };

/* 本篇色（与图录一致：按 id 稳定取六色之一） */
$PAL = ['red', 'amber', 'green', 'blue', 'purple', 'ochre'];
$catRow = function_exists('tales_cat_of') ? tales_cat_of($cid) : null;
$myC = $catRow['color'] ?? 'var(--acc)';

/* 让 layout 把侧栏排到左侧（左栏 = 故事目录） */
$GLOBALS['YC_TALES_LEFT'] = true;
$GLOBALS['YC_TALES_CID']  = $cid;

/* 作者名/简介（按语言，缺失回落基准） */
$pgLang = Lang::short();
$authNick = trim((string)get_option('blogger.nick_' . $pgLang, ''));
if ($authNick === '') { $authNick = (string)get_option('blogger.nick', site_label()); }
$authBio  = trim((string)get_option('blogger.bio_' . $pgLang, ''));
if ($authBio === '') { $authBio = (string)get_option('blogger.bio', ''); }

/* 结构化数据 */
$ptT = strtotime((string)($p['published_at'] ?? $p['created_at']));
$ptISO = $ptT ? date('c', $ptT) : date('c');
$host2 = $_SERVER['HTTP_HOST'] ?? 'cntales.com';
$scheme2 = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$artUrl = $GLOBALS['YC_CANONICAL'] ?? ($scheme2 . '://' . $host2 . '/' . (string)$p['slug'] . '.html');
$artDesc = !empty($GLOBALS['YC_META']['description']) ? $GLOBALS['YC_META']['description']
    : trim((string)preg_replace('/\s+/u', ' ', Str::cleanMd((string)(($p['excerpt'] ?? '') !== '' ? $p['excerpt'] : ($p['content'] ?? '')))));
echo "\n" . '<script type="application/ld+json">' . json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BlogPosting',
    'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $artUrl],
    'headline' => (string)$p['title'],
    'description' => mb_substr($artDesc, 0, 300),
    'datePublished' => $ptISO,
    'dateModified' => date('c', strtotime((string)($p['updated_at'] ?? $p['published_at'] ?? $p['created_at']))),
    'author' => ['@type' => 'Organization', 'name' => site_label()],
    'publisher' => ['@type' => 'Organization', 'name' => site_label()],
    'inLanguage' => $pgLang === 'zh' ? 'zh-CN' : $pgLang,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';

/* 摘要去重：摘要若只是正文开头原样摘抄则不再重复显示（沿用 v2 判定） */
$showLead = false;
if (($p['excerpt'] ?? '') !== '') {
    $h = (string)($html ?? '');
    $h = (string)preg_replace('#</?(?:strong|em|b|i|u|s|code|a|span)[^>]*>#i', '', $h);
    $plainLead = trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/<[^>]+>/', ' ', $h)));
    $excerptN = trim((string)preg_replace('/\s+/u', ' ', (string)$p['excerpt']));
    if ($excerptN !== '' && $plainLead !== '') {
        $probe = mb_substr($excerptN, 0, 80);
        $headT = mb_substr($plainLead, 0, mb_strlen($probe) + 20);
        similar_text($probe, $headT, $pct);
        $showLead = $pct < 82;
    }
}
$cat0 = !empty($p['cats']) ? $p['cats'][0] : null;
$catName = $cat0 ? Poly::termName($cat0) : '';
$catUrl  = $cat0 ? Poly::termUrl('category', $cat0) : '';
$words = mb_strlen(trim((string)preg_replace('/\s+/u', '', Str::cleanMd((string)($p['content'] ?? '')))));
?>
<div class="wrap" data-cid="<?= $cid ?>">
  <nav class="crumb tales-crumb">
    <a href="<?= e($lp2 . '/') ?>"><?= e($T_('首页', 'Home')) ?></a><i>/</i>
    <?php if ($cat0): ?><a href="<?= e($catUrl) ?>"><?= e($catName) ?></a><i>/</i><?php endif; ?>
    <?= e($p['title']) ?>
  </nav>

  <main class="art-main" style="--c:<?= $myC ?>">
      <article class="art">
        <?php if (!empty($p['cats'])): ?>
        <div class="kicker kicker-acc">
          <?php foreach ($p['cats'] as $c): ?><a href="<?= e(Poly::termUrl('category', $c)) ?>"><?= e(Poly::termName($c)) ?></a><?php endforeach; ?>
          <?php if ($pgLang !== Poly::defaultLang()): ?> · <?= e(Poly::label($pgLang)) ?><?php endif; ?>
        </div>
        <?php endif; ?>
        <h1><?= e($p['title']) ?></h1>
        <div class="art-meta">
          <span><?= e(date('Y-m-d', $ptT)) ?></span><i>·</i>
          <span><?= e($T_('约 ', '~')) ?><?= (int)round($words / 100) * 100 ?: 0 ?><?= e($T_(' 字', ' chars')) ?></span><i>·</i>
          <span><?= e(__('meta.reads')) ?><i class="yc-views" data-cid="<?= $cid ?>" style="font-style:normal"><?= Str::num((int)$p['views']) ?></i></span><i>·</i>
          <span><?= e(__('meta.comments')) ?><?= (int)$comment_total ?></span>
        </div>
        <?php if (Poly::on() && $pgLang !== Poly::defaultLang() && (!empty($fallback) || !empty($p['stale']))): ?>
        <div class="art-notice">
          <?= !empty($fallback) ? e(__('poly.fallback')) : e(__('poly.stale')) ?>
          · <a href="/<?= e((string)$p['slug']) ?>.html"><?= e(__('poly.view_orig')) ?></a>
        </div>
        <?php endif; ?>
        <div class="art-rule"><i></i><i></i><i></i><i></i><i></i></div>
        <?php if ($showLead): ?><p class="art-lead"><?= e($p['excerpt']) ?></p><?php endif; ?>

        <div class="art-body" id="articleBody"><?= $html ?></div>

        <?php if (!empty($p['tags'])): ?>
        <div class="art-tags">
          <span class="k"><?= e(__('ui.tags_label')) ?></span>
          <?php foreach ($p['tags'] as $i => $t): ?><?= $i ? '<i>·</i>' : '' ?><a href="<?= e(Poly::termUrl('tag', $t)) ?>"><?= e(Poly::termName($t)) ?></a><?php endforeach; ?>
        </div>
        <?php endif; ?>
      </article>

      <nav class="pn">
        <?php if (!empty($prev)): ?>
        <a href="<?= e($lp2 . '/' . rawurlencode((string)$prev['slug'])) ?>.html"><span><?= e(__('post.prev')) ?></span><b><?= e($prev['title']) ?></b></a>
        <?php else: ?><span></span><?php endif; ?>
        <?php if (!empty($next)): ?>
        <a class="nx" href="<?= e($lp2 . '/' . rawurlencode((string)$next['slug'])) ?>.html"><span><?= e(__('post.next')) ?></span><b><?= e($next['title']) ?></b></a>
        <?php else: ?><span></span><?php endif; ?>
      </nav>

      <div class="art-tools">
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
        <?php else: ?>
          <a class="btn btn-sm" href="<?= e(ui_login($back)) ?>"><?= e(__('post.login_cta')) ?></a>
        <?php endif; ?>
      </div>

      <?php if ($authBio !== ''): ?>
      <div class="art-author">
        <span class="av"><?= e(mb_substr($authNick, 0, 1)) ?></span>
        <div><b><?= e($authNick) ?></b><p><?= e($authBio) ?></p></div>
      </div>
      <?php endif; ?>

      <?= View::render('partials/comments', [
          'cid' => $cid, 'logged' => $logged, 'back' => $back,
          'comments' => $comments, 'segments' => $segments, 'para_n' => $para_n, 'csrf' => $csrf,
          'comment_page' => $comment_page ?? 1, 'comment_pages' => $comment_pages ?? 1,
          'comment_total' => (int)$comment_total,
      ]) ?>
  </main>
</div>
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
  // 阅读计数：访客+文章+自然日 去重上报；并用响应里的最新总数即时刷新页面上的阅读数
  (function () {
    var cid = <?= $cid ?>;
    function paint(v) {
      if (v === undefined || v === null) { return; }
      var el = document.querySelector('.yc-views[data-cid="' + cid + '"]');
      if (el) { el.textContent = String(v); }
    }
    try {
      var key = 'yc_view_' + cid + '_' + new Date().toISOString().slice(0, 10);
      if (localStorage.getItem(key)) { return; }
      var fd = new FormData();
      fd.append('content_id', String(cid));
      fetch('/count', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok) { localStorage.setItem(key, '1'); paint(j.views); } })
        .catch(function () {});
    } catch (e) {}
  })();
</script>
<script src="<?= e(View::asset('reader.js')) ?>"></script>
