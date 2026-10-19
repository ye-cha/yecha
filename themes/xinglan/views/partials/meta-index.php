<?php
/** 标签 / 分类索引（星澈 Xinglan · chip 墙 + 每个标签下的文章列表）
 *  $type(category|tag) $items $page_title $sub $hint */
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';

// 取计数最高的若干条目，列出其下最新文章
$top = [];
if (!empty($items)) {
    $rank = $items;
    usort($rank, function ($a, $b) { return (int)($b['count'] ?? 0) <=> (int)($a['count'] ?? 0); });
    $rank = array_slice($rank, 0, 6);
    try {
        if (class_exists('Db', false)) {
            $T = Db::t('contents'); $R = Db::t('relationships');
            foreach ($rank as $it) {
                $mid = (int)($it['id'] ?? 0);
                $rows = $mid > 0 ? Db::rows(
                    "SELECT c.title, c.slug, COALESCE(c.published_at, c.created_at) AS d"
                    . " FROM {$T} c JOIN {$R} r ON r.content_id=c.id"
                    . " WHERE r.meta_id={$mid} AND c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
                    . " ORDER BY d DESC LIMIT 4"
                ) : [];
                if ($rows) { $top[] = ['it' => $it, 'posts' => $rows]; }
            }
        }
    } catch (\Throwable $e) { $top = []; }
}
?>
<div class="xl-mi">
  <div class="xl-mi-head">
    <h1 class="xl-mi-title"><?= e($page_title) ?></h1>
    <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
  </div>
  <?php if (!empty($hint)): ?><p class="xl-mi-hint"><?= e($hint) ?></p><?php endif; ?>

  <?php if ($items): ?>
  <div class="xl-chipwall">
    <?php foreach ($items as $it):
      $c = (int)$it['count'];
      $sz = $c >= 8 ? ' xl-chipwall-b' : ($c >= 4 ? ' xl-chipwall-m' : '');
    ?>
      <a class="xl-chipwall-i<?= $sz ?>" href="<?= e(Poly::termUrl($type, $it)) ?>">
        <span class="xl-chipwall-n"><?= e($it['name']) ?></span>
        <b><?= $c ?></b>
      </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="c-empty"><?= e(__('ui.empty_none')) ?></div>
  <?php endif; ?>

  <?php if ($top): ?>
  <div class="xl-mi-groups">
    <?php foreach ($top as $g):
      $it = $g['it'];
    ?>
    <section class="xl-mi-group">
      <header class="xl-mi-group-h">
        <a class="xl-mi-group-n" href="<?= e(Poly::termUrl($type, $it)) ?>"><?= e((string)$it['name']) ?></a>
        <span class="xl-mi-group-c"><?= (int)$it['count'] ?> 篇</span>
      </header>
      <ul class="xl-mi-group-list">
        <?php foreach ($g['posts'] as $p):
          $dt = strtotime((string)$p['d']);
        ?>
        <li>
          <time><?= e(date('m-d', $dt)) ?></time>
          <a href="<?= e($base . rawurlencode((string)$p['slug']) . '.html') ?>"><?= e((string)$p['title']) ?></a>
        </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
