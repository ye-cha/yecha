<?php
/**
 * 评论区（Astra 蓝调轻盈 风格 · 由 tools/gen_parts.py 生成，勿手改）
 * 入参：$cid $logged $back $comments $segments $para_n $csrf $comment_page $comment_pages
 */
$hue = function (string $n): int { return crc32($n) % 360; };
function yc_m(string $t): string
{
    $s = e($t);
    return (string)(preg_replace('/(^|[\s,，。！？!?（）()])@([A-Za-z0-9_\x{4e00}-\x{9fa5}]{1,40})/u', '$1<span class="mention">@$2</span>', $s) ?? $s);
}
$postAuthorId = (int)(Db::one('SELECT author_id FROM ' . Db::t('contents') . ' WHERE id=?', [$cid]) ?: 0);
$GLOBALS['yc_post_author'] = $postAuthorId;
function yc_badge(array $c, int $postAuthorId, string $rootName): string
{
    $uid = (int)($c['author_id'] ?? 0);
    if ($uid > 0 && $postAuthorId > 0 && $uid === $postAuthorId) {
        return '<span class="c-badge c-badge-author">' . e(__('cm.badge_author')) . '</span>';
    }
    if ($rootName !== '' && (string)$c['author_name'] === $rootName) {
        return '<span class="c-badge c-badge-op">' . e(__('cm.badge_op')) . '</span>';
    }
    return '';
}
$kids = [];
$byId = [];
foreach ($comments as $c) {
    $pid = (int)($c['parent_id'] ?? 0);
    $byId[(int)$c['id']] = $c;
    $kids[$pid][] = $c;
}
function yc_cmt_item(array $c, array $kids, bool $logged, bool $guest, int $cid, string $back, string $csrf, callable $hue, int $depth = 0, int $floor = 0, string $rootName = ''): void
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
        . '<div class="c-who"><b>' . e($c['author_name']) . '</b>'
        . ($floor > 0 ? '<span class="c-floor">#' . $floor . '</span>' : '')
        . yc_badge($c, (int)($GLOBALS['yc_post_author'] ?? 0), $depth > 0 ? $rootName : '')
        . '<time>' . date('Y-m-d H:i', strtotime((string)$c['created_at'])) . '</time>'
        . ($replyCount > 0 ? '<span class="c-replies">' . e(__('cm.replies')) . $replyCount . '</span>' : '')
        . '</div>'
        . '<div class="c-text">' . yc_m((string)$c['body']) . '</div>';
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
        . '<div class="c-actions"><span class="hint">' . e(__('cm.moderated')) . '</span><button class="btn" type="submit">' . e(__('cm.reply')) . '</button></div>'
        . '</form></details>';
    echo '</div></div>';
    foreach ($children as $gc) {
        yc_cmt_item($gc, $kids, $logged, $guest, $cid, $back, $csrf, $hue, $depth + 1, 0, $rootName);
    }
    echo '</div>';
}

$roots = $kids[0] ?? [];
$totalComments = (int)($comment_total ?? (count($comments) + count($segments)));
$guest = !$logged;
$nameRow = $guest
    ? '<input type="text" name="author_name" class="c-input c-nick" required maxlength="40" placeholder="' . e(__('cm.guest_nick')) . '" autocomplete="nickname">'
    : '';
?>
<div class="comments-area cmt cmt-pinyun">

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
            <div class="c-who"><b><?= e($sg['author_name']) ?></b><?= yc_badge($sg, $postAuthorId, '') ?><time><?= date('Y-m-d H:i', strtotime((string)$sg['created_at'])) ?></time></div>
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

  <section class="comments" id="comments">
    <h3 class="comments-title"><?= e(__('cm.cmt_title')) ?><span class="count"><?= $totalComments ?> <?= e(__('cm.count_unit')) ?></span></h3>

    <?php if ($roots): ?>
      <?php $floorNo = ($comment_page - 1) * 20; foreach ($roots as $root) { $floorNo++; yc_cmt_item($root, $kids, $logged, $guest, $cid, $back, $csrf, $hue, 0, $floorNo, (string)$root['author_name']); } ?>
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

      <form class="c-form" method="post" action="/user/comment">
        <input type="hidden" name="content_id" value="<?= (int)$cid ?>">
        <input type="hidden" name="redirect" value="<?= e($back) ?>#comments">
        <input type="hidden" name="kind" value="normal">
        <input type="text" name="company" value="" class="hp" tabindex="-1" autocomplete="off">
        <?= $logged ? Csrf::field() : '' ?>
        <?= $nameRow ?>
        <textarea name="body" class="c-input" required placeholder="<?= e(__('cm.ph_body')) ?>"></textarea>
        <div class="c-actions"><span class="hint"><?= e(__('cm.kind_hint')) ?></span><button class="btn" type="submit"><?= e(__('cm.post_btn')) ?></button></div>
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
          <div class="c-actions"><span class="hint"><?= e(__('cm.seg_hint2')) ?></span><button class="btn" type="submit"><?= e(__('cm.seg_post_btn')) ?></button></div>
        </form>
      </details>
      <?php endif; ?>
  </section>
</div>
