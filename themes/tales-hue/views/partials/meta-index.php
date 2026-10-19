<?php
/**
 * Tales · 索引页（/tag/ 与 /category/ 索引）
 * 变量：$type（'category'|'tag'）、$items（含 count 的 metas 行）、$page_title、$sub
 * 标签页与拼音索引同一版式（页签互切、不同 URL）；分类页渲染八大类总览。
 */
require_once __DIR__ . '/../../lib.php';
$L  = (Lang::short() === 'en');
$T_ = function (string $zh, string $en) use ($L): string { return $L ? $en : $zh; };
$lp = (string)(Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : '');
$type = (string)($type ?? 'tag');
$items = is_array($items ?? null) ? $items : [];
$GLOBALS['YC_TALES_LIST'] = true;

$termName = function (array $r) use ($L): string {
    return ($L && trim((string)($r['name_en'] ?? '')) !== '') ? (string)$r['name_en'] : (string)$r['name'];
};

if ($type === 'category') {
    /* 分类索引：八大类色块 */
    echo View::render('partials/idx-head', [
        'idx_active' => 'tags',
        'idx_title'  => $T_('分类', 'Categories'),
        'idx_lead'   => $T_('八大类各有其色；颜色即分类，全站一致。', 'Eight categories, each with its own colour.'),
    ]);
    $cats = tales_cats($L);
    echo '<div class="cat8" style="margin-top:6px">';
    foreach ($cats as $i => $c) {
        echo '<a href="' . e($lp . '/category/' . rawurlencode($c['slug']) . '/') . '" style="background:' . e($c['color']) . '">'
            . '<span class="no">' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) . '</span>'
            . '<h3>' . e($c['name']) . '</h3>'
            . '<span class="n">' . ((int)$c['count'] > 0 ? (int)$c['count'] . ' ' . e($T_('则', 'stories')) : e($T_('征稿中', 'open'))) . '</span>'
            . '<span class="go">→</span></a>';
    }
    echo '</div>';
    return;
}

/* 标签索引：按拼音首字母分组 */
$groups = [];
foreach ($items as $r) {
    if ((int)($r['count'] ?? 0) <= 0) { continue; }
    $groups[tales_initial((string)$r['name'])][] = $r;
}
ksort($groups);
$letters = array_merge(range('A', 'Z'), ['#']);

echo View::render('partials/idx-head', [
    'idx_active' => 'tags',
    'idx_title'  => $T_('标签', 'Tags'),
    'idx_lead'   => sprintf($T_('共 %d 个标签 · 与拼音索引同一版式，两个网址之间可随时切换。', '%d tags · same layout as the pinyin index, one click apart.'), count($items)),
]);
?>
<div class="az">
  <a class="all" href="#top"><?= e($T_('全部', 'All')) ?></a>
  <?php foreach ($letters as $l): ?>
    <?php if (isset($groups[$l])): ?><a href="#T<?= e($l) ?>"><?= e($l) ?></a>
    <?php else: ?><span class="l"><?= e($l) ?></span><?php endif; ?>
  <?php endforeach; ?>
  <span class="ctrl"><?= e($T_('按首字母分组 · 组内按热度', 'Grouped by initial, sorted by count')) ?></span>
</div>

<?php if (!$groups): ?>
<div class="empty"><?= e($T_('还没有标签。', 'No tags yet.')) ?></div>
<?php else: foreach ($groups as $letter => $rows): ?>
<section class="tagblk" id="T<?= e((string)$letter) ?>">
  <h3><?= e((string)$letter) ?> <span><?= count($rows) ?> <?= e($T_('个', 'tags')) ?></span></h3>
  <div class="taglist">
    <?php foreach ($rows as $r):
        $cat = null;
        foreach (tales_cats(false) as $c) { /* 标签不绑定分类，用中性色 */ }
    ?>
    <a href="<?= e(Poly::termUrl('tag', $r)) ?>" style="--c:var(--ink-3)"><?= e($termName($r)) ?> <i><?= (int)$r['count'] ?></i></a>
    <?php endforeach; ?>
  </div>
</section>
<?php endforeach; endif; ?>
