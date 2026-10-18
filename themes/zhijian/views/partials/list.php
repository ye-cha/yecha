<?php
/** 列表：分类页 / 标签页 / 归档月（纸笺 Zhijian · 衬线标题 + 垂直堆叠卡片）
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$empty,$empty_link,$link_prefix */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<div class="zj-page">
  <header class="zj-page-h">
    <h1 class="zj-page-t"><?= e($page_title) ?></h1>
    <?php if (!empty($description)): ?><p class="zj-page-d"><?= e($description) ?></p><?php endif; ?>
    <p class="zj-page-n">
      <?php if (!empty($total_items)): ?><span><?= (int)$total_items ?> 篇</span><?php endif; ?>
      <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
    </p>
  </header>

  <?php if (!empty($posts)): ?>
  <div class="zj-cards">
  <?php foreach ($posts as $p):
    $u = $base . e((string)$p['slug']) . '.html';
    $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
    $raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
    $exc = Str::plain(Str::excerpt(Str::cleanMd($raw), 118));
    $tg = $p['tags'] ?? [];
  ?>
    <article class="zj-card">
      <h2 class="zj-card-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
      <p class="zj-card-meta"><time class="zj-date" datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y 年 n 月 j 日', $dt)) ?></time></p>
      <?php if ($exc !== ''): ?><p class="zj-card-x"><?= e($exc) ?></p><?php endif; ?>
      <?php if ($tg): ?>
      <div class="zj-chips">
        <?php foreach (array_slice($tg, 0, 4) as $t): ?>
        <a class="zj-chip" href="<?= e(Poly::termUrl('tag', $t)) ?>">#<?= e(Poly::termName($t)) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
  </div>
  <?php if ($total_pages > 1): ?>
  <nav class="pager">
    <?php if ($page > 1): ?><a href="<?= e(str_replace('{n}', (string)($page - 1), $pager_base)) ?>"><?= e(__('ui.prev')) ?></a><?php endif; ?>
    <span><?= $page ?> / <?= $total_pages ?></span>
    <?php if ($page < $total_pages): ?><a href="<?= e(str_replace('{n}', (string)($page + 1), $pager_base)) ?>"><?= e(__('ui.next')) ?></a><?php endif; ?>
  </nav>
  <?php endif;
  else:
  $emptyTxt = isset($empty) && $empty !== '' ? $empty : __('list.empty'); ?>
  <div class="empty"><?= e($emptyTxt) ?><?php if (!empty($empty_link)): ?><br><a href="<?= e($empty_link) ?>"><?= e(__('list.empty_alt')) ?></a><?php endif; ?></div>
  <?php endif; ?>
</div>
