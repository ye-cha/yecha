<?php
/** WarmPaper 列表区块（ochre 细节）：$posts,$page_title,$sub,$page,$total_pages,$pager_base,$empty,$link_prefix */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$blog = (string)get_option('blogger.nick', site_name());
$blog = $blog !== '' ? $blog : site_label();
$av = mb_substr($blog, 0, 1);
$isHome = str_starts_with((string)($pager_base ?? ''), '/page/');
?><?php if (!empty($posts)):
$i = 0;
foreach ($posts as $p):
    $i++;
    $c0 = $p['cats'][0] ?? null;
    $cat = $c0 ? Poly::termName($c0) : '';
    $pt = strtotime((string)($p['published_at'] ?? $p['created_at']));
?>
<article class="post-item">
  <div class="post-top">
    <span class="no"><?= str_pad((string)$i, 2, '0', STR_PAD_LEFT) ?></span>
    <?php if ($cat !== ''): ?><a class="chip" href="<?= e(Poly::termUrl('category', $p['cats'][0])) ?>"><?= e($cat) ?></a><?php endif; ?>
  </div>
  <h2><a href="<?= e($lp . '/' . (string)$p['slug']) ?>.html"><?= e($p['title']) ?></a></h2>
  <p class="post-excerpt"><?= e(Str::excerpt(((string)($p['excerpt'] ?? '')) !== '' ? (string)$p['excerpt'] : (string)($p['content'] ?? ''), 140)) ?></p>
  <div class="post-meta">
    <span class="avatar"><?= e($av) ?></span>
    <span><?= e($blog) ?></span><span class="dot">·</span>
    <span><?= e(lang_date($pt)) ?></span>
    <a class="read-more" href="<?= e($lp . '/' . (string)$p['slug']) ?>.html"><?= e(__('list.read')) ?></a>
  </div>
</article>
<?php endforeach;
if ($total_pages > 1): ?>
<nav class="pager">
  <?php if ($page > 1): ?><a class="pg" href="<?= e(str_replace('{n}', (string)($page - 1), $pager_base)) ?>">‹</a><?php endif; ?>
  <?php for ($n = 1; $n <= $total_pages; $n++):
      if ($total_pages > 9 && $n > 2 && $n < $total_pages - 1 && abs($n - $page) > 2) { if ($n === 3) { echo '<span class="pg off">…</span>'; } continue; }
      if ($n === $page): ?><span class="pg on"><?= $n ?></span><?php else: ?><a class="pg" href="<?= e(str_replace('{n}', (string)$n, $pager_base)) ?>"><?= $n ?></a><?php endif; ?>
  <?php endfor; ?>
  <?php if ($page < $total_pages): ?><a class="pg" href="<?= e(str_replace('{n}', (string)($page + 1), $pager_base)) ?>">›</a><?php endif; ?>
</nav>
<?php endif;
else:
$emptyTxt = isset($empty) && $empty !== '' ? $empty : __('list.empty'); ?>
<div class="empty"><?= e($emptyTxt) ?><?php if (!empty($empty_link)): ?><br><a href="<?= e($empty_link) ?>"><?= e(__('list.empty_alt')) ?></a><?php endif; ?></div>
<?php endif; ?>
