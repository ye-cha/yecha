<?php
/** 独立搜索页（WarmPaper ochre 风格）：$q,$posts（结构与列表一致） */
require_once __DIR__ . '/../../inc.php';
/* ★ P3：页型 = 搜索页（v2 无左栏） */
$GLOBALS['YC_P3_KIND'] = 'search';
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
      <h2><a href="<?= e(chawiki_url((string)$p['slug'])) ?>"><?= Str::mark((string)$p['title'], $q) ?></a></h2>
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
  <?php if (chawiki_v2()): /* ★ P3：搜索页出口（设计 §③-5 线框 ⑥-7「出口 4 个」）——
      改前只有「结果列表 + 分页」，无结果时是**死路**；现在无论有没有结果都给 4 条出口。 */ ?>
  <nav class="sr-exits" aria-label="搜索出口">
    <span class="sr-exits-cap">没有找到？换条路 →</span>
    <a href="<?= e($lp . '/category/') ?>">全部分类（13）</a>
    <a href="<?= e($lp . '/tag/') ?>">标签索引</a>
    <a href="<?= e($lp . '/latest') ?>">最新词条</a>
    <a href="<?= e($lp . '/') ?>">返回首页</a>
  </nav>
  <?php endif; ?>
</div>
