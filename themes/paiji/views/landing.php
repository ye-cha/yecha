<?php
/** 派记 Paiji（仿 blog.pi3.fun）—— 首页：笔记列表
 *  每条 = 左侧「笔记」标记（✎ 竖条）+ 标题 + 摘要 + 日期；纸质笔记感：浅米色底、细横线分隔、无重阴影 */
$lp   = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';

/** 摘要：彻底清掉 Markdown 残留（图片/链接片段、---、>、强调符、行中标题符） */
$pjX = function ($raw, $len = 96) {
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

$pjPosts = [];
$pjTotal = 0;
try {
    if (class_exists('Db', false)) {
        $T = Db::t('contents');
        $pjTotal = (int)Db::one("SELECT COUNT(*) FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL");
        $pjPosts = Db::rows(
            "SELECT c.title, c.slug, c.excerpt, c.content, c.views,"
            . " COALESCE(c.published_at, c.created_at) AS dt,"
            . " (SELECT m.name FROM " . Db::t('relationships') . " r JOIN " . Db::t('metas') . " m ON m.id=r.meta_id WHERE r.content_id=c.id AND m.type='category' ORDER BY m.`order` ASC LIMIT 1) AS cat"
            . " FROM {$T} c WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
            . " ORDER BY dt DESC LIMIT 12"
        );
    }
} catch (\Throwable $e) { $pjPosts = []; }

$heroSub = trim((string)get_option('site.subtitle', ''));
if ($heroSub === '') { $heroSub = '记录、摘抄与随想 —— 一页一页写下去。'; }
?>
<div class="pj-home">
  <header class="pj-head">
    <h1 class="pj-head-t"><?= e(site_name()) ?></h1>
    <p class="pj-head-sub"><?= e($heroSub) ?></p>
    <p class="pj-head-count">共 <?= Str::num($pjTotal) ?> 则笔记</p>
  </header>

  <?php if ($pjPosts): ?>
  <div class="pj-notes">
    <?php foreach ($pjPosts as $p):
      $u   = $base . e((string)$p['slug']) . '.html';
      $dt  = strtotime((string)$p['dt']);
      $raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
      $exc = $pjX($raw);
      $cat = (string)($p['cat'] ?? '');
    ?>
    <article class="pj-note">
      <span class="pj-mark" aria-hidden="true">✎</span>
      <div class="pj-note-b">
        <h2 class="pj-note-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
        <?php if ($exc !== ''): ?><p class="pj-note-x"><?= e($exc) ?></p><?php endif; ?>
        <div class="pj-note-m">
          <time datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y年m月d日', $dt)) ?></time>
          <?php if ($cat !== ''): ?><span class="pj-dot">·</span><span class="pj-note-cat"><?= e($cat) ?></span><?php endif; ?>
          <span class="pj-dot">·</span><span><?= e(__('meta.reads')) ?><?= Str::num((int)($p['views'] ?? 0)) ?></span>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <div class="pj-notes-foot"><a href="<?= e($lp) ?>/archives">翻更早的笔记</a></div>
  <?php else: ?>
  <div class="empty"><?= e(__('list.empty')) ?></div>
  <?php endif; ?>
</div>
