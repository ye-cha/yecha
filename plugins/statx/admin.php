<?php
declare(strict_types=1);
/**
 * StatX · 后台入口（唯一入口：`/admin/?s=plug_statx`）
 *
 * ============================ 批次 3 的形态 ============================
 * 批次 2 这里是「一个页面 + 两个折叠区（能力②/能力①）+ 25 个平铺区块」。
 * 站长明确要求**界面按老版 tj 重做** ⇒ 现在这里是：
 *   · 一个**左栏分组导航树**（7 个分组 + 33 个导航项，当前页反白）；
 *   · 一个**右主区**：`topbar`（H1 + 站点切换条）+ 站点页**三件套**（时间范围胶囊 / 日期选择器 / 站点切换）
 *     + 卡片式正文 + 页脚口径说明；
 *   · 页面靠查询串切换：`?p=<页面>&r=<区间>&site=<站>`（老版是路径段，原因见 stxui.php 文件头）。
 * 本文件只做三件事：**前置自愈 → POST 动作 → 派发渲染**。页面本身在 pages*.php / adv.php。
 *
 * ★ 安全（本文件里最重要的一条）：所有会改数据、删数据、注入代码的动作**一律 POST + CSRF**。
 *   GET 任何页面都不产生副作用（尤其「数据保留」页：GET 纯只读，删数据必须 POST + 二次确认）。
 */

require_once __DIR__ . '/pages.php';       // 时间维度 / 页面路由 / 外壳（连带 dash.php 取数层）
require_once __DIR__ . '/adv.php';          // 页面路由函数 + 本批新增的两个 POST 动作

statx_sync_tz();   // ★ 让 MySQL 会话时区与 PHP 一致（见 inc.php 里 statx_sync_tz 的说明）

/** 返回本插件的入口 URL（所有跳转都回到它，避免把后台外壳绕出去） */
function statx_admin_self(): string { return '/admin/?s=plug_statx'; }

function yc_statx_admin(): string
{
    if (!Plugins::isOn('statx')) {
        return '<div class="card"><h3>统计与站点健康</h3>'
            . '<div class="stub">插件已停用：到「插件」页启用后即可使用（数据保留，不丢）。</div></div>';
    }

    // ---------------- 前置自愈（与批次 2 相同；失败不影响页面渲染） ----------------
    statx_ensure_schema();
    statx_seed_targets();
    try {
        if ((int)statx_sdb_val('SELECT COUNT(*) FROM ' . STATX_SDB_SITES) === 0) {
            statx_collect_seed_sites();
            statx_collect_rt_write();
        } elseif (!is_file(statx_collect_rt_path())) {
            statx_collect_rt_write();
        }
        if ((int)statx_sdb_val('PRAGMA user_version') < STATX_SDB_SCHEMA) { statx_sdb_init(); }
    } catch (Throwable $e) { /* 采集表尚未建好时不影响健康区块 */ }

    $ctx = statx_ctx('dash');

    /* ---------------- CSV 导出（★ 必须在任何 HTML 之前） ---------------- */
    statx_ui_csv_handle($ctx);

    /* ---------------- 本批新增的两个写动作（POST + CSRF + 二次确认） ---------------- */
    statx_handle_post($ctx);

    /* ---------------- 2026-09-15 新增：站点管理 + 账号 的写动作 ----------------
       （同样 POST + CSRF；删站点 / 改账号都在服务端强制二次确认，GET 一律零副作用） */
    require_once __DIR__ . '/adminops.php';
    statx_ao_handle_post();

    /* ---------------- 其余 POST 动作（全部来自批次 1/2，一个都没减） ---------------- */
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $do = (string)($_POST['do'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        $back = statx_admin_self();

        if ($do === 'statx-probe') {
            $ids = $id > 0 ? [$id] : array_map(static fn($r) => (int)$r['id'], statx_targets(['enabled' => 1], 'sort, id'));
            $ids = array_slice($ids, 0, 40);
            $n = statx_probe_now($ids);
            $_SESSION['flash'] = '已检测 ' . count($ids) . ' 个目标（新增 ' . $n['checks'] . ' 条记录）'
                . ($n['fail'] > 0 ? '，其中 ' . $n['fail'] . ' 个失败' : '')
                . ($n['probe'] > 0 ? '，' . $n['probe'] . ' 个探针被拦（非站点故障）' : '');
            header('Location: ' . $back); exit;
        }
        if ($do === 'statx-toggle') {
            Db::update('statx_health_targets', ['enabled' => ((int)($_POST['enabled'] ?? 0)) === 1 ? 1 : 0], 'id=?', [$id]);
            $_SESSION['flash'] = '目标状态已更新'; header('Location: ' . $back); exit;
        }
        if ($do === 'statx-del') {
            Db::deleteBy('statx_health_targets', 'id=?', [$id]);
            $_SESSION['flash'] = '目标已删除（历史检测记录保留）'; header('Location: ' . $back); exit;
        }
        if ($do === 'statx-add') {
            $url = trim((string)($_POST['url'] ?? ''));
            if (!preg_match('~^https?://~i', $url)) {
                $_SESSION['flash'] = 'URL 必须以 http(s):// 开头';
            } else {
                statx_target_upsert([
                    'site_key' => trim((string)($_POST['site_key'] ?? 'misc')) ?: 'misc',
                    'label'    => trim((string)($_POST['label'] ?? '')) ?: $url,
                    'url'      => $url,
                    'kind'     => 'other',
                    'tier'     => (string)($_POST['tier'] ?? 'P2'),
                    'expect_codes' => trim((string)($_POST['expect_codes'] ?? '200')) ?: '200',
                    'require_re'   => (string)($_POST['require_re'] ?? ''),
                    'interval_s'   => max(60, (int)($_POST['interval_s'] ?? 300)),
                    'slow_ms'      => max(200, (int)($_POST['slow_ms'] ?? 2500)),
                    'enabled'  => 1, 'sort' => 200,
                ]);
                $_SESSION['flash'] = '目标已添加';
            }
            header('Location: ' . $back); exit;
        }
        if ($do === 'statx-cfg') {
            foreach (['notify_to', 'maint_until'] as $k) { statx_opt_set($k, trim((string)($_POST[$k] ?? ''))); }
            foreach (['fail_threshold', 'ok_threshold', 'slow_threshold', 'keep_days', 'keep_months', 'tls_warn_days'] as $k) {
                if (isset($_POST[$k])) { statx_opt_set($k, (string)(int)$_POST[$k]); }
            }
            statx_opt_set('notify_enabled', ((int)($_POST['notify_enabled'] ?? 0)) === 1 ? '1' : '0');
            $_SESSION['flash'] = '告警配置已保存'; header('Location: ' . $back); exit;
        }
        if ($do === 'statx-mail') {
            foreach (['host' => 'mail.host', 'port' => 'mail.port', 'ssl' => 'mail.ssl',
                      'user' => 'mail.user', 'from' => 'mail.from'] as $f => $o) {
                if (isset($_POST[$f])) { statx_opt_set($o, trim((string)$_POST[$f])); }
            }
            $p = (string)($_POST['pass'] ?? '');
            if ($p !== '' && function_exists('yc_opt_set')) { yc_opt_set('statx.mail.pass', $p); }
            $_SESSION['flash'] = '邮件配置已保存（密码加密存储，留空表示不修改）'; header('Location: ' . $back); exit;
        }
        if ($do === 'statx-mailtest') {
            $to = statx_notify_to();
            $r = statx_mail_send($to, '[站点健康] 邮件通道自检',
                "这是 StatX 站点健康检测的邮件通道自检。\n时间：" . date('Y-m-d H:i:s') . "\n收到即说明告警链路可用。\n");
            $_SESSION['flash'] = ($r === true) ? ('测试邮件已发送给 ' . $to) : ('发送失败：' . (string)$r);
            header('Location: ' . $back); exit;
        }
        if ($do === 'statx-ui-chrome') {
            /* ★★ r11：把「隐藏宿主后台外壳顶部」做成后台开关（走插件自己的选项接口，不裸 SQL）。
             *   故意**不**碰 $_SESSION['flash']：本页 POST 后 $_SESSION['flash'] 会被用来
             *   清掉「欢迎回来，…」那一条（见 boot.php 的落地提示处理），在 GET 侧自己加一条更干净。 */
            $on = ((int)($_POST['hide_host_chrome'] ?? 0) === 1) ? '1' : '0';
            statx_opt_set('ui.hide_host_chrome', $on);
            header('Location: ' . statx_admin_self() . '&p=settings&ui=chrome-' . ($on === '1' ? 'on' : 'off'));
            exit;
        }
        if ($do === 'statx-notify-retry') {
            $ids = statx_pending_notifications();
            $r = statx_notify($ids);
            $_SESSION['flash'] = '补发：待发 ' . count($ids) . ' · 成功 ' . $r['sent'] . ' · 失败 ' . $r['failed']
                . ($r['error'] !== '' ? ('（' . $r['error'] . '）') : '');
            header('Location: ' . $back); exit;
        }
        if ($do === 'statx-prune') {
            // ⚠️ 兼容入口：老书签/老按钮可能还带这个 do。**仍然要求 POST + CSRF**（Csrf::check 由内核在本页 POST 时执行）
            $r = statx_prune();
            $c = $r['collect'] ?? [];
            $_SESSION['flash'] = '保留清理：健康明细删除 ' . $r['deleted'] . ' 行（' . $r['batches'] . ' 批）· 健康汇总 ' . $r['daily_deleted'] . ' 行'
                . ' ｜ 统计明细删除 ' . (int)($c['deleted'] ?? 0) . ' 行（' . (int)($c['batches'] ?? 0) . ' 批）'
                . '· 统计汇总 ' . (int)($c['daily_deleted'] ?? 0) . ' 行 · 爬虫表 ' . (int)($c['bots_deleted'] ?? 0) . ' 行'
                . '。建议改用「数据保留」页（带二次确认）。';
            header('Location: ' . $back); exit;
        }

        /* ---------------- 能力①：统计采集 ---------------- */
        if ($do === 'statx-collect-flush') {
            $f = statx_collect_flush();
            $r = statx_collect_rollup(3);
            $_SESSION['flash'] = '已收件：文件 ' . $f['files'] . ' · 明细 ' . $f['rows'] . ' 行'
                . '（其中爬虫 ' . $f['bot_rows'] . '）· 丢弃 ' . $f['dropped'] . ' · 批量语句 ' . $f['batches']
                . ' 条 ｜ 汇总 ' . $r['days'] . ' 组 / ' . $r['rows'] . ' 行 · 爬虫组 ' . $r['bot_rows']
                . ($f['error'] !== '' ? (' ｜ ' . $f['error']) : '');
            header('Location: ' . $back); exit;
        }
        if ($do === 'statx-collect-toggle') {
            statx_opt_set('collect.enabled', ((int)($_POST['enabled'] ?? 0)) === 1 ? '1' : '0');
            $rt = statx_collect_rt_write();
            $_SESSION['flash'] = '采集总开关 = ' . ((int)($_POST['enabled'] ?? 0) === 1 ? '开' : '关')
                . '（已重写 rt.php：on=' . (int)$rt['on'] . '）';
            header('Location: ' . $back); exit;
        }
        if ($do === 'statx-collect-cfg') {
            // ★ 批次 3 新增三个可调项：collect.keep_days / collect.keep_months / collect.alert_thr
            foreach (['collect.site_key', 'collect.pub_origin', 'collect.inject_mode', 'collect.spool_dir',
                      'collect.db_path', 'collect.roots', 'collect.cache_glob', 'geo.db',
                      'collect.backup_to', 'collect.keep_days', 'collect.keep_months', 'collect.alert_thr'] as $k) {
                $post = str_replace('.', '_', $k);
                if (isset($_POST[$post])) { statx_opt_set($k, trim((string)$_POST[$post])); }
            }
            $rt = statx_collect_rt_write();
            $_SESSION['flash'] = '采集配置已保存（rt.php 重写：on=' . (int)$rt['on'] . ' site=' . (string)$rt['site']
                . ' origins=' . count((array)$rt['origins']) . ' tjmap=' . count((array)($rt['tjmap'] ?? [])) . '）';
            header('Location: ' . $back); exit;
        }
        if ($do === 'statx-collect-seed') {
            $r = statx_collect_seed_sites();
            statx_collect_rt_write();
            $_SESSION['flash'] = '站点登记已同步：新增 ' . $r['added'] . ' · 保留 ' . $r['kept'];
            header('Location: ' . $back); exit;
        }
        if ($do === 'statx-collect-inject' || $do === 'statx-collect-remove' || $do === 'statx-collect-verify') {
            $sk = (string)($_POST['site_key'] ?? '');
            if ($sk === '') { $_SESSION['flash'] = '缺少 site_key'; header('Location: ' . $back); exit; }
            if ($do === 'statx-collect-inject') {
                $r = statx_collect_inject($sk, ['where' => (string)($_POST['where'] ?? '')]);
                $_SESSION['flash'] = $r['ok']
                    ? ('「一行 JS」已写入 ' . $sk . ' 的 stats.' . (string)$r['where'] . '，并**抓首页实证通过**（'
                       . (string)$r['verify']['note'] . '；清 ' . (int)$r['flush'] . ' 个页面缓存）')
                    : ('写入失败：' . (string)($r['error'] ?? '') . ' ｜ ' . implode(' ｜ ', (array)($r['log'] ?? [])));
            } elseif ($do === 'statx-collect-remove') {
                $r = statx_collect_remove($sk);
                $_SESSION['flash'] = $r['ok']
                    ? ('已移除 ' . $sk . ' 的这一行 JS（改写 ' . (int)$r['cleared'] . ' 个 option，清 ' . (int)$r['flush'] . ' 个页面缓存）；旁站标签未动')
                    : ('移除失败：' . (string)($r['error'] ?? ''));
            } else {
                $v = statx_collect_verify_site($sk, ['bust' => true]);
                statx_collect_site_save($sk, ['verify_at' => date('Y-m-d H:i:s'), 'verify_ok' => $v['ok'] ? 1 : 0,
                    'verify_note' => mb_substr($v['note'], 0, 255)]);
                $_SESSION['flash'] = '实证校验 ' . $sk . '：' . ($v['ok'] ? '通过' : '未通过')
                    . '（HTTP ' . (int)$v['code'] . ' · ' . (int)$v['bytes'] . ' B · ' . (string)$v['note'] . '）';
            }
            header('Location: ' . $back); exit;
        }
        if ($do === 'statx-collect-endpoint') {
            $rt = statx_collect_rt_read();
            $org = statx_collect_pub_origin();
            $a = statx_collect_probe_script($org . (string)($rt['script'] ?? '/st.js'), []);
            $b = statx_collect_probe_endpoint($org . (string)($rt['path'] ?? '/st'), ['site' => (string)($rt['site'] ?? 'probe')]);
            $_SESSION['flash'] = '端点自检：脚本 HTTP ' . (int)$a['code'] . ' / ' . (int)$a['bytes'] . ' B / '
                . (string)($a['cache'] !== '' ? $a['cache'] : '-') . ' ｜ 上报端点 HTTP ' . (int)$b['code']
                . ' X-Statx=' . ($b['marker'] !== '' ? (string)$b['marker'] : '-') . ' / ' . (int)$b['total'] . 'ms';
            header('Location: ' . $back); exit;
        }
        if ($do === 'statx-collect-backup') {
            $r = statx_collect_backup(7, (string)statx_cfg('collect.backup_to'));
            $_SESSION['flash'] = $r['ok']
                ? ('异地备份已发出：' . (string)$r['to'] . ' · ' . (int)$r['rows'] . ' 行按天汇总 / ' . (int)$r['bytes'] . ' B（不含明细、不含 IP）')
                : ('备份失败：' . (string)($r['error'] ?? ''));
            header('Location: ' . $back); exit;
        }
    }

    /* ---------------- 渲染 ---------------- */
    $flash = (string)($_SESSION['flash'] ?? ''); unset($_SESSION['flash']);
    $body = '';
    if ($flash !== '') { $body .= '<div class="stx-okbox">' . e($flash) . '</div>'; }
    /* ★ 兜底：若左栏导航表 / 路由表被并发改动冲掉，这两页仍可直接渲染（见 adminops.php） */
    $__aoDirect = statx_ao_render_direct();
    $body .= ($__aoDirect !== null) ? $__aoDirect : statx_render_page(statx_ctx('dash'));

    // flash 消息的样式跟本插件的色板（`stx-` 前缀，不外泄到内核后台）
    $body = '<style>.stx-okbox{color:#0f6e56;background:#e1f5ee;border:1px solid #bfe8d8;border-radius:8px;'
        . 'padding:9px 12px;font-size:13px;margin:0 0 14px}</style>' . $body;

    return $body;
}
