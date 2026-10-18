<?php
/**
 * Tales · 索引页头部（拼音索引 ⇄ 标签：同一版式、两个 URL）
 * 变量：$idx_title, $idx_lead, $idx_active（'pinyin'|'tags'）
 */
require_once __DIR__ . '/../../lib.php';
$lp = (string)(Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : '');
$L  = (Lang::short() === 'en');
$T_ = function (string $zh, string $en) use ($L): string { return $L ? $en : $zh; };
$active = (string)($idx_active ?? 'pinyin');
?>
<div class="idx-head">
  <div class="kicker kicker-acc"><?= e($T_('故事索引 · INDEX', 'STORY INDEX')) ?></div>
  <h1><?= e((string)($idx_title ?? $T_('故事索引', 'Story index'))) ?></h1>
  <?php if (!empty($idx_lead)): ?><p class="lead"><?= e((string)$idx_lead) ?></p><?php endif; ?>
  <div class="idx-tabs">
    <a class="<?= $active === 'pinyin' ? 'on' : '' ?>" href="<?= e($lp . '/pinyin/') ?>"><?= e($T_('拼音索引', 'By pinyin')) ?></a>
    <a class="<?= $active === 'tags' ? 'on' : '' ?>" href="<?= e($lp . '/tag/') ?>"><?= e($T_('标签', 'Tags')) ?></a>
  </div>
</div>
