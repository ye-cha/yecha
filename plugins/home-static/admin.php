<?php
declare(strict_types=1);
// 首页生成静态 · 后台管理（/admin/?s=plug_home-static）

function yc_home_static_admin(): string
{
    $back = '/admin/?s=plug_home-static';
    if (!Plugins::isOn('home-static')) {
        return '<div class="card"><h3>首页生成静态</h3><div class="stub">插件已停用：到「插件」页重新启用后，首页将按需生成静态文件并直出。</div></div>';
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'save') {
            $ttl = max(30, (int)($_POST['ttl'] ?? 600));
            set_option('home_static.ttl', (string)$ttl);
            set_option('home_static.enabled', (string)(($_POST['enabled'] ?? '1') === '1' ? '1' : '0'));
            header('Location: ' . $back . '&msg=1'); exit;
        }
        if ($do === 'gen') {
            hs_generate();
            header('Location: ' . $back . '&msg=2'); exit;
        }
        if ($do === 'clear') {
            $dir = hs_dir();
            foreach (glob($dir . '/*') ?: [] as $p) {
                if (is_dir($p)) { @array_map('unlink', glob($p . '/*') ?: []); @rmdir($p); }
                else { @unlink($p); }
            }
            header('Location: ' . $back . '&msg=3'); exit;
        }
    }

    $msg = (int)($_GET['msg'] ?? 0);
    $flash = ['1' => '设置已保存。', '2' => '已重新生成全部语言首页。', '3' => '静态首页已清除（下次访问自动重建）。'][$msg] ?? '';

    $dir = hs_dir();
    $ttl = hs_ttl();
    $on = (string)get_option('home_static.enabled', '1') === '1';

    $rows = '';
    $now = time();
    foreach (hs_langs() as $lg => $prefix) {
        $file = ($prefix === '' ? $dir . '/index.html' : $dir . '/' . $lg . '/index.html');
        if (is_file($file)) {
            $rows .= '<tr><td>' . e($prefix === '' ? '/' : $prefix . '/') . '</td><td>' . e($lg) . '</td><td>'
                . e(date('Y-m-d H:i:s', (int)filemtime($file))) . '</td><td>' . (int)filesize($file) . ' B</td><td>'
                . ((int)filemtime($file) + $ttl < $now ? '已过期（访问将重建）' : '有效') . '</td></tr>';
        } else {
            $rows .= '<tr><td>' . e($prefix === '' ? '/' : $prefix . '/') . '</td><td>' . e($lg) . '</td><td colspan="3">未生成（访问首页或点「立即生成」）</td></tr>';
        }
    }

    return '<div class="card"><h3>首页生成静态</h3>'
        . ($flash !== '' ? '<div class="ok">' . e($flash) . '</div>' : '')
        . '<p style="color:#566">把站点首页渲染为静态 HTML 文件并直接输出：首次访问生成，命中即直出（毫秒级）；超过有效期自动静默重建；也可手动刷新。文件目录：<code>' . e($dir) . '</code></p>'
        . '<form method="post" action="' . e($back) . '"><input type="hidden" name="do" value="save">
           <label style="display:flex;gap:10px;align-items:center;margin:6px 0"><input type="checkbox" name="enabled" value="1"' . ($on ? ' checked' : '') . '> 启用首页静态直出</label>
           <label style="display:flex;gap:10px;align-items:center;margin:6px 0">有效期（秒）：<input type="number" name="ttl" value="' . $ttl . '" min="30" style="width:110px"></label>
           <button class="btn" type="submit">保存设置</button></form>'
        . '<h4 style="margin:18px 0 8px">当前静态文件</h4><table class="list"><tr><th>路径</th><th>语言</th><th>生成时间</th><th>大小</th><th>状态</th></tr>' . $rows . '</table>'
        . '<div style="margin-top:14px;display:flex;gap:10px">
           <form method="post" action="' . e($back) . '"><input type="hidden" name="do" value="gen"><button class="btn" type="submit">立即生成</button></form>
           <form method="post" action="' . e($back) . '" onsubmit="return confirm(\'清除全部静态首页？下次访问将自动重建。\')"><input type="hidden" name="do" value="clear"><button class="btn ghost" type="submit">清除静态文件</button></form>
         </div></div>';
}
