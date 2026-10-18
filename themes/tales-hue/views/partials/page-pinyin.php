<?php
/**
 * Tales · 拼音索引页（作为 page 使用：后台建一个 slug=pinyin 的页面即出现 /pinyin/）
 * 结构：索引页头（含 拼音索引 ⇄ 标签 页签）→ A–Z 字母条（锚点）→ 按首字母分组的全部故事
 */
require_once __DIR__ . '/../../lib.php';

$lp = (string)(Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : '');
$L  = (Lang::short() === 'en');
$T_ = function (string $zh, string $en) use ($L): string { return $L ? $en : $zh; };
$GLOBALS['YC_TALES_LIST'] = true;

$rows = Db::rows(
    'SELECT c.id, c.slug, c.title, COALESCE(c.published_at, c.created_at) AS dt FROM ' . Db::t('contents') . ' c '
    . "WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL "
    . 'ORDER BY c.title ASC'
);
$enTitles = [];
if ($L && $rows) {
    $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    foreach (Db::rows('SELECT group_id, title FROM ' . Db::t('poly_contents') . " WHERE lang='en' AND status='publish' AND group_id IN ($in)", $ids) as $er) {
        $enTitles[(int)$er['group_id']] = (string)$er['title'];
    }
}
/* 按首字母分组（用中文标题定首字母，保证分组稳定） */
$groups = [];
foreach ($rows as $r) {
    $cid = (int)$r['id'];
    $initial = tales_initial((string)$r['title']);
    $groups[$initial][] = [
        'slug' => (string)$r['slug'],
        'title' => ($L && isset($enTitles[$cid])) ? $enTitles[$cid] : (string)$r['title'],
        'dt' => substr((string)$r['dt'], 0, 10),
        'cat' => tales_cat_of($cid),
    ];
}
ksort($groups);
$letters = array_merge(range('A', 'Z'), ['#']);

echo View::render('partials/idx-head', [
    'idx_active' => 'pinyin',
    'idx_title'  => $T_('故事索引', 'Story index'),
    'idx_lead'   => sprintf($T_('共 %d 则 · 按标题拼音 A–Z 排列；也可切换到标签浏览。', '%d stories, sorted A–Z by title pinyin; tags are one click away.'), count($rows)),
]);
?>
<div class="az">
  <a class="all" href="#top"><?= e($T_('全部', 'All')) ?></a>
  <?php foreach ($letters as $l): ?>
    <?php if (isset($groups[$l])): ?><a href="#L<?= e($l) ?>"><?= e($l) ?></a>
    <?php else: ?><span class="l"><?= e($l) ?></span><?php endif; ?>
  <?php endforeach; ?>
</div>

<?php if (!$groups): ?>
<div class="empty"><?= e($T_('还没有故事。', 'No stories yet.')) ?></div>
<?php else: foreach ($groups as $letter => $items): ?>
<section class="grp" id="L<?= e((string)$letter) ?>">
  <h3><?= e((string)$letter) ?> <span><?= count($items) ?> <?= e($T_('则', 'stories')) ?></span></h3>
  <ul>
    <?php foreach ($items as $it): ?>
    <li>
      <a href="<?= e($lp . '/' . rawurlencode($it['slug']) . '.html') ?>" style="--c:<?= e($it['cat']['color'] ?? 'var(--acc)') ?>">
        <span class="c"></span><span class="t"><?= e($it['title']) ?></span>
        <span class="m"><?php if ($it['cat']): ?><em><?= e($L && trim((string)$it['cat']['name_en']) !== '' ? (string)$it['cat']['name_en'] : (string)$it['cat']['name']) ?></em> · <?php endif; ?><?= e($it['dt']) ?></span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; endif; ?>
