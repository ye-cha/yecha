<?php
/** 派记 Paiji —— 搜索页：搜索框 + 同款笔记列表结果
 *  $q,$posts */
$lp = ui_lp();
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
?>
<header class="pj-list-head">
  <h1 class="pj-list-t"><?= e(__('sr.title')) ?></h1>
  <p class="pj-list-desc"><?= e(__('sr.sub')) ?></p>
</header>

<form class="pj-search" method="get" action="<?= e($lp . '/search') ?>" role="search">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('sr.ph')) ?>" aria-label="<?= e(__('sr.ph')) ?>">
  <button type="submit"><?= e(__('sr.btn')) ?></button>
</form>

<?php if ($q !== ''): ?>
<p class="pj-search-sum">“<?= e($q) ?>” · <?= count($posts) ?> 条结果</p>
  <?php if (!empty($posts)): ?>
  <div class="pj-notes">
  <?php foreach ($posts as $p):
      $c0  = $p['cats'][0] ?? null;
      $cat = $c0 ? Poly::termName($c0) : '';
      $u   = $base . e((string)$p['slug']) . '.html';
      $dt  = strtotime((string)($p['published_at'] ?? $p['created_at']));
      $exc = $pjX((string)($p['content'] ?? ''));
  ?>
  <article class="pj-note">
    <span class="pj-mark" aria-hidden="true">✎</span>
    <div class="pj-note-b">
      <h2 class="pj-note-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
      <?php if ($exc !== ''): ?><p class="pj-note-x"><?= e($exc) ?></p><?php endif; ?>
      <div class="pj-note-m">
        <time datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y年m月d日', $dt)) ?></time>
        <?php if ($cat !== ''): ?><span class="pj-dot">·</span><span class="pj-note-cat"><?= e($cat) ?></span><?php endif; ?>
      </div>
    </div>
  </article>
  <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="empty"><?= e(__('sr.none')) ?></div>
  <?php endif; ?>
<?php endif; ?>
