<?php
/** 侧栏：搜索 / 博主信息 / 分类 / 最新文章 / 标签 / 归档；入参 $latest,$cats,$tags,$months */
$en = Lang::isEn();
$bio = (string)get_option('blogger.bio', '');
if ($en) {
    $bioEn = (string)get_option('blogger.bio_en', '');
    if ($bioEn !== '') { $bio = $bioEn; }
    elseif ($bio === '' || $bio === '安静写字，认真喝茶。') { $bio = 'A quiet place to write and taste tea.'; }
}
$nick = trim((string)get_option('blogger.nick', ''));
if ($en) {
    $nickEn = trim((string)get_option('blogger.nick_en', ''));
    if ($nickEn !== '') { $nick = $nickEn; }
}
?>
<div class="side-box">
  <h4><?= e(__('sb.search')) ?></h4>
  <form class="side-search" method="get" action="/search" role="search">
    <input type="search" name="q" placeholder="<?= e(__('sb.search_ph')) ?>" aria-label="<?= e(__('sb.search_aria')) ?>">
    <button type="submit"><?= e(__('sb.go')) ?></button>
  </form>
</div>

<div class="side-box">
  <h4><?= e(__('sb.blog')) ?></h4>
  <?php if ($nick !== ''): ?><div class="side-nick" style="font-weight:700;margin-bottom:6px;"><?= e($nick) ?></div><?php endif; ?>
  <div class="side-bio"><?= e(Str::excerpt($bio, 60)) ?></div>
</div>

<div class="side-box">
  <h4><a href="<?= e(ui_lp() . '/category/') ?>"><?= e(__('sb.cats')) ?></a></h4>
  <ul class="side-list">
    <?php foreach ($cats as $c): ?>
    <li><a class="side-cat" href="<?= e(Poly::termUrl('category', $c)) ?>"><span><?= e(Poly::termName($c)) ?></span><span><?= (int)$c['count'] ?></span></a></li>
    <?php endforeach; ?>
  </ul>
</div>

<div class="side-box">
  <h4><?= e(__('sb.latest')) ?></h4>
  <ul class="side-list">
    <?php foreach ($latest as $p): ?>
    <li><a href="<?= e(($en ? '/en/' : '/') . (string)$p['slug']) ?>.html"><?= e(Str::excerpt($p['title'], 30)) ?><span class="d"><?= e(lang_date(strtotime((string)$p['dt']))) ?></span></a></li>
    <?php endforeach; ?>
  </ul>
</div>

<?php if ($tags): ?>
<div class="side-box">
  <h4><a href="<?= e(ui_lp() . '/tag/') ?>"><?= e(__('sb.tags')) ?></a></h4>
  <ul class="side-list side-tags">
    <?php foreach ($tags as $t):
        $c = (int)$t['count'];
        $w = $c >= 12 ? 5 : ($c >= 6 ? 4 : ($c >= 3 ? 3 : ($c >= 2 ? 2 : 1)));
    ?>
    <li><a class="tw tw<?= $w ?>" href="<?= e(Poly::termUrl('tag', $t)) ?>"><?= e(Poly::termName($t)) ?></a></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if ($months): ?>
<div class="side-box">
  <h4><?= e(__('sb.arch')) ?></h4>
  <ul class="side-list">
    <?php foreach ($months as $mm): ?>
    <li><a class="side-cat" href="<?= e(ui_lp() . '/archives/' . (int)$mm['y'] . '/' . (int)$mm['m'] . '/') ?>"><span><?= e(lang_month((int)$mm['y'], (int)$mm['m'])) ?></span><span><?= (int)$mm['n'] ?></span></a></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
