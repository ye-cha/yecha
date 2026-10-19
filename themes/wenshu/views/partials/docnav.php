<?php
/** 文枢 Wenshu · 左侧文档导航树（分类 / 页面 / 标签，可折叠层级）
 *  由 layout 渲染在 main 左侧；$drawer=true 时输出抽屉内部版本（无 aside 外壳）。 */
$drawer = !empty($drawer);
$cur = Nav::currentPath();
$lp  = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
$pfx = $lp === '' ? '/' : $lp . '/';

$cats = []; $pages = []; $tags = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents'); $M = Db::t('metas'); $R = Db::t('relationships');
        $cats = Db::rows(
            "SELECT m.id, m.name, m.slug, m.description,"
            . " (SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) AS n"
            . " FROM {$M} m WHERE m.type='category'"
            . " AND (SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) > 0"
            . " ORDER BY n DESC, m.`order` ASC LIMIT 14"
        );
        $pages = Db::rows(
            "SELECT c.title, c.slug FROM {$T} c"
            . " WHERE c.type='page' AND c.status='publish' AND c.deleted_at IS NULL"
            . " ORDER BY c.id ASC LIMIT 12"
        );
        $tags = Db::rows(
            "SELECT m.id, m.name, m.slug, (SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) AS n"
            . " FROM {$M} m WHERE m.type='tag'"
            . " AND (SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) > 0"
            . " ORDER BY n DESC, m.name ASC LIMIT 10"
        );
    }
} catch (\Throwable $e) { $cats = []; $pages = []; $tags = []; }

$on = function (string $href) use ($cur): string {
    return ($href === '/') ? ($cur === '/' || $cur === '') : str_starts_with($cur, $href);
};
?>
<?php if (!$drawer): ?><nav class="ws-side" aria-label="文档导航"><?php endif; ?>
  <div class="ws-side-in">
    <a class="ws-side-home<?= $on($pfx) ? ' on' : '' ?>" href="<?= e($pfx) ?>">
      <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"></path></svg>
      文档首页
    </a>

    <?php if ($cats): ?>
    <details class="ws-tree" open>
      <summary>分类目录<em><?= count($cats) ?></em></summary>
      <div class="ws-tree-body">
        <?php foreach ($cats as $c):
            $u = $pfx . 'category/' . rawurlencode((string)$c['slug']) . '/';
            $onC = str_starts_with($cur, rtrim($u, '/'));
        ?>
        <a class="ws-tree-i<?= $onC ? ' on' : '' ?>" href="<?= e($u) ?>">
          <span class="ws-tree-t"><?= e((string)$c['name']) ?></span><span class="ws-tree-n"><?= (int)$c['n'] ?></span>
        </a>
        <?php endforeach; ?>
        <a class="ws-tree-i ws-tree-all" href="<?= e($pfx) ?>category/">全部分类</a>
      </div>
    </details>
    <?php endif; ?>

    <?php if ($pages): ?>
    <details class="ws-tree">
      <summary>独立页面<em><?= count($pages) ?></em></summary>
      <div class="ws-tree-body">
        <?php foreach ($pages as $pg):
            $u = $pfx . (string)$pg['slug'] . '.html';
        ?>
        <a class="ws-tree-i<?= $on($u) ? ' on' : '' ?>" href="<?= e($u) ?>"><span class="ws-tree-t"><?= e((string)$pg['title']) ?></span></a>
        <?php endforeach; ?>
        <a class="ws-tree-i ws-tree-all" href="<?= e($pfx) ?>pages">全部页面</a>
      </div>
    </details>
    <?php endif; ?>

    <?php if ($tags): ?>
    <details class="ws-tree">
      <summary>标签<em><?= count($tags) ?></em></summary>
      <div class="ws-tree-body">
        <?php foreach ($tags as $t): ?>
        <a class="ws-tree-i" href="<?= e(Poly::termUrl('tag', $t)) ?>">
          <span class="ws-tree-t"><?= e((string)$t['name']) ?></span><span class="ws-tree-n"><?= (int)$t['n'] ?></span>
        </a>
        <?php endforeach; ?>
        <a class="ws-tree-i ws-tree-all" href="<?= e($pfx) ?>tag/">全部标签</a>
      </div>
    </details>
    <?php endif; ?>

    <div class="ws-side-foot">
      <a href="<?= e($pfx) ?>archives">归档</a>
      <a href="<?= e($pfx) ?>feed">RSS</a>
    </div>
  </div>
<?php if (!$drawer): ?></nav><?php endif; ?>
