<?php
declare(strict_types=1);
// 词条页静态化 · 后台管理（/admin/?s=plug_entry-static）

function yc_entry_static_admin(): string
{
    $back = '/admin/?s=plug_entry-static';
    if (!Plugins::isOn('entry-static')) {
        return '<div class="card"><h3>词条页静态化</h3><div class="stub">插件已停用：词条页全部回落内核动态渲染（与改造前逐字节相同）。'
            . '重新启用后，静态文件由后台/同步器按需重建。</div></div>';
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'save') {
            set_option('entry_static.enabled', (string)(($_POST['enabled'] ?? '1') === '1' ? '1' : '0'));
            header('Location: ' . $back . '&msg=1'); exit;
        }
        if ($do === 'sync') {
            es_sync(true);
            es_trigger_worker('--sync --max 200 --concurrency 2');
            header('Location: ' . $back . '&msg=2'); exit;
        }
        if ($do === 'all') {
            es_trigger_worker('--all --max 12000 --concurrency 2');
            header('Location: ' . $back . '&msg=4'); exit;
        }
        if ($do === 'sitemap') {
            es_trigger_worker('--sitemap');
            header('Location: ' . $back . '&msg=5'); exit;
        }
        if ($do === 'clear') {
            $d = es_entry_dir();
            $n = 0;
            foreach ((array)glob($d . '/*.html') as $f) { if (@unlink($f)) { $n++; } }
            @unlink(es_sitemap_file());
            es_log('后台清空静态文件 ' . $n . ' 个（回落动态渲染）');
            header('Location: ' . $back . '&msg=3'); exit;
        }
    }

    $msg = (int)($_GET['msg'] ?? 0);
    $flash = [
        1 => '设置已保存。',
        2 => '已同步：失效文件已删除，缺失文件已进入后台重建队列（构建器在跑，可稍后刷新看数字）。',
        4 => '已触发全量重建（后台串行执行，最旧的先刷新）。',
        5 => '已触发静态 sitemap 刷新。',
        3 => '静态文件已清空：所有词条页立刻回落内核动态渲染（逐字节相同，稍慢）。',
    ][$msg] ?? '';

    $st = es_admin_stats();
    $on = es_on();
    $miss = max(0, $st['desired'] - $st['present']);
    $last = '';
    $lf = es_log_file();
    if (is_file($lf)) {
        $lines = array_slice(array_filter(explode("\n", (string)@file_get_contents($lf))), -6);
        $last = implode("\n", $lines);
    }
    $state = es_state_load();
    $row = function (string $k, string $v): string {
        return '<tr><td style="white-space:nowrap">' . e($k) . '</td><td>' . $v . '</td></tr>';
    };

    return '<div class="card"><h3>词条页静态化</h3>'
        . ($flash !== '' ? '<div class="ok">' . e($flash) . '</div>' : '')
        . '<p style="color:#566">已发布词条页（<code>/entry/{slug}</code>）渲染成静态 HTML 放在 <code>' . e(es_entry_dir()) . '</code>，'
        . '由 nginx <code>try_files</code> 直接直出 —— <b>不经过 PHP/DB</b>，因此不占 php-fpm 子进程。'
        . '词条被改/被撤稿/被删 ⇒ 静态文件<b>立刻删除</b>并回落内核动态渲染（与改造前逐字节相同，含 gone301 的 301/404）；'
        . '未命中静态文件时 nginx 必定回落 <code>/index.php</code>，不会新增任何 404。'
        . '全量重建命令：<code>bash tools/entry-static-build.sh --all --max 12000 --concurrency 2</code></p>'
        . '<table class="list"><tr><th>项目</th><th>值</th></tr>'
        . $row('静态直出', $on ? '<b style="color:#2e6b34">开启</b>' : '<b style="color:#9a6b3f">关闭（全部动态）</b>')
        . $row('应在架词条（DB publish）', (string)$st['desired'])
        . $row('已有静态文件', (string)$st['present'] . ($miss > 0 ? '（缺 ' . $miss . '）' : '（齐）'))
        . $row('静态 sitemap', $st['sitemap'] > 0 ? number_format($st['sitemap']) . ' B' : '未生成（回落动态）')
        . $row('重建队列', (string)$st['pending'])
        . $row('最近一次状态变更', isset($state['gen_at']) ? e(date('Y-m-d H:i:s', (int)$state['gen_at'])) : '—')
        . '</table>'
        . '<form method="post" action="' . e($back) . '" style="margin-top:12px">'
        . '<input type="hidden" name="do" value="save">'
        . '<label style="display:flex;gap:10px;align-items:center;margin:6px 0">'
        . '<input type="checkbox" name="enabled" value="1"' . ($on ? ' checked' : '') . '> 启用词条页静态直出</label>'
        . '<button class="btn" type="submit">保存设置</button></form>'
        . '<div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">'
        . '<form method="post" action="' . e($back) . '"><input type="hidden" name="do" value="sync"><button class="btn" type="submit">立即同步（删失效 + 补缺失）</button></form>'
        . '<form method="post" action="' . e($back) . '"><input type="hidden" name="do" value="all"><button class="btn ghost" type="submit">全量重建</button></form>'
        . '<form method="post" action="' . e($back) . '"><input type="hidden" name="do" value="sitemap"><button class="btn ghost" type="submit">刷新静态 sitemap</button></form>'
        . '<form method="post" action="' . e($back) . '" onsubmit="return confirm(\'清空全部词条静态文件？所有词条页将立刻回落动态渲染（内容不变，只是变慢）。\')">'
        . '<input type="hidden" name="do" value="clear"><button class="btn ghost" type="submit">清空静态文件</button></form>'
        . '</div>'
        . '<h4 style="margin:18px 0 8px">最近日志（' . e($lf) . '）</h4>'
        . '<pre style="white-space:pre-wrap;background:#f6f6f3;padding:10px;border-radius:6px;font-size:12px;max-height:220px;overflow:auto">' . e($last !== '' ? $last : '（暂无）') . '</pre>'
        . '</div>';
}
