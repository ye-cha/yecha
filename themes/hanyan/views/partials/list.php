<?php
/** 列表：分类页 / 标签页 / 归档月 / 搜索（寒砚 Hanyan）
 *  结构：页头（标题 + 描述 + 篇数 + 面包屑）→ 标签 chip 墙（带计数）→ 卡片列表（左封面渐变块 + 右 标题/摘要/日期）→ 分页
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$empty,$empty_link,$link_prefix */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';

$hotTags = [];
try {
    if (class_exists('Db', false)) {
        $M = Db::t('metas'); $R = Db::t('relationships');
        $hotTags = Db::rows(
            "SELECT m.id, m.name, m.slug, (SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) AS n"
            . " FROM {$M} m WHERE m.type='tag'"
            . " AND (SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) > 0"
            . " ORDER BY n DESC, m.name ASC LIMIT 12"
        );
    }
} catch (\Throwable $e) { $hotTags = []; }
?>
<div class="hy-head">
  <h1><?= e($page_title) ?></h1>
  <?php if (!empty($description)): ?><p class="hy-head-desc"><?= e($description) ?></p><?php endif; ?>
  <div class="hy-head-meta">
    <?php if (!empty($total_items)): ?><span class="hy-head-count"><?= (int)$total_items ?> 篇</span><?php endif; ?>
    <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
  </div>
</div>

<?php if ($hotTags): ?>
<div class="hy-chipwall">
  <?php foreach ($hotTags as $t): ?>
  <a href="<?= e(Poly::termUrl('tag', $t)) ?>"><?= e((string)$t['name']) ?><i><?= (int)$t['n'] ?></i></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($posts)): ?>
<div class="hy-feed">
<?php $i = 0; foreach ($posts as $p):
    $i++;
    $c0 = $p['cats'][0] ?? null;
    $cat = $c0 ? Poly::termName($c0) : '';
    $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
    $u = $base . e((string)$p['slug']) . '.html';
    $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
    $raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
    $exc = trim(Str::plain(Str::plain(Str::excerpt($raw, 110))));
?>
  <article class="hy-card">
    <a class="hy-cover hy-c<?= (($i - 1) % 6) + 1 ?>" href="<?= e($u) ?>" aria-hidden="true" tabindex="-1"><span><?= e(mb_substr((string)$p['title'], 0, 1)) ?></span></a>
    <div class="hy-card-body">
      <h2 class="hy-card-title"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
      <p class="hy-excerpt"><?= e($exc) ?></p>
      <div class="hy-card-meta">
        <time datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y-m-d', $dt)) ?></time>
        <?php if ($cat !== ''): ?><a class="hy-chip" href="<?= e($catUrl) ?>"><?= e($cat) ?></a><?php endif; ?>
        <span><?= e(__('meta.reads')) ?><?= Str::num((int)($p['views'] ?? 0)) ?></span>
      </div>
    </div>
  </article>
<?php endforeach; ?>
</div>
<?php if ($total_pages > 1): ?>
<div class="pager">
  <?php if ($page > 1): ?><a href="<?= e(str_replace('{n}', (string)($page - 1), $pager_base)) ?>"><?= e(__('ui.prev')) ?></a><?php endif; ?>
  <span><?= $page ?> / <?= $total_pages ?></span>
  <?php if ($page < $total_pages): ?><a href="<?= e(str_replace('{n}', (string)($page + 1), $pager_base)) ?>"><?= e(__('ui.next')) ?></a><?php endif; ?>
</div>
<?php endif;
else:
$emptyTxt = isset($empty) && $empty !== '' ? $empty : __('list.empty'); ?>
<div class="empty"><?= e($emptyTxt) ?><?php if (!empty($empty_link)): ?><br><a href="<?= e($empty_link) ?>"><?= e(__('list.empty_alt')) ?></a><?php endif; ?></div>
<?php endif; ?>
