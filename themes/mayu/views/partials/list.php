<?php
/** 码语 Mayu —— 列表（分类 / 标签 / 归档月）：双栏骨架，左侧文章卡流 + 右侧小侧栏
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$description,$total_items */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<div class="my-page">
  <header class="my-page-h">
    <h1 class="my-page-t"><?= e($page_title) ?></h1>
    <?php if (!empty($description)): ?><p class="my-page-desc"><?= e($description) ?></p><?php endif; ?>
    <div class="my-page-meta">
      <?php if (!empty($total_items)): ?><span class="my-badge"><?= (int)$total_items ?> 篇</span><?php endif; ?>
      <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
    </div>
  </header>

  <div class="my-shell">
    <div class="my-feed">
      <?php if (!empty($posts)): ?>
      <?php foreach ($posts as $p):
        $c0 = $p['cats'][0] ?? null;
        $cat = $c0 ? Poly::termName($c0) : '';
        $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
        $u = $base . e((string)$p['slug']) . '.html';
        $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
        $raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
        $exc = trim(Str::plain(Str::excerpt(Str::cleanMd($raw), 118)));
        $min = max(1, (int)ceil(mb_strlen(trim((string)preg_replace('/\s+/u', '', strip_tags((string)($p['content'] ?? ''))))) / 400));
      ?>
      <article class="my-card">
        <h2 class="my-card-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
        <p class="my-card-x"><?= e($exc) ?></p>
        <div class="my-card-foot">
          <?php if (!empty($p['tags'])): ?>
          <div class="my-chips">
            <?php foreach (array_slice((array)$p['tags'], 0, 3) as $t): ?>
            <a class="my-chip" href="<?= e(Poly::termUrl('tag', $t)) ?>"># <?= e(Poly::termName($t)) ?></a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <div class="my-card-m">
            <?php if ($cat !== ''): ?><a class="my-card-cat" href="<?= e($catUrl) ?>"><?= e($cat) ?></a><?php endif; ?>
            <time><?= e(date('Y-m-d', $dt)) ?></time>
            <span class="my-min">约 <?= $min ?> 分钟</span>
          </div>
        </div>
      </article>
      <?php endforeach; ?>

      <?php if ($total_pages > 1): ?>
      <div class="pager">
        <?php if ($page > 1): ?><a href="<?= e(str_replace('{n}', (string)($page - 1), $pager_base)) ?>"><?= e(__('ui.prev')) ?></a><?php endif; ?>
        <span class="cur"><?= $page ?> / <?= $total_pages ?></span>
        <?php if ($page < $total_pages): ?><a href="<?= e(str_replace('{n}', (string)($page + 1), $pager_base)) ?>"><?= e(__('ui.next')) ?></a><?php endif; ?>
      </div>
      <?php endif; ?>
      <?php else: ?>
      <div class="my-empty"><?= e(!empty($empty) ? $empty : __('list.empty')) ?></div>
      <?php endif; ?>
    </div>

    <?= View::render('partials/my-side') ?>
  </div>
</div>
