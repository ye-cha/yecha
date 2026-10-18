<?php
/** 页脚（素简 Sujian v2 · 极简两段：左 copyright + 右 站点说明） */
$icp     = trim((string)get_option('site.icp', ''));
$icpLink = (string)get_option('site.icp_link', 'https://beian.miit.gov.cn/');
?>
<footer class="suj-footer">
  <div class="suj-footer-in">
    <div class="suj-footer-l">
      &copy; <?= date('Y') ?> <a href="<?= e(ui_lp() ?: '/') ?>"><?= e(site_label()) ?></a>
      <?php if ($icp !== ''): ?><span class="suj-devide-dot"></span><a href="<?= e($icpLink) ?>" rel="noopener" target="_blank"><?= e($icp) ?></a><?php endif; ?>
    </div>
    <div class="suj-footer-r">
      <span><?= e(__('ui.demo_site')) ?></span>
      <span class="suj-devide-dot"></span>
      <span>Powered by <a href="<?= e(ui_lp() ?: '/') ?>"><?= e(site_label()) ?></a></span>
    </div>
  </div>
</footer>