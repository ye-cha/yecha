<?php
/** t38：404 页按当前语言回首页（/en/ 下回 /en/，不再回中文首页） */
$yc404home = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() . '/' : '/';
?>
<div class="empty" style="padding:80px 0;">
  <h1 style="font-size:64px;margin:0;color:var(--line);">404</h1>
  <p><?= e(__('e404.msg')) ?><a href="<?= e($yc404home) ?>"><?= e(__('e404.back')) ?></a></p>
</div>
