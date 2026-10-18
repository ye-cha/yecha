<?php
/** 列表：标签页 / 分类页 / 搜索 / 归档（Astra 蓝调轻盈 风格 · 由 tools/gen_parts.py 生成，勿手改）
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$empty,$empty_link,$link_prefix */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<div class="page-head"><h1><?= e($page_title) ?></h1><?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?></div>
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
    $exc = Str::excerpt(((string)($p['excerpt'] ?? '') !== '' ? (string)$p['excerpt'] : (string)($p['content'] ?? '')), 90);
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
