<?php
/** 列表：分类页 / 标签页 / 归档月（文枢 Wenshu · 文档站风）
 *  骨架：左导航(layout) + 右侧内容（面包屑 + 文档头 + 文档行列表 + 分页）
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$empty,$empty_link,$link_prefix */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
$crumbRoot = __('nav.cats');
?>
<nav class="ws-crumb" aria-label="面包屑">
  <a href="<?= e($base) ?>">首页</a><span>/</span><b><?= e($page_title) ?></b>
</nav>

<div class="ws-doc-head">
  <h1><?= e($page_title) ?></h1>
  <?php if (!empty($description)): ?><p class="ws-doc-desc"><?= e($description) ?></p><?php endif; ?>
  <div class="ws-doc-headmeta">
    <?php if (!empty($total_items)): ?><span class="ws-count"><?= (int)$total_items ?> 篇</span><?php endif; ?>
    <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
  </div>
</div>

<?php if (!empty($posts)): ?>
<div class="ws-doclist">
<?php foreach ($posts as $p):
    $c0 = $p['cats'][0] ?? null;
    $cat = $c0 ? Poly::termName($c0) : '';
    $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
    $u = $base . e((string)$p['slug']) . '.html';
    $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
    $raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
    $exc = trim(Str::plain(Str::plain(Str::excerpt($raw, 110))));
?>
  <article class="ws-docrow">
    <h2 class="ws-docrow-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
    <p class="ws-docrow-x"><?= e($exc) ?></p>
    <div class="ws-docrow-m">
      <time datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y-m-d', $dt)) ?></time>
      <?php if ($cat !== ''): ?><a class="ws-docrow-cat" href="<?= e($catUrl) ?>"><?= e($cat) ?></a><?php endif; ?>
      <span><?= e(__('meta.reads')) ?><?= Str::num((int)($p['views'] ?? 0)) ?></span>
      <a class="ws-docrow-more" href="<?= e($u) ?>"><?= e(__('ui.read_more')) ?></a>
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
