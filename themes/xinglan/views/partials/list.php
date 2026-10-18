<?php
/** 列表：分类页 / 标签页 / 首页分页（星澈 Xinglan · 分类 banner + 两列卡片网格）
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$empty,$empty_link,$link_prefix */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<?php if (!empty($description) || !empty($total_items)): ?>
<div class="xl-banner">
  <nav class="xl-banner-crumb"><?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?></nav>
  <h1 class="xl-banner-title"><?= e($page_title) ?></h1>
  <?php if (!empty($total_items)): ?><span class="xl-badge"><?= (int)$total_items ?> 篇文章</span><?php endif; ?>
  <?php if (!empty($description)): ?><p class="xl-banner-desc"><?= e($description) ?></p><?php endif; ?>
</div>
<?php else: ?>
<div class="xl-head">
  <h1 class="xl-head-title"><?= e($page_title) ?></h1>
  <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
</div>
<?php endif; ?>
<?php if (!empty($posts)): ?>
<div class="xl-grid xl-grid-sm">
<?php $i = 0; foreach ($posts as $p):
    $i++;
    $c0 = $p['cats'][0] ?? null;
    $cat = $c0 ? Poly::termName($c0) : '';
    $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
    $u = $base . e((string)$p['slug']) . '.html';
    $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
    $raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
    $exc = Str::plain(Str::plain(Str::excerpt($raw, 92)));
?>
  <article class="xl-card">
    <a class="xl-cover xl-c<?= (($i - 1) % 6) + 1 ?>" href="<?= e($u) ?>" aria-hidden="true" tabindex="-1">
      <span class="xl-cover-letter"><?= e(mb_substr((string)$p['title'], 0, 1)) ?></span>
    </a>
    <div class="xl-card-body">
      <h2 class="xl-card-title"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
      <p class="xl-card-exc"><?= e($exc) ?></p>
      <div class="xl-card-foot">
        <time class="xl-card-date" datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y-m-d', $dt)) ?></time>
        <?php if ($cat !== '' && $catUrl !== ''): ?><a class="xl-chip" href="<?= e($catUrl) ?>"><?= e($cat) ?></a>
        <?php elseif ($cat !== ''): ?><span class="xl-chip"><?= e($cat) ?></span><?php endif; ?>
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
<?php endif;
else:
$emptyTxt = isset($empty) && $empty !== '' ? $empty : __('list.empty'); ?>
<div class="empty"><?= e($emptyTxt) ?><?php if (!empty($empty_link)): ?><br><a href="<?= e($empty_link) ?>"><?= e(__('list.empty_alt')) ?></a><?php endif; ?></div>
<?php endif; ?>
