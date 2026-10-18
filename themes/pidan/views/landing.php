<?php
/** 皮蛋 Pidan —— 居中窄栏卡片流（仿 pidan.dev）
 *  窄栏 720px：站名小头 → 卡片列表（标题 + 摘要 + 日期 + 标签）→ 全部文章。
 *  卡片圆角 14px、轻阴影、卡片间距 26px；无侧栏、无网格、单列竖排。 */
$name = e(site_name());
$lp   = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';

if (!function_exists('pd_exc')) {
    /** 皮蛋摘要：cleanMd 之后再清行内 --- 分割线、> 引用符等残留 */
    function pd_exc(string $raw, int $len = 110): string {
        $t = Str::cleanMd($raw);
        $t = (string)preg_replace('/\s*[-*_]{3,}\s*/u', ' ', $t);
        $t = (string)preg_replace('/\s+>\s+/u', ' ', $t);
        $t = (string)preg_replace('/\s*\[!?\[[^\]]*$/', '', $t);   // 截断的嵌套图片
        $t = (string)preg_replace('/!?\[([^\]]*)\]\([^)]*$/', '$1', $t); // 截断的链接留文字
        return trim(Str::plain(Str::excerpt($t, $len)));
    }
}

$feed = [];
$stat = ['post' => 0, 'cat' => 0, 'tag' => 0];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents'); $M = Db::t('metas'); $R = Db::t('relationships');
        $stat['post'] = (int)Db::one("SELECT COUNT(*) FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL");
        $stat['cat']  = (int)Db::one("SELECT COUNT(*) FROM {$M} WHERE type='category'");
        $stat['tag']  = (int)Db::one("SELECT COUNT(*) FROM {$M} WHERE type='tag'");
        $rows = Db::rows(
            "SELECT c.id, c.title, c.slug, c.excerpt, c.content, COALESCE(c.published_at, c.created_at) d,"
            . " (SELECT m.name FROM {$R} r JOIN {$M} m ON m.id=r.meta_id WHERE r.content_id=c.id AND m.type='category' ORDER BY m.`order` ASC LIMIT 1) AS cat,"
            . " (SELECT m.slug FROM {$R} r JOIN {$M} m ON m.id=r.meta_id WHERE r.content_id=c.id AND m.type='category' ORDER BY m.`order` ASC LIMIT 1) AS cat_slug"
            . " FROM {$T} c WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
            . " ORDER BY d DESC LIMIT 10"
        );
        $ids = [];
        foreach ($rows as $r) { $ids[] = (int)$r['id']; }
        $tags = [];
        if ($ids) {
            $in = implode(',', $ids);
            foreach (Db::rows("SELECT r.content_id cid, m.name, m.slug FROM {$R} r JOIN {$M} m ON m.id=r.meta_id"
                . " WHERE r.content_id IN ({$in}) AND m.type='tag' ORDER BY m.`order` ASC, m.id ASC") as $tr) {
                $tags[(int)$tr['cid']][] = ['name' => (string)$tr['name'], 'slug' => (string)$tr['slug']];
            }
        }
        foreach ($rows as $r) {
            $raw = ((string)$r['excerpt'] !== '') ? (string)$r['excerpt'] : (string)$r['content'];
            $cid = (int)$r['id'];
            $feed[] = [
                'title'   => (string)$r['title'],
                'url'     => $base . rawurlencode((string)$r['slug']) . '.html',
                'excerpt' => pd_exc($raw),
                'date'    => strtotime((string)$r['d']),
                'cat'     => (string)($r['cat'] ?? ''),
                'cat_url' => ($r['cat_slug'] ?? '') !== '' ? $lp . '/category/' . rawurlencode((string)$r['cat_slug']) . '/' : '',
                'tags'    => array_slice($tags[$cid] ?? [], 0, 3),
            ];
        }
    }
} catch (\Throwable $e) { $feed = []; }
?>
<div class="pd-home">
  <header class="pd-hero">
    <p class="pd-hero-eyebrow"><?= e(site_label()) ?></p>
    <h1 class="pd-hero-t"><?= $name ?></h1>
    <?php $hs = trim((string)get_option('site.subtitle', '')); if ($hs === '') { $hs = '一只懒猫的虚拟世界 —— 慢慢写，慢慢看。'; } ?>
    <p class="pd-hero-sub"><?= e($hs) ?></p>
    <p class="pd-hero-stat"><?= (int)$stat['post'] ?> 篇文章 · <?= (int)$stat['cat'] ?> 个分类 · <?= (int)$stat['tag'] ?> 个标签</p>
  </header>

  <?php if ($feed): ?>
  <div class="pd-feed">
    <?php foreach ($feed as $it): ?>
    <article class="pd-card">
      <h2 class="pd-card-t"><a href="<?= e($it['url']) ?>"><?= e($it['title']) ?></a></h2>
      <p class="pd-card-x"><?= e($it['excerpt']) ?></p>
      <div class="pd-card-f">
        <time class="pd-card-d" datetime="<?= e(date('Y-m-d', (int)$it['date'])) ?>"><?= e(date('Y-m-d', (int)$it['date'])) ?></time>
        <?php if ($it['cat'] !== ''): ?>
          <?php if ($it['cat_url'] !== ''): ?><a class="pd-card-cat" href="<?= e($it['cat_url']) ?>"><?= e($it['cat']) ?></a>
          <?php else: ?><span class="pd-card-cat"><?= e($it['cat']) ?></span><?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($it['tags'])): ?><span class="pd-card-tags">
          <?php foreach ($it['tags'] as $tg): ?><a class="pd-tag" href="<?= e($lp . '/tag/' . rawurlencode($tg['slug']) . '/') ?>"><?= e($tg['name']) ?></a><?php endforeach; ?>
        </span><?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <div class="pd-feed-more"><a class="pd-btn" href="<?= e($lp) ?>/archives">浏览全部文章</a></div>
  <?php else: ?>
  <div class="pd-empty">还没有发布的文章。</div>
  <?php endif; ?>
</div>
