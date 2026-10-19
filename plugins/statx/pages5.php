<?php
declare(strict_types=1);
/**
 * StatX · 页面级 builder 之三（批次 3）：全局页 / 设置 / 统计检测 / 站点健康 / 数据保留
 *
 * ★ 本文件里最重要的一件事是**数据保留页的安全**：
 *   老版 tj 的 `/admin/retention` 是「**GET 即真删数据**」的（源码 `app.py` L2012–2024：
 *   一进函数就 `db.run_retention(keep_days=30, vacuum=True)`），任何人/任何爬虫/任何浏览器预渲染
 *   打开那个 URL 就会真删。**本插件绝不复制这个坏模式**：
 *     · 删数据的动作**只接受 POST**，并且必须带 CSRF token；
 *     · **二次确认**：POST 表单里必须带 `confirm=yes`（没有就只回一句话，什么都不做）；
 *     · **默认不 vacuum**（SQLite 用 `PRAGMA incremental_vacuum` 增量回收；整库 VACUUM 会长时间独占写锁）；
 *     · GET 这一页**纯只读**：只显示当前边界与"如果现在执行会删多少行"的预览。
 *   验收时用实测证明「GET 不删任何数据」（见报告）。
 */

require_once __DIR__ . '/pages4.php';

/* ============================== 系统：全局蜘蛛 / 全局预警 ============================== */

function statx_pg_bots_all(array $ctx): array
{
    $h = stx_table(stx_t(statx_c_bots($ctx), 'bots'), $ctx);
    $h .= stx_table(stx_t(statx_c_bots_recent($ctx), 'bots_rec'), $ctx);
    $h .= stx_muted('★ 老版有"蜘蛛与 AI（全部站点）"这个跨站视图（表里带「站点」列），本插件照做。'
        . '跨站看的意义是：**一眼看出"哪台站被刷得最凶"** —— 单站页面永远只能看到自己那一份。');
    return ['kind' => 'page', 'body' => $h];
}

function statx_pg_alerts_all(array $ctx): array
{
    $h = stx_table(stx_t(statx_c_alerts($ctx), 'alerts'), $ctx);
    $h .= stx_muted('★ 老版有"采集预警（全部站点）"。本插件的规则是**行为口径**（同一访客对同一页面 ≥ 阈值次），'
        . '不需要维护任何特征库；阈值在「设置」页可调（`collect.alert_thr`，当前 '
        . (int)statx_cfg_int('collect.alert_thr') . '）。');
    return ['kind' => 'page', 'body' => $h];
}

/* ============================== 系统：统计检测 ============================== */

function statx_pg_check(array $ctx): array
{
    $st = statx_q_collect_status();
    $h = stx_kpis([
        ['插件', Plugins::isOn('statx') ? '已启用' : '已停用', '', Plugins::isOn('statx') ? '' : 'bad'],
        ['采集总开关', $st['enabled'] ? '开' : '关', '', $st['enabled'] ? '' : 'warn'],
        ['端点 rt.php', $st['rt_exists'] ? '已生成' : '缺失', '', $st['rt_exists'] ? '' : 'bad'],
        ['站点登记', (string)count($st['sites']) . ' 个'],
        ['spool 积压', (string)(int)$st['spool']['lines'] . ' 行'],
        ['最近上报', (string)($st['kpi']['last_hit'] !== '' ? $st['kpi']['last_hit'] : '（还没有）'),
         '', $st['kpi']['last_hit'] !== '' ? '' : 'warn'],
    ]);
    $h .= stx_table(stx_t(statx_c_check_items($ctx), 'chk_items'), $ctx);
    $h .= statx_c_pipeline_card($ctx);
    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>端点与脚本自检</h2>'
        . '<span class="stx-note">点一下会真的发一次请求（带 cache-buster）</span></div>';
    $h .= '<form method="post" action="/admin/?s=plug_statx">' . Csrf::field()
        . '<input type="hidden" name="do" value="statx-collect-endpoint">'
        . '<button class="stx-btn" type="submit">端点 + 脚本自检</button></form>';
    $h .= stx_muted('这会用真实浏览器 UA 请求一次 `' . e($st['pub']) . (string)($st['rt']['script'] ?? '/st.js')
        . '` 与上报端点，并打印 HTTP 状态、字节数与缓存标记 —— 与老版"从后台抓一次页面看代码接没接上"等价，'
        . '但**只碰自己的端点**，不去抓别人的站。');
    $h .= '</div>';
    return ['kind' => 'page', 'body' => $h];
}

function statx_c_pipeline_card(array $ctx): string
{
    $t = statx_c_pipeline($ctx);
    return stx_table($t, $ctx);
}

/* ============================== 系统：设置（可写页，全部 POST） ============================== */

function statx_pg_settings(array $ctx): array
{
    $st = statx_q_collect_status();
    $sites = $st['sites'];

    /* ★★ r11【站长 2026-09-17】「隐藏宿主后台的外壳顶部」做成**后台里点着设置**的开关。
     *   这里是纯 HTML <form> + 复选框 + 保存按钮（**零 JS**，与本站其它表单同款写法）。
     *   默认**关**（`statx.ui.hide_host_chrome = 0`）—— 别人把本插件装进自己的博客后台时，
     *   那个顶部是**他自己的**，所以出厂必须原样保留（见 README 第 3.5 节）。
     *   复选框的经典坑：未勾选的 checkbox **不会**出现在 POST 里 ⇒ 前面放一个同名 hidden=0，
     *   勾选时后者覆盖前者（PHP 取最后一个同名值），未勾选时拿到 0。 */
    $hcOn = ((string)statx_opt('ui.hide_host_chrome', '0') === '1');
    /* 保存后的当页可见反馈（普通 GET 参数，无需 JS、也无需动会话 flash） */
    $hcMsg = (string)($_GET['ui'] ?? '');
    $h = '';
    if ($hcMsg === 'chrome-on') {
        $h .= '<div class="stx-okbox">已开启：本插件后台页顶部的外壳已隐藏，浏览器标题改为「统计 · '
            . e((string)get_option('site.name', '')) . '」。刷新任意本插件页面即可看到。</div>';
    } elseif ($hcMsg === 'chrome-off') {
        $h .= '<div class="stx-okbox">已关闭：宿主后台顶部**原样恢复**（出厂默认；把本插件装在自己博客后台时用这个）。</div>';
    }
    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>隐藏宿主后台的外壳顶部（欢迎回来 / 查看站点）</h2>'
        . '<span class="stx-note">当前：' . ($hcOn ? '已隐藏' : '未隐藏（默认）') . '</span></div>';
    $h .= '<form method="post" action="/admin/?s=plug_statx" class="stx-form">' . Csrf::field()
        . '<input type="hidden" name="do" value="statx-ui-chrome">'
        . '<input type="hidden" name="hide_host_chrome" value="0">'
        . '<label class="stx-chk"><input type="checkbox" name="hide_host_chrome" value="1"'
        . ($hcOn ? ' checked' : '') . '> 隐藏内核后台那条顶部（本站 Logo / 欢迎回来 / 查看站点 ↗ / 博主：xxx / 退出）</label>'
        . '<button class="stx-btn" type="submit">保存</button></form>';
    $h .= stx_muted('独立统计站建议**开启**；把本插件装在自己博客后台里时建议**关闭**'
        . '（那是你自己的后台外壳，关掉才找得到回前台和退出的入口）。<br>'
        . '开启后本插件的页面还会把**浏览器标题**换成 <code>统计 · &lt;站点名&gt;</code>。<br>'
        . '★ 说明：内核把这条顶部**硬编码**输出，插件层只能把它<strong>隐藏</strong>（<code>display:none</code>），'
        . '不能让它不进入 HTML —— 想彻底不输出必须改内核。本开关**只作用于本插件的页面**，'
        . '同机其它站点与其它后台**不受影响**。');
    $h .= '</div>';
    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>采集配置</h2>'
        . '<span class="stx-note">保存后会重写 <code>plugins/statx/rt.php</code>（端点读它 → 零 SQL）</span></div>';
    $h .= '<form method="post" action="/admin/?s=plug_statx" class="stx-form">' . Csrf::field()
        . '<input type="hidden" name="do" value="statx-collect-cfg">'
        . '<div class="stx-fld"><label>本站 site_key</label><input name="collect_site_key" value="'
        . e((string)statx_cfg('collect.site_key')) . '" style="width:180px"></div>'
        . '<div class="stx-fld"><label>集中端点域名</label><input name="collect_pub_origin" value="'
        . e((string)statx_cfg('collect.pub_origin')) . '" placeholder="' . e($st['pub']) . '" style="width:230px"></div>'
        . '<div class="stx-fld"><label>注入模式</label><select name="collect_inject_mode">';
    foreach (['auto' => 'auto（推荐：站内自己收，数据不出站）', 'pub' => 'pub（外部端点域名）',
              'static' => 'static（站内静态副本）', 'path' => 'path（站内 /st.js）'] as $k => $lab) {
        $h .= '<option value="' . e($k) . '"' . (statx_cfg('collect.inject_mode') === $k ? ' selected' : '') . '>' . e($lab) . '</option>';
    }
    $h .= '</select></div>'
        . '<div class="stx-fld"><label>SQLite 库位置</label><input name="collect_db_path" value="'
        . e((string)statx_cfg('collect.db_path')) . '" placeholder="' . e(statx_sdb_path()) . '" style="width:300px"></div>'
        . stx_muted('库位置留空 = 自动（当前用 <code>' . e(statx_sdb_path()) . '</code>，来源：'
            . e(statx_sdb_path_source()) . '）。它默认在 `<站点根>/var/statx/`，内核本来就不让 HTTP 直接取到。')
        . '<div class="stx-fld"><label>spool 目录</label><input name="collect_spool_dir" value="'
        . e((string)statx_cfg('collect.spool_dir')) . '" placeholder="' . e(statx_collect_spool_dir()) . '" style="width:200px"></div>'
        . '<div class="stx-fld"><label>异地备份收件人</label><input name="collect_backup_to" value="'
        . e((string)statx_cfg('collect.backup_to')) . '" placeholder="' . e(statx_notify_to()) . '" style="width:200px"></div>'
        . '<div class="stx-fld"><label>明细保留（天）</label><input name="collect_keep_days" value="'
        . (int)statx_cfg_int('collect.keep_days') . '" style="width:90px"></div>'
        . '<div class="stx-fld"><label>汇总保留（月）</label><input name="collect_keep_months" value="'
        . (int)statx_cfg_int('collect.keep_months') . '" style="width:90px"></div>'
        . '<div class="stx-fld"><label>采集预警阈值（次/页）</label><input name="collect_alert_thr" value="'
        . (int)statx_cfg_int('collect.alert_thr') . '" style="width:110px"></div>'
        . '<button class="stx-btn" type="submit">保存</button></form>';
    $h .= stx_muted('spool 目录默认自动选择（当前 <code>' . e(statx_collect_spool_dir()) . '</code>）：'
        . '优先系统临时目录，临时目录不可写时自动退到 `<站点根>/var/statx-spool`。'
        . '**中间产物绝不落 webroot**（红线）。'
        . 'CORS 的 Origin 白名单由「站点登记」里启用的站点自动生成，**不用通配符**。');
    $h .= '</div>';

    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>采集总开关</h2>'
        . '<span class="stx-note">当前：' . ($st['enabled'] ? '已开启' : '已关闭') . '</span></div>';
    $h .= '<form method="post" action="/admin/?s=plug_statx" style="display:inline">' . Csrf::field()
        . '<input type="hidden" name="do" value="statx-collect-toggle">'
        . '<input type="hidden" name="enabled" value="' . ($st['enabled'] ? '0' : '1') . '">'
        . '<button class="stx-btn' . ($st['enabled'] ? ' danger' : '') . '" type="submit">'
        . ($st['enabled'] ? '关闭采集' : '开启采集') . '</button></form> '
        . '<form method="post" action="/admin/?s=plug_statx" style="display:inline">' . Csrf::field()
        . '<input type="hidden" name="do" value="statx-collect-seed">'
        . '<button class="stx-btn ghost" type="submit">同步站点登记</button></form> '
        . '<form method="post" action="/admin/?s=plug_statx" style="display:inline">' . Csrf::field()
        . '<input type="hidden" name="do" value="statx-collect-flush">'
        . '<button class="stx-btn ghost" type="submit">立刻收件 + 汇总</button></form> '
        . '<form method="post" action="/admin/?s=plug_statx" style="display:inline">' . Csrf::field()
        . '<input type="hidden" name="do" value="statx-collect-backup">'
        . '<button class="stx-btn ghost" type="submit">发异地备份邮件</button></form>';
    $h .= stx_muted('关闭采集后端点返回 204（站点侧脚本照样加载但不上报）—— 所以关掉它**不会**在站点上留下 404 或报错。');
    $h .= '</div>';

    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>站点登记与一行 JS</h2>'
        . '<span class="stx-note">「写入」会真的改该站 option 并**抓它的首页实证**</span></div>';
    $h .= '<div class="stx-scroll"><table class="stx-tb"><thead><tr>'
        . '<th>site_key</th><th>站名</th><th>host</th><th>今日 PV/UV</th><th>爬虫</th>'
        . '<th>注入位置</th><th>实证</th><th>本机可写</th><th>操作</th></tr></thead><tbody>';
    foreach ($sites as $r) {
        $sk = (string)$r['site_key'];
        $vw = (string)$r['inject_where'];
        $h .= '<tr><td><code>' . e($sk) . '</code>' . ((int)$r['enabled'] === 1 ? '' : ' <span class="stx-muted-inline">未启用</span>') . '</td>'
            . '<td>' . e(mb_substr((string)$r['label'], 0, 20)) . '</td>'
            . '<td>' . e((string)$r['host']) . '</td>'
            . '<td class="num"><b>' . (int)$r['pv'] . '</b> / ' . (int)$r['uv'] . '</td>'
            . '<td class="num">' . (int)$r['bot'] . '</td>'
            . '<td>' . ($vw !== '' ? e('stats.' . $vw) : '<span class="stx-muted-inline">无</span>') . '</td>'
            . '<td>' . ((int)$r['verify_ok'] === 1 ? stx_tag('已实证', 'g') : stx_tag('未通过', 'r'))
            . ' <span class="stx-muted-inline" title="' . e((string)$r['verify_note']) . '">'
            . e(mb_substr((string)$r['verify_note'], 0, 16)) . '</span></td>'
            . '<td>' . (!empty($r['local']) ? '是' : '<span class="stx-muted-inline">否</span>') . '</td>'
            . '<td style="white-space:nowrap">';
        foreach ([['statx-collect-inject', '写入'], ['statx-collect-verify', '校验'], ['statx-collect-remove', '移除']] as [$a, $lb]) {
            $h .= '<form method="post" action="/admin/?s=plug_statx" style="display:inline">' . Csrf::field()
                . '<input type="hidden" name="do" value="' . $a . '">'
                . '<input type="hidden" name="site_key" value="' . e($sk) . '">'
                . '<button class="stx-btn ghost sm" type="submit">' . $lb . '</button></form> ';
        }
        $h .= '</td></tr>';
    }
    if (!$sites) { $h .= '<tr><td colspan="9" class="stx-empty">站点登记是空的 —— 点上面的「同步站点登记」。</td></tr>'; }
    $h .= '</tbody></table></div>';
    $h .= stx_muted('★ 老版有独立的「编辑站点」页（名称 / 域名 / **多域名 textarea** / **统计开关**复选框）。'
        . '内核已经把站点主数据管起来了（`plugins/statx/collect.php` 从站点登记同步），'
        . '所以本插件**不重复造一个编辑表单**（两个地方都能改同一份数据 = 迟早互相覆盖）；'
        . '这里直接把"多域名 / 统计开关"的状态**显示出来**，改则在站点登记处改。这是**故意不同**，理由如上。');
    $h .= '</div>';

    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>告警与邮件通道</h2>'
        . '<span class="stx-note">能力②（站点健康）用；收件人为空时不会发信</span></div>';
    $mail = statx_mail_conf();
    $mailOk = statx_mail_ready($mail) && statx_notify_to() !== '';
    $h .= '<div class="stx-muted" style="margin-bottom:8px">当前解析结果：<b>' . ($mailOk ? '可用' : '不可用')
        . '</b> · 来源 <code>' . e($mail['src']) . '</code> · '
        . e($mail['host'] !== '' ? ($mail['host'] . ':' . (int)$mail['port'] . ' ssl=' . $mail['ssl']) : '（未配置）')
        . ' · 发件人 ' . e($mail['from'] !== '' ? $mail['from'] : '（空）')
        . ' · 密码 ' . ($mail['pass'] !== '' ? '已设置（加密存储）' : '未设置')
        . ' · 收件人 ' . e(statx_notify_to() !== '' ? statx_notify_to() : '（空）') . '</div>';
    $h .= '<form method="post" action="/admin/?s=plug_statx" class="stx-form">' . Csrf::field()
        . '<input type="hidden" name="do" value="statx-cfg">'
        . '<div class="stx-fld"><label>告警收件人</label><input name="notify_to" value="' . e((string)statx_opt('notify_to', '')) . '" style="width:220px"></div>'
        . '<div class="stx-fld"><label>连续失败 N 轮告警</label><input name="fail_threshold" value="' . statx_cfg_int('fail_threshold') . '" style="width:90px"></div>'
        . '<div class="stx-fld"><label>连续成功 N 轮报恢复</label><input name="ok_threshold" value="' . statx_cfg_int('ok_threshold') . '" style="width:90px"></div>'
        . '<div class="stx-fld"><label>慢告警连续轮数</label><input name="slow_threshold" value="' . statx_cfg_int('slow_threshold') . '" style="width:90px"></div>'
        . '<div class="stx-fld"><label>TLS 告警阈值(天)</label><input name="tls_warn_days" value="' . statx_cfg_int('tls_warn_days') . '" style="width:90px"></div>'
        . '<div class="stx-fld"><label>健康明细保留(天)</label><input name="keep_days" value="' . statx_cfg_int('keep_days') . '" style="width:100px"></div>'
        . '<div class="stx-fld"><label>健康汇总保留(月)</label><input name="keep_months" value="' . statx_cfg_int('keep_months') . '" style="width:100px"></div>'
        . '<div class="stx-fld"><label>维护窗口截止</label><input name="maint_until" value="' . e((string)statx_opt('maint_until', '')) . '" placeholder="2026-09-14 02:00:00" style="width:180px"></div>'
        . '<div class="stx-fld"><label>邮件告警</label><input type="checkbox" name="notify_enabled" value="1"'
        . (statx_cfg('notify_enabled') === '1' ? ' checked' : '') . '> 开</div>'
        . '<button class="stx-btn" type="submit">保存</button></form>';
    $h .= '<form method="post" action="/admin/?s=plug_statx" class="stx-form" style="margin-top:12px">' . Csrf::field()
        . '<input type="hidden" name="do" value="statx-mail">'
        . '<div class="stx-fld"><label>SMTP 主机</label><input name="host" value="' . e((string)statx_opt('mail.host', '')) . '" style="width:190px"></div>'
        . '<div class="stx-fld"><label>端口</label><input name="port" value="' . e((string)statx_opt('mail.port', '')) . '" style="width:70px"></div>'
        . '<div class="stx-fld"><label>SSL(465=1)</label><input name="ssl" value="' . e((string)statx_opt('mail.ssl', '')) . '" style="width:70px"></div>'
        . '<div class="stx-fld"><label>账号</label><input name="user" value="' . e((string)statx_opt('mail.user', '')) . '" style="width:190px"></div>'
        . '<div class="stx-fld"><label>发件人</label><input name="from" value="' . e((string)statx_opt('mail.from', '')) . '" style="width:190px"></div>'
        . '<div class="stx-fld"><label>密码（留空=不改）</label><input name="pass" type="password" style="width:150px" autocomplete="new-password"></div>'
        . '<button class="stx-btn" type="submit">保存邮件通道</button></form>';
    $h .= '<form method="post" action="/admin/?s=plug_statx" style="margin-top:8px">' . Csrf::field()
        . '<input type="hidden" name="do" value="statx-mailtest">'
        . '<button class="stx-btn ghost" type="submit">发送测试邮件到收件人</button></form>';
    $h .= '</div>';

    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>数据保留（只读摘要）</h2></div>';
    $b = statx_bounds();
    $h .= '<div class="stx-muted">明细保留 <b>' . (int)$b['keep_days'] . '</b> 天（当前明细边界 <b>'
        . e((string)$b['detail_min']) . '</b>）· 汇总保留 <b>' . (int)statx_cfg('collect.keep_months')
        . '</b> 个月 · 库中有数据：<b>' . e((string)$b['data_min']) . '</b> ~ <b>' . e((string)$b['data_max'])
        . '</b>（' . number_format((int)$b['rows']) . ' 行）</div>';
    $h .= '<div class="stx-acts"><a class="stx-btn ghost" href="' . e(statx_ui_url($ctx, ['p' => 'retention'])) . '">打开「数据保留」页 →</a></div>';
    $h .= '</div>';
    return ['kind' => 'page', 'body' => $h];
}

/* ============================== 监测：单站蜘蛛 / 单站预警 ============================== */

/**
 * 单站「蜘蛛与 AI」。与全局版（`?p=bots_all`）**共用同一个 builder**：
 * `statx_c_bots()` 内部按 `$ctx['p'] === 'bots_all'` 决定是否限定站点。
 * ⚠️ 实测踩到：`statx_page_builder()` 的路由表里漏登记 `bots` / `alerts` 这两个 **id**，
 *    左栏「监测」组里那两个单站菜单项就会渲染成"页面不存在：bots／alerts"。
 */
function statx_pg_bots(array $ctx): array
{
    $h = stx_table(stx_t(statx_c_bots($ctx), 'bots'), $ctx);
    $h .= stx_table(stx_t(statx_c_bots_recent($ctx), 'bots_rec'), $ctx);
    $h .= stx_muted('这一页是**单站**视图；跨所有站点一起看的版本在「系统 → 蜘蛛与 AI」（`?p=bots_all`）。'
        . '爬虫**不计入 PV/UV**（看板的 PV 只有人类流量），它们单独成列 —— '
        . '这样"流量涨了"不会被爬虫刷出来的数字骗到。'
        . '<br><b>★ r12 必须知道的一件事：</b>这里只统计**执行了 JS 的爬虫**。'
        . 'Googlebot / bingbot / Applebot 抓 HTML 时**不跑 JS**，因此它们即使每天抓几百页，'
        . '本页也**不会有它们的行**；这不是漏采，是 JS 采集方式的边界。'
        . '反过来，本页出现的 Sogou / Yisou / Ahrefs 等"高频蜘蛛"里，'
        . '有相当一部分是**冒用这些 UA 的采集器**（2026-09-18 实测：同一秒里同一 IP 段以 `/st` 上报），'
        . '所以「次数」应看作"自称是谁"，不宜直接当"官方蜘蛛的抓取量"。');
    return ['kind' => 'page', 'body' => $h];
}

/** 单站「采集预警」。同样与全局版 `?p=alerts_all` 共用 builder。 */
function statx_pg_alerts(array $ctx): array
{
    $h = stx_table(stx_t(statx_c_alerts($ctx), 'alerts'), $ctx);
    $h .= stx_muted('这一页是**单站**视图；跨所有站点一起看的版本在「系统 → 采集预警」（`?p=alerts_all`）。'
        . '阈值可在「设置」页调整（`collect.alert_thr`，当前 ' . (int)statx_cfg_int('collect.alert_thr') . '）。');
    return ['kind' => 'page', 'body' => $h];
}

/* ============================== 监测：站点健康（能力②，原有界面） ============================== */

function statx_c_health(array $ctx): array
{
    $targets = statx_q_health(stx_page_is_site($ctx) ? $ctx['site'] : null);
    $bySite = [];
    $kindOrder = ['home' => 0, 'content' => 1, 'other' => 2, 'asset' => 3, 'api' => 4, 'healthz' => 5];
    foreach ($targets as $t) { $bySite[(string)$t['site_key']][] = $t; }
    // ★ 2026-09-14：置顶的 site_key 改成可配（`statx.health.pin_site_key`，空 → 不置顶）。
    //   老实现写死把 `tj` 置顶 —— 别的用户机器上根本没有叫 tj 的站。
    $pin = trim((string)statx_opt('health.pin_site_key', ''));
    uksort($bySite, static function ($a, $b) use ($pin) {
        if ($pin !== '') {
            if (strcmp($a, $pin) === 0) { return -1; }
            if (strcmp($b, $pin) === 0) { return 1; }
        }
        return strcmp($a, $b);
    });
    $rows = [];
    foreach ($bySite as $site => $list) {
        usort($list, static fn($a, $b) => ($kindOrder[$a['kind']] ?? 9) <=> ($kindOrder[$b['kind']] ?? 9));
        foreach ($list as $t) {
            $cnt = (int)$t['cnt24']; $fl = (int)$t['fail24'];
            $up = $cnt > 0 ? round((($cnt - $fl) / $cnt) * 100, 1) . '%' : '—';
            $cls = (string)$t['last_cls'];
            if ($t['last_ts'] === null) { $st = stx_tag('未检测', ''); }
            elseif ((int)$t['last_ok'] === 1) { $st = stx_tag('正常 ' . (int)$t['last_code'], 'g'); }
            elseif ((int)($t['is_probe_last'] ?? 0) === 1 || $cls === 'probe_config' || $cls === 'cf_challenge') {
                $st = stx_tag(statx_err_label($cls), 'o', '探针被拦 —— 不是站点故障');
            } else { $st = stx_tag((int)$t['last_code'] . ' ' . statx_err_label($cls), 'r'); }
            $tls = $t['tls_days'] === null ? '—' : ((int)$t['tls_days'] . ' 天');
            $rows[] = ['site' => e((string)$site), 'label' => e((string)$t['label'])
                . '<div class="stx-muted-inline">' . e((string)$t['tier']) . ' · ' . (int)$t['interval_s'] . 's</div>',
                'st' => $st, 'ttfb' => (int)$t['last_ts'] === 0 ? '—' : statx_ms((int)$t['last_ms']),
                'at' => e((string)($t['last_ts'] ?? '—')), 'tls' => e($tls), 'up' => e($up)];
        }
    }
    return ['cols' => [['站点', 'site'], ['检测项', 'label'], ['最近状态', 'st'], ['TTFB', 'ttfb', 'r'],
                       ['最近检测', 'at'], ['TLS', 'tls', 'r'], ['24h 可用率', 'up', 'r']],
            'rows' => $rows, 'bar' => '', 'title' => '站点健康矩阵',
            'right' => '绿=正常 / 橙=探针被拦或限频 / 红=异常 / 灰=未检测',
            'note' => '这一块是**新版有、老版完全没有**的能力（老版后台没有一个可用性监控）。'
                . '★ 关键设计：`probe_config`（探针被 WAF/guardweb 拦）**不算站点故障** —— '
                . '自己的访问控制拦了自己的探针，报"站点坏了"是误报。'];
}

function statx_pg_health(array $ctx): array
{
    $kpi = statx_kpi();
    $upStr = $kpi['up24'] === null ? '—' : ($kpi['up24'] . '%');
    $h = stx_kpis([
        ['在测目标', (string)$kpi['targets']],
        ['未恢复事件', (string)$kpi['open'], '', $kpi['open'] > 0 ? 'bad' : ''],
        ['24h 可用率', $upStr],
        ['7 天平均响应', $kpi['avg7'] > 0 ? statx_ms((int)$kpi['avg7']) : '—'],
        ['最近一轮', (string)($kpi['lastRun'] ?: '尚未运行')],
    ]);
    $h .= stx_table(stx_t(statx_c_health($ctx), 'health'), $ctx);

    // 未恢复事件
    $open = statx_open_events(30);
    $rows = [];
    foreach ($open as $e2) {
        $dur = max(0, time() - strtotime((string)$e2['started_at']));
        $durS = $dur > 86400 ? round($dur / 86400, 1) . ' 天' : ($dur > 3600 ? round($dur / 3600, 1) . ' 小时' : round($dur / 60) . ' 分钟');
        $ns = (string)$e2['notify_state'];
        $nsH = $ns === 'failed' ? stx_tag('发送失败', 'r')
            : ($ns === 'sent' ? stx_tag('已发送', 'g') : ($ns === 'muted' ? stx_tag('维护窗口抑制', 'o') : stx_tag('待发送', '')));
        $rows[] = ['kind' => e(statx_event_kind_label((string)$e2['kind'])),
                   'label' => e((string)($e2['label'] ?? ('#' . $e2['target_id']))),
                   'at' => e((string)$e2['started_at']), 'dur' => e($durS),
                   'n' => (int)$e2['fail_count'],
                   'detail' => e(mb_substr((string)$e2['detail'], 0, 220)), 'ns' => $nsH];
    }
    $h .= stx_table(['cols' => [['类型', 'kind'], ['目标', 'label'], ['开始', 'at'], ['持续', 'dur'],
                                ['失败轮数', 'n', 'r'], ['详情', 'detail'], ['通知', 'ns']],
                     'rows' => $rows, 'bar' => '', 'title' => '未恢复事件', 'empty' => '当前没有未恢复事件。'], $ctx);

    // P95 趋势
    $trend = statx_p95_trend(14);
    $rows = [];
    foreach ($trend as $r) { $rows[] = ['d' => e((string)$r['d']), 'p95' => statx_ms((int)$r['p95']),
                                        'up' => (string)$r['up'] . '%', 'f' => (int)$r['fails']]; }    $h .= stx_table(['cols' => [['日期', 'd'], ['P95', 'p95', 'r'], ['可用率', 'up', 'r'], ['失败', 'f', 'r']],
                     'rows' => $rows, 'bar' => '', 'title' => 'P95 响应趋势（近 14 天）',
                     'empty' => '还没有按天汇总数据（汇总只处理"已完整过去的日子"）。'], $ctx);

    // TLS 倒计时
    $tls = statx_tls_list();
    $rows = [];
    foreach ($tls as $r) {
        $d = (int)$r['tls_days'];
        $rows[] = ['label' => e((string)$r['label']),
                   'days' => $d <= 14 ? stx_tag('剩余 ' . $d . ' 天', 'r') : ($d <= statx_cfg_int('tls_warn_days')
                       ? stx_tag('剩余 ' . $d . ' 天', 'o') : stx_tag('剩余 ' . $d . ' 天', 'g')),
                   'at' => e((string)$r['tls_at'])];
    }
    $h .= stx_table(['cols' => [['目标', 'label'], ['证书', 'days'], ['采集于', 'at']],
                     'rows' => $rows, 'bar' => '', 'title' => 'TLS 到期倒计时（公网边缘证书，不是源站证书）'], $ctx);

    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>操作</h2></div>'
        . '<form method="post" action="/admin/?s=plug_statx" style="display:inline">' . Csrf::field()
        . '<input type="hidden" name="do" value="statx-probe">'
        . '<button class="stx-btn" type="submit">立即检测一次（全部启用目标）</button></form> '
        . '<form method="post" action="/admin/?s=plug_statx" style="display:inline">' . Csrf::field()
        . '<input type="hidden" name="do" value="statx-notify-retry">'
        . '<button class="stx-btn ghost" type="submit">补发失败告警</button></form>';
    $h .= stx_muted('探针红线（每条都来自实测）：不请求会写数据的路径 · `/healthz` 只当辅助信号（假绿陷阱）· '
        . '必带真实浏览器 UA · URL 带 cache-buster · 429 单独识别为限频不计失败 · 每站每轮 ≤3 请求、'
        . '间隔 ≥60s、并发 ≤2 · TLS 测公网边缘证书 · 有副作用/需凭据的路径一律不探。');
    $h .= '</div>';
    return ['kind' => 'page', 'body' => $h];
}

/* ============================== 监测：数据保留（★ 安全设计的重点页） ============================== */

function statx_pg_retention(array $ctx): array
{
    $b = statx_bounds();
    $keepDays = (int)statx_cfg('collect.keep_days');
    $keepMonths = (int)statx_cfg('collect.keep_months');
    $boundary = date('Y-m-d', strtotime('-' . $keepDays . ' day'));

    $preview = 0; $previewDays = 0; $total = 0;
    try {
        $total = (int)statx_sdb_val('SELECT COUNT(*) FROM ' . STATX_SDB_HITS);
        $preview = (int)statx_sdb_val('SELECT COUNT(*) FROM ' . STATX_SDB_HITS . ' WHERE d < ?', [$boundary]);
        $previewDays = (int)statx_sdb_val('SELECT COUNT(DISTINCT d) FROM ' . STATX_SDB_HITS . ' WHERE d < ?', [$boundary]);
    } catch (Throwable $e) { /* 只在页面显示"读不到" */ }

    $h = stx_kpis([
        ['明细保留', $keepDays . ' 天'],
        ['清除边界（北京时间）', $boundary, '该日期**之前**的明细将被删除'],
        ['本次将删除', number_format($preview) . ' 行', '跨 ' . $previewDays . ' 天'],
        ['库内明细合计', number_format($total) . ' 行'],
        ['汇总保留', $keepMonths . ' 个月'],
    ]);

    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>滚动汇总说明</h2>'
        . '<span class="stx-note">这一页是只读的 —— 打开它不会删任何东西</span></div>';
    $h .= stx_muted('机制：每天由 cron 调 `cli.php --task=collect-rollup`，把"已完整过去的日子"按天汇总写进 '
        . '`hits_rollup`（**永久保留**），再由 `--task=prune` 分批删掉超过保留窗口的**原始明细**。'
        . '所以"超过 ' . $keepDays . ' 天的访客明细看不到"是**设计如此**，不是丢数据 —— '
        . '区间类聚合（PV/UV/来源/页面）在汇总表里仍然成立。<br>'
        . '★ 老版 tj 的 `/admin/retention` 是**GET 即真删数据**的：一进函数就跑 '
        . '`db.run_retention(keep_days=30, vacuum=True)`（源码 `app.py` L2012–2024）—— '
        . '任何人、任何爬虫、任何浏览器的预渲染打开那个 URL 都会真删，还会做一次整库 VACUUM（长时间独占写锁）。'
        . '**本插件不复制这个坏模式**，改成下面这样。');
    $h .= '</div>';

    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>立即执行保留清理</h2>'
        . '<span class="stx-note">只有点了这个按钮才会删数据</span></div>';
    $h .= '<div class="stx-note-warn">⚠ <b>这是一个会真删数据的动作</b>：'
        . '将删除 ' . e($boundary) . ' 之前的 <b>' . number_format($preview) . '</b> 行原始明细（跨 '
        . $previewDays . ' 天）。<b>按天汇总（`hits_rollup`）不会被删</b>；'
        . '删除是**分批**进行的（每批 5000 行），不会长时间独占 SQLite 写锁；'
        . '**默认不做整库 VACUUM**（只跑 `PRAGMA incremental_vacuum` 增量回收空闲页）。'
        . '执行前建议先备份：`cp ' . e(statx_sdb_path()) . ' ' . e(rtrim(sys_get_temp_dir(), '/')) . '/statx-before-prune.db`'
        . '（路径就是本插件当前的库位置；用面板做一次全站备份也一样）。</div>';
    $h .= '<form method="post" action="/admin/?s=plug_statx">' . Csrf::field()
        . '<input type="hidden" name="do" value="statx-retention-run">'
        . '<div class="stx-fld"><label>二次确认：请勾选后才可执行</label>'
        . '<label style="display:flex;gap:8px;align-items:center;color:#222">'
        . '<input type="checkbox" name="confirm" value="yes" required> 我确认要删除 '
        . e($boundary) . ' 之前的原始明细（约 ' . number_format($preview) . ' 行）</label></div>'
        . '<div style="margin-top:10px;display:flex;gap:10px;align-items:center">'
        . '<button class="stx-btn danger" type="submit">执行保留清理</button>'
        . '<span class="stx-muted-inline">没有勾选 / 没有 CSRF token / 用 GET 打开 —— 三种情况都不会删任何数据</span></div>'
        . '</form>';
    $h .= '</div>';

    $h .= stx_table(stx_t(statx_c_pipeline($ctx), 'pipeline'), $ctx);
    return ['kind' => 'page', 'body' => $h];
}

/* ============================== 采集与站点管理（原折叠区，现独立页） ============================== */

function statx_pg_collect(array $ctx): array
{
    $h = statx_c_pipeline_card($ctx);
    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>数据流向</h2></div>';
    $st = statx_q_collect_status();
    $rt = $st['rt'];
    $h .= stx_muted('<b>站点一行 JS</b> → <code>' . e($st['pub']) . e((string)($rt['script'] ?? '/st.js')) . '</code>（脚本，长缓存）'
        . ' → <code>' . e($st['pub']) . e((string)($rt['path'] ?? '/st')) . '</code>（上报，no-store / 零 SQL / 批量写）'
        . ' → spool → <code>cli.php --task=collect-rollup</code> 批量落 SQLite → 本页看板。<br>'
        . '口径：<b>pv/uv 只计人类流量</b>，爬虫单独一列（复用 guardweb 三级特征库）。'
        . '隐私：<b>不存明文 IP</b>，访客标识 = 客户端随机 uid；库里没有 IP 列、没有 UA 原文列。'
        . 'jsver=<code>' . e((string)($rt['jsver'] ?? '-')) . '</code> · Origin 白名单 '
        . count((array)($rt['origins'] ?? [])) . ' 个 · tj token 映射 ' . count((array)($rt['tjmap'] ?? [])) . ' 个。');
    $h .= '</div>';
    $ck = $st['kpi'];
    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>概览</h2></div>';
    $h .= '<div class="stx-scroll"><table class="stx-tb"><thead><tr><th>区间</th><th class="num">PV（人类）</th>'
        . '<th class="num">UV（人类）</th><th class="num">爬虫命中</th></tr></thead><tbody>';
    foreach ([['今日', 'today'], ['近 7 天', 'd7'], ['近 30 天', 'd30']] as [$lab, $key]) {
        $h .= '<tr><td>' . e($lab) . '</td><td class="num"><b>' . (int)$ck[$key]['pv'] . '</b></td>'
            . '<td class="num">' . (int)$ck[$key]['uv'] . '</td><td class="num">' . (int)$ck[$key]['bot'] . '</td></tr>';
    }
    $h .= '</tbody></table></div>';
    $h .= '</div>';
    // ★ 2026-09-14：外链只在**用户自己配了外部统计域名**时才给（老实现写死 tj 后台的外链）
    $pub = function_exists('statx_collect_pub_origin') ? statx_collect_pub_origin() : '';
    $selfHost = (string)parse_url($pub !== '' ? $pub : 'https://' . statx_collect_site_key(), PHP_URL_HOST);
    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>外部统计端点</h2></div>';
    if ($pub !== '' && strcasecmp($selfHost, statx_collect_site_key()) !== 0) {
        $h .= '<a class="stx-btn ghost" target="_blank" rel="noopener noreferrer" href="' . e(rtrim($pub, '/')) . '/admin">打开端点后台 →</a>'
            . stx_muted('只给一个**外链**（不用 iframe、不读它的接口、不抓它的页面）。');
    } else {
        $h .= stx_muted('本站用的是**站内端点**（`' . e(statx_collect_pub_origin() !== '' ? statx_collect_pub_origin() : '未配置') . '`）——'
            . '数据就在你自己的库里，没有别人家的后台可打开。');
    }
    $h .= '</div>';
    return ['kind' => 'page', 'body' => $h];
}
