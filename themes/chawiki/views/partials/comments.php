<?php
/** 评论区（对齐参考站 vuecho 样式）：扁平列表 + 昵称/邮箱/网站 + 评论框 + 发表按钮。
 *  入参：$cid $logged $back $comments $segments $para_n $csrf $comment_page $comment_pages $comment_total
 *  仍走 YeCha 后端：/user/comment（kind=normal，company 蜜罐，CSRF，先审后发）。多语文案走语言包 cm.*。
 */
$anchor = '#doc-comments';
$backHash = $back . $anchor;
$list = is_array($comments ?? null) ? $comments : [];
$normalTotal = max(0, (int)($comment_total ?? count($list)));
usort($list, fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
?>
<div class="doc-comments" id="doc-comments">
  <h3><?= e(rtrim((string)__('cm.cmt_title'))) ?> (<?= (int)$normalTotal ?>)</h3>
  <?php if ($list): ?>
  <ul class="doc-comment-list">
    <?php foreach ($list as $cm):
        $who = trim((string)($cm['author_name'] ?? ''));
        $who = $who !== '' ? $who : __('cm.guest');
        $whoUrl = trim((string)($cm['author_url'] ?? ''));
        $ts = strtotime((string)($cm['created_at'] ?? ''));
        $body = (string)($cm['body'] ?? '');
        $bodyTxt = preg_replace('/@([A-Za-z0-9_\x{4e00}-\x{9fa5}]{1,40})/u', '@$1', $body) ?? $body;
        ?>
    <li class="doc-comment-item">
      <div class="doc-comment-meta"><?php if ($whoUrl !== '' && preg_match('#^https?://#i', $whoUrl)): ?><a href="<?= e($whoUrl) ?>" rel="nofollow noopener" target="_blank"><?= e($who) ?></a><?php else: ?><?= e($who) ?><?php endif; ?><?php if ($ts): ?> · <?= e(date('Y-m-d H:i', $ts)) ?><?php endif; ?></div>
      <div class="doc-comment-body"><?= nl2br(e($bodyTxt)) ?></div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <form class="doc-comment-form" method="post" action="/user/comment">
    <?= Csrf::field() ?>
    <input type="hidden" name="content_id" value="<?= (int)$cid ?>">
    <input type="hidden" name="redirect" value="<?= e($backHash) ?>">
    <input type="hidden" name="kind" value="normal">
    <input type="text" name="company" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;opacity:0;pointer-events:none;">
    <div class="doc-comment-fields">
      <input type="text" name="author_name" placeholder="<?= e(__('cm.guest_nick')) ?>" value="" required maxlength="40" autocomplete="nickname">
      <input type="email" name="author_email" placeholder="<?= e(__('cm.email_ph')) ?>" value="" autocomplete="email">
      <input type="url" name="author_url" placeholder="<?= e(__('cm.site_ph')) ?>" value="">
    </div>
    <textarea name="body" rows="4" placeholder="<?= e(__('cm.body_ph')) ?>" required></textarea>
    <button class="doc-comment-btn" type="submit"><?= e(__('cm.post_btn')) ?></button>
  </form>
</div>
