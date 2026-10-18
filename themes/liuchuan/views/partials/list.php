<?php
/** 柳川 Liuchuan —— 列表（分类 / 标签 / 归档月）：三列网格卡片
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$description,$total_items */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<div class="lc-page">
  <header class="lc-head">
    <h1 class="lc-head-t"><?= e($page_title) ?></h1>
    <?php if (!empty($description)): ?><p class="lc-head-d"><?= e($description) ?></p><?php endif; ?>
    <div class="lc-head-m">
      <?php if (!empty($total_items)): ?><span class="lc-badge"><?= (int)$total_items ?> 篇</span><?php endif; ?>
      <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
    </div>
  </header>

  <?php if (!empty($posts)): ?>
  <div class="lc-grid">
    <?php foreach ($posts as $p):
      $c0 = $p['cats'][0] ?? null;
      $cat = $c0 ? Poly::termName($c0) : '';
      $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
      $u = $base . e((string)$p['slug']) . '.html';
      $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
      $raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
      $exc = trim(Str::plain(Str::excerpt(Str::cleanMd($raw), 92)));
    ?>
    <article class="lc-card">
      <?php if ($cat !== ''): ?><a class="lc-card-cat" href="<?= e($catUrl) ?>"><?= e($cat) ?></a><?php endif; ?>
      <h2 class="lc-card-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
      <time class="lc-card-d"><?= e(date('Y-m-d', $dt)) ?></time>
      <p class="lc-card-x"><?= e($exc) ?></p>
      <span class="lc-card-go">阅读全文</span>
    </article>
    <?php endforeach; ?>
  </div>

  <?php if ($total_pages > 1): ?>
  <div class="pager">
    <?php if ($page > 1): ?><a href="<?= e(str_replace('{n}', (string)($page - 1), $pager_base)) ?>"><?= e(__('ui.prev')) ?></a><?php endif; ?>
    <span class="cur"><?= $page ?> / <?= $total_pages ?></span>
    <?php if ($page < $total_pages): ?><a href="<?= e(str_replace('{n}', (string)($page + 1), $pager_base)) ?>"><?= e(__('ui.next')) ?></a><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php else: ?>
  <div class="lc-empty"><?= e(!empty($empty) ? $empty : __('list.empty')) ?></div>
  <?php endif; ?>
</div>
