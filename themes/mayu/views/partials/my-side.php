<?php
/** 码语 Mayu —— 右侧小侧栏（标签云 / 归档 / 最新文章 三张小卡纵向堆叠）
 *  被 landing / list / meta-index / search / archives 复用，构成全站统一的双栏骨架。 */
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
$tags = []; $mons = []; $rec = [];
try {
    $T = Db::t('contents'); $M = Db::t('metas');
    $tags = Db::rows("SELECT name, slug, `count` FROM {$M} WHERE type='tag' AND `count`>0 ORDER BY `count` DESC, id ASC LIMIT 16");
    $mons = Db::rows(
        "SELECT DATE_FORMAT(COALESCE(published_at, created_at), '%Y') y, DATE_FORMAT(COALESCE(published_at, created_at), '%m') m, COUNT(*) n"
        . " FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL"
        . " GROUP BY y, m ORDER BY y DESC, m DESC LIMIT 8"
    );
    $rec = Db::rows(
        "SELECT title, slug, COALESCE(published_at, created_at) d FROM {$T}"
        . " WHERE type='post' AND status='publish' AND deleted_at IS NULL"
        . " ORDER BY d DESC LIMIT 5"
    );
} catch (\Throwable $e) { $tags = []; $mons = []; $rec = []; }
?>
<aside class="my-side">
  <section class="my-box">
    <h2 class="my-box-h">标签云</h2>
    <?php if ($tags): ?>
    <div class="my-cloud">
      <?php foreach ($tags as $t): ?>
      <a class="my-cloud-i" href="<?= e($lp) ?>/tag/<?= rawurlencode((string)$t['slug']) ?>/"><?= e((string)$t['name']) ?><b><?= (int)$t['count'] ?></b></a>
      <?php endforeach; ?>
    </div>
    <a class="my-box-more" href="<?= e($lp) ?>/tag/">全部标签 →</a>
    <?php else: ?><p class="my-box-none">暂无标签</p><?php endif; ?>
  </section>

  <section class="my-box">
    <h2 class="my-box-h">归档</h2>
    <?php if ($mons): ?>
    <ul class="my-arch">
      <?php foreach ($mons as $mm): ?>
      <li><a href="<?= e($lp) ?>/archives/<?= (int)$mm['y'] ?>/<?= e(sprintf('%02d', (int)$mm['m'])) ?>/"><span><?= (int)$mm['y'] ?>年<?= e(sprintf('%02d', (int)$mm['m'])) ?>月</span><b><?= (int)$mm['n'] ?></b></a></li>
      <?php endforeach; ?>
    </ul>
    <a class="my-box-more" href="<?= e($lp) ?>/archives/">全部归档 →</a>
    <?php else: ?><p class="my-box-none">暂无归档</p><?php endif; ?>
  </section>

  <section class="my-box">
    <h2 class="my-box-h">最新文章</h2>
    <?php if ($rec): ?>
    <ul class="my-rec">
      <?php foreach ($rec as $r): ?>
      <li><a href="<?= e($base . (string)$r['slug']) ?>.html"><?= e((string)$r['title']) ?></a><time><?= e(date('m-d', strtotime((string)$r['d']))) ?></time></li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?><p class="my-box-none">暂无文章</p><?php endif; ?>
  </section>
</aside>
