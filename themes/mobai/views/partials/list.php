<?php
/** 列表（首页 / 分类 / 标签 / 搜索 / 归档）· 墨白 Mobai v2（对标 u.sb）
 *  形态：居中页头（内页）→ 两列卡片网格（首篇横跨大卡）→ 圆形页码分页。
 *  清单对齐：⑨ 无 astra 残留且非空；⑩ $sub 原文输出（含首页链接）；⑪ 摘要去 Markdown、无 read-more 双箭头
 *  （u.sb 卡整卡可点，本身没有 read more，故直接不输出 more，从根上消除双箭头）。
 *  手维文件，勿用 tools/gen_parts.py 覆盖。 */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
$isHome = !empty($is_home);
$sp = '<i class="dot" style="width:3px;height:3px;top:48%;right:13%"></i><i class="dot" style="width:2px;height:2px;top:66%;right:32%"></i>'
    . '<i style="width:12px;height:12px;top:58%;right:22%"></i><i style="width:10px;height:10px;top:14%;right:34%"></i>';
?>
<?php if (!$isHome): ?>
<section class="us-hero">
  <div class="us-sparkles" aria-hidden="true"><?= $sp ?></div>
  <div class="us-hero-in">
    <h1><?= e($page_title) ?></h1>
    <?php if (!empty($sub)): ?><div class="us-meta"><?= $sub ?></div><?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if (!empty($posts)): ?>
<div class="us-grid">
<?php foreach ($posts as $i => $p):
    $u   = $base . e((string)$p['slug']) . '.html';
    $ts  = strtotime((string)($p['published_at'] ?? $p['created_at']));
    $raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
    $exc = Str::excerpt(Str::plain(Str::plain($raw)), 110);
    $c0  = $p['cats'][0] ?? null;
    $cat = $c0 ? Poly::termName($c0) : '';
    $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
    $tg  = $p['tags'] ?? [];
    $lead = ($i === 0 && (int)($page ?? 1) === 1);
?>
  <article class="us-card<?= $lead ? ' lead' : '' ?>">
    <h2><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
    <div class="us-m">
      <time datetime="<?= e(date('Y-m-d', $ts)) ?>"><?= e(date('Y-m-d', $ts)) ?></time>
      <?php if ($cat !== ''): ?><span><a href="<?= e($catUrl) ?>"><?= e($cat) ?></a></span><?php endif; ?>
      <span><?= e(__('meta.reads')) ?><?= Str::num((int)($p['views'] ?? 0)) ?></span>
    </div>
    <p class="us-x"><?= e($exc) ?></p>
    <?php if (!empty($tg)): ?>
    <div class="us-tags">
      <?php foreach (array_slice($tg, 0, 6) as $t): ?>
      <a class="us-chip" href="<?= e(Poly::termUrl('tag', $t)) ?>"><?= e(Poly::termName($t)) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </article>
<?php endforeach; ?>
</div>

<?php if ($total_pages > 1): ?>
<div class="us-pager">
  <?php if ($page > 1): ?><a class="wide" href="<?= e(str_replace('{n}', (string)($page - 1), $pager_base)) ?>"><?= e(__('ui.prev')) ?></a><?php endif; ?>
  <?php
    $win = 2; $from = max(1, $page - $win); $to = min($total_pages, $page + $win);
    if ($from > 1) { echo '<a class="num" href="' . e(str_replace('{n}', '1', $pager_base)) . '">1</a>'; if ($from > 2) { echo '<span class="gap">…</span>'; } }
    for ($n = $from; $n <= $to; $n++) {
        if ($n === (int)$page) { echo '<b class="num">' . $n . '</b>'; }
        else { echo '<a class="num" href="' . e(str_replace('{n}', (string)$n, $pager_base)) . '">' . $n . '</a>'; }
    }
    if ($to < $total_pages) { if ($to < $total_pages - 1) { echo '<span class="gap">…</span>'; } echo '<a class="num" href="' . e(str_replace('{n}', (string)$total_pages, $pager_base)) . '">' . $total_pages . '</a>'; }
  ?>
  <?php if ($page < $total_pages): ?><a class="wide" href="<?= e(str_replace('{n}', (string)($page + 1), $pager_base)) ?>"><?= e(__('ui.next')) ?></a><?php endif; ?>
</div>
<?php endif;

else:
$emptyTxt = isset($empty) && $empty !== '' ? $empty : __('list.empty'); ?>
<div class="us-empty"><?= e($emptyTxt) ?><?php if (!empty($empty_link)): ?><br><a href="<?= e($empty_link) ?>"><?= e(__('list.empty_alt')) ?></a><?php endif; ?></div>
<?php endif; ?>
