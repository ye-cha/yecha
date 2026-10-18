<?php
/** 案台 Antai —— 搜索页：「查找器窗口」+ 窗口内结果列表
 *  $q,$posts */
$lp = ui_lp();
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
if (!function_exists('at_exc')) {
    /** 案台摘要：cleanMd 之后再清行内 --- 分割线、> 引用符等残留 */
    function at_exc(string $raw, int $len = 88): string {
        $t = Str::cleanMd($raw);
        $t = (string)preg_replace('/\s*[-*_]{3,}\s*/u', ' ', $t);
        $t = (string)preg_replace('/\s+>\s+/u', ' ', $t);
        $t = (string)preg_replace('/\s*\[!?\[[^\]]*$/', '', $t);   // 截断的嵌套图片
        $t = (string)preg_replace('/!?\[([^\]]*)\]\([^)]*$/', '$1', $t); // 截断的链接留文字
        return trim(Str::plain(Str::excerpt($t, $len)));
    }
}
?>
<div class="at-page">
<div class="at-win">
  <div class="at-win-bar">
    <span class="at-dots" aria-hidden="true"><i class="at-dot at-dot-r"></i><i class="at-dot at-dot-y"></i><i class="at-dot at-dot-g"></i></span>
    <span class="at-win-title"><?= e(__('sr.title')) ?></span>
    <span class="at-win-x" aria-hidden="true">×</span>
  </div>
  <div class="at-win-body">
    <form class="at-search" method="get" action="<?= e($lp . '/search') ?>" role="search">
      <span class="at-search-ico" aria-hidden="true">🔍</span>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('sr.ph')) ?>" aria-label="<?= e(__('sr.ph')) ?>">
      <button type="submit"><?= e(__('sr.btn')) ?></button>
    </form>

    <?php if ($q !== ''): ?>
      <p class="at-result"><?= e(sprintf(__('sr.result'), $q, count($posts))) ?></p>
      <?php if (!empty($posts)): ?>
      <div class="at-files">
      <?php foreach ($posts as $p):
          $u = $base . e((string)$p['slug']) . '.html';
          $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
          $raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
          $exc = at_exc($raw);
      ?>
      <article class="at-file">
        <a class="at-file-a" href="<?= e($u) ?>">
          <span class="at-file-ico" aria-hidden="true">📄</span>
          <span class="at-file-main">
            <span class="at-file-t"><?= e((string)$p['title']) ?></span>
            <?php if ($exc !== ''): ?><span class="at-file-x"><?= e($exc) ?></span><?php endif; ?>
          </span>
          <time class="at-file-d" datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y-m-d', $dt)) ?></time>
        </a>
      </article>
      <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="at-empty"><?= e(__('sr.none')) ?></div>
      <?php endif; ?>
    <?php else: ?>
      <div class="at-empty">输入关键词，在全站文稿中查找。</div>
    <?php endif; ?>
  </div>
</div>
</div>
