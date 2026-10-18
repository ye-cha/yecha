<?php
/** 派记 Paiji —— 分类 / 标签 / 归档列表：同款笔记列表（纸质、细横线）
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$description,$total_items */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';

$pjX = function ($raw, $len = 96) {
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
$isMeta = !empty($total_items) || !empty($description);
?>
<?php if ($isMeta): ?>
<header class="pj-list-head">
  <h1 class="pj-list-t"><?= e($page_title) ?></h1>
  <?php if (!empty($description)): ?><p class="pj-list-desc"><?= e((string)$description) ?></p><?php endif; ?>
  <div class="pj-list-meta">
    <?php if (!empty($total_items)): ?><span class="pj-list-count"><?= (int)$total_items ?> 则</span><?php endif; ?>
    <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
  </div>
</header>
<?php else: ?>
<header class="pj-list-head">
  <h1 class="pj-list-t"><?= e($page_title) ?></h1>
  <?php if (!empty($sub)): ?><span class="sub"><?= e((string)$sub) ?></span><?php endif; ?>
</header>
<?php endif; ?>

<?php if (!empty($posts)): ?>
<div class="pj-notes">
<?php foreach ($posts as $p):
    $c0   = $p['cats'][0] ?? null;
    $cat  = $c0 ? Poly::termName($c0) : '';
    $catU = $c0 ? Poly::termUrl('category', $c0) : '';
    $u    = $base . e((string)$p['slug']) . '.html';
    $dt   = strtotime((string)($p['published_at'] ?? $p['created_at']));
    $raw  = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
    $exc  = $pjX($raw);
?>
  <article class="pj-note">
    <span class="pj-mark" aria-hidden="true">✎</span>
    <div class="pj-note-b">
      <h2 class="pj-note-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
      <?php if ($exc !== ''): ?><p class="pj-note-x"><?= e($exc) ?></p><?php endif; ?>
      <div class="pj-note-m">
        <time datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y年m月d日', $dt)) ?></time>
        <?php if ($cat !== '' && $catU !== ''): ?><span class="pj-dot">·</span><a class="pj-note-cat" href="<?= e($catU) ?>"><?= e($cat) ?></a><?php endif; ?>
      </div>
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
