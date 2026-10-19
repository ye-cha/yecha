<?php
/** 列表（素简 Sujian v2 · doku 风格：标题行 + 日期 + 分类，简洁文档列表）
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$empty,$empty_link,$link_prefix */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<div class="suj-page-head"><h1><?= e($page_title) ?></h1><?php if (!empty($sub)): ?><span class="suj-sub"><?= $sub ?></span><?php endif; ?></div>

<?php if (!empty($posts)): ?>
<div class="suj-doc-list">
<?php foreach ($posts as $p):
    $c0 = $p['cats'][0] ?? null;
    $cat = $c0 ? Poly::termName($c0) : '';
    $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
    $u = $base . e((string)$p['slug']) . '.html';
    $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
    $exc = Str::excerpt(((string)($p['excerpt'] ?? '') !== '' ? (string)$p['excerpt'] : (string)($p['content'] ?? '')), 110);
    $exc = Str::plain(Str::plain($exc));
?>
  <article class="suj-doc-item">
    <h2 class="suj-doc-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
    <div class="suj-doc-m">
      <time datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y-m-d', $dt)) ?></time>
      <?php if ($cat !== ''): ?><span class="suj-devide-dot"></span><a class="suj-doc-cat" href="<?= e($catUrl) ?>"><?= e($cat) ?></a><?php endif; ?>
      <span class="suj-devide-dot"></span>
      <span><?= e(__('meta.reads')) ?><?= Str::num((int)($p['views'] ?? 0)) ?></span>
    </div>
    <?php if ($exc !== ''): ?><p class="suj-doc-x"><?= e($exc) ?></p><?php endif; ?>
  </article>
<?php endforeach; ?>
</div>
<?php if ($total_pages > 1): ?>
<nav class="suj-pager">
  <?php if ($page > 1): ?><a href="<?= e(str_replace('{n}', (string)($page - 1), $pager_base)) ?>">← <?= e(__('ui.prev')) ?></a><?php endif; ?>
  <span class="suj-pager-c"><?= $page ?> / <?= $total_pages ?></span>
  <?php if ($page < $total_pages): ?><a href="<?= e(str_replace('{n}', (string)($page + 1), $pager_base)) ?>"><?= e(__('ui.next')) ?> →</a><?php endif; ?>
</nav>
<?php endif;
else:
$emptyTxt = isset($empty) && $empty !== '' ? $empty : __('list.empty'); ?>
<div class="suj-empty"><?= e($emptyTxt) ?><?php if (!empty($empty_link)): ?><br><a href="<?= e($empty_link) ?>"><?= e(__('list.empty_alt')) ?></a><?php endif; ?></div>
<?php endif; ?>