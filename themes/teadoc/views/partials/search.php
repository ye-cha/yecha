<?php
/** 独立搜索页（WarmPaper ochre 风格）：$q,$posts（结构与列表一致） */
$lp = ui_lp();
$en = Poly::on() && Lang::short() !== Poly::defaultLang();
$ph = __('sr.ph');
$blog = (string)get_option('blogger.nick', site_name());
$blog = $blog !== '' ? $blog : site_label();
$av = mb_substr($blog, 0, 1);
?>
<div class="search-page">
  <div class="sr-head"><h1><?= e(__('sr.title')) ?></h1><span class="sr-sub"><?= e(__('sr.sub')) ?></span></div>
  <form class="sr-search" method="get" action="<?= e($lp . '/search') ?>" role="search">
    <button type="submit" class="sr-ic" aria-label="<?= e(__('sr.btn')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"></circle><path d="M21 21l-4.3-4.3"></path></svg></button>
    <input id="srQ" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e($ph) ?>" autocomplete="off">
    <span class="search-kbd">Ctrl K</span>
  </form>
  <?php if ($q !== ''): ?>
    <p class="sr-result-note"><?= e(sprintf(__('sr.result'), $q, count($posts))) ?></p>
    <?php if ($posts): ?>
      <?php $si = 0; foreach ($posts as $p): $si++;
          $c0 = $p['cats'][0] ?? null;
          $cat = $c0 ? Poly::termName($c0) : '';
          $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
          $src = (string)($p['excerpt'] !== '' ? $p['excerpt'] : $p['content']);
          $plain = trim((string)(preg_replace(['~!\[[^\]]*\]\([^)]*\)~', '~\[[^\]]*\]\([^)]*\)~', '~[#>`]{1,3}~', '~\*{1,3}~', '~_{1,3}~'], '', $src) ?? $src));
      ?>
    <article class="post-item">
      <div class="post-top">
        <span class="no"><?= str_pad((string)$si, 2, '0', STR_PAD_LEFT) ?></span>
        <?php if ($cat !== ''): ?><a class="chip" href="<?= e(Poly::termUrl('category', $p['cats'][0])) ?>"><?= e($cat) ?></a><?php endif; ?>
      </div>
      <h2><a href="<?= e($lp . '/' . (string)$p['slug']) ?>.html"><?= Str::mark((string)$p['title'], $q) ?></a></h2>
      <p class="post-excerpt"><?= Str::mark(Str::snippet($plain, $q), $q) ?></p>
      <div class="post-meta">
        <span class="avatar"><?= e($av) ?></span>
        <span><?= e($blog) ?></span><span class="dot">·</span>
        <span><?= e(lang_date($dt)) ?></span><span class="dot">·</span>
        <span><?= e(__('meta.reads')) ?><?= Str::num((int)$p['views']) ?></span>
        <span class="read-more"><?= e(__('list.read')) ?></span>
      </div>
    </article>
    <?php endforeach; ?>
    <?php else: ?>
      <div class="sr-info"><?= e(sprintf(__('sr.empty'), $q)) ?></div>
    <?php endif; ?>
  <?php else: ?>
    <div class="sr-info"><?= e(__('sr.hint0')) ?></div>
  <?php endif; ?>
</div>
