<?php
/** kuake 夸克 —— 右侧双 widget（文章分类 + 热门标签）
 *  自带查询，可被 landing / list / search / archives 等双栏页 include。 */
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
$cats = []; $tags = [];
try {
    if (class_exists('Db', false)) {
        $M = Db::t('metas'); $R = Db::t('relationships');
        $cats = Db::rows(
            "SELECT m.id,m.name,m.slug,(SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) AS n"
            . " FROM {$M} m WHERE m.type='category'"
            . " AND (SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) > 0"
            . " ORDER BY n DESC, m.`order` ASC LIMIT 10"
        );
        $tags = Db::rows(
            "SELECT m.id,m.name,m.slug,(SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) AS n"
            . " FROM {$M} m WHERE m.type='tag'"
            . " AND (SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) > 0"
            . " ORDER BY n DESC LIMIT 28"
        );
    }
} catch (\Throwable $e) { $cats = []; $tags = []; }
?>
<aside class="kk-rail" aria-label="侧栏">
  <section class="kk-widget">
    <h3 class="kk-w-h">文章分类</h3>
    <ul class="kk-catlist">
      <?php foreach ($cats as $c): $slug = rawurlencode((string)$c['slug']); ?>
      <li><a href="<?= e($lp) ?>/category/<?= $slug ?>/"><span class="kk-cat-n"><?= e((string)$c['name']) ?></span><b class="kk-cat-c"><?= (int)$c['n'] ?></b></a></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <section class="kk-widget">
    <h3 class="kk-w-h">热门标签</h3>
    <div class="kk-chips">
      <?php foreach ($tags as $t): $slug = rawurlencode((string)$t['slug']); ?>
      <a class="kk-chip" href="<?= e($lp) ?>/tag/<?= $slug ?>/"><?= e((string)$t['name']) ?></a>
      <?php endforeach; ?>
    </div>
  </section>
</aside>
