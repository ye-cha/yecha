<?php
declare(strict_types=1);
// 多语言插件 · 后台管理（ext poly：/admin/?s=plug_poly）
// 复用核心 admin/poly.inc.php 的渲染函数；POST 动作由该文件内 poly_do 处理。
if (!is_file(__DIR__ . '/poly.inc.php')) {
    return '<div class="card"><h3>多语言</h3><div class="stub">多语言模块缺失（poly.inc.php 未找到）。</div></div>';
}
if (!function_exists('poly_do')) { require_once __DIR__ . '/poly.inc.php'; }

function yc_poly_admin(): string
{
    if (!Plugins::isOn('poly')) {
        return '<div class="card"><h3>多语言</h3><div class="stub">多语言插件已停用：到「插件」页重新启用（译文数据 ye_poly_contents 保留不删）。</div></div>';
    }
    // POST 动作（poly-config/poly-save/ai-generate/poly-batch/poly-langs/poly-modes）由 admin/index.php 外层分发，
    // 这里只处理 GET 渲染；若直达本入口的 POST 也转发给 poly_do。
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $do = (string)($_POST['do'] ?? '');
        if (in_array($do, ['poly-config', 'poly-save', 'ai-generate', 'poly-batch', 'poly-langs', 'poly-modes'], true)) {
            poly_do($do);
        }
    }
    $a = (string)($_GET['action'] ?? '');
    $pLang = poly_cur_lang();
    $req = trim((string)($_GET['lang'] ?? ''));
    if ($req !== '' && in_array($req, Poly::enabled(), true) && $req !== Poly::defaultLang()) { $pLang = $req; }
    if ($a === 'edit' && isset($_GET['id'])) {
        return poly_edit_html((int)$_GET['id'], $pLang);
    }
    return poly_overview_html($pLang);
}
