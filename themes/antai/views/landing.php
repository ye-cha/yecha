<?php
/** 案台 Antai —— 首页即「桌面」（桌面操作系统隐喻）
 *  紫蓝渐变壁纸 + 图标网格：每个图标 = 圆角方块（约 76px，内含 emoji）+ 下方文字标签。
 *  图标内容：系统入口（归档 / 标签 / 搜索）+ 全部分类（文件夹）+ 最新文章（文稿）。 */
$name = e(site_name());
$lp   = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';

$icons = [
    ['g' => '🗂', 't' => __('nav.archive'), 'u' => $lp . '/archives', 'k' => 'sys'],
    ['g' => '🏷', 't' => __('nav.tags'),    'u' => $lp . '/tag/',     'k' => 'sys'],
    ['g' => '🔍', 't' => __('sr.title'),    'u' => $lp . '/search',   'k' => 'sys'],
];
$cats = [];
$recent = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents'); $M = Db::t('metas'); $R = Db::t('relationships');
        $cats = Db::rows(
            "SELECT m.name, m.slug, (SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) AS n"
            . " FROM {$M} m WHERE m.type='category'"
            . " AND (SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id) > 0"
            . " ORDER BY n DESC, m.`order` ASC LIMIT 10"
        );
        $recent = Db::rows(
            "SELECT title, slug, COALESCE(published_at, created_at) d FROM {$T}"
            . " WHERE type='post' AND status='publish' AND deleted_at IS NULL"
            . " ORDER BY d DESC LIMIT 8"
        );
    }
} catch (\Throwable $e) { $cats = []; $recent = []; }

foreach ($cats as $c) {
    $icons[] = ['g' => '📁', 't' => (string)$c['name'], 'u' => $lp . '/category/' . rawurlencode((string)$c['slug']) . '/', 'k' => 'dir', 'n' => (int)$c['n']];
}
foreach ($recent as $r) {
    $icons[] = ['g' => '📄', 't' => (string)$r['title'], 'u' => $base . rawurlencode((string)$r['slug']) . '.html', 'k' => 'doc'];
}
?>
<div class="at-home">
  <div class="at-menubar">
    <span class="at-mb-brand"><?= e(site_label()) ?></span>
    <h1 class="at-mb-mid"><?= $name ?></h1>
    <span class="at-mb-right"><?= count($icons) ?> 个项目</span>
  </div>

  <div class="at-desk">
    <?php foreach ($icons as $ic): ?>
    <article class="at-icon at-icon-<?= e((string)$ic['k']) ?>">
      <a class="at-icon-a" href="<?= e((string)$ic['u']) ?>" title="<?= e((string)$ic['t']) ?>">
        <span class="at-icon-box"><span class="at-icon-glyph"><?= e((string)$ic['g']) ?></span></span>
        <span class="at-icon-label"><?= e((string)$ic['t']) ?></span>
        <?php if (!empty($ic['n'])): ?><span class="at-icon-n"><?= (int)$ic['n'] ?></span><?php endif; ?>
      </a>
    </article>
    <?php endforeach; ?>
  </div>
</div>
