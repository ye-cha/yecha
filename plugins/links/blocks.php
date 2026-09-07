<?php
declare(strict_types=1);
// 友链插件右栏功能块：plug:links:friend（友链墙）/ plug:links:nav（网址导航）
require_once __DIR__ . '/data.php';

function yc_links_block(string $blockId): string
{
    $kind = $blockId === 'nav' ? 'nav' : 'friend';
    $groups = yc_links_enabled_groups($kind);
    if (!$groups) { return ''; }
    $label = $kind === 'nav' ? __('ui.links_nav') : __('ui.links_friend');
    $capFallback = $kind === 'nav' ? '网址导航' : '友情链接';
    $cap = ($label !== null && !str_starts_with((string)$label, 'ui.')) ? (string)$label : $capFallback;
    $html = '<div class="side-box"><h4>' . e($cap) . '</h4>';
    $first = true;
    foreach ($groups as $g) {
        $list = yc_links_group_links((int)$g['id']);
        if (!$list) { continue; }
        if (!$first) { $html .= '<div style="font-size:11.5px;color:#9a9a90;margin:8px 0 2px;">' . e($g['name']) . '</div>'; }
        $first = false;
        $html .= '<ul class="side-list">';
        foreach ($list as $l) {
            $logo = (string)$l['logo'] !== ''
                ? '<img src="' . e($l['logo']) . '" alt="" style="width:16px;height:16px;vertical-align:-3px;margin-right:6px;border-radius:3px;">'
                : '';
            $html .= '<li><a href="' . e($l['url']) . '" rel="noopener" target="_blank">' . $logo . e($l['name']) . '</a></li>';
        }
        $html .= '</ul>';
    }
    $html .= '</div>';
    return $html;
}
