<?php
/** 首页图文列表（左图右文）：$posts,$page_title,$sub,$page,$total_pages,$pager_base,$link_prefix(可选,如 /ja) */
$lp = trim((string)($link_prefix ?? ''));
$pfx = $lp === '' ? '/' : $lp . '/';
?>
<div class="page-head"><h1><?= e($page_title) ?></h1><?php if (!empty($sub)): ?><span class="sub"><?= e($sub) ?></span><?php endif; ?></div>
<div class="post-list media" id="viewMedia">
<?php foreach ($posts as $p):
    $c0 = $p['cats'][0] ?? null;
    $cat = $c0 ? Poly::termName($c0) : '';
    $img = '';
    $src = (string)($p['content'] ?? '');
    if (preg_match('/!\[[^\]]*\]\(([^)\s]+)\)/', $src, $mi)) { $img = $mi[1]; }
    elseif (preg_match('/\]\(([^)\s]+\.(?:webp|png|jpe?g|gif|svg))\)/', $src, $mi)) { $img = $mi[1]; }
    $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
    $u = $pfx . e((string)$p['slug']) . '.html';
?>
  <article class="media-row">
    <div class="media-th">
      <?php if ($img !== ''): ?>
        <a href="<?= $u ?>"><img src="<?= e($img) ?>" alt="<?= e((string)$p['title']) ?>" loading="lazy"></a>
      <?php else: ?>
        <a class="noimg" href="<?= $u ?>"><?= e(__('ui.noimg')) ?></a>
      <?php endif; ?>
    </div>
    <div class="media-body">
      <h3><a href="<?= $u ?>"><?= e($p['title']) ?></a></h3>
      <p class="lead"><?= e(Str::excerpt(((string)($p['excerpt'] ?? '')) !== '' ? (string)$p['excerpt'] : $src, 120)) ?></p>
      <div class="post-meta">
        <span><?= e(date('Y-m-d', $dt)) ?></span>
        <span><?= e(__('meta.reads')) ?><?= Str::num((int)$p['views']) ?></span>
        <?php if ($cat !== '' && isset($p['cats'][0]['slug'])): ?><a class="cat" href="<?= e(Poly::termUrl('category', $p['cats'][0])) ?>"><?= e($cat) ?></a><?php endif; ?>
        <a class="readmore" href="<?= $u ?>"><?= e(__('ui.read_more')) ?></a>
      </div>
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
<?php endif; ?>
