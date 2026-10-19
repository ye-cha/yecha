<?php
declare(strict_types=1);
/** 邮件订阅 · 后台管理:名单 / 测试发信 / 立即群发 / 导出 */
function yc_subscribe_admin(): string
{
    $back = '/admin/?s=plug_subscribe';
    if (!Plugins::isOn('subscribe')) {
        return '<div class="card"><h3>邮件订阅</h3><div class="stub">插件已停用:到「插件」页启用后即可使用(订阅数据保留)。</div></div>';
    }
    require_once __DIR__ . '/inc.php';
    sub_ensure_table();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'test') {
            $to = trim((string)($_POST['test_email'] ?? ''));
            $r = sub_send_weekly(false, $to, 3);
            $_SESSION['flash'] = ($r['sent'] > 0 ? '测试邮件已发送到 ' . $to : '测试发送失败,请检查 SMTP 配置') . '';
            header('Location: ' . $back); exit;
        }
        if ($do === 'send') {
            $r = sub_send_weekly(false, '', 3);
            $_SESSION['flash'] = "群发完成:成功 {$r['sent']} · 失败 {$r['fail']} · 跳过 {$r['skipped']}";
            header('Location: ' . $back); exit;
        }
        if ($do === 'unsub') {
            Db::run('UPDATE ' . sub_table() . ' SET status=? WHERE id=?', ['unsub', (int)($_POST['id'] ?? 0)]);
            $_SESSION['flash'] = '已标记退订'; header('Location: ' . $back); exit;
        }
        if ($do === 'del') {
            Db::run('DELETE FROM ' . sub_table() . ' WHERE id=?', [(int)($_POST['id'] ?? 0)]);
            $_SESSION['flash'] = '已删除'; header('Location: ' . $back); exit;
        }
    }
    if ((string)($_GET['action'] ?? '') === 'export') {
        $rows = Db::rows('SELECT email,status,lang,created_at,confirmed_at,last_sent_at FROM ' . sub_table() . ' ORDER BY id ASC');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="subscribers.csv"');
        echo "\xEF\xBB\xBFemail,status,lang,created_at,confirmed_at,last_sent_at\n";
        foreach ($rows as $r) {
            echo '"' . implode('","', array_map(static fn($v) => str_replace('"', '""', (string)$v), $r)) . "\"\n";
        }
        exit;
    }

    $c = sub_mail_conf();
    $all = Db::rows('SELECT id,email,status,lang,created_at,confirmed_at,last_sent_at FROM ' . sub_table() . ' ORDER BY id DESC LIMIT 300');
    $by = [];
    foreach (Db::rows('SELECT status, COUNT(*) n FROM ' . sub_table() . ' GROUP BY status') as $r) { $by[(string)$r['status']] = (int)$r['n']; }
    $flash = (string)($_SESSION['flash'] ?? ''); unset($_SESSION['flash']);

    $h = '<div class="card"><h3>邮件订阅 <small>订阅表单 / 双确认 / 周刊群发</small></h3>';
    if ($flash !== '') { $h .= '<div class="ok" style="margin:8px 0">' . e($flash) . '</div>'; }

    // SMTP 状态
    $smtpOk = $c['host'] !== '' && $c['from'] !== '';
    $h .= '<div class="stub" style="margin:8px 0">SMTP:' . ($smtpOk
            ? ' ' . e($c['host']) . ':' . (int)$c['port'] . ($c['ssl'] ? ' SSL' : ' STARTTLS') . ' · 发件人 ' . e($c['from'])
            : ' 未配置 —— 请到「设置 → 邮件通知」填写 SMTP') . '</div>';

    // 统计
    $h .= '<div class="stub">订阅者:' . array_sum($by) . ' 封(已确认 ' . ($by['confirmed'] ?? 0)
        . ' · 待确认 ' . ($by['pending'] ?? 0) . ' · 已退订 ' . ($by['unsub'] ?? 0) . ')</div>';

    // 操作
    $h .= '<form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin:12px 0">'
        . '<input type="hidden" name="do" value="test">'
        . '<div><label class="pf">测试发信到</label><input name="test_email" placeholder="you@example.com" style="min-width:240px"></div>'
        . '<button class="btn">发送测试</button></form>';
    $h .= '<form method="post" onsubmit="return confirm(\'确定给全部已确认订阅者发送本期内容?\')" style="margin:0 0 12px">'
        . '<input type="hidden" name="do" value="send"><button class="btn">立即群发本期</button>'
        . ' <a class="btn" href="' . e($back) . '&action=export">导出 CSV</a></form>';

    // 名单
    $h .= '<table class="tbl" style="width:100%;border-collapse:collapse"><thead><tr>'
        . '<th align="left">邮箱</th><th align="left">状态</th><th align="left">语言</th><th align="left">订阅时间</th><th align="left">最近发送</th><th></th></tr></thead><tbody>';
    foreach ($all as $r) {
        $h .= '<tr><td>' . e((string)$r['email']) . '</td><td>' . e((string)$r['status']) . '</td><td>' . e((string)$r['lang'])
            . '</td><td>' . e((string)$r['created_at']) . '</td><td>' . e((string)($r['last_sent_at'] ?? '—')) . '</td><td>'
            . '<form method="post" style="display:inline"><input type="hidden" name="do" value="unsub"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn">退订</button></form> '
            . '<form method="post" style="display:inline" onsubmit="return confirm(\'删除该订阅者?\')"><input type="hidden" name="do" value="del"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn">删除</button></form>'
            . '</td></tr>';
    }
    if (!$all) { $h .= '<tr><td colspan="6" class="stub">还没有订阅者。把订阅页 /subscribe 放到导航或页脚即可开始收集。</td></tr>'; }
    $h .= '</tbody></table>';

    $h .= '<div class="stub" style="margin-top:12px">定时群发:把 <code>php ' . e(dirname(__DIR__, 2) . '/plugins/subscribe/newsletter.php') . '</code> 加入计划任务(例:每周日 09:00)。</div>';
    return $h . '</div>';
}
