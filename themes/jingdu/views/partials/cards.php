<?php
/**
 * 卡片流（首页 / 列表 / 分类 / 标签 / 搜索 共用）
 * 期望变量：$jd_posts（数组）、$jd_excerpt_n（摘要字数）、$lp（语言前缀）
 * 由 landing.php / partials/list.php 通过 require 引入（共享作用域，避免重复实现）
 */
$__n = (int)($jd_excerpt_n ?? 150);
if ($__n < 60) { $__n = 150; }
$__lp = (string)($lp ?? '');
if (!isset($jd_posts) || !is_array($jd_posts) || !$jd_posts) { return; }
foreach ($jd_posts as $__p):
    $__c0  = $__p['cats'][0] ?? null;
    $__cat = $__c0 ? Poly::termName($__c0) : '';
    $__pt  = strtotime((string)($__p['published_at'] ?? $__p['created_at'] ?? 'now'));
    $__url = $__lp . '/' . rawurlencode((string)$__p['slug']) . '.html';
    $__raw = (string)($__p['excerpt'] ?? '');
    if ($__raw === '') { $__raw = (string)($__p['content'] ?? ''); }
    $__sum = Str::excerpt($__raw, $__n);
?>
<article class="post-card">
  <div class="post-card__meta">
    <?php if ($__cat !== '' && $__c0): ?>
      <a href="<?= e(Poly::termUrl('category', $__c0)) ?>"><?= e($__cat) ?></a>
      <span class="post-card__sep">·</span>
    <?php endif; ?>
    <time datetime="<?= e(date('Y-m-d', $__pt)) ?>"><?= e(date('Y-m-d', $__pt)) ?></time>
    <?php if (!empty($__p['pinned'])): ?><span class="post-card__pin">置顶</span><?php endif; ?>
  </div>

  <h2 class="post-card__title"><a href="<?= e($__url) ?>"><?= e((string)$__p['title']) ?></a></h2>

  <?php if ($__sum !== ''): ?><p class="post-card__excerpt"><?= e($__sum) ?></p><?php endif; ?>

  <div class="post-card__footer">
    <?php if (!empty($__p['tags'])): ?>
    <div class="post-card__tags">
      <?php foreach (array_slice((array)$__p['tags'], 0, 4) as $__t): ?>
        <a href="<?= e(Poly::termUrl('tag', $__t)) ?>"><?= e(Poly::termName($__t)) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="post-card__stats">
      <span class="stat" title="<?= e(__('meta.reads')) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"></path><circle cx="12" cy="12" r="2.6"></circle></svg>
        <span class="num"><?= e(Str::num((int)($__p['views'] ?? 0))) ?></span>
      </span>
    </div>
  </div>
</article>
<?php endforeach; ?>
