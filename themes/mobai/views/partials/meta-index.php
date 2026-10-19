<?php
/** 标签 / 分类索引 · 墨白 Mobai v2（对标 u.sb /tags/ 形态）
 *  形态：居中页头（标题 + 等宽统计）→ 按首字符分组的 chip 云（左 5rem 等宽大字键 + 右 flex 云）。
 *  清单 ⑩：$sub 原文输出（含首页链接 HTML），不做 e() 转义。
 *  手维文件，勿用 tools/gen_parts.py 覆盖。 */
$groups = [];
$total = 0;
foreach (($items ?? []) as $it) {
    $nm = (string)($it['name'] ?? '');
    $first = mb_substr($nm, 0, 1);
    if (preg_match('/[a-zA-Z]/', $first)) { $key = mb_strtoupper($first); }
    elseif (preg_match('/[0-9]/', $first)) { $key = '#'; }
    else { $key = $first; }
    $groups[$key][] = $it;
    $total++;
}
ksort($groups, SORT_STRING);
$sp = '<i class="dot" style="width:3px;height:3px;top:20%;right:18%"></i><i style="width:10px;height:10px;top:14%;right:34%"></i>';
?>
<section class="us-hero">
  <div class="us-sparkles" aria-hidden="true"><?= $sp ?></div>
  <div class="us-hero-in">
    <h1><?= e($page_title) ?></h1>
    <div class="us-meta">
      <?php if (!empty($sub)): ?><?= $sub ?><?php endif; ?>
      <?php if (!empty($hint)): ?><span><?= e($hint) ?></span><?php endif; ?>
      <span><?= $total ?> 项</span>
    </div>
  </div>
</section>

<?php if ($groups): ?>
<div class="us-idx">
  <?php foreach ($groups as $key => $g): ?>
  <section class="us-idx-sec">
    <div class="us-idx-key"><?= e($key) ?></div>
    <div class="us-idx-val">
      <?php foreach ($g as $it): ?>
      <a class="us-chip" href="<?= e(Poly::termUrl($type, $it)) ?>"><?= e((string)$it['name']) ?><i><?= (int)($it['count'] ?? 0) ?></i></a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="us-empty"><?= e(__('ui.empty_none')) ?></div>
<?php endif; ?>
