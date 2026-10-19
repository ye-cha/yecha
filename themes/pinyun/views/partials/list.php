<?php
/** 品云 Pinyun —— 分类/标签/归档列表
 *  顶部 banner（名称 + 描述 + 篇数）→ 卡片网格（封面渐变色块）
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$description,$total_items */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';

$pyX = function ($raw, $len = 82) {
    $s = (string)$raw;
    $s = (string)preg_replace('/```.*?```/su', ' ', $s);
    $s = (string)preg_replace('~!\[[^\]]*\]\([^)\n]*\)~u', '', $s);
    $s = (string)preg_replace('~!\[[^\]]*\]\([^)\n]*$~mu', '', $s);
    $s = (string)preg_replace('~!\[[^\]]*\]~u', '', $s);
    $s = (string)preg_replace('~\[([^\]]*)\]\([^)\n]*\)~u', '$1', $s);
    $s = (string)preg_replace('~\[([^\]]*)\]\([^)\n]*$~mu', '$1', $s);
    $s = str_replace(['](', '![]'], '', $s);
    $s = (string)preg_replace('~\[[^\]]*\]~u', '', $s);
    $s = (string)preg_replace('~\([^()\n]{0,90}\)~u', '', $s);
    $s = (string)preg_replace('~\([^()\n]{2,}$~mu', '', $s);
    $s = (string)preg_replace('~^\s*#{1,6}\s*~mu', '', $s);
    $s = (string)preg_replace('~#{1,6}\s+~u', ' ', $s);
    $s = (string)preg_replace('~^\s*>+\s?~mu', '', $s);
    $s = (string)preg_replace('~^\s*([-*+]|\d+[.)])\s+~mu', '', $s);
    $s = (string)preg_replace('~^\s*([-*_]\s*){3,}$~mu', '', $s);
    $s = (string)preg_replace('#[*_~`]{1,3}#u', '', $s);
    $s = strip_tags($s);
    $s = (string)preg_replace('/\s+/u', ' ', $s);
    $s = (string)preg_replace('/[-–—]{3,}/u', ' ', $s);
    $s = trim($s, " \t\n\r\0\x0B-–—>|·");
    return mb_strlen($s) > $len ? mb_substr($s, 0, $len) . '…' : $s;
};
$hue = function (int $i) { return 148 + (($i * 13) % 34); };
$isMeta = !empty($total_items) || !empty($description);
?>
<?php if ($isMeta): ?>
<section class="py-cat-banner">
  <div class="py-cat-in">
    <h1 class="py-cat-title"><?= e($page_title) ?></h1>
    <?php if (!empty($description)): ?><p class="py-cat-desc"><?= e((string)$description) ?></p><?php endif; ?>
    <div class="py-cat-meta">
      <?php if (!empty($total_items)): ?><span class="py-cat-count"><?= (int)$total_items ?> 篇</span><?php endif; ?>
      <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
    </div>
  </div>
</section>
<?php else: ?>
<div class="py-plain-head">
  <h1><?= e($page_title) ?></h1>
  <?php if (!empty($sub)): ?><span class="sub"><?= e((string)$sub) ?></span><?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($posts)): ?>
<div class="py-grid py-grid-list">
<?php $i = 0; foreach ($posts as $p):
    $i++;
    $c0   = $p['cats'][0] ?? null;
    $cat  = $c0 ? Poly::termName($c0) : '';
    $catU = $c0 ? Poly::termUrl('category', $c0) : '';
    $u    = $base . e((string)$p['slug']) . '.html';
    $dt   = strtotime((string)($p['published_at'] ?? $p['created_at']));
    $raw  = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
    $exc  = $pyX($raw);
    $h    = $hue($i);
    $ini  = mb_substr(trim((string)$p['title']), 0, 1);
?>
  <article class="py-card">
    <a class="py-cover" href="<?= e($u) ?>" style="--cva:hsl(<?= $h ?>,46%,64%);--cvb:hsl(<?= ($h + 26) ?>,52%,42%)" aria-hidden="true" tabindex="-1">
      <span class="py-cover-initial"><?= e($ini) ?></span>
      <?php if ($cat !== ''): ?><span class="py-cover-cat"><?= e($cat) ?></span><?php endif; ?>
    </a>
    <div class="py-card-b">
      <h2 class="py-card-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
      <?php if ($exc !== ''): ?><p class="py-card-x"><?= e($exc) ?></p><?php endif; ?>
      <div class="py-card-m">
        <time datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y-m-d', $dt)) ?></time>
        <span><?= e(__('meta.reads')) ?><?= Str::num((int)($p['views'] ?? 0)) ?></span>
      </div>
      <a class="py-card-more" href="<?= e($u) ?>">阅读全文</a>
    </div>
  </article>
<?php endforeach; ?>
</div>
<?php if ($total_pages > 1): ?>
<div class="pager">
  <?php if ($page > 1): ?><a href="<?= e(str_replace('{n}', (string)($page - 1), $pager_base)) ?>"><?= e(__('ui.prev')) ?></a><?php endif; ?>
  <span><?= (int)$page ?> / <?= (int)$total_pages ?></span>
  <?php if ($page < $total_pages): ?><a href="<?= e(str_replace('{n}', (string)($page + 1), $pager_base)) ?>"><?= e(__('ui.next')) ?></a><?php endif; ?>
</div>
<?php endif; ?>
<?php else:
$emptyTxt = isset($empty) && $empty !== '' ? $empty : __('list.empty'); ?>
<div class="empty"><?= e($emptyTxt) ?><?php if (!empty($empty_link)): ?><br><a href="<?= e($empty_link) ?>"><?= e(__('list.empty_alt')) ?></a><?php endif; ?></div>
<?php endif; ?>
