<?php
/** 皮蛋 Pidan —— 搜索页：窄栏搜索框 + 卡片结果
 *  $q,$posts */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
if (!function_exists('pd_exc')) {
    /** 皮蛋摘要：cleanMd 之后再清行内 --- 分割线、> 引用符等残留 */
    function pd_exc(string $raw, int $len = 110): string {
        $t = Str::cleanMd($raw);
        $t = (string)preg_replace('/\s*[-*_]{3,}\s*/u', ' ', $t);
        $t = (string)preg_replace('/\s+>\s+/u', ' ', $t);
        $t = (string)preg_replace('/\s*\[!?\[[^\]]*$/', '', $t);   // 截断的嵌套图片
        $t = (string)preg_replace('/!?\[([^\]]*)\]\([^)]*$/', '$1', $t); // 截断的链接留文字
        return trim(Str::plain(Str::excerpt($t, $len)));
    }
}
?>
<div class="pd-page">
  <header class="pd-head">
    <h1 class="pd-head-t"><?= e(__('sr.title')) ?></h1>
    <div class="pd-head-m"><span class="sub"><?= e(__('sr.sub')) ?></span></div>
  </header>

  <form class="pd-search" method="get" action="<?= e($lp . '/search') ?>" role="search">
    <svg class="pd-search-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m21 21-4.3-4.3"></path></svg>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('sr.ph')) ?>" aria-label="<?= e(__('sr.ph')) ?>">
    <button type="submit"><?= e(__('sr.btn')) ?></button>
  </form>

  <?php if ($q !== ''): ?>
    <p class="pd-result"><?= e(sprintf(__('sr.result'), $q, count($posts))) ?></p>
    <?php if (!empty($posts)): ?>
    <div class="pd-feed">
    <?php foreach ($posts as $p):
        $c0 = $p['cats'][0] ?? null;
        $cat = $c0 ? Poly::termName($c0) : '';
        $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
        $u = $base . e((string)$p['slug']) . '.html';
        $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
        $raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
        $exc = pd_exc($raw);
        $ptags = array_slice((array)($p['tags'] ?? []), 0, 3);
    ?>
    <article class="pd-card">
      <h2 class="pd-card-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
      <p class="pd-card-x"><?= e($exc) ?></p>
      <div class="pd-card-f">
        <time class="pd-card-d" datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y-m-d', $dt)) ?></time>
        <?php if ($cat !== ''): ?><a class="pd-card-cat" href="<?= e($catUrl) ?>"><?= e($cat) ?></a><?php endif; ?>
        <?php if ($ptags): ?><span class="pd-card-tags">
          <?php foreach ($ptags as $tg): ?><a class="pd-tag" href="<?= e(Poly::termUrl('tag', $tg)) ?>"><?= e(Poly::termName($tg)) ?></a><?php endforeach; ?>
        </span><?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="pd-empty"><?= e(__('sr.none')) ?></div>
    <?php endif; ?>
  <?php endif; ?>
</div>
