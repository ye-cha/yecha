<?php
/** 皮蛋 Pidan —— 列表（分类 / 标签 / 归档月）：居中窄栏卡片流
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$description,$total_items,$empty */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
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
    <h1 class="pd-head-t"><?= e($page_title) ?></h1>
    <?php if (!empty($description)): ?><p class="pd-head-d"><?= e($description) ?></p><?php endif; ?>
    <div class="pd-head-m">
      <?php if (!empty($total_items)): ?><span class="pd-badge"><?= (int)$total_items ?> 篇</span><?php endif; ?>
      <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
    </div>
  </header>

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

  <?php if ($total_pages > 1): ?>
  <div class="pager">
    <?php if ($page > 1): ?><a href="<?= e(str_replace('{n}', (string)($page - 1), $pager_base)) ?>"><?= e(__('ui.prev')) ?></a><?php endif; ?>
    <span class="cur"><?= $page ?> / <?= $total_pages ?></span>
    <?php if ($page < $total_pages): ?><a href="<?= e(str_replace('{n}', (string)($page + 1), $pager_base)) ?>"><?= e(__('ui.next')) ?></a><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php else: ?>
  <div class="pd-empty"><?= e(!empty($empty) ? $empty : __('list.empty')) ?></div>
  <?php endif; ?>
</div>
