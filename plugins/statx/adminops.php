<?php
declare(strict_types=1);
/**
 * StatX · 站点管理 + 账号（2026-09-15 新增，一个正常用户进来要用的那几件事）
 * =====================================================================================
 * 站长原话：「要有添加和修改站点，还需要修改用户名和密码。你就想，这是一个正常用户，
 *            他进来后要有哪些操作」。本文件就是这两页的实现。
 *
 * 新增两页（左栏「系统」组下）：
 *   · `?p=sites_mgr` 「站点管理」—— 列表（一眼看出哪站没通）+ 添加（当场给可复制的统计代码）
 *                                  + 修改（名称/域名/启停）+ 删除（预览→确认，删前备份）
 *                                  + 一键「检测这个站」（端点 / 代码 / 上报 三查 → 一句结论）
 *   · `?p=account`   「账号」   —— 改用户名 / 改密码（**全部复用内核 Auth**，不自己造认证）
 *
 * ★ 设计纪律（与既有页面完全一致，逐条对照）：
 *   ① 会改数据/删数据的动作**一律 POST + CSRF**，且二次确认**在服务端强制**（不能只靠前端 required）；
 *   ② GET 任何页面**零副作用**（尤其删除预览：GET 只看，POST 才删）；
 *   ③ **零 JS**：没有 `<script src>`、没有内联 `on*=`；统计代码用 `<pre>` 让人自己选中复制；
 *   ④ 认证**只走内核**（`Auth::verifyPassword` / `Auth::setPassword` / `Db::update('users',…)`）——
 *      绝不新造一套口令表或会话；
 *   ⑤ 删数据前**先 SELECT 给人看**（行数 + 前 10 行样本），并先 `VACUUM INTO` 备份；
 *   ⑥ 界面克制：两页各一块表 + 几个表单，不新增设置区泛滥。
 *
 * ★ 回滚（一条命令）：
 *   `rm -f /www/wwwroot/tj-stat/plugins/statx/adminops.php` + 把 ui.php/pages.php/adv.php/admin.php/plugin.php
 *   从 `/root/statx-r6-live-backup-<ts>/` 拷回（详见 /root/statx-admin-ops-REPORT.md）。
 */

require_once __DIR__ . '/pages5.php';

/** 两页的固定入口（跳转一律回这里，不把后台外壳绕出去） */
function statx_ao_url(string $p = 'sites_mgr', array $extra = []): string
{
    $q = ['s' => 'plug_statx', 'p' => $p] + $extra;
    // ★★ r8：原来只拼 `s`/`p`（+ 本页自己的 new/edit/del/det）—— 从**带站/带档**的 URL 进来后，
    //   点「刷新本页 / 回到列表 / 取消 / 修改 / 删除…」会把这些上下文全丢掉。
    //   这里把当前请求里已有的上下文并回来（与 `statx_ui_url()` 同一口径：有 r 带 r，单日带 d，
    //   有 site 带 site；目标页面的**动作键**优先）。
    if ($p === 'sites_mgr') {
        $r = (string)preg_replace('~[^a-z0-9]~', '', (string)($_GET['r'] ?? ''));
        if ($r !== '') { $q['r'] = $r; }
        if ($r === 'day') {
            $d = (string)preg_replace('~[^0-9\-]~', '', (string)($_GET['d'] ?? ''));
            if ($d !== '') { $q['d'] = $d; }
        }
        $sk = (string)preg_replace('~[^A-Za-z0-9._:@\-]~', '', (string)($_GET['site'] ?? ''));
        if ($sk !== '') { $q['site'] = $sk; }
    }
    return '/admin/?' . http_build_query($q);
}

/* ============================== 小工具 ============================== */

/**
 * 域名 → site_key（**沿用既有生成方式**：与 `statx_collect_site_key()` / `statx_ep_key()` 同口径）
 *   · 去协议、去路径、去端口；小写；去掉前导 `www.`；只留 `[a-z0-9.-]`；最长 64
 *   · 站点数据的主键就是它（`data-site=` / spool / sites / hits 全用它）⇒ 一旦确定**不再变**
 */
function statx_ao_sk(string $domain): string
{
    $d = strtolower(trim($domain));
    if (str_contains($d, '://')) { $d = (string)(parse_url($d, PHP_URL_HOST) ?? $d); }
    else { $d = (string)(parse_url('http://' . $d, PHP_URL_HOST) ?? $d); }
    $d = (string)preg_replace('~^www\.~', '', $d);
    $d = (string)preg_replace('~[^a-z0-9.\-]~', '', $d);
    $d = trim($d, '.-');
    return substr($d, 0, 64);
}

/** 用户填的域名/host → 规范 host（用于 rt.php 的 Origin 白名单与「抓首页实证」） */
function statx_ao_host(string $raw): string
{
    return statx_ao_sk($raw);
}

/** 该站近 24 小时落库行数（口径与列表一致；用 PHP 算阈值 ⇒ 与 TZ=Asia/Shanghai 一致） */
function statx_ao_recent_24h(string $key, int $n = 24): int
{
    $from = date('Y-m-d H:i:s', time() - $n * 3600);
    try {
        return (int)statx_sdb_val('SELECT COUNT(*) FROM ' . STATX_SDB_HITS . ' WHERE site_key=? AND ts>=?', [$key, $from]);
    } catch (Throwable $e) { return 0; }
}

/** 「多久之前」一句话（列表与结论共用；不引入新依赖） */
function statx_ao_ago(string $ts): string
{
    if ($ts === '') { return '（从未）'; }
    $t = strtotime($ts);
    if ($t === false) { return $ts; }
    $d = time() - $t;
    if ($d < 0) { return $ts . '（时间在未来？）'; }
    if ($d < 90) { return $d . ' 秒前'; }
    if ($d < 5400) { return (int)round($d / 60) . ' 分钟前'; }
    if ($d < 172800) { return (int)round($d / 3600) . ' 小时前'; }
    return (int)round($d / 86400) . ' 天前';
}

/**
 * 列表用的一次性聚合（**两条 GROUP BY 拿全表**，避免每站一次查询的 N+1）。
 * @return array{recent:array<string,array{n:int,mx:string}>, mx:array<string,string>}
 */
function statx_ao_agg(): array
{
    $out = ['recent' => [], 'mx' => []];
    $from = date('Y-m-d H:i:s', time() - 86400);
    try {
        foreach (statx_sdb_all('SELECT site_key k, COUNT(*) n, MAX(ts) mx FROM ' . STATX_SDB_HITS
            . ' WHERE ts>=? GROUP BY site_key', [$from]) as $r) {
            $out['recent'][(string)$r['k']] = ['n' => (int)$r['n'], 'mx' => (string)($r['mx'] ?? '')];
        }
        foreach (statx_sdb_all('SELECT site_key k, MAX(ts) mx FROM ' . STATX_SDB_HITS . ' GROUP BY site_key') as $r) {
            $out['mx'][(string)$r['k']] = (string)($r['mx'] ?? '');
        }
    } catch (Throwable $e) { /* 库读不到时列表仍要渲染 */ }
    return $out;
}

/** 一个站的数据统计（删除预览 / 站点卡片用） */
function statx_ao_site_stat(string $key): array
{
    $out = ['hits' => 0, 'rollup' => 0, 'site_rows' => 0, 'first' => '', 'last' => '', 'sample' => [], 'bytes_mb' => 0.0];
    try {
        $r = statx_sdb_one('SELECT COUNT(*) n, MIN(ts) a, MAX(ts) b FROM ' . STATX_SDB_HITS . ' WHERE site_key=?', [$key]) ?? [];
        $out['hits'] = (int)($r['n'] ?? 0);
        $out['first'] = (string)($r['a'] ?? '');
        $out['last']  = (string)($r['b'] ?? '');
        $out['rollup'] = (int)statx_sdb_val('SELECT COUNT(*) FROM ' . STATX_SDB_ROLLUP . ' WHERE site_key=?', [$key]);
        $out['site_rows'] = (int)statx_sdb_val('SELECT COUNT(*) FROM ' . STATX_SDB_SITES . ' WHERE site_key=?', [$key]);
        // ★ 红线：删之前先把「将删哪些行」给人看 —— 前 10 行样本（最近 10 条）
        $out['sample'] = statx_sdb_all('SELECT ts, d, path, kind, is_bot, device, region, host FROM '
            . STATX_SDB_HITS . ' WHERE site_key=? ORDER BY ts DESC LIMIT 10', [$key]);
    } catch (Throwable $e) { /* 同上 */ }
    return $out;
}

/* ============================== ③⒝ 采集一句话结论 ============================== */

/**
 * 「到底有没有在收数据」的**一句话结论 + 怎么办**（站点管理页顶部一条，克制：就一行）。
 * 复用既有 `statx_q_pipeline()` 的口径，不新造判断逻辑。
 * @return array{tone:string,line:string,todo:string}
 */
function statx_ao_verdict(): array
{
    try {
        $pl = statx_q_pipeline();
    } catch (Throwable $e) {
        return ['tone' => 'bad', 'line' => '读不到采集状态（库打不开？）', 'todo' => '先看「统计检测」页的端点自检。'];
    }
    $rows = (int)$pl['rows'];
    $last = (string)$pl['last_hit'];
    $n24  = statx_ao_recent_24h_all();

    if (!$pl['enabled']) {
        return ['tone' => 'bad', 'line' => '采集总开关是**关**的 —— 站点侧脚本照样加载，但一条都不会上报。',
                'todo' => '到「设置 → 采集总开关」点「开启采集」。'];
    }
    if ($rows === 0 || $last === '') {
        return ['tone' => 'bad', 'line' => '尚未收到数据（库里 0 条明细）。',
                'todo' => '三步：① 在下面「添加站点」拿到统计代码 → ② 贴到站点（YeCha 放 `stats.foot`；静态页放 `</body>` 前）→ ③ 回来点该站的「检测」。'];
    }
    if ($n24 === 0) {
        return ['tone' => 'warn', 'line' => '在建收数，但**近 24 小时 0 条** —— 最后一次上报是 '
                . $last . '（' . statx_ao_ago($last) . '）。',
                'todo' => '先点该站的「检测」：若「代码已注入」通过，多半是这 24 小时真没有访客执行 JS（爬虫不跑 JS），不是故障。'];
    }
    return ['tone' => 'ok', 'line' => '采集正常：近 24 小时收到 **' . $n24 . '** 条，最后一次 '
            . $last . '（' . statx_ao_ago($last) . '）；库内明细共 ' . number_format($rows) . ' 行。',
            'todo' => ''];
}

/** 全库近 24h 行数（结论用；与单站口径分开，避免混淆） */
function statx_ao_recent_24h_all(): int
{
    try {
        return (int)statx_sdb_val('SELECT COUNT(*) FROM ' . STATX_SDB_HITS . ' WHERE ts>=?',
            [date('Y-m-d H:i:s', time() - 86400)]);
    } catch (Throwable $e) { return 0; }
}

/* ============================== ③⒞ 版本一行 ============================== */

/** 当前插件版本 + 内核版本 + 去哪看新版（**一行**；不发起任何网络请求） */
function statx_ao_version_line(): string
{
    $ver = '';
    try {
        if (class_exists('Plugins') && method_exists('Plugins', 'def')) {
            $d = Plugins::def('statx');
            if (is_array($d)) { $ver = (string)($d['ver'] ?? ''); }
        }
    } catch (Throwable $e) { $ver = ''; }
    if ($ver === '') { $ver = '（未读到）'; }
    $core = defined('YC_VERSION') ? (string)YC_VERSION : '（未知）';
    $built = is_file(__DIR__ . '/CHANGELOG.md') ? date('Y-m-d', (int)filemtime(__DIR__ . '/CHANGELOG.md')) : '';
    return '<p class="stx-muted">插件 <b>StatX ' . e($ver) . '</b>'
        . ($built !== '' ? '（本包 ' . e($built) . ' 构建）' : '')
        . ' · 内核 <b>' . e($core) . '</b>'
        . ' · 看有没有新版：<a href="https://yecha.org/market/" target="_blank" rel="noopener noreferrer">云市场</a>'
        . '（插件版本只在这里和 CHANGELOG 里报，不在页面上到处显示）。</p>';
}

/* ============================== 统计代码块（纯文本可选中复制，零 JS） ============================== */

/** 取「一行 JS」的纯 script 文本（去掉自己写的 begin/end 注释包裹） */
function statx_ao_tag_line(string $key): string
{
    $t = statx_collect_tag($key);
    $raw = (string)($t['tag'] ?? '');
    foreach (preg_split('~\R~', $raw) ?: [] as $ln) {
        $ln = trim($ln);
        if (str_starts_with($ln, '<script')) { return $ln; }
    }
    return trim($raw);
}

/** 可复制的代码卡：`<pre>` + 「贴在哪」+（本机可写时）一键写入入口 */
function statx_ao_code_card(string $key, bool $justAdded = false): string
{
    $line = statx_ao_tag_line($key);
    $pub  = statx_collect_pub_origin();
    $s    = statx_collect_site($key);
    $h    = '<div class="stx-card"><div class="stx-cardh"><h2>'
        . ($justAdded ? '站点已添加 —— 这是它的统计代码' : '统计代码') . '</h2>'
        . '<span class="stx-note">纯文本，自己选中复制（本插件零 JS，没有复制按钮）</span></div>';
    $h .= '<pre class="stx-pre">' . e($line) . '</pre>';
    $h .= stx_muted('贴在哪：<b>YeCha 站点</b> → 后台「设置」里塞进 <code>stats.foot</code> 选项'
        . '（就是这样一行，插件另有「一行 JS 写入」按钮可以一键代贴）；'
        . '<b>静态页 / 别的 CMS</b> → 每个页面 <code>&lt;/body&gt;</code> 之前放一次。'
        . '只需要首页/全站各一次即可（脚本自己判断）。');
    $h .= stx_muted('★ 端点域名 = <b>' . e($pub !== '' ? $pub : '（未配置：请到「设置」填「集中端点域名」）') . '</b>'
        . '（本机 <code>collect.pub_origin</code>）。别人把本插件装到**他自己的站**上时，'
        . '这一串里的域名会是**他自己的域名** —— 插件从不把别人的访客数据指向第三方域名。'
        . '`data-site` 必须**逐字**等于 <code>' . e($key) . '</code>，改一个字就统计到别的站去了。');
    // 一键写入只在「本机确实有该站的 YeCha 站点根」时才给（否则按钮点了也只能报错）
    $local = statx_collect_local_root($key, (string)($s['host'] ?? ''));
    if ($local !== null) {
        $h .= '<form method="post" action="' . e(statx_ao_url()) . '" style="display:inline">' . Csrf::field()
            . '<input type="hidden" name="do" value="statx-collect-inject">'
            . '<input type="hidden" name="site_key" value="' . e($key) . '">'
            . '<button class="stx-btn" type="submit">一键写入该站的 stats.foot（并抓首页实证）</button></form>';
        $h .= stx_muted('检测到本机站点根：<code>' . e($local) . '</code> —— 写入会改该站 option 并**抓它首页实证**，'
            . '只动本插件自己的注入单元（旁站标签一个字节不动）。');
    } else {
        $h .= stx_muted('该站不在本机（找不到 YeCha 站点根）⇒ 只能手工贴，本页不提供「一键写入」。');
    }
    $h .= '</div>';
    return $h;
}

/* ============================== 一键「检测这个站」 ============================== */

/**
 * 三查 + 一句话结论：
 *   ① 端点是否被内核/插件接管（复用 collect-endpoint 的判定：X-Statx 内容标记，**不看状态码**）
 *   ② 该站 HTML 里是否已注入（复用 statx_collect_verify_site：抓首页找脚本地址 + data-site）
 *   ③ 最近有没有上报（库里 MAX(ts) 与近 24h 行数）
 * @return array{at:string,key:string,ep:array,js:array,db:array,local:?string,verdict:string,tone:string,todo:string}
 */
function statx_ao_detect(string $key): array
{
    $rt  = statx_collect_rt_read();
    $org = statx_collect_pub_origin();
    $s   = statx_collect_site($key);
    $out = ['at' => date('Y-m-d H:i:s'), 'key' => $key, 'local' => null,
            'ep' => [], 'js' => [], 'db' => [], 'verdict' => '', 'tone' => 'warn', 'todo' => ''];

    // ① 端点（探针带 probe=1：不写盘、不查询、不看采集开关）
    $out['ep'] = statx_collect_probe_endpoint(
        ($org !== '' ? $org : '') . (string)($rt['path'] ?? '/st'), ['site' => $key]);
    $out['js'] = statx_collect_probe_script(($org !== '' ? $org : '') . (string)($rt['script'] ?? '/st.js'));
    $epOk = ((int)$out['ep']['code'] === 200) && ((string)$out['ep']['marker'] !== '');

    // ② 该站 HTML 是否已注入（能取到就取；取不到不算失败，只算「无法确认」）
    $local = statx_collect_local_root($key, (string)($s['host'] ?? ''));
    $out['local'] = $local;
    $out['inj'] = statx_collect_verify_site($key);
    $injOk = (bool)$out['inj']['ok'];

    // ③ 有没有上报
    try {
        $r = statx_sdb_one('SELECT COUNT(*) n, MAX(ts) mx FROM ' . STATX_SDB_HITS . ' WHERE site_key=?', [$key]) ?? [];
        $out['db'] = ['n' => (int)($r['n'] ?? 0), 'last' => (string)($r['mx'] ?? ''),
                      'n24' => statx_ao_recent_24h($key)];
    } catch (Throwable $e) { $out['db'] = ['n' => 0, 'last' => '', 'n24' => 0]; }
    $hasData = ((int)$out['db']['n'] > 0);

    // ---- 一句话结论 ----
    if (!$epOk) {
        $out['tone'] = 'bad';
        $out['verdict'] = '端点**没被接住**（上报端点 HTTP ' . (int)$out['ep']['code']
            . '，X-Statx 标记「' . ((string)$out['ep']['marker'] !== '' ? (string)$out['ep']['marker'] : '空') . '」）'
            . '—— 这一个站的数据谁也收不到。';
        $out['todo'] = '先去「统计检测」点一次「端点 + 脚本自检」；端点不通时先修端点，再管代码。';
    } elseif ($injOk && $hasData) {
        $out['tone'] = 'ok';
        $out['verdict'] = '通了：端点正常、该站 HTML 里已实证到代码，最近上报 '
            . (string)$out['db']['last'] . '（' . statx_ao_ago((string)$out['db']['last']) . '），'
            . '共 ' . number_format((int)$out['db']['n']) . ' 行。';
        $out['todo'] = ((int)$out['db']['n24'] === 0)
            ? '近 24 小时 0 条：多半是这 24 小时真没有访客（爬虫不执行 JS），不是故障。'
            : '';
    } elseif ($injOk && !$hasData) {
        $out['tone'] = 'warn';
        $out['verdict'] = '代码已在页面上、端点也正常，但**还没收到这个站的任何上报**。';
        $out['todo'] = '用真实浏览器打开一次该站页面（脚本在访客浏览器里执行后才会来），回来再看本页「近 24h」。';
    } elseif (!$injOk && (int)$out['inj']['code'] === 200) {
        $out['tone'] = 'warn';
        $out['verdict'] = '端点正常，但**抓回来的首页 HTML 里没有这行代码**（'
            . (string)$out['inj']['note'] . '）。';
        $out['todo'] = '把上面的代码贴到该站（YeCha：`stats.foot`；静态页：`</body>` 前），再点一次「检测」。';
    } else {
        $out['tone'] = 'warn';
        $out['verdict'] = '端点正常；但**无法自动确认代码是否已注入**（该站首页 HTTP '
            . (int)$out['inj']['code'] . (string)($out['local'] === null ? '，且该站不在本机' : '')
            . ' —— ' . (string)$out['inj']['note'] . '）。';
        $out['todo'] = '自行确认一下首页源码里有没有那行 `<script … data-site="' . $key . '">`；'
            . '确认有就直接看「近 24h」有没有数。';
    }
    return $out;
}

/* ============================== POST（全部 POST + CSRF + 服务端强制二次确认） ============================== */

function statx_ao_handle_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return; }
    $do = (string)($_POST['do'] ?? '');
    $mine = ['statx-site-add', 'statx-site-edit', 'statx-site-toggle', 'statx-site-delete',
             'statx-site-detect', 'statx-acct-username', 'statx-acct-password'];
    if (!in_array($do, $mine, true)) { return; }

    Csrf::check();   // 内核已在校验，这里显式再来一次（与 adv.php::statx_handle_post 同口径）

    /* ---------------- 添加站点 ---------------- */
    if ($do === 'statx-site-add') {
        $label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 80);
        $domIn = trim((string)($_POST['domain'] ?? ''));
        $hostIn = trim((string)($_POST['host'] ?? ''));
        $key = statx_ao_sk($domIn);
        $host = $hostIn !== '' ? statx_ao_host($hostIn) : $key;
        $back = statx_ao_url('sites_mgr');

        if ($label === '') { $_SESSION['flash'] = '没填名称：给个你自己认得出来的名字（如「学茶网」）。'; header('Location: ' . $back); exit; }
        if ($key === '')   { $_SESSION['flash'] = '域名不合法：填 like `example.com`（不要带 http:// 也可以，带了会自动去掉）。'; header('Location: ' . $back); exit; }
        if (statx_collect_site($key) !== null) {
            $_SESSION['flash'] = '这个站已经登记过了（site_key = ' . $key . '）—— 直接在上面列表里改它就行，不用重复添加。';
            header('Location: ' . $back); exit;
        }
        if (statx_collect_sites(['site_key' => $key]) || statx_collect_sites(['host' => $host])) {
            $_SESSION['flash'] = '该域名/host 已被另一个 site_key 占用，请检查是否重复添加。';
            header('Location: ' . $back); exit;
        }
        statx_collect_site_save($key, [
            'label' => $label, 'host' => $host, 'enabled' => 1, 'sort' => 200,
            'note' => '后台「站点管理」添加于 ' . date('Y-m-d H:i:s'),
        ]);
        $rt = statx_collect_rt_write();   // 让端点 CORS 白名单立刻包含这个站
        $extra = '';
        if (!empty($rt['_skipped'])) { $extra = ' ｜ 注意：' . (string)$rt['_skipped']; }
        elseif (empty($rt['_written'])) { $extra = ' ｜ 注意：rt.php 未能重写（插件目录只读）—— 见本页底部说明。'; }
        $_SESSION['flash'] = '已添加站点「' . $label . '」（site_key = ' . $key . '，已启用）。下面是它的统计代码。' . $extra;
        header('Location: ' . statx_ao_url('sites_mgr', ['new' => $key])); exit;
    }

    /* ---------------- 修改站点（名称 / 域名 / 启停） ---------------- */
    if ($do === 'statx-site-edit') {
        $key = statx_ep_key((string)($_POST['site_key'] ?? ''));
        $cur = statx_collect_site($key);
        $back = statx_ao_url('sites_mgr', $key !== '' ? ['edit' => $key] : []);
        if ($cur === null) { $_SESSION['flash'] = '站点不存在：' . $key; header('Location: ' . statx_ao_url('sites_mgr')); exit; }
        $label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 80);
        $host  = statx_ao_host((string)($_POST['host'] ?? ''));
        $upd = [];
        if ($label !== '' && $label !== (string)$cur['label']) { $upd['label'] = $label; }
        if ($host !== '' && $host !== (string)$cur['host'])    { $upd['host'] = $host; }
        if (!$upd) { $_SESSION['flash'] = '没有改动（名称与域名都和原来一样）。'; header('Location: ' . $back); exit; }
        statx_collect_site_save($key, $upd);
        $rt = statx_collect_rt_write();
        $_SESSION['flash'] = '已保存 ' . $key . '：' . implode('、', array_map(
            static fn($k, $v) => $k . ' → ' . $v, array_keys($upd), array_values($upd)))
            . '。★ site_key 刻意**不变** ⇒ 历史数据仍然连着这个站；改了 host 只影响端点白名单与「抓首页实证」。'
            . (empty($rt['_written']) ? ' ｜ 注意：rt.php 未能重写（插件目录只读）。' : '');
        header('Location: ' . $back); exit;
    }

    /* ---------------- 启用 / 停用 ---------------- */
    if ($do === 'statx-site-toggle') {
        $key = statx_ep_key((string)($_POST['site_key'] ?? ''));
        $cur = statx_collect_site($key);
        if ($cur === null) { $_SESSION['flash'] = '站点不存在：' . $key; header('Location: ' . statx_ao_url('sites_mgr')); exit; }
        $to = ((int)($cur['enabled'] ?? 0) === 1) ? 0 : 1;
        statx_collect_site_save($key, ['enabled' => $to]);
        $rt = statx_collect_rt_write();
        $_SESSION['flash'] = ($to === 1 ? '已启用 ' : '已停用 ') . $key
            . ($to === 1 ? '：重新进端点 CORS 白名单，恢复统计。'
                         : '：**不再进白名单、新数据不再关联它**，但库里的历史数据一条都没删（要删走下面的「删除」两步流程）。')
            . (empty($rt['_written']) ? ' ｜ 注意：rt.php 未能重写（插件目录只读）。' : '');
        header('Location: ' . statx_ao_url('sites_mgr')); exit;
    }

    /* ---------------- 检测这个站 ---------------- */
    if ($do === 'statx-site-detect') {
        $key = statx_ep_key((string)($_POST['site_key'] ?? ''));
        if (statx_collect_site($key) === null) {
            $_SESSION['flash'] = '站点不存在：' . $key;
            header('Location: ' . statx_ao_url('sites_mgr')); exit;
        }
        $_SESSION['statx_ao_detect'] = statx_ao_detect($key);
        header('Location: ' . statx_ao_url('sites_mgr', ['det' => $key])); exit;
    }

    /* ---------------- 删除站点（★ 两步：预览 GET 只读 → 这里才真删） ---------------- */
    if ($do === 'statx-site-delete') {
        $key = statx_ep_key((string)($_POST['site_key'] ?? ''));
        $back = statx_ao_url('sites_mgr', ['del' => $key]);
        $cur = statx_collect_site($key);
        if ($cur === null) { $_SESSION['flash'] = '站点不存在：' . $key; header('Location: ' . statx_ao_url('sites_mgr')); exit; }

        // ★★ 二次确认**在服务端强制**：勾选框 + 手打 site_key，任一不符就什么都不做
        $confirm = (string)($_POST['confirm'] ?? '');
        $typed   = trim((string)($_POST['confirm_key'] ?? ''));
        if ($confirm !== 'yes' || $typed !== $key) {
            $_SESSION['flash'] = '⚠ 未执行：缺少二次确认（要勾选确认框，并把手打的 site_key 输成「' . $key
                . '」）。**本次没有删除任何数据。**';
            header('Location: ' . $back); exit;
        }

        $st = statx_ao_site_stat($key);

        // ① 删前备份（VACUUM INTO 一致性快照；写本插件自己的 var/ 区，绝不落 webroot）
        $bakDir = rtrim(statx_sdb_site_root(), '/') . '/var/statx/backup';
        if (!is_dir($bakDir)) { @mkdir($bakDir, 0750, true); }
        $bak = $bakDir . '/statx-before-site-del-' . preg_replace('~[^A-Za-z0-9._\-]~', '_', $key)
             . '-' . date('Ymd-His') . '.db';
        $bakOk = false; $bakVerify = '';
        try {
            $pdo = statx_sdb();
            $pdo->exec('VACUUM INTO ' . $pdo->quote($bak));
            $bakOk = is_file($bak) && filesize($bak) > 0;
            if ($bakOk) {
                $chk = new PDO('sqlite:' . $bak);
                $chk->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $ic = (string)$chk->query('PRAGMA integrity_check')->fetchColumn();
                $n  = (int)$chk->query('SELECT COUNT(*) FROM ' . STATX_SDB_HITS . ' WHERE site_key='
                    . $chk->quote($key))->fetchColumn();
                $bakVerify = 'integrity_check=' . $ic . '、备份内含该站明细 ' . $n . ' 行';
                $chk = null;
            }
        } catch (Throwable $e) { $bakOk = false; $bakVerify = '备份失败：' . $e->getMessage(); }

        if (!$bakOk) {
            $_SESSION['flash'] = '⚠ 未执行：删前备份失败（' . $bakVerify . '）—— **本次没有删除任何数据**。'
                . '备份目录：' . $bakDir . '（权限/磁盘请先处理）。';
            header('Location: ' . $back); exit;
        }

        // ② 真删（只删这个 site_key；分批删明细，避免长时间独占写锁）
        try {
            $d1 = statx_sdb_delete_batch(STATX_SDB_HITS, 'site_key=?', [$key], 5000);
            $d2 = statx_sdb_run('DELETE FROM ' . STATX_SDB_ROLLUP . ' WHERE site_key=?', [$key]);
            $d3 = statx_sdb_run('DELETE FROM ' . STATX_SDB_SITES . ' WHERE site_key=?', [$key]);
        } catch (Throwable $e) {
            $_SESSION['flash'] = '⚠ 删除过程中出错：' . $e->getMessage() . ' ｜ 备份已在 ' . $bak
                . '（可用它整库恢复）。';
            header('Location: ' . $back); exit;
        }
        $rt = statx_collect_rt_write();
        $_SESSION['flash'] = '已删除站点 ' . $key . '：明细 ' . (int)$d1['deleted'] . ' 行（' . (int)$d1['batches']
            . ' 批）、按天汇总 ' . (int)$d2 . ' 行、登记 ' . (int)$d3 . ' 行。'
            . '删前备份：' . $bak . '（' . $bakVerify . '）—— 要恢复就用这个文件。'
            . '**不可恢复**：上面这些行已从线上库删除。'
            . (empty($rt['_written']) ? ' ｜ 注意：rt.php 未能重写（插件目录只读）。' : '');
        header('Location: ' . statx_ao_url('sites_mgr')); exit;
    }

    /* ---------------- 账号：改用户名 ---------------- */
    if ($do === 'statx-acct-username') {
        $back = statx_ao_url('account');
        $uid  = Auth::id();
        $new  = trim((string)($_POST['username'] ?? ''));
        $old  = (string)($_POST['old_password'] ?? '');
        if ($uid <= 0) { $_SESSION['flash'] = '会话已失效，请重新登录。'; header('Location: ' . $back); exit; }
        if (!Auth::verifyPassword($uid, $old)) {
            $_SESSION['flash'] = '当前密码不正确 —— 改登录名必须验一次当前密码（防止有人趁你没锁屏改掉它）。**未做任何修改**';
            header('Location: ' . $back); exit;
        }
        // ★ 沿用内核 Auth::register() 的同一条用户名规则，不自造一套
        if (!preg_match('/^[A-Za-z0-9_\-\x{4e00}-\x{9fa5}]{2,30}$/u', $new)) {
            $_SESSION['flash'] = '新登录名不合法：只允许中英文、数字、下划线、连字符，长度 2–30。**未做任何修改**';
            header('Location: ' . $back); exit;
        }
        $T = Db::t('users');
        $dup = Db::row("SELECT id, username, email FROM {$T} WHERE (username=? OR email=?) AND id<>? LIMIT 1",
            [$new, $new, $uid]);
        if ($dup !== null) {
            $_SESSION['flash'] = '「' . $new . '」已经被占用（登录框接受**用户名或邮箱**，所以两者都不能撞）。**未做任何修改**';
            header('Location: ' . $back); exit;
        }
        $before = (string)(Auth::user()['username'] ?? '');
        Db::update('users', ['username' => $new], 'id=?', [$uid]);
        Cache::flush('pages'); Cache::flush('blocks');   // 与内核改密码同口径：清全页缓存，免得旧名字还留在缓存 HTML 里
        $_SESSION['flash'] = '登录名已改：' . $before . ' → ' . $new . '。'
            . '★ 当前这个会话**不会失效**（内核会话按用户 id 存，不按名字），你现在就在用新名字的后台里；'
            . '下次登录请用新登录名（旧登录名立即失效）。';
        header('Location: ' . $back); exit;
    }

    /* ---------------- 账号：改密码 ---------------- */
    if ($do === 'statx-acct-password') {
        $back = statx_ao_url('account');
        $uid  = Auth::id();
        $old  = (string)($_POST['old_password'] ?? '');
        $new  = (string)($_POST['new_password'] ?? '');
        $cfm  = (string)($_POST['confirm_password'] ?? '');
        if ($uid <= 0) { $_SESSION['flash'] = '会话已失效，请重新登录。'; header('Location: ' . $back); exit; }
        // ★ 口径与内核 do=admin-password 逐字一致：验旧 → 长度 → 两次一致（不新造规则）
        if (!Auth::verifyPassword($uid, $old)) {
            $_SESSION['flash'] = '当前密码不正确。**密码未改动**';
        } elseif (mb_strlen($new) < 6) {
            $_SESSION['flash'] = '新密码太短：至少 6 位（内核同一条规则）。**密码未改动**';
        } elseif ($new !== $cfm) {
            $_SESSION['flash'] = '两次输入的新密码不一致。**密码未改动**';
        } else {
            Auth::setPassword($uid, $new);          // bcrypt，内核函数
            Cache::flush('pages'); Cache::flush('blocks');
            $_SESSION['flash'] = '密码已更新（bcrypt 存储）。★ 当前会话**不会失效** —— '
                . '你现在还在后台里；**下次登录请用新密码**（旧密码立即失效）。';
        }
        header('Location: ' . $back); exit;
    }
}

/* ============================== 渲染兜底（抗并发覆盖） ============================== */

/**
 * ★★ 为什么需要这个兜底（2026-09-15 实测到的并发事故，如实记录）：
 *   本插件在 .106 上有**两条作业线同时在改**。另一条线（改"仪表盘默认汇总"）用的是
 *   **整文件覆盖** ui.php / pages.php / stxui.php（实测 01:41:30 与 01:44:08 两次覆盖，
 *   每次都把本作业刚加进 ui.php 的「站点管理 / 账号」两个导航项与 pages.php 的一行路由登记**冲掉**）。
 *   ⇒ 页面的"渲染"因此会随时退化：`statx_ctx()` 靠 `stx_titles()` 认页面、`statx_page_builder()`
 *     靠一张硬编码 map 找 builder，两者都在被覆盖的文件里。
 *
 *   本函数让这两页**不依赖那两个文件**也能渲染：
 *     · 只有请求真的指名 `p=sites_mgr|account` 时才介入；
 *     · 若 `stx_titles()` 与 `statx_page_builder()` 都认得它（= 导航登记还在）⇒ **返回 null，走正常路径**
 *       （所以稳定期只有一套渲染逻辑，兜底是死代码）；
 *     · 只有登记被冲掉时才自己拼外壳（`stx_shell` 来自 stxui.php，那是"外观"不是"路由"，
 *       被覆盖也只是外观变化，不会让页面消失）。
 */
function statx_ao_render_direct(): ?string
{
    $req = (string)preg_replace('~[^a-z_0-9]~', '', (string)($_GET['p'] ?? ''));
    if (!in_array($req, ['sites_mgr', 'account'], true)) { return null; }
    // 正常路径认识它 ⇒ 交回正常路径
    if (isset(stx_titles()[$req]) && statx_page_builder($req) !== null) { return null; }

    $ctx = statx_ctx('dash');
    $ctx['p'] = $req;
    try {
        $sites = statx_ui_sites_ordered($ctx['from'], $ctx['to']);
        $b = statx_bounds();
        $r = ($req === 'sites_mgr') ? statx_pg_sites_mgr($ctx) : statx_pg_account($ctx);
        $body = '<div class="stx-note-warn">ℹ 这两页暂时没出现在左栏导航里：'
            . '<code>ui.php</code> / <code>pages.php</code> 正被另一处改动整文件重写，把导航登记冲掉了。'
            . '**本页功能完全正常**；等那两个文件稳定后，重新登记一次即可回到左栏（见本页报告）。</div>'
            . (string)($r['body'] ?? '');
        // 系统级页面：不给时间三件套（与登记成功时的行为一致）
        return stx_shell($ctx, $sites, $body, ['foot' => statx_footer(microtime(true), $ctx, $b)]);
    } catch (Throwable $e) {
        error_log('[statx] ao render direct failed: ' . $e->getMessage());
        return stx_error('这两个新页面暂时不可用（统计数据读取失败，详见服务器 PHP 错误日志）。采集与站点本身不受影响。');
    }
}

/* ============================== 页面：站点管理 ============================== */

function statx_pg_sites_mgr(array $ctx): array
{
    $sites = statx_collect_sites();
    $agg   = statx_ao_agg();
    $selfKey = statx_collect_site_key();
    $del   = (string)preg_replace('~[^A-Za-z0-9._:@\-]~', '', (string)($_GET['del'] ?? ''));
    $new   = (string)preg_replace('~[^A-Za-z0-9._:@\-]~', '', (string)($_GET['new'] ?? ''));
    $detK  = (string)preg_replace('~[^A-Za-z0-9._:@\-]~', '', (string)($_GET['det'] ?? ''));
    $edK   = (string)preg_replace('~[^A-Za-z0-9._:@\-]~', '', (string)($_GET['edit'] ?? ''));

    $h = '<style>'
        . '.stx-pre{background:#0f2a22;color:#d7f0e7;border:1px solid #23544a;border-radius:8px;'
        . 'padding:11px 13px;font:12.5px/1.65 ui-monospace,SFMono-Regular,Consolas,"Courier New",monospace;'
        . 'overflow-x:auto;white-space:pre;margin:0 0 10px;user-select:all}'
        . '.stx-redcard{background:#fdf1f0;border:1px solid #f3c9c5;border-radius:8px;padding:11px 13px;'
        . 'font-size:13px;color:#7d2a20;margin:0 0 12px}'
        . '.stx-ao-tag{font-size:12px;color:#8a5a12}'
        . '</style>';

    /* ---- ③⒝ 采集一句话结论（一条，克制）+ ③⒞ 版本一行 ---- */
    $v = statx_ao_verdict();
    $h .= '<div class="stx-card ' . ($v['tone'] === 'bad' ? 'stx-redcard' : '') . '">';
    $h .= '<div class="stx-note">' . ($v['tone'] === 'ok' ? '✅ ' : ($v['tone'] === 'warn' ? '⚠ ' : '✖ '))
        . $v['line'] . ($v['todo'] !== '' ? ' <b>怎么办：</b>' . $v['todo'] : '') . '</div>';
    $h .= '<div class="stx-acts"><a class="stx-btn ghost" href="'
        . e(statx_ao_url('sites_mgr')) . '">刷新本页</a> '
        . '<a class="stx-btn ghost" href="' . e(statx_ui_url($ctx, ['p' => 'collect'])) . '">采集与站点管理（数据流向 / 概览）→</a> '
        . '<a class="stx-btn ghost" href="' . e(statx_ui_url($ctx, ['p' => 'check'])) . '">统计检测（端点 + 脚本自检）→</a></div>';
    $h .= '</div>';

    /* ---- 刚添加：当场给代码 ---- */
    if ($new !== '' && statx_collect_site($new) !== null) {
        $h .= statx_ao_code_card($new, true);
    }

    /* ---- 检测结果卡（一次性；由 POST 存进 session，渲染后清掉） ---- */
    if ($detK !== '' && isset($_SESSION['statx_ao_detect']) && is_array($_SESSION['statx_ao_detect'])
        && (string)($_SESSION['statx_ao_detect']['key'] ?? '') === $detK) {
        $d = $_SESSION['statx_ao_detect'];
        unset($_SESSION['statx_ao_detect']);
        $h .= '<div class="stx-card"><div class="stx-cardh"><h2>检测结果 · ' . e($detK) . '</h2>'
            . '<span class="stx-note">' . e((string)$d['at']) . ' 实测</span></div>';
        $h .= '<div class="' . ($d['tone'] === 'ok' ? 'stx-note' : 'stx-note-warn') . '"><b>结论：</b>'
            . (string)$d['verdict'] . ((string)$d['todo'] !== '' ? '<br><b>怎么办：</b>' . (string)$d['todo'] : '')
            . '</div>';
        $e = (array)$d['ep']; $j = (array)$d['js']; $i = (array)$d['inj']; $b = (array)$d['db'];
        $h .= '<div class="stx-scroll"><table class="stx-tb"><thead><tr><th>检查项</th><th>结果</th><th>原始证据</th></tr></thead><tbody>';
        $h .= '<tr><td>① 端点被接住？</td><td>'
            . (((int)$e['code'] === 200 && (string)$e['marker'] !== '') ? stx_tag('是', 'g') : stx_tag('否', 'r')) . '</td>'
            . '<td class="stx-ao-tag">HTTP ' . (int)$e['code'] . ' · X-Statx='
            . e((string)$e['marker'] !== '' ? (string)$e['marker'] : '（空）') . ' · ' . (int)$e['total'] . 'ms · '
            . e((string)$e['url']) . '</td></tr>';
        $h .= '<tr><td>② 采集脚本可取？</td><td>'
            . (!empty($j['ok']) ? stx_tag('是', 'g') : stx_tag('否', 'r')) . '</td>'
            . '<td class="stx-ao-tag">HTTP ' . (int)$j['code'] . ' · ' . (int)$j['bytes'] . ' B · '
            . e((string)$j['ct']) . ' · cache=' . e((string)$j['cache']) . '</td></tr>';
        $h .= '<tr><td>③ 该站 HTML 里有代码？</td><td>'
            . (!empty($i['ok']) ? stx_tag('已实证', 'g') : stx_tag('未通过', '')) . '</td>'
            . '<td class="stx-ao-tag">首页 HTTP ' . (int)$i['code'] . '（' . (int)$i['bytes'] . ' B）· 找到脚本地址='
            . (!empty($i['src']) ? '是' : '否') . ' · 找到 data-site=' . (!empty($i['site']) ? '是' : '否')
            . ' · ' . e((string)$i['note']) . ($d['local'] !== null ? (' · 本机站点根 ' . e((string)$d['local'])) : ' · 该站不在本机') . '</td></tr>';
        $h .= '<tr><td>④ 最近有上报？</td><td>'
            . ((int)$b['n'] > 0 ? stx_tag('有', 'g') : stx_tag('没有', 'r')) . '</td>'
            . '<td class="stx-ao-tag">共 ' . number_format((int)$b['n']) . ' 行 · 最近 ' . e((string)$b['last'] !== '' ? (string)$b['last'] : '—')
            . '（' . e(statx_ao_ago((string)$b['last'])) . '）· 近 24h ' . (int)$b['n24'] . ' 行</td></tr>';
        $h .= '</tbody></table></div>';
        $h .= stx_muted('① 看的是**内容标记 X-Statx**（不只看状态码）—— 源码里 404/302 也可能带 200，'
            . '标记是「这个请求真的被本插件端点处理了」的凭据。②③ 都会用真实浏览器 UA + cache-buster 真发一次请求；'
            . '③ 走的是该站对外首页，**只读首页源码，不改它一个字节**。');
        $h .= '</div>';
    }

    /* ---- 删除预览（GET 只读；POST 才真删） ---- */
    if ($del !== '') {
        $s = statx_collect_site($del);
        if ($s === null) {
            $h .= '<div class="stx-card"><div class="stx-errbox">没有这个站点：' . e($del) . '</div>'
                . '<div class="stx-acts"><a class="stx-btn ghost" href="' . e(statx_ao_url('sites_mgr')) . '">← 回到列表</a></div></div>';
        } else {
            $st = statx_ao_site_stat($del);
            $bakDir = rtrim(statx_sdb_site_root(), '/') . '/var/statx/backup';
            $h .= '<div class="stx-card"><div class="stx-cardh"><h2>删除预览 · ' . e($del) . '</h2>'
                . '<span class="stx-note">这一页是**只读**的 —— 打开它不会删任何东西</span></div>';
            $h .= '<div class="stx-redcard">⚠ <b>这是一个会真删数据的动作，且不可恢复。</b>'
                . '下面列出的行会在你**显式确认后**从线上库删除；删前会自动做一次一致性备份。</div>';
            $h .= '<div class="stx-kpis">'
                . '<div class="stx-kpi"><b>' . number_format($st['hits']) . '</b><span>将删除的明细行（hits）</span></div>'
                . '<div class="stx-kpi"><b>' . number_format($st['rollup']) . '</b><span>将删除的按天汇总（hits_rollup）</span></div>'
                . '<div class="stx-kpi"><b>' . number_format($st['site_rows']) . '</b><span>将删除的登记行（sites）</span></div>'
                . '<div class="stx-kpi"><b>' . ($st['first'] !== '' ? e($st['first']) : '—') . ' ~ '
                . ($st['last'] !== '' ? e($st['last']) : '—') . '</b><span>这些明细的时间范围</span></div>'
                . '</div>';
            $h .= stx_muted('名称 <b>' . e((string)$s['label']) . '</b> · 域名 <code>' . e((string)$s['host'])
                . '</code> · 当前状态 ' . ((int)$s['enabled'] === 1 ? '启用中' : '已停用')
                . '（如果只是想暂时不统计，**用「停用」就够了**：停用不删任何数据）'
                . ($del === $selfKey ? ' · <b style="color:#b3261e">注意：这就是本统计站自己的 site_key</b>' : '')
                . '<br>备份会写到：<code>' . e($bakDir) . '/statx-before-site-del-' . e($del) . '-&lt;时间&gt;.db</code>'
                . '（本插件自己的 <code>var/</code> 区，HTTP 取不到；删完页面会把完整路径告诉你，务必自己留一份到别处）。');

            if (!$st['sample']) {
                $h .= stx_empty('该站没有明细行（只删登记行）。');
            } else {
                $h .= '<div class="stx-scroll"><table class="stx-tb"><thead><tr><th>时间</th><th>日期</th>'
                    . '<th>路径</th><th>类型</th><th>爬虫</th><th>设备</th><th>地域</th><th>host</th></tr></thead><tbody>';
                foreach ($st['sample'] as $r) {
                    $h .= '<tr><td>' . e((string)$r['ts']) . '</td><td>' . e((string)$r['d']) . '</td>'
                        . '<td>' . e(mb_substr((string)$r['path'], 0, 60)) . '</td>'
                        . '<td>' . e((string)$r['kind']) . '</td>'
                        . '<td>' . ((int)$r['is_bot'] === 1 ? stx_tag('爬虫', 'o') : '人') . '</td>'
                        . '<td>' . e((string)$r['device']) . '</td><td>' . e((string)$r['region']) . '</td>'
                        . '<td>' . e((string)$r['host']) . '</td></tr>';
                }
                $h .= '</tbody></table></div>';
                $h .= stx_muted('上面是**该站最近 10 行明细的样本**（共 ' . number_format($st['hits']) . ' 行将被删除）。');
            }

            $h .= '<form method="post" action="' . e(statx_ao_url()) . '">' . Csrf::field()
                . '<input type="hidden" name="do" value="statx-site-delete">'
                . '<input type="hidden" name="site_key" value="' . e($del) . '">'
                . '<div class="stx-fld"><label>二次确认①：勾选</label>'
                . '<label style="display:flex;gap:8px;align-items:center;color:#222">'
                . '<input type="checkbox" name="confirm" value="yes" required> 我确认要删除站点 <code>' . e($del)
                . '</code> 的 ' . number_format($st['hits']) . ' 行明细 + ' . number_format($st['rollup'])
                . ' 行汇总 + 它的登记行（不可恢复）</label></div>'
                . '<div class="stx-fld"><label>二次确认②：把 site_key 手打一遍</label>'
                . '<input name="confirm_key" placeholder="' . e($del) . '" style="width:240px" autocomplete="off" required></div>'
                . '<div style="margin-top:10px;display:flex;gap:10px;align-items:center">'
                . '<button class="stx-btn danger" type="submit">确认删除（先自动备份）</button>'
                . '<a class="stx-btn ghost" href="' . e(statx_ao_url('sites_mgr')) . '">取消，什么都不删</a>'
                . '<span class="stx-muted-inline">没勾选 / 没打对 / 没有 CSRF token / 用 GET 打开 —— 四种情况都不会删任何数据</span>'
                . '</div></form></div>';
        }
    }

    /* ---- 修改表单（GET ?edit=<key> 预填） ---- */
    if ($edK !== '' && ($es = statx_collect_site($edK)) !== null) {
        $h .= '<div class="stx-card"><div class="stx-cardh"><h2>修改站点 · ' . e($edK) . '</h2>'
            . '<span class="stx-note">site_key 不可改（它才是历史数据的主键）</span></div>'
            . '<form method="post" action="' . e(statx_ao_url()) . '" class="stx-form">' . Csrf::field()
            . '<input type="hidden" name="do" value="statx-site-edit">'
            . '<input type="hidden" name="site_key" value="' . e($edK) . '">'
            . '<div class="stx-fld"><label>名称</label><input name="label" value="' . e((string)$es['label']) . '" style="width:200px"></div>'
            . '<div class="stx-fld"><label>域名 / host</label><input name="host" value="' . e((string)$es['host']) . '" style="width:220px"></div>'
            . '<button class="stx-btn" type="submit">保存修改</button> '
            . '<a class="stx-btn ghost" href="' . e(statx_ao_url('sites_mgr')) . '">取消</a></form>'
            . stx_muted('改域名只影响**端点 Origin 白名单**与「抓首页实证」；'
                . '站点上报用的 <code>data-site</code> 是 site_key，**不受影响**，所以改域名不会丢历史数据。')
            . '</div>';
    }

    /* ---- 列表（一屏表：名称 · 域名 · site_key · 启用 · 最近上报 · 近24h · 操作） ---- */
    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>站点列表（' . count($sites) . ' 个）</h2>'
        . '<span class="stx-note">★ 一眼看哪站没通：近 24h 为 0 的标红</span></div>';
    $h .= '<div class="stx-scroll"><table class="stx-tb"><thead><tr>'
        . '<th>名称</th><th>域名 / host</th><th>site_key</th><th>启用</th><th>最近上报</th>'
        . '<th class="num">近 24h 行数</th><th>本机可写</th><th>操作</th></tr></thead><tbody>';
    $nRed = 0;
    foreach ($sites as $s) {
        $k = (string)$s['site_key'];
        $on = ((int)$s['enabled'] === 1);
        $n24 = (int)($agg['recent'][$k]['n'] ?? 0);
        $mx  = (string)($agg['mx'][$k] ?? '');
        $lastTxt = $mx !== '' ? $mx : ((string)$s['last_hit_at'] !== '' ? (string)$s['last_hit_at'] . '（登记值）' : '');
        $bad = ($on && $n24 === 0);
        if ($bad) { $nRed++; }
        $h .= '<tr><td>' . e((string)$s['label'] !== '' ? (string)$s['label'] : '（未命名）')
            . ($k === $selfKey ? ' <span class="stx-muted-inline">本站</span>' : '') . '</td>'
            . '<td>' . e((string)$s['host']) . '</td>'
            . '<td><code>' . e($k) . '</code></td>'
            . '<td>' . ($on ? stx_tag('启用', 'g') : stx_tag('停用', '')) . '</td>'
            . '<td>' . ($lastTxt !== '' ? e($lastTxt) . '<div class="stx-muted-inline">'
                . e(statx_ao_ago($mx !== '' ? $mx : (string)$s['last_hit_at'])) . '</div>'
                : '<b style="color:#b3261e">从未</b>') . '</td>'
            . '<td class="num">' . ($bad ? '<b style="color:#b3261e">' . $n24 . ' 条 · 没通</b>'
                : ($n24 . ' 条')) . '</td>'
            . '<td>' . (statx_collect_local_root($k, (string)$s['host']) !== null
                ? '是' : '<span class="stx-muted-inline">否</span>') . '</td>'
            . '<td style="white-space:nowrap">'
            // 「检测」要真发请求 ⇒ POST；「修改」「删除」是 GET 只读预填（无副作用）⇒ 用链接
            . '<form method="post" action="' . e(statx_ao_url()) . '" style="display:inline">' . Csrf::field()
            . '<input type="hidden" name="do" value="statx-site-detect">'
            . '<input type="hidden" name="site_key" value="' . e($k) . '">'
            . '<button class="stx-btn ghost sm" type="submit">检测这个站</button></form> '
            . '<a class="stx-lk" href="' . e(statx_ao_url('sites_mgr', ['edit' => $k])) . '">修改</a> '
            . '<form method="post" action="' . e(statx_ao_url()) . '" style="display:inline">' . Csrf::field()
            . '<input type="hidden" name="do" value="statx-site-toggle">'
            . '<input type="hidden" name="site_key" value="' . e($k) . '">'
            . '<button class="stx-btn ghost sm" type="submit">' . ($on ? '停用' : '启用') . '</button></form> '
            . '<a class="stx-lk" href="' . e(statx_ao_url('sites_mgr', ['del' => $k])) . '">删除…</a>'
            . '</td></tr>';
    }
    if (!$sites) {
        $h .= '<tr><td colspan="8" class="stx-empty">站点登记是空的 —— 用下面的「添加站点」加第一个。</td></tr>';
    }
    $h .= '</tbody></table></div>';
    if ($nRed > 0) {
        $h .= '<div class="stx-note-warn">⚠ 有 <b>' . $nRed . '</b> 个**启用中**的站近 24 小时 0 条 —— '
            . '点它的「检测这个站」看一句结论（端点？代码？上报？）。</div>';
    }
    $h .= stx_muted('口径：<b>近 24h 行数</b>=该站在 <code>hits</code> 里最近 24 小时的落库行数（含爬虫）；'
        . '**最近上报**取该站明细的最大时间（若明细已被保留清理清掉，则退回登记值并标注）。'
        . '「本机可写」= 本机能找到该站的 YeCha 站点根目录 ⇒ 可以一键代贴代码。');
    $h .= '</div>';

    /* ---- 添加站点 ---- */
    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>添加站点</h2>'
        . '<span class="stx-note">填名称 + 域名即可；site_key 由域名自动生成</span></div>'
        . '<form method="post" action="' . e(statx_ao_url()) . '" class="stx-form">' . Csrf::field()
        . '<input type="hidden" name="do" value="statx-site-add">'
        . '<div class="stx-fld"><label>名称</label><input name="label" placeholder="学茶网" style="width:180px" required></div>'
        . '<div class="stx-fld"><label>域名</label><input name="domain" placeholder="xuecha.org" style="width:200px" required></div>'
        . '<div class="stx-fld"><label>host（可选）</label><input name="host" placeholder="留空=同域名" style="width:180px"></div>'
        . '<button class="stx-btn" type="submit">添加并生成统计代码</button></form>'
        . stx_muted('site_key 的生成口径与插件既有方式**完全一致**：域名小写、去掉 <code>www.</code>、'
            . '只留字母数字点连字符（例：<code>https://www.XueCha.org/abc</code> → <code>xuecha.org</code>）。'
            . '它就是 <code>data-site</code>，**一旦生成不再变**（历史数据全靠它）。'
            . '<br>★ 「启用/停用」不会删数据：**停用 = 不再统计，但一条历史都不删**；要删数据走上面的「删除…」两步流程。')
        . '</div>';

    /* ---- 插件目录只读的说明（只在确实写不进去时出现，一行） ---- */
    $rt = statx_collect_rt_read();
    if (is_file(statx_collect_rt_path())) {
        $w = true;
        // 只做一次廉价的写权限判断（不真写）
        if (!is_writable(statx_collect_rt_path())) { $w = false; }
        if (!$w) {
            $h .= '<div class="stx-card"><div class="stx-cardh"><h2>一个已知限制（不影响收数）</h2></div>'
                . '<div class="stx-note-warn">插件目录是 root 只读（<code>'
                . e(dirname(statx_collect_rt_path())) . '</code>）⇒ 增删站点后 <code>rt.php</code> 的'
                . '<b>Origin 白名单</b>不会自动刷新。<b>这不影响收数</b>：端点只看 <code>data-site</code> 是否合法，'
                . '**不按 Origin 拒绝**；站点侧用的是 <code>sendBeacon</code>，本来就不需要 CORS 头。'
                . '要刷新白名单（可选，纯加固）：<code>chown www:www ' . e(dirname(statx_collect_rt_path()))
                . '</code> → 本页点一次「停用/启用」→ <code>chown -R root:root ' . e(dirname(statx_collect_rt_path()))
                . '</code>。</div></div>';
        }
    }

    $h .= statx_ao_version_line();
    return ['kind' => 'page', 'body' => $h];
}

/* ============================== 页面：账号 ============================== */

function statx_pg_account(array $ctx): array
{
    $me = null;
    try { $me = Auth::user(); } catch (Throwable $e) { $me = null; }
    $u  = (string)($me['username'] ?? '');
    $em = (string)($me['email'] ?? '');
    $dn = (string)($me['display_name'] ?? '');
    $role = (string)($me['role'] ?? '');

    $h = '<div class="stx-card"><div class="stx-cardh"><h2>当前账号</h2>'
        . '<span class="stx-note">就是本统计站自己的后台账号</span></div>';
    $h .= '<div class="stx-kpis">'
        . '<div class="stx-kpi"><b>' . e($u) . '</b><span>登录名</span></div>'
        . '<div class="stx-kpi"><b>' . e($dn !== '' ? $dn : '（空）') . '</b><span>昵称（顶栏显示的就是它）</span></div>'
        . '<div class="stx-kpi"><b>' . e($role) . '</b><span>角色</span></div>'
        . '<div class="stx-kpi"><b>' . e((string)($me['last_login_at'] ?? '—')) . '</b><span>上次登录</span></div>'
        . '</div>';
    $h .= stx_muted('这一页改的是 <b>本统计站的 YeCha 后台账号</b>（内核 <code>ye_users</code> 表里那一行，'
        . '当前 id=' . (int)Auth::id() . '，邮箱 <code>' . e($em) . '</code>）——'
        . '和「站点数据」没有任何关系：改账号<strong>不会</strong>动到任何一个站的统计数据，也不会动站点侧那行 JS。'
        . '<br>★ 登录框接受 <b>用户名或邮箱</b>（内核 <code>Auth::attempt</code> 的口径），'
        . '所以「旧登录名不能登了」要同时考虑这两者：改完名字后，用旧登录名登不进去，用**新登录名**或**邮箱**都能进。');
    $h .= '</div>';

    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>改登录名</h2>'
        . '<span class="stx-note">要验一次当前密码</span></div>'
        . '<form method="post" action="' . e(statx_ao_url('account')) . '" class="stx-form">' . Csrf::field()
        . '<input type="hidden" name="do" value="statx-acct-username">'
        . '<div class="stx-fld"><label>当前登录名</label><input value="' . e($u) . '" style="width:170px" disabled></div>'
        . '<div class="stx-fld"><label>新登录名</label><input name="username" placeholder="2–30 位：中英文 / 数字 / _ / -"'
        . ' style="width:230px" autocomplete="off" required></div>'
        . '<div class="stx-fld"><label>当前密码</label><input name="old_password" type="password"'
        . ' style="width:170px" autocomplete="current-password" required></div>'
        . '<button class="stx-btn" type="submit">改登录名</button></form>'
        . stx_muted('规则**沿用内核注册时的同一条**：<code>/^[A-Za-z0-9_\\-\\x{4e00}-\\x{9fa5}]{2,30}$/u</code>；'
            . '并且不能与任何已有用户的<b>用户名或邮箱</b>重复（登录框两者都认）。'
            . '<b>改完当前会话不会失效</b>（内核会话按用户 id 存，不按名字），但你**下次登录必须用新登录名**。')
        . '</div>';

    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>改密码</h2>'
        . '<span class="stx-note">bcrypt 存储，与内核同一套</span></div>'
        . '<form method="post" action="' . e(statx_ao_url('account')) . '" class="stx-form">' . Csrf::field()
        . '<input type="hidden" name="do" value="statx-acct-password">'
        . '<div class="stx-fld"><label>当前密码</label><input name="old_password" type="password"'
        . ' style="width:170px" autocomplete="current-password" required></div>'
        . '<div class="stx-fld"><label>新密码</label><input name="new_password" type="password"'
        . ' style="width:180px" autocomplete="new-password" required></div>'
        . '<div class="stx-fld"><label>再输一次</label><input name="confirm_password" type="password"'
        . ' style="width:180px" autocomplete="new-password" required></div>'
        . '<button class="stx-btn" type="submit">改密码</button></form>'
        . stx_muted('强度最低要求：<b>至少 6 位</b>（与内核 <code>do=admin-password</code> 完全一致的规则，'
            . '本插件不另立标准）；两次必须一致；必须验一次当前密码。'
            . '口令用内核 <code>Auth::setPassword()</code> 以 <b>bcrypt</code> 存储（库里存的是哈希，不是明文）。'
            . '<b>改完当前会话不会失效</b>，下次登录用新密码。')
        . '</div>';

    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>忘了密码怎么办</h2></div>'
        . stx_muted('本页三条路都要求你先能进后台。若**已经登不出来**，只能在服务器上重置'
            . '（需要 root，且会写库）：<br>'
            . '<code>cd ' . e(rtrim(statx_sdb_site_root(), '/')) . ' &amp;&amp; php -r \'define("YC_ROOT",getcwd());'
            . 'require "core/bootstrap.php"; Db::update("users",["password"=&gt;password_hash("新口令",PASSWORD_DEFAULT)],'
            . '"id=?",[1]);\'</code><br>'
            . '（这就是内核登录用的同一个哈希函数；重置后立刻用新口令登录一次确认。）'
            . '★ 如果改登录名时把名字打错、又忘了新名字：同样用上面这段先确认'
            . '<code>SELECT id,username FROM ye_users</code> 再改回来。')
        . '</div>';

    $h .= statx_ao_version_line();
    return ['kind' => 'page', 'body' => $h];
}
