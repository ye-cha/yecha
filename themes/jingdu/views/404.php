<?php
/**
 * 静读 · 404（便签纸）
 * 渲染在 layout 的 <main class="main-col"> 内。
 * 回首页地址跟随当前语言（/en 下回 /en/，不再回中文首页）。
 */
$jd_lp = ui_lp();
$jd_home = $jd_lp === '' ? '/' : $jd_lp . '/';
?>
<div class="note-404">
  <div class="note-404__paper">
    <span class="note-404__tape" aria-hidden="true"></span>
    <p class="note-404__code">404</p>
    <p class="note-404__msg"><?= e(__('e404.msg')) ?></p>
    <a class="note-404__back" href="<?= e($jd_home) ?>"><?= e(__('e404.back')) ?></a>
  </div>
</div>
