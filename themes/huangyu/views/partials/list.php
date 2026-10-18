<?php
/** 列表：分类页 / 标签页 / 归档列表 / 首页文字列表（黄羽 Huangyu · 杂志风）
 *  圆形封面头像 + 标题 + 摘要，圆润柔和；面包屑含真实未转义首页链接。
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$description,$total_items,$empty */
require_once __DIR__ . '/../_util.php';
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
$homeHref = $lp === '' ? '/' : $lp . '/';
?>
<?php if (!empty($description) || !empty($total_items) || !empty($page_title)): ?>
<div class="hyu-head">
  <h1 class="hyu-head-t"><?= e($page_title) ?></h1>
  <?php if (!empty($description)): ?><p class="hyu-head-d"><?= e((string)$description) ?></p><?php endif; ?>
  <?php if (!empty($total_items)): ?><span class="hyu-head-c"><?= (int)$total_items ?> 篇</span><?php endif; ?>
</div>
<?php endif; ?>

<nav class="sub hyu-crumb" aria-label="breadcrumb">
  <a href="<?= e($homeHref) ?>">首页</a>
  <?php if (!empty($sub)): ?><span class="hyu-sep">/</span><span class="hyu-crumb-t"><?= $sub ?></span><?php endif; ?>
</nav>

<?php if (!empty($posts)): ?>
<div class="hyu-stream">
  <?php foreach ($posts as $p):
      $c0 = $p['cats'][0] ?? null;
      $cat = $c0 ? Poly::termName($c0) : '';
      $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
      $u = $base . e((string)$p['slug']) . '.html';
      $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
      $dateStr = $dt ? date('Y-m-d', $dt) : '';
      [$color, $ini] = hyu_avatar((string)$p['slug'], (string)($p['title'] ?? ''));
      $raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
      $exc = hyu_excerpt($raw, 96);
  ?>
  <article class="hyu-post">
    <a class="hyu-ava" href="<?= e($u) ?>" style="background:<?= e($color) ?>" aria-hidden="true"><?= e($ini) ?></a>
    <div class="hyu-body">
      <?php if ($cat !== ''): ?><a class="hyu-cat" href="<?= e($catUrl) ?>"><?= e($cat) ?></a><?php endif; ?>
      <h2 class="hyu-post-t"><a href="<?= e($u) ?>"><?= e((string)($p['title'] ?? '')) ?></a></h2>
      <p class="hyu-post-x"><?= e($exc) ?></p>
      <div class="hyu-post-m"><time><?= e($dateStr) ?></time><span class="hyu-dot">·</span><span><?= e(__('meta.reads')) ?><?= Str::num((int)($p['views'] ?? 0)) ?></span></div>
    </div>
  </article>
  <?php endforeach; ?>
</div>

<?php if (!empty($total_pages) && (int)$total_pages > 1): ?>
<nav class="hyu-pager" aria-label="pager">
  <?php if ((int)$page > 1): ?><a class="hyu-pg" href="<?= e(str_replace('{n}', (string)((int)$page - 1), (string)$pager_base)) ?>"><?= e(__('ui.prev')) ?></a><?php endif; ?>
  <span class="hyu-pg-cur"><?= (int)$page ?> / <?= (int)$total_pages ?></span>
  <?php if ((int)$page < (int)$total_pages): ?><a class="hyu-pg" href="<?= e(str_replace('{n}', (string)((int)$page + 1), (string)$pager_base)) ?>"><?= e(__('ui.next')) ?></a><?php endif; ?>
</nav>
<?php endif;

else:
$emptyTxt = isset($empty) && $empty !== '' ? $empty : __('list.empty'); ?>
<div class="hyu-empty"><?= e($emptyTxt) ?><?php if (!empty($empty_link)): ?><br><a href="<?= e((string)$empty_link) ?>"><?= e(__('list.empty_alt')) ?></a><?php endif; ?></div>
<?php endif; ?>
