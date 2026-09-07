<?php
/** 独立搜索页：$q,$posts（posts 为结果行，结构与列表一致） */
$lp = ui_lp();
?>
<div class="page-head"><h1><?= e(__('sr.title')) ?></h1><span class="sub"><?= e(__('sr.sub')) ?></span></div>
<form class="search-page-form" method="get" action="<?= e($lp . '/search') ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('sr.ph')) ?>" autofocus>
  <button type="submit"><?= e(__('sr.btn')) ?></button>
</form>
<?php if ($q !== ''): ?>
  <p class="hint" style="margin:6px 0 14px;"><?= e(sprintf(__('sr.result'), $q, count($posts))) ?></p>
  <?php if ($posts): ?>
  <div class="post-list" id="viewText">
    <?php foreach ($posts as $p):
        $c0 = $p['cats'][0] ?? null;
        $cat = $c0 ? Poly::termName($c0) : '';
        $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
        $src = (string)($p['excerpt'] !== '' ? $p['excerpt'] : $p['content']);
        $plain = trim(preg_replace(['~!\[[^\]]*\]\([^)]*\)~', '~\[[^\]]*\]\([^)]*\)~', '~[#>`]{1,3}~', '~\*{1,3}~', '~_{1,3}~'], '', $src) ?? $src); ?>
    <article class="post-item">
      <h2><a href="<?= e($lp . '/' . (string)$p['slug']) ?>.html"><?= Str::mark((string)$p['title'], $q) ?></a></h2>
      <p class="post-excerpt"><?= Str::mark(Str::snippet($plain, $q), $q) ?></p>
      <div class="post-meta">
        <span><?= e(date('Y-m-d', $dt)) ?></span><span><?= e(__('meta.reads')) ?><?= Str::num((int)$p['views']) ?></span>
        <?php if ($cat !== ''): ?><a class="cat" href="<?= e(Poly::termUrl('category', $p['cats'][0])) ?>"><?= e($cat) ?></a><?php endif; ?>
        <a class="readmore" href="<?= e($lp . '/' . (string)$p['slug']) ?>.html"><?= e(__('ui.read_more')) ?></a>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="uc-empty"><?= e(sprintf(__('sr.empty'), $q)) ?></div>
  <?php endif; ?>
<?php else: ?>
  <div class="uc-empty"><?= e(__('sr.hint0')) ?></div>
<?php endif; ?>
