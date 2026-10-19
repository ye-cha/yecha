<?php
/** kejiyuan 科技原 —— 侧栏双 widget（热门技术 + 标签云）
 *  自带查询，供 landing 双栏页 include。 */
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
<aside class="kj-rail" aria-label="侧栏">
  <section class="kj-widget">
    <h3 class="kj-w-h">热门技术</h3>
    <ul class="kj-techlist">
      <?php foreach ($cats as $c): $slug = rawurlencode((string)$c['slug']); ?>
      <li><a href="<?= e($lp) ?>/category/<?= $slug ?>/"><span class="kj-tech-n"><?= e((string)$c['name']) ?></span><b class="kj-tech-c"><?= (int)$c['n'] ?></b></a></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <section class="kj-widget">
    <h3 class="kj-w-h">标签云</h3>
    <div class="kj-cloud">
      <?php foreach ($tags as $t): $slug = rawurlencode((string)$t['slug']); ?>
      <a class="kj-tag" href="<?= e($lp) ?>/tag/<?= $slug ?>/"><?= e((string)$t['name']) ?></a>
      <?php endforeach; ?>
    </div>
  </section>
</aside>
