<?php
/** 品云 Pinyun —— 搜索页：搜索框 + 卡片网格结果
 *  $q,$posts */
$lp = ui_lp();
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
?>
<section class="py-cat-banner py-cat-banner-idx">
  <div class="py-cat-in">
    <h1 class="py-cat-title"><?= e(__('sr.title')) ?></h1>
    <p class="py-cat-desc"><?= e(__('sr.sub')) ?></p>
  </div>
</section>

<form class="py-searchbar" method="get" action="<?= e($lp . '/search') ?>" role="search">
  <svg class="py-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m21 21-4.3-4.3"></path></svg>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('sr.ph')) ?>" aria-label="<?= e(__('sr.ph')) ?>">
  <button type="submit"><?= e(__('sr.btn')) ?></button>
</form>

<?php if ($q !== ''): ?>
<p class="py-search-sum">“<?= e($q) ?>” · <?= count($posts) ?> 条结果</p>
  <?php if (!empty($posts)): ?>
  <div class="py-grid py-grid-list">
  <?php $i = 0; foreach ($posts as $p):
      $i++;
      $c0  = $p['cats'][0] ?? null;
      $cat = $c0 ? Poly::termName($c0) : '';
      $u   = $base . e((string)$p['slug']) . '.html';
      $dt  = strtotime((string)($p['published_at'] ?? $p['created_at']));
      $exc = $pyX((string)($p['content'] ?? ''));
      $h   = $hue($i);
      $ini = mb_substr(trim((string)$p['title']), 0, 1);
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
  <?php else: ?>
  <div class="empty"><?= e(__('sr.none')) ?></div>
  <?php endif; ?>
<?php endif; ?>
