<?php
/** 品云 Pinyun（仿 pinpe.top）—— 首页
 *  顶部 banner（渐变绿底 + 站名 + 描述 + 搜索）→ 下方卡片网格（每张卡带封面渐变色块）→ 右侧栏 widget（最新文章 / 标签云 / 归档）
 *  变量：$posts（预览模式传入，可不用，这里自取站点真实内容） */
$lp   = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';

/** 摘要：彻底清掉 Markdown 残留（图片/链接片段、---、>、强调符） */
$pyX = function ($raw, $len = 82) {
    $s = (string)$raw;
    $s = (string)preg_replace('/```.*?```/su', ' ', $s);
    $s = (string)preg_replace('~!\[[^\]]*\]\([^)\n]*\)~u', '', $s);
    $s = (string)preg_replace('~!\[[^\]]*\]\([^)\n]*$~mu', '', $s);
    $s = (string)preg_replace('~!\[[^\]]*\]~u', '', $s);
    $s = (string)preg_replace('~\[([^\]]*)\]\([^)\n]*\)~u', '$1', $s);
    $s = (string)preg_replace('~\[([^\]]*)\]\([^)\n]*$~mu', '$1', $s);
    $s = str_replace(['](', '![]'], '', $s);
    $s = (string)preg_replace('~\[[^\]]*\]~u', '', $s);
    $s = (string)preg_replace('~\([^()\n]{0,90}\)~u', '', $s);
    $s = (string)preg_replace('~\([^()\n]{2,}$~mu', '', $s);
    $s = (string)preg_replace('~^\s*#{1,6}\s*~mu', '', $s);
    $s = (string)preg_replace('~#{1,6}\s+~u', ' ', $s);
    $s = (string)preg_replace('~^\s*>+\s?~mu', '', $s);
    $s = (string)preg_replace('~^\s*([-*+]|\d+[.)])\s+~mu', '', $s);
    $s = (string)preg_replace('~^\s*([-*_]\s*){3,}$~mu', '', $s);
    $s = (string)preg_replace('#[*_~`]{1,3}#u', '', $s);
    $s = strip_tags($s);
    $s = (string)preg_replace('/\s+/u', ' ', $s);
    $s = (string)preg_replace('/[-–—]{3,}/u', ' ', $s);
    $s = trim($s, " \t\n\r\0\x0B-–—>|·");
    return mb_strlen($s) > $len ? mb_substr($s, 0, $len) . '…' : $s;
};

$pyPosts = [];
$pyTags  = [];
$pyArch  = [];
$pyTotal = 0;
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents'); $M = Db::t('metas'); $R = Db::t('relationships');
        $pyTotal = (int)Db::one("SELECT COUNT(*) FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL");
        $pyPosts = Db::rows(
            "SELECT c.title, c.slug, c.excerpt, c.content, c.views,"
            . " COALESCE(c.published_at, c.created_at) AS dt,"
            . " (SELECT m.name FROM {$R} r JOIN {$M} m ON m.id=r.meta_id WHERE r.content_id=c.id AND m.type='category' ORDER BY m.`order` ASC LIMIT 1) AS cat,"
            . " (SELECT m.slug FROM {$R} r JOIN {$M} m ON m.id=r.meta_id WHERE r.content_id=c.id AND m.type='category' ORDER BY m.`order` ASC LIMIT 1) AS cat_slug"
            . " FROM {$T} c WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
            . " ORDER BY dt DESC LIMIT 9"
        );
        $pyTags = Db::rows("SELECT name, slug, `count` FROM {$M} WHERE type='tag' AND `count`>0 ORDER BY `count` DESC, id ASC LIMIT 22");
        $pyArch = Db::rows(
            "SELECT DATE_FORMAT(COALESCE(c.published_at, c.created_at), '%Y') y, DATE_FORMAT(COALESCE(c.published_at, c.created_at), '%m') m, COUNT(*) n"
            . " FROM {$T} c WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
            . " GROUP BY y, m ORDER BY y DESC, m DESC LIMIT 8"
        );
    }
} catch (\Throwable $e) { $pyPosts = []; $pyTags = []; $pyArch = []; }

$heroSub = trim((string)get_option('site.subtitle', ''));
if ($heroSub === '') { $heroSub = '把读过的书、喝过的茶、想明白的事，都存进这一片云里。'; }
// 封面渐变色相：绿系 148~196，按序错位
$hue = function (int $i) { return 148 + (($i * 13) % 34); };
?>
<div class="py-home">

  <section class="py-banner">
    <div class="py-banner-in">
      <p class="py-banner-eyebrow"><?= e(site_label()) ?></p>
      <h1 class="py-banner-title"><?= e(site_name()) ?></h1>
      <p class="py-banner-desc"><?= e($heroSub) ?></p>
      <form class="py-banner-search" action="<?= e($lp) ?>/search" method="get" role="search">
        <svg class="py-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m21 21-4.3-4.3"></path></svg>
        <input type="search" name="q" placeholder="搜索文章、标签…" aria-label="搜索文章、标签">
        <button type="submit">搜索</button>
      </form>
      <div class="py-banner-meta">
        <span><b><?= Str::num($pyTotal) ?></b> 篇文章</span>
        <span><b><?= count($pyTags) ?></b> 个标签</span>
        <a class="py-banner-link" href="<?= e($lp) ?>/archives">全部归档</a>
      </div>
    </div>
  </section>

  <div class="py-body">
    <div class="py-main">
      <div class="py-sec-head">
        <h2>最新文章</h2>
        <a class="py-sec-more" href="<?= e($lp) ?>/archives">查看全部</a>
      </div>

      <?php if ($pyPosts): ?>
      <div class="py-grid">
        <?php $i = 0; foreach ($pyPosts as $p):
          $i++;
          $u    = $base . e((string)$p['slug']) . '.html';
          $dt   = strtotime((string)$p['dt']);
          $raw  = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
          $exc  = $pyX($raw);
          $cat  = (string)($p['cat'] ?? '');
          $cs   = (string)($p['cat_slug'] ?? '');
          $h    = $hue($i);
          $ini  = mb_substr(trim((string)$p['title']), 0, 1);
        ?>
        <article class="py-card">
          <a class="py-cover" href="<?= e($u) ?>" style="--cva:hsl(<?= $h ?>,46%,64%);--cvb:hsl(<?= ($h + 26) ?>,52%,42%)" aria-hidden="true" tabindex="-1">
            <span class="py-cover-initial"><?= e($ini) ?></span>
            <?php if ($cat !== ''): ?><span class="py-cover-cat"><?= e($cat) ?></span><?php endif; ?>
          </a>
          <div class="py-card-b">
            <h3 class="py-card-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h3>
            <?php if ($exc !== ''): ?><p class="py-card-x"><?= e($exc) ?></p><?php endif; ?>
            <div class="py-card-m">
              <time datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y-m-d', $dt)) ?></time>
              <span><?= e(__('meta.reads')) ?><?= Str::num((int)($p['views'] ?? 0)) ?></span>
              <?php if ($cat !== '' && $cs !== ''): ?><a class="py-card-cat" href="<?= e($lp) ?>/category/<?= e(rawurlencode($cs)) ?>/"><?= e($cat) ?></a><?php endif; ?>
            </div>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="empty"><?= e(__('list.empty')) ?></div>
      <?php endif; ?>
    </div>

    <aside class="py-side">
      <?php if ($pyPosts): ?>
      <section class="py-widget">
        <h3 class="py-widget-t">最新文章</h3>
        <ul class="py-w-latest">
          <?php foreach (array_slice($pyPosts, 0, 6) as $p):
            $u = $base . e((string)$p['slug']) . '.html';
            $dt = strtotime((string)$p['dt']);
          ?>
          <li><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a><time><?= e(date('m-d', $dt)) ?></time></li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <?php if ($pyTags): ?>
      <section class="py-widget">
        <h3 class="py-widget-t">标签云</h3>
        <div class="py-w-tags">
          <?php foreach ($pyTags as $tg): ?>
          <a href="<?= e(Poly::termUrl('tag', $tg)) ?>"><?= e((string)$tg['name']) ?><i><?= (int)$tg['count'] ?></i></a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($pyArch): ?>
      <section class="py-widget">
        <h3 class="py-widget-t">归档</h3>
        <ul class="py-w-arch">
          <?php foreach ($pyArch as $a): ?>
          <li><a href="<?= e($lp) ?>/archives/<?= (int)$a['y'] ?>/<?= e((string)$a['m']) ?>/"><span><?= (int)$a['y'] ?> 年 <?= (int)$a['m'] ?> 月</span><i><?= (int)$a['n'] ?></i></a></li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>
    </aside>
  </div>
</div>
