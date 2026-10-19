<?php
/** 列表：标签页 / 分类页 / 搜索 / 归档（新绿 Xinlv · 手维，勿用 tools/gen_parts.py 覆盖）
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$empty,$empty_link,$link_prefix */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<?php if (empty($is_home)): ?>
<div class="page-head"><h1><?= e($page_title) ?></h1><?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?></div>
<?php else:
/* 首页 hero：精选轮播 featured-carousel（对标 kwa.cc）
   所有 slide 直接输出在 HTML 里；JS 只负责切换（无 JS 时全部平铺可见，不靠 JS 渲染内容） */
$__slides = array_slice(is_array($posts) ? $posts : [], 0, 5);
if (!empty($__slides)):
?>
<section class="featured-carousel" id="hsCarousel" aria-roledescription="carousel" aria-label="精选文章">
  <div class="carousel-track">
    <?php $__i = 0; foreach ($__slides as $f):
        $fC0 = $f['cats'][0] ?? null;
        $fCat = $fC0 ? Poly::termName($fC0) : '';
        $fCatUrl = $fC0 ? Poly::termUrl('category', $fC0) : '';
        $fImg = '';
        if (preg_match('/!\[[^\]]*\]\(([^)\s]+)\)/', (string)($f['content'] ?? ''), $fm)) { $fImg = $fm[1]; }
        $fU = $base . e((string)$f['slug']) . '.html';
        $fDt = strtotime((string)($f['published_at'] ?? $f['created_at']));
        $fExc = Str::excerpt(Str::plain(Str::plain(((string)($f['excerpt'] ?? '') !== '' ? (string)$f['excerpt'] : (string)($f['content'] ?? '')))), 80);
    ?>
    <article class="featured-card carousel-slide<?= $__i === 0 ? ' active' : '' ?>"<?= $__i === 0 ? '' : ' aria-hidden="true"' ?>>
      <div class="featured-bg<?= $fImg === '' ? ' no-img' : '' ?>"<?= $fImg !== '' ? ' style="background-image:url(' . e($fImg) . ')"' : '' ?>></div>
      <div class="featured-overlay"></div>
      <div class="featured-content">
        <?php if ($fCat !== ''): ?><a class="featured-badge" href="<?= e($fCatUrl) ?>"><?= e($fCat) ?></a>
        <?php else: ?><span class="featured-badge">精选</span><?php endif; ?>
        <h2 class="featured-title"><a href="<?= e($fU) ?>"><?= e((string)$f['title']) ?></a></h2>
        <?php if ($fExc !== ''): ?><p class="featured-desc"><?= e($fExc) ?></p><?php endif; ?>
        <div class="featured-meta">
          <time datetime="<?= e(date('Y-m-d', $fDt)) ?>"><?= e(date('Y-m-d', $fDt)) ?></time>
          <?php if ($fCat !== ''): ?><a href="<?= e($fCatUrl) ?>"><?= e($fCat) ?></a><?php endif; ?>
          <span><?= e(__('meta.reads')) ?><?= Str::num((int)($f['views'] ?? 0)) ?></span>
        </div>
      </div>
    </article>
    <?php $__i++; endforeach; ?>
  </div>
  <?php if (count($__slides) > 1): ?>
  <button type="button" class="carousel-arrow carousel-prev" aria-label="上一篇"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button>
  <button type="button" class="carousel-arrow carousel-next" aria-label="下一篇"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button>
  <div class="carousel-dots" role="tablist" aria-label="轮播导航">
    <?php for ($__d = 0; $__d < count($__slides); $__d++): ?>
    <button type="button" class="carousel-dot<?= $__d === 0 ? ' active' : '' ?>" role="tab" aria-selected="<?= $__d === 0 ? 'true' : 'false' ?>" aria-label="第 <?= $__d + 1 ?> 篇"></button>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
<div class="page-head hs-sec-head"><h2><?= e(__('sb.latest')) ?></h2><?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?></div>
<?php endif; ?>
<?php if (!empty($posts)): ?>
<div class="lc lc-astra">
<?php foreach ($posts as $p):
    $c0 = $p['cats'][0] ?? null;
    $cat = $c0 ? Poly::termName($c0) : '';
    $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
    $img = '';
    if (preg_match('/!\[[^\]]*\]\(([^)\s]+)\)/', (string)($p['content'] ?? ''), $mi)) { $img = $mi[1]; }
    $u = $base . e((string)$p['slug']) . '.html';
    $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
    $exc = Str::excerpt(Str::plain(Str::plain(((string)($p['excerpt'] ?? '') !== '' ? (string)$p['excerpt'] : (string)($p['content'] ?? '')))), 90);
?>
  <article class="lcc lcc-astra">
    <a class="lcc-th" href="<?= e($u) ?>"><?php if ($img !== ''): ?><img src="<?= e($img) ?>" alt="<?= e((string)$p['title']) ?>" loading="lazy"><?php endif; ?></a>
    <div class="lcc-bd">
      <?php if ($cat !== ''): ?><a class="lcc-cat" href="<?= e($catUrl) ?>"><?= e($cat) ?></a><?php endif; ?>
      <h2 class="lcc-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
      <p class="lcc-x"><?= e($exc) ?></p>
      <div class="lcc-m"><span><?= e(date('Y-m-d', $dt)) ?></span><span><?= e(__('meta.reads')) ?><?= Str::num((int)($p['views'] ?? 0)) ?></span></div>
      <a class="lcc-more" href="<?= e($u) ?>"><?= e(__('ui.read_more')) ?></a>
    </div>
  </article>
<?php endforeach; ?>
</div>
<?php if ($total_pages > 1): ?>
<div class="pager">
  <?php if ($page > 1): ?><a href="<?= e(str_replace('{n}', (string)($page - 1), $pager_base)) ?>"><?= e(__('ui.prev')) ?></a><?php endif; ?>
  <span><?= $page ?> / <?= $total_pages ?></span>
  <?php if ($page < $total_pages): ?><a href="<?= e(str_replace('{n}', (string)($page + 1), $pager_base)) ?>"><?= e(__('ui.next')) ?></a><?php endif; ?>
</div>
<?php endif;
else:
$emptyTxt = isset($empty) && $empty !== '' ? $empty : __('list.empty'); ?>
<div class="empty"><?= e($emptyTxt) ?><?php if (!empty($empty_link)): ?><br><a href="<?= e($empty_link) ?>"><?= e(__('list.empty_alt')) ?></a><?php endif; ?></div>
<?php endif; ?>
