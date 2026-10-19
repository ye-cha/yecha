<?php
/** 列表：标签页 / 分类页 / 搜索 / 归档（Toolbox v2 绿条风格，与首页 / 搜索统一）
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$empty,$empty_link,$link_prefix
 *  与 landing.php 共用 .astra-grid / .astra-card 文字卡视觉，避免 astra 蓝调 .lc-astra 残留。 */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';

if (!function_exists('tb_excerpt2')) {
    function tb_excerpt2($content, $len = 76) {
        $plain = trim((string)($content ?? ''));
        $plain = preg_replace('/!\[[^\]]*\]\([^\)]+\)/', '', $plain);
        $plain = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $plain);
        $plain = strip_tags($plain);
        $plain = Str::plain($plain);
        return Str::excerpt($plain, $len);
    }
}
?>
<?php if (!empty($posts)): ?>
<div class="tb-sec">
  <div class="astra-sec-head">
    <h2><?= e($page_title) ?></h2>
    <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
  </div>
  <div class="astra-grid">
  <?php foreach ($posts as $p):
      $c0 = $p['cats'][0] ?? null;
      $cat = $c0 ? Poly::termName($c0) : '';
      $u = $base . e((string)$p['slug']) . '.html';
      $desc = tb_excerpt2((string)($p['excerpt'] ?? ''), 76);
      if ($desc === '') { $desc = tb_excerpt2($p['content'] ?? '', 76); }
  ?>
    <article class="astra-card">
      <div class="astra-card-body">
        <?php if ($cat !== ''): ?><span class="astra-card-cat"><?= e($cat) ?></span><?php endif; ?>
        <h3 class="astra-card-title"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h3>
        <p class="astra-card-excerpt"><?= e($desc) ?></p>
        <a class="astra-readmore" href="<?= e($u) ?>"><?= e(__('ui.read_more')) ?></a>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
  <?php if ($total_pages > 1): ?>
  <div class="pager">
    <?php if ($page > 1): ?><a href="<?= e(str_replace('{n}', (string)($page - 1), $pager_base)) ?>"><?= e(__('ui.prev')) ?></a><?php endif; ?>
    <span><?= $page ?> / <?= $total_pages ?></span>
    <?php if ($page < $total_pages): ?><a href="<?= e(str_replace('{n}', (string)($page + 1), $pager_base)) ?>"><?= e(__('ui.next')) ?></a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php else:
$emptyTxt = isset($empty) && $empty !== '' ? $empty : __('list.empty'); ?>
<div class="tb-sec">
  <div class="astra-sec-head"><h2><?= e($page_title) ?></h2></div>
  <p class="empty"><?= e($emptyTxt) ?><?php if (!empty($empty_link)): ?><br><a href="<?= e($empty_link) ?>"><?= e(__('list.empty_alt')) ?></a><?php endif; ?></p>
</div>
<?php endif; ?>
