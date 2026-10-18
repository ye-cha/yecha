<?php
declare(strict_types=1);
// 防采集 · 后台管理（/admin/?s=plug_guardweb）；POST 由后台外层统一 CSRF 校验

function yc_guardweb_admin(): string
{
    $back = '/admin/?s=plug_guardweb';
    $cfg = yc_guardweb_cfg(); // 共享默认值（boot.php 已定义）
    $T = Db::t('guard_logs');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'save') {
            $pick = ['on', 'mode', 'block_se', 'rate', 'log', 'robots', 'trust_cf'];
            foreach ($pick as $k) {
                $v = (string)($_POST['g_' . $k] ?? '');
                $cfg[$k] = in_array($v, ['1', '0'], true) ? $v : ($cfg[$k] ?? '0');
            }
            $mode = (string)($_POST['g_mode'] ?? 'block');
            $cfg['mode'] = in_array($mode, ['block', 'empty', 'gone', '429'], true) ? $mode : 'block';
            $cfg['rate_n'] = max(5, (int)($_POST['g_rate_n'] ?? 120));
            $cfg['rate_win'] = max(10, (int)($_POST['g_rate_win'] ?? 60));
            $cfg['allow_ips'] = trim((string)($_POST['g_allow_ips'] ?? ''));
            $cfg['notice'] = trim((string)($_POST['g_notice'] ?? ''));
            // 模式按钮里 mode=block/empty/… 复用 save
            set_option('guardweb.cfg', json_encode($cfg, JSON_UNESCAPED_UNICODE));
            Cache::flush('pages'); Cache::flush('misc');
            header('Location: ' . $back . '&msg=1'); exit;
        }
        if ($do === 'clear' && $T !== '') {
            try { Db::run('DELETE FROM ' . $T); } catch (Throwable $e) {}
            header('Location: ' . $back . '&msg=2'); exit;
        }
        if ($do === 'test' && $T !== '') {
            // 手动验证：三种命中写入样例日志（不真实拦截）
            foreach ([['ai_bot', 'GPTBot/1.0 (+https://openai.com/gptbot)'], ['scraper', 'python-requests/2.31'], ['rate', 'Mozilla/5.0 (compatible; custom)']] as [$r, $u]) {
                try {
                    Db::run("INSERT INTO {$T} (day, ip, ua, path, reason, n, created_at) VALUES (?,?,?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE n = n + 1, updated_at = NOW()",
                        [date('Y-m-d'), '127.0.0.1', $u, '/test', $r]);
                } catch (Throwable $e) {}
            }
            header('Location: ' . $back . '&msg=3'); exit;
        }
    }

    $msg = (int)($_GET['msg'] ?? 0);
    $flash = ['1' => '已保存。', '2' => '日志已清空。', '3' => '已写入 3 条测试日志。'][$msg] ?? '';

    $st = '关闭';
    $stColor = '#b3261e';
    if ($cfg['on'] === '1') { $st = '运行中'; $stColor = '#2e6b34'; }
    $sel = fn(string $v): string => (($cfg['mode'] ?? 'block') === $v) ? ' selected' : '';
    $modeName = ['block' => '403 拒绝', 'empty' => '200 空响应', 'gone' => '410 已删除', '429' => '429 限速'][$cfg['mode'] ?? 'block'];

    // 日志统计
    $today = date('Y-m-d');
    $todayN = 0; $totalN = 0; $rowsHtml = '';
    try {
        $todayN = (int)Db::one("SELECT COALESCE(SUM(n),0) FROM {$T} WHERE day=?", [$today]);
        $totalN = (int)Db::one("SELECT COALESCE(SUM(n),0) FROM {$T}");
        $rows = Db::rows("SELECT day, ip, reason, n, LEFT(ua,60) ua, LEFT(path,60) path, updated_at FROM {$T} ORDER BY updated_at DESC LIMIT 40");
        foreach ($rows as $r) {
            $rowsHtml .= '<tr><td>' . e((string)$r['day']) . '</td><td><code>' . e((string)$r['ip']) . '</code></td>'
                . '<td>' . e((string)$r['reason']) . '</td><td>' . (int)$r['n'] . '</td>'
                . '<td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' . e((string)$r['ua']) . '">' . e((string)$r['ua']) . '</td>'
                . '<td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . e((string)$r['path']) . '</td>'
                . '<td>' . e((string)$r['updated_at']) . '</td></tr>';
        }
    } catch (Throwable $e) { $rowsHtml = ''; }

    $csrf = Csrf::field();
    $sel2 = fn(string $k, string $on): string => ($cfg[$k] ?? '0') === $on ? ' checked' : '';
    $modeSel = fn(string $v): string => (($cfg['mode'] ?? 'block') === $v) ? ' selected' : '';

    return '<div class="card"><h3>防采集 · 拦截 AI 与采集脚本</h3>'
        . '<p style="color:#6f767e;font-size:13px;">识别并拦截 AI 训练爬虫（GPTBot / ClaudeBot / PerplexityBot / CCBot / Bytespider / Amazonbot / Google-Extended…）、采集脚本与高频抓取；搜索引擎蜘蛛（Google / Bing / 百度 / 搜狗 / 360 / Yandex / DuckDuckGo 等）自动放行，真人访客零感知。判断在路由前完成，不命中不落库，对正常访问无性能影响。</p>'
        . ($flash !== '' ? '<p style="color:#2e6b34;font-weight:600;">' . e($flash) . '</p>' : '')
        . '<form method="post"><input type="hidden" name="do" value="save">' . $csrf
        . '<table class="opt"><tr><th>总开关</th><td><label><input type="hidden" name="g_on" value="0"><input type="checkbox" name="g_on" value="1"' . $sel2('on', '1') . '> 启用防采集（默认关闭；开启后按下方策略生效）</label></td></tr>'
        . '<tr><th>命中响应</th><td><select name="g_mode">'
        . '<option value="block"' . $modeSel('block') . '>403 Forbidden（推荐，明确拒绝）</option>'
        . '<option value="empty"' . $modeSel('empty') . '>200 返回空内容（采集器拿到空壳）</option>'
        . '<option value="gone"' . $modeSel('gone') . '>410 Gone（提示资源已移除）</option>'
        . '<option value="429"' . $modeSel('429') . '>429 限速（Retry-After）</option></select></td></tr>'
        . '<tr><th>robots 增强</th><td><label><input type="hidden" name="g_robots" value="0"><input type="checkbox" name="g_robots" value="1"' . $sel2('robots', '1') . '> 输出 /robots.txt：对 AI 爬虫声明 Disallow（搜索引擎不受影响）</label></td></tr>'
        . '<tr><th>频率限制</th><td><label><input type="hidden" name="g_rate" value="0"><input type="checkbox" name="g_rate" value="1"' . $sel2('rate', '1') . '> 启用（对非浏览器 UA 按 IP 计数）</label> '
        . '窗口 <input type="number" name="g_rate_win" value="' . (int)($cfg['rate_win'] ?? 60) . '" min="10" style="width:70px"> 秒内最多 '
        . '<input type="number" name="g_rate_n" value="' . (int)($cfg['rate_n'] ?? 120) . '" min="5" style="width:80px"> 次页面，超出即 ' . e($modeName) . '</td></tr>'
        . '<tr><th>日志</th><td><label><input type="hidden" name="g_log" value="0"><input type="checkbox" name="g_log" value="1"' . $sel2('log', '1') . '> 命中写入日志（按 日/IP/原因 聚合计数）</label></td></tr>'
        . '<tr><th>CF 代理</th><td><label><input type="hidden" name="g_trust_cf" value="0"><input type="checkbox" name="g_trust_cf" value="1"' . $sel2('trust_cf', '1') . '> 站点在 Cloudflare 等反向代理后时信任 CF-Connecting-IP</label></td></tr>'
        . '<tr><th>白名单 IP</th><td><input type="text" name="g_allow_ips" value="' . e((string)($cfg['allow_ips'] ?? '')) . '" style="width:100%;max-width:420px" placeholder="如：1.2.3.4, 10.0.0.0/8（逗号/换行分隔）"> 这些 IP 永远放行（建议加入自己的出口 IP / 监控 IP）</td></tr>'
        . '<tr><th>拒绝提示</th><td><input type="text" name="g_notice" value="' . e((string)($cfg['notice'] ?? '')) . '" style="width:100%;max-width:420px" placeholder="Access denied by guard."></td></tr>'
        . '</table><p><button class="btn">保存设置</button></p></form></div>'

        . '<div class="card"><h3>命中日志 <small>今日 ' . (int)$todayN . ' · 累计 ' . (int)$totalN . '（reason：ai_bot AI爬虫 / scraper 采集脚本 / no_ua 空UA / rate 超频）</small></h3>'
        . '<form method="post" style="display:inline;">' . $csrf . '<input type="hidden" name="do" value="test"><button class="btn ghost mini">写入 3 条测试样例</button></form> '
        . '<form method="post" style="display:inline;" onsubmit="return confirm(\'清空全部日志？\')">' . $csrf . '<input type="hidden" name="do" value="clear"><button class="btn ghost mini" style="color:#b3261e;">清空日志</button></form>'
        . ($rowsHtml !== ''
            ? '<div style="overflow-x:auto;margin-top:10px;"><table><tr><th>日期</th><th>IP</th><th>原因</th><th>次数</th><th>UA</th><th>路径</th><th>最近</th></tr>' . $rowsHtml . '</table></div>'
            : '<p style="color:#9a9a90;">暂无命中记录。</p>')
        . '</div>';
}
