<?php
/**
 * ChaWiki · 索引页（分类总览 / 标签总览）
 * 变量：$type(category|tag) $items $page_title $sub $hint
 *
 * ★★ P3（2026-09-18）：本页是「索引页」—— v2 下**保留满高左栏**（layout.php 读 YC_P3_KIND）。
 *    · 分类索引：13 类按设计 §③-2(2) **分 4 组**呈现，每卡补 3 条词条样本（入口 13 → 39，判据 A17）
 *    · 标签索引：设计 §③-2(3) R1 —— 只列 **count≥2 的 1,173 个**（内核 Front::metaIndex 限了
 *      LIMIT 400 ⇒ 这里自己查一次），并按**拼音首字母** <details> 分组（零 JS，判据 A17 ≥1,000）
 *    v1 分支原样保留。
 */
require_once __DIR__ . '/../../inc.php';
/* ★ P3：页型 = 索引页（分类索引 / 标签索引） */
$GLOBALS['YC_P3_KIND'] = 'index';
$cwV2 = chawiki_v2();
$lp = chawiki_prefix();
$isCat = ($type ?? '') === 'category';
$catDesc = [
    'tea-type' => '绿茶、红茶、乌龙、黑茶、白茶、黄茶等茶类词条',
    'region'   => '各省产区、山头、村寨与地理环境档案',
    'company'  => '茶企与品牌：历史、代表产品与特色',
    'variety'  => '茶树品种与名丛资源',
    'people'   => '茶人、制茶师与茶史人物',
    'custom'   => '茶俗、礼俗与民俗茶事',
    'review'   => '评茶：审评方法、术语与品质特征',
    'health'   => '茶与健康：成分、功效与饮用禁忌',
    'history'  => '茶史：源流、断代与文献',
    'craft'    => '制茶工艺：杀青、揉捻、发酵、焙火等',
    'storage'  => '仓储与收藏：存放、陈化与辨伪',
    'brew'     => '冲泡：水温、器具、冲泡手法',
    'tool'     => '器具：壶、盖碗、杯与配件',
];

/* ---- v2 专用数据（分类：4 组 + 每类 3 条样本；标签：count≥2 全量 + 拼音首字母） ---- */
$cwGroups = [];
$cwTagGroups = [];
$cwTagTotal = 0;
if ($cwV2 && $isCat && function_exists('chawiki_cat_groups')) {
    $cwGroups = chawiki_cat_groups();
    /* ★ 样本来源 = 既有左栏树的**块缓存**（chawiki_left_tree，每页 0 条新增 SQL），每类取前 3 条。
       ⇒ 分类索引页保持「0 条新增 SQL」这条既有成果（与设计 §③-8 一致）。 */
    $__useT = Poly::on() && Lang::short() !== Poly::defaultLang();
    $__tree2 = chawiki_left_tree(Lang::short(), $__useT);
    $__samp = [];
    foreach ($__tree2 as $__g) {
        $__arr = [];
        foreach ((array)($__g['items'] ?? []) as $__it) { if (count($__arr) >= 3) { break; } $__arr[] = $__it; }
        $__samp[(string)($__g['slug'] ?? '')] = $__arr;
    }
    foreach ($cwGroups as $gi => $g) {
        foreach ((array)$g['cats'] as $ci => $c) {
            $cwGroups[$gi]['cats'][$ci]['samples'] = $__samp[(string)$c['slug']] ?? [];
        }
    }
}
if ($cwV2 && !$isCat) {
    $rows = Db::rows('SELECT id, name, slug, `count` FROM ' . Db::t('metas') . " WHERE type='tag' AND `count`>=2 ORDER BY `count` DESC, id ASC LIMIT 1500");
    foreach ($rows as $r) {
        $ini = chawiki_pinyin_initial((string)$r['name']);
        $cwTagGroups[$ini][] = ['name' => (string)$r['name'], 'slug' => (string)$r['slug'], 'n' => (int)$r['count']];
    }
    ksort($cwTagGroups, SORT_STRING);
    foreach ($cwTagGroups as $k => $v) { $cwTagTotal += count($v); }
}
?>
<nav class="breadcrumb">
  <a href="<?= e($lp . '/') ?>"><?= e(__('nav.home')) ?></a>
  <span class="sep">/</span>
  <span class="cur"><?= e((string)$page_title) ?></span>
</nav>
<header class="list-head">
  <h1 class="article-title"><?= e((string)$page_title) ?></h1>
  <?php if (!empty($sub)): ?><p class="list-sub"><?= e((string)$sub) ?></p><?php endif; ?>
</header>
<?php if (!empty($hint)): ?><p class="idx-hint"><?= e((string)$hint) ?></p><?php endif; ?>

<?php if ($cwV2 && $isCat && $cwGroups): ?>
<?php /* ★ 分类索引（设计 §③-5 线框 ⑥-5）：4 组 × 13 卡，每卡「计数 + 说明 + 3 条样本」= 39 个 /entry/ 入口 */ ?>
<div class="cat-groups">
  <?php foreach ($cwGroups as $g): ?>
  <section class="cat-group">
    <h2 class="cg-head"><?= e((string)$g['name']) ?><span class="cg-n"><?= number_format((int)$g['n']) ?> 条</span></h2>
    <div class="cat-grid">
      <?php foreach ((array)$g['cats'] as $it): $sl = (string)($it['slug'] ?? ''); ?>
      <div class="cat-card cat-card-v2">
        <a class="cc-top" href="<?= e(Poly::termUrl('category', $it)) ?>">
          <span class="cc-name"><?= e((string)$it['name']) ?></span>
          <span class="cc-n"><?= number_format((int)$it['n']) ?></span>
        </a>
        <?php if (isset($catDesc[$sl])): ?><span class="cc-desc"><?= e($catDesc[$sl]) ?></span><?php endif; ?>
        <?php if (!empty($it['samples'])): ?>
        <span class="cc-samp">
          <?php foreach ((array)$it['samples'] as $sp): ?>
          <a href="<?= e($lp . chawiki_entry_url((string)$sp['slug'])) ?>"><?= e(Str::excerpt((string)$sp['title'], 14)) ?></a>
          <?php endforeach; ?>
        </span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>
<?php elseif ($cwV2 && !$isCat && $cwTagGroups): ?>
<?php /* ★ 标签索引（设计 §③-5 线框 ⑥-6 + §③-2(3) R1/R2）：
     · 只列 count≥2 的全部标签（R1）；count=1 的约 7,700 个**不进任何导航**（R2）
     · 按拼音首字母 <details> 分组，默认收起（零 JS） */ ?>
<p class="tag-idx-note">共 <b><?= number_format((int)$cwTagTotal) ?></b> 个标签（出现 ≥2 次的）· 另有约 7,700 个仅出现 1 次的标签，不在此索引；它们仍可在词条页的标签上点进去。</p>
<div class="tag-groups">
  <?php foreach ($cwTagGroups as $ini => $list): ?>
  <details class="tag-group">
    <summary class="tg-sum"><?= e((string)$ini) ?><span class="tg-n"><?= count($list) ?></span></summary>
    <div class="term-bar tg-bar">
      <?php foreach ($list as $it): ?>
      <a class="term-chip" href="<?= e(Poly::termUrl('tag', $it)) ?>"><?= e((string)$it['name']) ?><span class="n"><?= (int)$it['n'] ?></span></a>
      <?php endforeach; ?>
    </div>
  </details>
  <?php endforeach; ?>
</div>
<?php elseif (!empty($items)): ?>
<?php /* ---- v1 原路径（原样保留） ---- */ ?>
<?php if ($isCat): ?>
<div class="cat-grid">
  <?php foreach ($items as $it): $sl = (string)($it['slug'] ?? ''); ?>
  <a class="cat-card" href="<?= e(Poly::termUrl('category', $it)) ?>">
    <span>
      <span class="cc-name"><?= e((string)$it['name']) ?></span>
      <?php if (isset($catDesc[$sl])): ?><span class="cc-desc"><?= e($catDesc[$sl]) ?></span><?php endif; ?>
    </span>
    <span class="cc-n"><?= number_format((int)$it['count']) ?></span>
  </a>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="term-bar">
  <?php foreach ($items as $it): ?>
  <a class="term-chip" href="<?= e(Poly::termUrl('tag', $it)) ?>"><?= e((string)$it['name']) ?><span class="n"><?= (int)$it['count'] ?></span></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php else: ?>
<div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
<?php endif; ?>
