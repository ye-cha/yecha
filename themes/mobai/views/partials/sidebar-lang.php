<?php
/** 非默认语言界面专用侧栏：搜索 / 博主信息 / Latest posts in this language
 * （分类/标签/归档为基准语言内容域，译文版侧栏只展示该语言内容）
 * 入参：$posts（Poly::publishedList(lang) 行） */
$lang = Lang::short();
// 博主简介/昵称：优先该语言专属文案（blogger.bio_<lang>/nick_<lang>），缺失回落默认语言
$bio = (string)get_option('blogger.bio_' . $lang, '');
$bioZh = (string)get_option('blogger.bio', '');
if ($bio === '' || $bio === '安静写字，认真喝茶。') { $bio = $bioZh; }
$nick = trim((string)get_option('blogger.nick_' . $lang, ''));
if ($nick === '') { $nick = trim((string)get_option('blogger.nick', '')); }
?>
<div class="side-box">
  <h4><?= e(__('sb.search')) ?></h4>
  <form class="side-search" method="get" action="<?= e(ui_lp() . '/search') ?>" role="search">
    <input type="search" name="q" placeholder="<?= e(__('sb.search_ph')) ?>" aria-label="<?= e(__('sb.search_aria')) ?>">
    <button type="submit"><?= e(__('sb.go')) ?></button>
  </form>
</div>

<?php if ($bio !== '' || $nick !== ''): ?>
<div class="side-box">
  <h4><?= e(__('sb.blog')) ?></h4>
  <?php if ($nick !== ''): ?><div class="side-nick" style="font-weight:700;margin-bottom:6px;"><?= e($nick) ?></div><?php endif; ?>
  <?php if ($bio !== ''): ?><div class="side-bio"><?= e(Str::excerpt($bio, 60)) ?></div><?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($posts)): ?>
<div class="side-box">
  <h4><a href="<?= e(ui_lp() . '/') ?>"><?= e(__('sb.latest')) ?></a></h4>
  <ul class="side-list">
    <?php foreach ($posts as $p): ?>
    <li><a href="<?= e(ui_lp() . '/' . (string)$p['slug']) ?>.html"><?= e(Str::excerpt($p['title'], 40)) ?><span class="d"><?= e(lang_date(strtotime((string)($p['published_at'] ?? $p['updated_at'])))) ?></span></a></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
