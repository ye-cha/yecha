<?php
/**
 * 评论区统一组件（简单大气 · 支持嵌套回复与分页）
 * 入参：$cid $logged $back $comments $segments $para_n $csrf $comment_page $comment_pages
 */
$hue = function (string $name): int { return crc32($name) % 360; };
/** @提及 高亮（输入为原文，输出安全 HTML） */
function yc_m(string $t): string
{
    $s = e($t);
    return (string)(preg_replace('/(^|[\s,，。！？!?（）()])@([A-Za-z0-9_\x{4e00}-\x{9fa5}]{1,40})/u', '$1<span class="mention">@$2</span>', $s) ?? $s);
}

/* 普通评论按父构建树 */
$kids = [];
$byId = [];
foreach ($comments as $c) {
    $pid = (int)($c['parent_id'] ?? 0);
    $byId[(int)$c['id']] = $c;
    $kids[$pid][] = $c;
}

/** 递归输出一条评论（含其回复） */
function yc_cmt_item(array $c, array $kids, bool $logged, bool $guest, int $cid, string $back, string $csrf, callable $hue, int $depth = 0): void
{
    $my = (int)$c['id'];
    $children = $kids[$my] ?? [];
    $replyCount = 0;
    $stack = $children;
    while ($stack) {
        $node = array_shift($stack);
        $replyCount++;
        foreach (($kids[(int)$node['id']] ?? []) as $gc) { $stack[] = $gc; }
    }
    $pad = min($depth, 4) * 26;
    echo '<div class="c-thread" style="' . ($depth > 0 ? 'padding-left:' . $pad . 'px;' : '') . '">';
    echo '<div class="c-item">'
        . '<span class="avatar" style="background:hsl(' . $hue((string)$c['author_name']) . ',32%,92%);color:hsl(' . $hue((string)$c['author_name']) . ',45%,30%);">' . e(mb_substr((string)$c['author_name'], 0, 1)) . '</span>'
        . '<div class="c-body">'
        . '<div class="c-who"><b>' . e($c['author_name']) . '</b><time>' . date('Y-m-d H:i', strtotime((string)$c['created_at'])) . '</time>'
        . ($replyCount > 0 ? '<span class="c-replies">' . e(__('cm.replies')) . $replyCount . '</span>' : '')
        . '</div>'
        . '<div class="c-text">' . yc_m((string)$c['body']) . '</div>';
    // 回复：登录用户带 CSRF，游客走同源校验并补昵称栏
    echo '<details class="reply-to"><summary>' . e(__('cm.reply')) . '</summary>'
        . '<form class="c-form reply-form" method="post" action="/user/comment">'
        . '<input type="hidden" name="content_id" value="' . (int)$cid . '">'
        . '<input type="hidden" name="parent_id" value="' . $my . '">'
        . '<input type="hidden" name="redirect" value="' . e($back) . '#comments">'
        . '<input type="hidden" name="kind" value="normal">'
        . '<input type="text" name="company" value="" class="hp" tabindex="-1" autocomplete="off">'
        . ($logged ? $csrf : '')
        . ($guest ? '<input type="text" name="author_name" class="c-input c-nick" required maxlength="40" placeholder="' . e(__('cm.guest_nick')) . '" autocomplete="nickname">' : '')
        . '<textarea name="body" class="c-input" required placeholder="' . e(__('cm.reply_ph')) . e($c['author_name']) . '…"></textarea>'
        . '<div class="c-actions"><span class="hint">' . e(__('cm.moderated')) . '</span><button class="btn btn-rust" type="submit">' . e(__('cm.reply')) . '</button></div>'
        . '</form></details>';
    echo '</div></div>';
    foreach ($children as $gc) {
        yc_cmt_item($gc, $kids, $logged, $guest, $cid, $back, $csrf, $hue, $depth + 1);
    }
    echo '</div>';
}

$roots = $kids[0] ?? [];
$totalComments = (int)($comment_total ?? (count($comments) + count($segments)));
$en = Lang::isEn();
$guest = !$logged;
/** 昵称栏（游客评论表单）：表单顶部一个占位输入 */
$nameRow = $guest
    ? '<input type="text" name="author_name" class="c-input c-nick" required maxlength="40" placeholder="' . e(__('cm.guest_nick')) . '" autocomplete="nickname">'
    : '';
?>
<div class="comments-area">

  <!-- 段评（公开 · 按段聚合） -->
  <section class="comments" id="segments">
    <h3 class="comments-title"><?= e(__('cm.para_title')) ?><span class="count"><?= count($segments) ?> <?= e(__('cm.count_unit')) ?></span></h3>
    <?php if ($segments):
        $segByP = [];
        foreach ($segments as $sg) { $segByP[$sg['anchor_ref']][] = $sg; }
        foreach ($segByP as $pidx => $list): ?>
      <div class="seg-block">
        <div class="seg-head"><?= e(sprintf(__('cm.para_head'), (string)$pidx)) ?></div>
        <?php foreach ($list as $sg): ?>
        <div class="c-item">
          <span class="avatar" style="background:hsl(<?= $hue((string)$sg['author_name']) ?>,32%,92%);color:hsl(<?= $hue((string)$sg['author_name']) ?>,45%,30%);"><?= e(mb_substr((string)$sg['author_name'], 0, 1)) ?></span>
          <div class="c-body">
            <div class="c-who"><b><?= e($sg['author_name']) ?></b><time><?= date('Y-m-d H:i', strtotime((string)$sg['created_at'])) ?></time></div>
            <?php if ($sg['anchor_quote'] !== ''): ?><div class="anchor-quote">“<?= e($sg['anchor_quote']) ?>”</div><?php endif; ?>
            <div class="c-text"><?= yc_m((string)$sg['body']) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach;
    else: ?>
      <div class="c-empty"><?= e(__('cm.seg_empty')) ?></div>
    <?php endif; ?>
  </section>

  <!-- 评论区 -->
  <section class="comments" id="comments">
    <h3 class="comments-title"><?= e(__('cm.cmt_title')) ?><span class="count"><?= $totalComments ?> <?= e(__('cm.count_unit')) ?></span></h3>

    <?php if ($roots): ?>
      <?php foreach ($roots as $root) { yc_cmt_item($root, $kids, $logged, $guest, $cid, $back, $csrf, $hue, 0); } ?>
    <?php else: ?>
      <div class="c-empty"><?= e(__('cm.cmt_empty')) ?></div>
    <?php endif; ?>

    <?php if ($comment_pages > 1): ?>
    <div class="pager">
      <?php if ($comment_page > 1): ?><a href="<?= e($back) ?>?cpage=<?= $comment_page - 1 ?>#comments"><?= e(__('ui.prev')) ?></a><?php endif; ?>
      <span><?= $comment_page ?> / <?= $comment_pages ?></span>
      <?php if ($comment_page < $comment_pages): ?><a href="<?= e($back) ?>?cpage=<?= $comment_page + 1 ?>#comments"><?= e(__('ui.next')) ?></a><?php endif; ?>
    </div>
    <?php endif; ?>

      <!-- 发表评论（登录用户用账号名；游客填昵称，走同源校验 + 先审后发） -->
      <form class="c-form" method="post" action="/user/comment">
        <input type="hidden" name="content_id" value="<?= (int)$cid ?>">
        <input type="hidden" name="redirect" value="<?= e($back) ?>#comments">
        <input type="hidden" name="kind" value="normal">
        <input type="text" name="company" value="" class="hp" tabindex="-1" autocomplete="off">
        <?= $logged ? Csrf::field() : '' ?>
        <?= $nameRow ?>
        <textarea name="body" class="c-input" required placeholder="<?= e(__('cm.ph_body')) ?>"></textarea>
        <div class="c-actions"><span class="hint"><?= e(__('cm.kind_hint')) ?></span><button class="btn btn-rust" type="submit"><?= e(__('cm.post_btn')) ?></button></div>
      </form>

      <?php if ($logged): ?>
      <details class="seg-write">
        <summary><?= e(sprintf(__('cm.seg_summary'), max(1, (int)$para_n))) ?></summary>
        <form class="c-form" method="post" action="/user/comment">
          <input type="hidden" name="content_id" value="<?= (int)$cid ?>">
          <input type="hidden" name="redirect" value="<?= e($back) ?>#segments">
          <input type="hidden" name="kind" value="segment">
          <input type="text" name="company" value="" class="hp" tabindex="-1" autocomplete="off">
          <?= Csrf::field() ?>
          <div class="c-row">
            <select name="anchor_ref" aria-label="<?= e(__('cm.para_opt')) ?>" required>
              <?php for ($i = 1; $i <= max(1, (int)$para_n) && $i <= 60; $i++): ?><option value="<?= $i ?>"><?= e(sprintf(__('cm.para_opt'), $i)) ?></option><?php endfor; ?>
            </select>
            <input name="anchor_quote" class="c-input" placeholder="<?= e(__('cm.seg_ph_quote')) ?>">
          </div>
          <textarea name="body" class="c-input" required placeholder="<?= e(__('cm.seg_ph_body')) ?>"></textarea>
          <div class="c-actions"><span class="hint"><?= e(__('cm.seg_hint2')) ?></span><button class="btn btn-rust" type="submit"><?= e(__('cm.seg_post_btn')) ?></button></div>
        </form>
      </details>
      <?php endif; ?>
  </section>
</div>
