<?php
/** yecha.org 绒光 Rongguang 演示站 —— 居中极简（仿 tufted-blog.pages.dev）
 *  结构：居中 masthead（站名 + 简介 + 社交链接一行）→ 纯文本文章列表（等宽小字日期 + 标题，细分隔线）
 *  无卡片、无边框、无图片、无阴影。 */
$name = e(site_name());
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';

$recent = [];
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents');
        $recent = Db::rows(
            "SELECT title, slug, COALESCE(published_at, created_at) AS d"
            . " FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL"
            . " ORDER BY d DESC LIMIT 20"
        );
    }
} catch (\Throwable $e) { $recent = []; }

$intro = trim((string)get_option('site.subtitle', ''));
if ($intro === '') { $intro = trim((string)get_option('site.description', '')); }
if ($intro === '') { $intro = '一个安静的写作角落。记录茶、器物与日常里的细碎光亮。'; }

$social = [];
$gh = trim((string)get_option('site.github', ''));
if ($gh !== '') { $social[] = ['href' => $gh, 'label' => 'GitHub']; }
$mail = trim((string)get_option('site.email', ''));
if ($mail !== '') { $social[] = ['href' => 'mailto:' . $mail, 'label' => 'Email']; }
$social[] = ['href' => $lp . '/feed', 'label' => 'RSS'];
$social[] = ['href' => $lp . '/archives/', 'label' => '归档'];
?>
<div class="rg-home">

  <header class="rg-masthead">
    <h1 class="rg-site"><?= $name ?></h1>
    <p class="rg-intro"><?= e($intro) ?></p>
    <p class="rg-social">
      <?php $n = 0; foreach ($social as $s): if ($n > 0): ?><span class="rg-dot">·</span><?php endif; $n++; ?>
      <a href="<?= e($s['href']) ?>"<?= str_starts_with((string)$s['href'], 'http') ? ' rel="noopener"' : '' ?>><?= e($s['label']) ?></a>
      <?php endforeach; ?>
    </p>
  </header>

  <section class="rg-posts">
    <?php if ($recent): ?>
    <div class="rg-list">
      <?php foreach ($recent as $p):
        $dt = strtotime((string)$p['d']);
        $u = $lp . '/' . rawurlencode((string)$p['slug']) . '.html';
      ?>
      <article class="rg-row">
        <time class="rg-date" datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y-m-d', $dt)) ?></time>
        <a class="rg-title" href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a>
      </article>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty"><?= e(__('list.empty')) ?></div>
    <?php endif; ?>
  </section>

  <p class="rg-foot-link"><a href="<?= e($lp) ?>/archives/">全部文章</a></p>
</div>
