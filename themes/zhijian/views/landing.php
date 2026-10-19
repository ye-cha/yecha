<?php
/** yecha.org 纸笺 Zhijian 演示站 —— 仿 astro-paper.pages.dev
 *  结构：hero（超大衬线站名 h1 + 描述 + 社交图标一行）→ 垂直堆叠的卡片列表
 *  每卡：标题 + 日期 + 标签 chips + 摘要。通体衬线、暖纸底、细边框无重阴影。 */
$name = e(site_name());
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';

$recent = [];
$tagMap = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents');
        $recent = Db::rows(
            "SELECT c.id, c.title, c.slug, c.excerpt, c.content, COALESCE(c.published_at, c.created_at) AS d"
            . " FROM {$T} c WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
            . " ORDER BY d DESC LIMIT 12"
        );
        if ($recent) {
            $ids = implode(',', array_map('intval', array_column($recent, 'id')));
            $M = Db::t('metas'); $R = Db::t('relationships');
            $rels = Db::rows(
                "SELECT r.content_id, m.name, m.slug, m.name_en, m.names_i18n, m.slug_i18n"
                . " FROM {$R} r JOIN {$M} m ON m.id=r.meta_id"
                . " WHERE r.content_id IN ($ids) AND m.type='tag' ORDER BY m.`order` ASC, m.id ASC"
            );
            foreach ($rels as $r) { $tagMap[(int)$r['content_id']][] = $r; }
        }
    }
} catch (\Throwable $e) { $recent = []; $tagMap = []; }

$intro = trim((string)get_option('site.subtitle', ''));
if ($intro === '') { $intro = trim((string)get_option('site.description', '')); }
if ($intro === '') { $intro = '在纸上记下茶的温度，也记下那些转瞬即逝的念头。'; }

$social = [];
$gh = trim((string)get_option('site.github', ''));
if ($gh !== '') { $social[] = ['href' => $gh, 'label' => 'GitHub', 'ico' => 'gh']; }
$mail = trim((string)get_option('site.email', ''));
if ($mail !== '') { $social[] = ['href' => 'mailto:' . $mail, 'label' => 'Email', 'ico' => 'mail']; }
$social[] = ['href' => $lp . '/feed', 'label' => 'RSS', 'ico' => 'rss'];
$social[] = ['href' => $lp . '/search', 'label' => '搜索', 'ico' => 'sr'];
$social[] = ['href' => $lp . '/archives/', 'label' => '归档', 'ico' => 'arch'];
?>
<div class="zj-home">

  <header class="zj-hero">
    <h1 class="zj-hero-name"><?= $name ?></h1>
    <p class="zj-hero-desc"><?= e($intro) ?></p>
    <ul class="zj-social">
      <?php foreach ($social as $s): ?>
      <li><a class="zj-si" href="<?= e($s['href']) ?>" title="<?= e($s['label']) ?>" aria-label="<?= e($s['label']) ?>">
        <?php if ($s['ico'] === 'gh'): ?>
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 .5A11.5 11.5 0 0 0 .5 12a11.5 11.5 0 0 0 7.86 10.92c.58.1.79-.25.79-.56v-2c-3.2.7-3.88-1.4-3.88-1.4-.53-1.34-1.3-1.7-1.3-1.7-1.06-.72.08-.7.08-.7 1.17.08 1.79 1.2 1.79 1.2 1.04 1.79 2.73 1.27 3.4.97.1-.76.4-1.27.73-1.56-2.56-.29-5.25-1.28-5.25-5.7 0-1.26.45-2.29 1.19-3.1-.12-.29-.52-1.46.11-3.05 0 0 .96-.3 3.15 1.18a10.9 10.9 0 0 1 5.74 0c2.18-1.48 3.14-1.18 3.14-1.18.63 1.59.23 2.76.12 3.05.74.81 1.18 1.84 1.18 3.1 0 4.43-2.69 5.4-5.26 5.69.41.36.78 1.06.78 2.14v3.17c0 .31.2.67.8.56A11.5 11.5 0 0 0 23.5 12 11.5 11.5 0 0 0 12 .5Z"/></svg>
        <?php elseif ($s['ico'] === 'mail'): ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2.5" y="4.5" width="19" height="15" rx="2.5"></rect><path d="m3 7 9 6 9-6"></path></svg>
        <?php elseif ($s['ico'] === 'rss'): ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 11a9 9 0 0 1 9 9"></path><path d="M4 4a16 16 0 0 1 16 16"></path><circle cx="5" cy="19" r="1.4"></circle></svg>
        <?php elseif ($s['ico'] === 'sr'): ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m21 21-4.3-4.3"></path></svg>
        <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"></rect><path d="M3 9h18"></path><path d="M8 2v4"></path><path d="M16 2v4"></path></svg>
        <?php endif; ?>
        <span class="zj-si-t"><?= e($s['label']) ?></span>
      </a></li>
      <?php endforeach; ?>
    </ul>
  </header>

  <section class="zj-feed">
    <h2 class="zj-feed-cap">最新文章</h2>

    <?php if ($recent): ?>
    <div class="zj-cards">
      <?php foreach ($recent as $p):
        $dt = strtotime((string)$p['d']);
        $u = $lp . '/' . rawurlencode((string)$p['slug']) . '.html';
        $raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
        $exc = Str::plain(Str::excerpt(Str::cleanMd($raw), 118));
        $tg = $tagMap[(int)$p['id']] ?? [];
      ?>
      <article class="zj-card">
        <h3 class="zj-card-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h3>
        <p class="zj-card-meta"><time class="zj-date" datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y 年 n 月 j 日', $dt)) ?></time></p>
        <?php if ($exc !== ''): ?><p class="zj-card-x"><?= e($exc) ?></p><?php endif; ?>
        <?php if ($tg): ?>
        <div class="zj-chips">
          <?php foreach (array_slice($tg, 0, 4) as $t): ?>
          <a class="zj-chip" href="<?= e(Poly::termUrl('tag', $t)) ?>">#<?= e(Poly::termName($t)) ?></a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>

    <p class="zj-all"><a href="<?= e($lp) ?>/archives/">浏览全部文章</a></p>
    <?php else: ?>
    <div class="empty"><?= e(__('list.empty')) ?></div>
    <?php endif; ?>
  </section>
</div>
