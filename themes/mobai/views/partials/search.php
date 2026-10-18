<?php
/** 搜索页 · 墨白 Mobai v2（对标 u.sb）
 *  形态：居中页头（标题 + 搜索框 + 等宽结果计数）→ 两列卡片网格。
 *  清单 ⑪：摘要走 Str::plain() 双趟去 Markdown；不输出 read more（u.sb 卡整卡可点），无双箭头。
 *  手维文件，勿用 tools/gen_parts.py 覆盖。 */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
$sp = '<i class="dot" style="width:3px;height:3px;top:36%;right:27%"></i><i style="width:12px;height:12px;top:22%;right:18%"></i>'
    . '<i style="width:8px;height:8px;top:72%;right:9%"></i>';
?>
<section class="us-hero">
  <div class="us-sparkles" aria-hidden="true"><?= $sp ?></div>
  <div class="us-hero-in">
    <h1><?= e(__('sr.title')) ?></h1>
    <form class="us-search" method="get" action="<?= e($lp . '/search') ?>">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('sr.ph')) ?>" aria-label="<?= e(__('sr.ph')) ?>" autofocus>
      <button type="submit"><?= e(__('sr.btn')) ?></button>
    </form>
    <?php if ($q !== ''): ?>
    <div class="us-meta"><?= e(sprintf(__('sr.result'), $q, count($posts))) ?></div>
    <?php endif; ?>
  </div>
</section>

<?php if ($q !== ''): ?>
<?php if (!empty($posts)): ?>
<div class="us-grid">
<?php foreach ($posts as $i => $p):
    $u   = $base . e((string)$p['slug']) . '.html';
    $ts  = strtotime((string)($p['published_at'] ?? $p['created_at']));
    $exc = Str::excerpt(Str::plain(Str::plain((string)($p['content'] ?? ''))), 110);
    $c0  = $p['cats'][0] ?? null;
    $cat = $c0 ? Poly::termName($c0) : '';
    $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
    $tg  = $p['tags'] ?? [];
?>
  <article class="us-card">
    <h2><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
    <div class="us-m">
      <time datetime="<?= e(date('Y-m-d', $ts)) ?>"><?= e(date('Y-m-d', $ts)) ?></time>
      <?php if ($cat !== ''): ?><span><a href="<?= e($catUrl) ?>"><?= e($cat) ?></a></span><?php endif; ?>
      <span><?= e(__('meta.reads')) ?><?= Str::num((int)($p['views'] ?? 0)) ?></span>
    </div>
    <p class="us-x"><?= e($exc) ?></p>
    <?php if (!empty($tg)): ?>
    <div class="us-tags">
      <?php foreach (array_slice($tg, 0, 6) as $t): ?>
      <a class="us-chip" href="<?= e(Poly::termUrl('tag', $t)) ?>"><?= e(Poly::termName($t)) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </article>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="us-empty"><?= e(__('sr.none')) ?></div>
<?php endif; ?>
<?php endif; ?>
