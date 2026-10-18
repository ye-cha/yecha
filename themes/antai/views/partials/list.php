<?php
/** 案台 Antai —— 列表（分类 / 标签 / 归档月）：「文件夹窗口」
 *  窗口标题栏（●●● + 标题 + ×）+ 窗口内的文件列表（每行：文件小图标 + 标题 + 摘要 + 日期）
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$description,$total_items,$empty */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
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
    <span class="at-win-title"><?= e($page_title) ?></span>
    <span class="at-win-x" aria-hidden="true">×</span>
  </div>
  <div class="at-win-body">
    <div class="at-head">
      <h1 class="at-head-t"><?= e($page_title) ?></h1>
      <?php if (!empty($description)): ?><p class="at-head-d"><?= e($description) ?></p><?php endif; ?>
      <div class="at-head-m">
        <?php if (!empty($total_items)): ?><span class="at-badge"><?= (int)$total_items ?> 项</span><?php endif; ?>
        <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
      </div>
    </div>

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

    <?php if ($total_pages > 1): ?>
    <div class="pager">
      <?php if ($page > 1): ?><a href="<?= e(str_replace('{n}', (string)($page - 1), $pager_base)) ?>"><?= e(__('ui.prev')) ?></a><?php endif; ?>
      <span class="cur"><?= $page ?> / <?= $total_pages ?></span>
      <?php if ($page < $total_pages): ?><a href="<?= e(str_replace('{n}', (string)($page + 1), $pager_base)) ?>"><?= e(__('ui.next')) ?></a><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="at-empty"><?= e(!empty($empty) ? $empty : __('list.empty')) ?></div>
    <?php endif; ?>
  </div>
</div>
</div>
