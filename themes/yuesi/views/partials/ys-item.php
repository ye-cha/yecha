<?php
/** 单条 post-item（跃思 Yuesi · 扁平纯列表项）
 *  结构：第一行 标题（左）+ 日期（右）；第二行 灰色摘要。
 *  无卡片、无边框、无图片、无阴影。
 *  $p（必须） $base（默认 /） $q（可选：搜索关键词，命中高亮） $hide_date（可选） */
$p    = $p ?? [];
$base = (string)($base ?? '/');
$q    = (string)($q ?? '');
$hide = !empty($hide_date);

$slug  = rawurlencode((string)($p['slug'] ?? ''));
$url   = $base . $slug . '.html';
$title = (string)($p['title'] ?? '');
$dt    = strtotime((string)($p['published_at'] ?? $p['created_at'] ?? $p['d'] ?? 'now'));

$raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
$exc = Str::plain(Str::excerpt(Str::cleanMd($raw), 130));
if ($q !== '') { $exc = Str::snippet($exc, $q); }
?>
<article class="ys-item">
  <div class="ys-item-top">
    <h2 class="ys-item-t"><a href="<?= e($url) ?>"><?= $q !== '' ? Str::mark($title, $q) : e($title) ?></a></h2>
    <?php if (!$hide): ?>
    <time class="ys-item-d" datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y-m-d', $dt)) ?></time>
    <?php endif; ?>
  </div>
  <?php if ($exc !== ''): ?>
  <p class="ys-item-x summary"><?= $q !== '' ? Str::mark($exc, $q) : e($exc) ?></p>
  <?php endif; ?>
</article>
