<?php
/**
 * Tales · 左侧栏（故事目录 / 栏目 / 标签 / 出处说明）
 * 文章页由 layout 的 <aside class="side"> 渲染，body.tales-left 把它排到左侧（与首页同宽 1280）
 */
$L  = (Lang::short() === 'en');
$T_ = function (string $zh, string $en) use ($L): string { return $L ? $en : $zh; };
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';

$PAL = ['red', 'amber', 'green', 'blue', 'purple', 'ochre'];
$palOf = function (int $cid) use ($PAL): string { return 'var(--c-' . $PAL[$cid % 6] . ')'; };

/* 当前篇：优先用 post.php 设的全局，其次按当前 URL 的 slug 匹配 */
$curCid = (int)($GLOBALS['YC_TALES_CID'] ?? 0);
$curSlug = '';
if ($curCid === 0) {
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (preg_match('#/([^/]+)\.html$#', $path, $m)) { $curSlug = (string)$m[1]; }
} else {
    $curSlug = (string)Db::one('SELECT slug FROM ' . Db::t('contents') . ' WHERE id=?', [$curCid]);
}

$T = Db::t('contents');
$list = Db::rows("SELECT id, slug, title FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT 12");
$cats = Db::rows('SELECT slug, name, name_en, count FROM ' . Db::t('metas') . " WHERE type='category' ORDER BY count DESC, id ASC LIMIT 6");
$tags = Db::rows('SELECT name, slug FROM ' . Db::t('metas') . " WHERE type='tag' AND count>0 ORDER BY count DESC, id DESC LIMIT 12");
?>
<div class="side-blk side-search-box">
  <h4><?= e($T_('搜索', 'SEARCH')) ?></h4>
  <form class="tales-search sm" method="get" action="<?= e($lp . '/search') ?>" role="search">
    <input type="search" name="q" placeholder="<?= e($T_('搜索故事…', 'Search stories…')) ?>" aria-label="<?= e($T_('搜索', 'Search')) ?>">
    <button type="submit"><?= e($T_('搜索', 'Search')) ?></button>
  </form>
</div>
<div class="side-blk">
  <h4><?= e($T_('故事目录', 'STORY INDEX')) ?></h4>
  <ul class="cat-list">
    <?php foreach ($list as $i => $r):
        $isCur = ($curSlug !== '' && (string)$r['slug'] === $curSlug);
        $ttl = (string)$r['title'];
        /* 英文站：若有已发布英文标题则用英文 */
        if ($L && Poly::on()) {
            $en = Db::one('SELECT title FROM ' . Db::t('poly_contents') . " WHERE group_id=? AND lang='en' AND status='publish'", [(int)$r['id']]);
            if (is_string($en) && $en !== '') { $ttl = $en; }
        }
    ?>
    <li>
      <a class="<?= $isCur ? 'on' : '' ?>" href="<?= e($lp . '/' . rawurlencode((string)$r['slug']) . '.html') ?>">
        <span class="n"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
        <span class="c" style="--c:<?= $palOf((int)$r['id']) ?>"></span><?= e($ttl) ?>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
</div>

<div class="side-blk">
  <h4><?= e($T_('栏目', 'COLUMNS')) ?></h4>
  <ul class="cat-list">
    <?php foreach ($cats as $i => $c):
        $nm = ($L && trim((string)$c['name_en']) !== '') ? (string)$c['name_en'] : (string)$c['name'];
        $col = ['var(--c-red)', 'var(--c-blue)', 'var(--c-amber)', 'var(--c-green)', 'var(--c-purple)', 'var(--c-ochre)'][$i % 6];
    ?>
    <li><a href="<?= e($lp . '/category/' . rawurlencode((string)$c['slug']) . '/') ?>"><span class="c" style="--c:<?= $col ?>"></span><?= e($nm) ?><span class="n" style="margin-left:auto"><?= (int)$c['count'] ?></span></a></li>
    <?php endforeach; ?>
  </ul>
</div>

<?php if ($tags): ?>
<div class="side-blk">
  <h4><?= e($T_('标签', 'TAGS')) ?></h4>
  <div class="side-tags">
    <?php foreach ($tags as $i => $t): ?><?= $i ? '<i>·</i>' : '' ?><a href="<?= e(Poly::termUrl('tag', $t)) ?>"><?= e(Poly::termName($t)) ?></a><?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="side-blk">
  <div class="side-note"><?= e($T_(
      '关于出处：口传与文献分开标注；同一故事的异文版本会在文末列出。',
      'Sources: oral and written traditions are marked separately; variants are listed at the end.'
  )) ?></div>
</div>
