<?php
/** 图文列表（home.style=img 时由 index.php 选中）
 *  ★ lqalign2 2026-10-05：参照 `www.liulanqi.com` **只有一种列表形态**（文字卡 + 圆角边框阴影，
 *  索引页 `www/tips` 78 条全无缩略图）⇒ 本文件与 partials/list.php 走**同一个** www 形状，
 *  「图文/文字」开关只决定是否额外显示缩略图列。入参与 list.php 完全一致。
 */
require_once __DIR__ . '/../../parts/lq-www.php';

$posts      = (array)($posts ?? []);
$pageTitle  = (string)($page_title ?? '');
$subTxt     = (string)($sub ?? '');
$page       = max(1, (int)($page ?? 1));
$totalPages = max(1, (int)($total_pages ?? 1));
$pagerBase  = (string)($pager_base ?? '/page/{n}');
$lp         = trim((string)($link_prefix ?? ''));
$per        = max(5, (int)theme_opt('per_page', 30));

[$section, $cat] = lqwww_section_of($pagerBase);
$label = lqwww_label($section);
$total = lqwww_total($section, $cat);
if ($total < count($posts)) { $total = count($posts) * $totalPages; }

$crumb = [['首页', '/']];
if ($section !== '') { $crumb[] = [$label, '/' . $section]; }
if ($pageTitle !== '' && $pageTitle !== $label) { $crumb[] = [$pageTitle, '']; }
?>
<?= lqwww_crumbs($crumb) ?>
<div class="min-w-0 space-y-6">
  <div>
    <h1 class="text-3xl font-bold"><?= e($pageTitle !== '' ? $pageTitle : $label) ?></h1>
    <?php if ($subTxt !== ''): ?>
    <p class="mt-2 max-w-2xl text-muted-foreground"><?= e($subTxt) ?></p>
    <?php endif; ?>
    <?php if (isset($GLOBALS['YC_CAT_CHIP'])): ?>
    <?php
    $def = dirname(__DIR__, 2) . '/yecha/parts/cat-def.php';
    if (is_file($def)) { require_once $def; }
    if (function_exists('yc_tools_by_cat')) {
        ob_start();
        include __DIR__ . '/cat-chips.php';
        echo '<div class="mt-3 flex flex-wrap items-center gap-2">' . (string)ob_get_clean() . '</div>';
    }
    ?>
    <?php endif; ?>
  </div>
  <?php if ($posts === []): ?>
  <div class="rounded-xl border bg-card p-6 text-sm text-muted-foreground"><?= e((string)($empty ?? '暂无内容')) ?></div>
  <?php else: ?>
  <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
    <?php foreach ($posts as $p):
        $store = lqwww_store_slug((string)$p['slug']);
        $r = [
            'url'   => $lp . yc_entry_url(lqwww_url_slug((string)$p['slug'])),
            'title' => lqwww_tool_title($store, (string)$p['title']),
            'lead'  => lqwww_lead((array)$p, 130),
            'pop'   => lqwww_pop($store, (int)($p['views'] ?? 0)),
            'date'  => '',
        ];
        $ts = strtotime((string)(($p['updated_at'] ?? '') ?: (($p['published_at'] ?? '') ?: ($p['created_at'] ?? ''))));
        if ($ts) { $r['date'] = date('Y-m-d', $ts); }
        echo str_starts_with($store, 'tool__') ? lqwww_tool_card($r) : lqwww_card($r);
    endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?= lqwww_pager($page, $totalPages, $pagerBase, $total, $per) ?>
