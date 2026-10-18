<?php
/** 英文界面专用侧栏：搜索 / 博主信息 / Latest English posts（分类/标签/归档暂为中文数据域，英文侧不展示） */
$bio = (string)get_option('blogger.bio_en', '');
if ($bio === '') { $bio = 'A quiet place to write and taste tea.'; }
$nick = trim((string)get_option('blogger.nick_en', ''));
if ($nick === '') { $nick = trim((string)get_option('blogger.nick', '')); }
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

<?php if (!empty($posts)): ?>
<div class="side-box">
  <h4><a href="/en/"><?= e(__('sb.latest')) ?></a></h4>
  <ul class="side-list">
    <?php foreach ($posts as $p): ?>
    <li><a href="/en/<?= e((string)$p['slug']) ?>.html"><?= e(Str::excerpt($p['title'], 40)) ?><span class="d"><?= e(lang_date(strtotime((string)($p['published_at'] ?? $p['updated_at'])))) ?></span></a></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
