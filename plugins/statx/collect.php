<?php
declare(strict_types=1);
/**
 * StatX · 能力① 采集侧的存储 / 运维层（由 inc.php require）
 *
 * ★★ 批次 1（基础层）起，**采集侧的数据全部落在「插件自带的 SQLite」**（见 sdb.php）：
 *    · 库文件默认在 `<站点根>/var/statx/statx.db`（站内 `var/`，内核/nginx 已拒绝直取），
 *      与 YeCha 内容库（MySQL）**彻底解耦**；路径可用选项/文件覆盖，见 sdb.php
 *    · 老的 4 张 MySQL 表（ye_statx_hits / _daily / _sites / _bots）**停止写入**，留作历史
 *      （`--task=collect-init` 会把站点登记与最近明细一次性搬进 SQLite，见 statx_collect_import_mysql）
 *    · 能力②「站点健康检测」仍留在 MySQL（ye_statx_health_*）—— 本批不动它
 *
 * 职责：
 *   · 本批的 3 张表（hits / hits_rollup / sites，结构与索引理由见 sdb.php）
 *   · rt.php（端点运行参数：**让端点在零 SQL 的前提下拿到配置**）
 *   · spool 收件（v2 行 → hits，**事务内批量 INSERT**；本批新增 is_new 判定 / utm 拆列 / 数值夹紧）
 *   · 汇总 rollup（hits → hits_rollup，按天，幂等 upsert）与保留清理 prune（**分批删**，不长时间持写锁）
 *   · 「一行 JS」注入：写入目标站 stats.foot（兼容 stats.head）+ 清该站全页缓存 + 抓首页实证校验
 *   · 看板取数 + 跨站注入（同机站点，用该站自己的 config.inc.php 凭据，绝不改内核/主题）
 *
 * ⚠️ 时间纪律（报告 §6 的教训）：**任何 SQL 都不许出现 NOW()/CURDATE() 做时间边界**，
 *    边界一律在 PHP 侧算好作为参数传入；SQLite 侧同理 —— `ts` 存北京时间字符串、`d` 是冗余的
 *    北京日期列，**查询里不做任何时区运算**（tj 的 `date(ts,'+8 hours')` 在这里变成一次索引查找）。
 *
 * ⚠️ 兼容口径：老代码里的 `statx_ct('hits')` 之类**已移除**（采集表不再是 ye_statx_*）。
 */

/* ============================== 表名 / 常量 ============================== */

/** 采集侧三张 SQLite 表（名字与 tj 对齐；`sites` 的列与老 MySQL 版一一对应） */
const STATX_SDB_HITS   = 'hits';
const STATX_SDB_ROLLUP = 'hits_rollup';
const STATX_SDB_SITES  = 'sites';

/** 采集脚本的静态副本路径（相对 webroot；任何 nginx 配置下都能被当静态文件取到） */
function statx_collect_static_script(): string { return '/plugins/statx/st.js'; }

/**
 * ★★ 采集端点的对外域名（**绝不回退到别人家的域名**，2026-09-14 重写）
 *
 * 老实现：`collect.pub_origin` 为空 → 回退 `https://tj.51puer.com`（统计服务器自己的域名）。
 * 后果：别人下载安装后，doc 里让用户贴的那一行 `<script>` 会把**他全部访客**的
 *       beacon 发到统计服务器，而该站又不在白名单里 ⇒ 数据出去了、看板还是空的。
 *       「下载即用」在这一点上是**不能接受**的。
 *
 * 新规则（顺序）：
 *   ① 选项 `collect.pub_origin` 明确配置的值；
 *   ② 选项 `site.url`（内核站点地址）的 origin；
 *   ③ Web 请求当下的 `$_SERVER['HTTP_HOST']`（同一台机器自己访问自己时成立）；
 *   ④ CLI 且都取不到 → 站点根目录名（站点根目录名就是域名时成立，宝塔/常规布局都如此）；
 *   ⑤ 仍然取不到 → **返回空串**，由调用方（设置页 / collect-inject）明确报错，
 *      而不是悄悄把数据发到第三方。
 */
function statx_collect_pub_origin(): string
{
    $o = trim((string)statx_opt('collect.pub_origin', ''));
    if ($o !== '') { return rtrim($o, '/'); }

    // ② 内核站点地址
    if (function_exists('get_option')) {
        $u = trim((string)get_option('site.url', ''));
        if ($u !== '') {
            $p = parse_url(str_contains($u, '://') ? $u : ('https://' . $u));
            $h = (string)($p['host'] ?? '');
            if ($h !== '') { return (strtolower((string)($p['scheme'] ?? 'https')) === 'http' ? 'http://' : 'https://') . $h; }
        }
    }

    // ③ 当前请求的 Host（仅 Web）
    if (PHP_SAPI !== 'cli') {
        $h = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if (preg_match('~^[a-z0-9.\-]+(:\d+)?$~', $h)) { return 'https://' . $h; }
    }

    // ④ 站点根目录名就是域名时成立（BT/常规布局；否则下一步返回空）
    $k = statx_collect_site_key();
    if (preg_match('~^[a-z0-9\-]+(\.[a-z0-9\-]+)+$~', $k)) { return 'https://' . $k; }

    // ⑤ 取不到 —— 交给调用方报错（**不回退到任何第三方域名**）
    return '';
}

/**
 * 建表（幂等）—— 批次 1 起改为 **插件自带 SQLite**（sdb.php）。
 * 保留原函数名，让 admin.php / cli.php 的调用点零改动；返回可打印的日志行。
 */
function statx_collect_ensure_schema(): array
{
    statx_sync_tz();
    try {
        return statx_sdb_init();
    } catch (Throwable $e) {
        return ['sqlite schema FAILED: ' . $e->getMessage()];
    }
}

/** 存储层一览（路径/权限/库大小/WAL/行数/单行字节）—— collect-status / --task=sdb 用 */
function statx_collect_storage(): array
{
    $info = ['file' => statx_sdb_file_info(), 'stats' => [], 'error' => ''];
    try {
        $info['stats'] = statx_sdb_stats();
    } catch (Throwable $e) {
        $info['error'] = $e->getMessage();
    }
    return $info;
}

/**
 * 一次性把**老的 MySQL 采集表**搬进 SQLite（只搬站点登记 + 保留窗口内的明细；幂等）。
 * 目的：切换存储时不丢站点配置与最近数据；老表**不删**（连数据一起留作历史）。
 * 只在 SQLite 侧为空时执行 —— 重复跑不会覆盖新数据。
 */
function statx_collect_import_mysql(array $opt = []): array
{
    $out = ['sites' => 0, 'hits' => 0, 'skipped' => '', 'error' => ''];
    $auto = (bool)($opt['auto'] ?? true);

    if ((int)statx_sdb_val('SELECT COUNT(*) FROM ' . STATX_SDB_SITES) > 0) {
        $out['skipped'] = 'sqlite sites 非空（已导入过）';
        return $out;
    }
    try {
        $S = Db::t('statx_sites');
        $rows = Db::rows("SELECT * FROM {$S}");
    } catch (Throwable $e) {
        $out['error'] = 'MySQL 老表不可读：' . $e->getMessage();
        return $out;
    }
    if (!$rows) { $out['skipped'] = 'MySQL 老表为空'; return $out; }

    foreach ($rows as $r) {
        $k = (string)($r['site_key'] ?? '');
        if ($k === '') { continue; }
        $d = [];
        foreach (['label', 'host', 'local_root', 'tj_token', 'inject_where', 'inject_tag',
                  'inject_at', 'verify_at', 'verify_note', 'script_url', 'endpoint_url',
                  'first_hit_at', 'last_hit_at', 'note'] as $c) {
            $d[$c] = (string)($r[$c] ?? '');
        }
        $d['enabled']    = (int)($r['enabled'] ?? 0);
        $d['verify_ok']  = (int)($r['verify_ok'] ?? 0);
        $d['hits_total'] = (int)($r['hits_total'] ?? 0);
        $d['sort']       = (int)($r['sort'] ?? 100);
        statx_collect_site_save($k, $d);
        $out['sites']++;
    }

    if ($auto) {
        // 明细：只搬保留窗口内的（默认 30 天）—— 老表可能很大，没必要整表搬
        $keep = max(1, statx_cfg_int('collect.keep_days'));
        $from = date('Y-m-d H:i:s', strtotime('-' . $keep . ' day'));
        try {
            $H = Db::t('statx_hits');
            $rows = Db::rows("SELECT ts,d,site_key,host,path,is_landing,ref_domain,ref_kind,device,
                    is_bot,bot_cls,bot_name,visitor_hash FROM {$H} WHERE ts>=? ORDER BY id", [$from]);
            $pdo = statx_sdb();
            $pdo->beginTransaction();
            $st = $pdo->prepare('INSERT INTO ' . STATX_SDB_HITS . ' (ts,d,site_key,host,path,is_landing,
                    ref_domain,ref_kind,device,is_bot,bot_cls,bot_name,visitor_hash,kind) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,\'pv\')');
            foreach ($rows as $r) {
                $st->execute([(string)$r['ts'], (string)$r['d'], (string)$r['site_key'], (string)$r['host'],
                    (string)$r['path'], (int)$r['is_landing'], (string)$r['ref_domain'], (string)$r['ref_kind'],
                    (string)$r['device'], (int)$r['is_bot'], (string)$r['bot_cls'], (string)$r['bot_name'],
                    (string)$r['visitor_hash']]);
                $out['hits']++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            try { statx_sdb()->rollBack(); } catch (Throwable $x) { /* 忽略 */ }
            $out['error'] = 'hits 导入中断：' . $e->getMessage();
        }
    }
    statx_sdb_own();
    return $out;
}

/* ============================== rt.php（端点零 SQL 的关键） ============================== */

function statx_collect_rt_path(): string { return __DIR__ . '/rt.php'; }

/** 读 rt.php（管理侧用；端点侧有自己的一份，见 endpoint.php） */
function statx_collect_rt_read(): array
{
    $f = statx_collect_rt_path();
    if (!is_file($f)) { return []; }
    $r = @include $f;
    return is_array($r) ? $r : [];
}

/**
 * 原子写文件。★ 2026-09-14：临时文件**优先放在目标文件同目录**（保证同文件系统 ⇒ rename 原子），
 * 老实现固定写进 sys_get_temp_dir()，在 /tmp 是 tmpfs 的主机上 rename 会跨设备失败、
 * 静默退化成非原子的 copy —— 并发请求可能读到写了一半的 rt.php。
 */
function statx_collect_write_atomic(string $target, string $content): bool
{
    $dir = dirname($target);
    if (!is_dir($dir)) { return false; }
    $tmp = $dir . '/.statx-rt-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $content) === false) {
        // 插件目录不可写 → 退回系统临时目录（跨设备时下面的 rename 会失败并走 copy）
        $tmp = rtrim(sys_get_temp_dir(), '/') . '/statx-w-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $content) === false) { return false; }
    }
    @chmod($tmp, 0644);
    if (@rename($tmp, $target)) { return true; }
    $ok = @copy($tmp, $target);
    @unlink($tmp);
    return $ok;
}

/**
 * 生成 rt.php —— 端点在**完全不做 SQL** 的前提下需要的全部运行参数。
 * 内容不含任何口令/PII：只有路径、开关、脚本版本、各站 Origin 白名单、tj token→site_key 映射。
 */
function statx_collect_rt_write(): array
{
    $sites = statx_collect_sites();
    $origins = [];
    $tjmap = [];
    foreach ($sites as $s) {
        if ((int)$s['enabled'] !== 1) { continue; }
        $h = mb_strtolower(preg_replace('~^www\.~', '', (string)$s['host']) ?? '');
        if ($h !== '' && preg_match('~^[a-z0-9.\-]+$~', $h)) {
            foreach (['https://' . $h, 'https://www.' . $h] as $o) { $origins[$o] = 1; }
        }
        if ((string)$s['tj_token'] !== '') { $tjmap[(string)$s['tj_token']] = (string)$s['site_key']; }
    }
    $js = __DIR__ . '/st.js';
    $jsver = is_file($js) ? substr((string)md5_file($js), 0, 8) : '';

    $rt = [
        'on'        => statx_cfg('collect.enabled') === '1' ? 1 : 0,
        'spool'     => statx_collect_spool_dir(),
        'spool_max' => max(1048576, statx_cfg_int('collect.spool_max')),
        'path'      => '/' . ltrim((string)statx_cfg('collect.endpoint'), '/'),
        'script'    => '/' . ltrim((string)statx_cfg('collect.script'), '/'),
        'compat'    => '/s3.js',
        'jsver'     => $jsver,
        'site'      => statx_collect_site_key(),
        'origins'   => array_keys($origins),
        'tjmap'     => $tjmap,
    ];

    // ★ 批次 1 安全阀：站点登记读不到（SQLite 打不开/被删）时**绝不覆盖**已存在的 rt.php ——
    //   否则会把 origins 白名单与 tjmap 一起写空，端点变成谁都能上报。宁可保持旧文件。
    if (!$sites && is_file(statx_collect_rt_path())) {
        $old = statx_collect_rt_read();
        $old['_written'] = false;
        $old['_skipped'] = 'sites 为空且 rt.php 已存在 → 保留旧文件（防白名单被写空）';
        return $old;
    }

    $php = "<?php\n"
         . "/* StatX 运行参数（由 admin / cli.php --task=collect-init 生成，请勿手改；改配置请走后台或 CLI）\n"
         . "   存在意义：让采集端点在不执行任何 SQL 的前提下拿到开关/白名单/校验串。 */\n"
         . 'return ' . var_export($rt, true) . ";\n";
    $ok = statx_collect_write_atomic(statx_collect_rt_path(), $php);
    if ($ok && function_exists('opcache_invalidate')) {
        @opcache_invalidate(statx_collect_rt_path(), true);
    }
    $rt['_written'] = $ok;
    return $rt;
}

/**
 * 站点自己的 key（会写进 data-site / spool 文件名 / sites 表 / CORS 白名单）。
 * 解析顺序（★ 2026-09-14 起加入自动识别 —— 老版直接取站点根目录名，
 * 在别人机器上会变成 `html` / `public_html` 这种毫无意义的 key）：
 *   ① 选项 `collect.site_key`；
 *   ② 选项 `site.url` 的 host（去掉 www.）；
 *   ③ 站点根目录名；
 *   ④ 'unknown'。
 */
function statx_collect_site_key(): string
{
    $k = trim((string)statx_opt('collect.site_key', ''));
    if ($k !== '') { return $k; }

    // ② 站点地址里的 host
    if (function_exists('get_option')) {
        $u = trim((string)get_option('site.url', ''));
        if ($u !== '') {
            $h = (string)(parse_url(str_contains($u, '://') ? $u : ('https://' . $u))['host'] ?? '');
            $h = mb_strtolower(preg_replace('~^www\.~', '', $h) ?? $h);
            if ($h !== '' && preg_match('~^[a-z0-9.\-]+$~', $h)) { return $h; }
        }
    }

    $root = basename(dirname(__DIR__, 2));
    return $root !== '' ? $root : 'unknown';
}

/**
 * spool 目录（端点追加写、cron 抢占了再入库）。
 * ★ 2026-09-14：不再写死 `/tmp/statx-spool` —— 有些主机把 /tmp 挂成 noexec/私有 tmp、
 *   或用 open_basedir 把 /tmp 排除在外，写不进去的后果是**访客数据静默丢失**。
 *   顺序：① 选项 collect.spool_dir；② 系统临时目录（绝大多数情况）；
 *         ③ 站点根/var/statx-spool（临时目录不可写时的兜底，仍在站内 var/ 下）。
 */
function statx_collect_spool_dir(): string
{
    $d = trim((string)statx_opt('collect.spool_dir', ''));
    if ($d !== '') { return rtrim($d, '/'); }

    $tmp = rtrim(sys_get_temp_dir(), '/') . '/statx-spool';
    if (statx_sdb_dir_usable($tmp . '/x')) { return $tmp; }

    return rtrim(statx_sdb_site_root(), '/') . '/var/statx-spool';
}

/* ============================== 站点登记（SQLite） ============================== */

/** sites 表允许写入的列（白名单 —— 绝不把调用方给的任意键拼进 SQL） */
function statx_collect_site_cols(): array
{
    return ['site_key', 'label', 'host', 'domains', 'enabled', 'local_root', 'tj_token',
            'inject_where', 'inject_tag', 'inject_at', 'verify_at', 'verify_ok', 'verify_note',
            'script_url', 'endpoint_url', 'first_hit_at', 'last_hit_at', 'hits_total', 'sort', 'note'];
}

/** 站点登记（原 MySQL 版语义完全一致，只是换到 SQLite） */
function statx_collect_sites(array $where = [], string $order = 'sort, site_key'): array
{
    $cols = statx_collect_site_cols();
    $sql  = 'SELECT * FROM ' . STATX_SDB_SITES;
    $par  = [];
    $bits = [];
    foreach ($where as $c => $v) {
        $c = (string)$c;
        if (!in_array($c, $cols, true)) { continue; }
        $bits[] = $c . '=?';
        $par[]  = $v;
    }
    if ($bits) { $sql .= ' WHERE ' . implode(' AND ', $bits); }
    $order = preg_replace('~[^a-z0-9_, ]~i', '', $order) ?? '';
    $sql .= ' ORDER BY ' . ($order !== '' ? $order : 'sort, site_key');
    try { return statx_sdb_all($sql, $par); } catch (Throwable $e) { return []; }
}

function statx_collect_site(string $key): ?array
{
    if ($key === '') { return null; }
    try { return statx_sdb_one('SELECT * FROM ' . STATX_SDB_SITES . ' WHERE site_key=?', [$key]); }
    catch (Throwable $e) { return null; }
}

/** 幂等 upsert（只在字段非空时覆盖，避免把已有信息抹掉）—— 与老 MySQL 版同语义 */
function statx_collect_site_save(string $key, array $d, bool $onlyIfNew = false): void
{
    $key = (string)statx_ep_key($key);
    if ($key === '') { return; }
    $cols = statx_collect_site_cols();
    $d = array_intersect_key($d, array_flip($cols));
    unset($d['site_key']);

    $cur = statx_collect_site($key);
    if ($cur === null) {
        // 新行：数值列给数、文本列给空串（SQLite 是动态类型，写 '' 到 INTEGER 列会退化成文本 ⇒ 必须分开）
        $num  = ['enabled' => 0, 'verify_ok' => 0, 'hits_total' => 0, 'sort' => 100];
        $row  = ['site_key' => $key, 'created_at' => date('Y-m-d H:i:s')];
        foreach ($cols as $c) {
            if ($c === 'site_key') { continue; }
            $row[$c] = $num[$c] ?? '';
        }
        foreach ($d as $k => $v) { $row[$k] = $v; }
        $names = array_keys($row);
        try {
            statx_sdb_run('INSERT INTO ' . STATX_SDB_SITES . ' (' . implode(',', $names) . ') VALUES ('
                . implode(',', array_fill(0, count($names), '?')) . ')', array_values($row));
        } catch (Throwable $e) { /* 写失败不该拖垮调用方（admin 页仍在渲染） */ }
        return;
    }
    if ($onlyIfNew || !$d) { return; }
    if (array_key_exists('site_key', $d)) { unset($d['site_key']); }
    $set = implode(',', array_map(static fn($c) => $c . '=?', array_keys($d)));
    try {
        statx_sdb_run('UPDATE ' . STATX_SDB_SITES . " SET {$set} WHERE site_key=?",
            array_merge(array_values($d), [$key]));
    } catch (Throwable $e) { /* 同上 */ }
}

/** site_key 清洗（原来靠 MySQL 列宽兜底，SQLite 没有列宽 ⇒ 显式截断到 64） */
function statx_ep_key(string $k): string
{
    $k = preg_replace('~[^A-Za-z0-9._:@\-]~', '', trim($k)) ?? '';
    return substr($k, 0, 64);
}

/** 同机的 YeCha 站点根目录（必须同时有 config.inc.php / index.php / core/Plugins.php） */
function statx_collect_is_root(string $dir): bool
{
    $dir = rtrim($dir, '/');
    return $dir !== '' && is_file($dir . '/config.inc.php') && is_file($dir . '/index.php') && is_file($dir . '/core/Plugins.php');
}

/**
 * 同机的 YeCha 站点根目录（必须同时有 config.inc.php / index.php / core/Plugins.php）
 *
 * ★ 2026-09-14 可移植化：不再只认宝塔的 `/www/wwwroot/<域名>`。候选顺序：
 *   ① sites 表里已登记的 local_root；② 本站实例自己；③ `dirname(站点根)/<域名>`（**同主机通用布局**）；
 *   ④ `/www/wwwroot/<域名>`（宝塔，作为额外候选而不是唯一答案）；
 *   ⑤ 选项 `collect.roots` 里显式列出的目录（多站点/非标准布局用）。
 */
function statx_collect_local_root(string $siteKey, string $host = ''): ?string
{
    $s = statx_collect_site($siteKey);
    if ($s !== null && (string)$s['local_root'] !== '' && statx_collect_is_root((string)$s['local_root'])) {
        return rtrim((string)$s['local_root'], '/');
    }
    $h = $host !== '' ? $host : (string)($s['host'] ?? $siteKey);
    $cands = [];
    // 本站实例自己：site_key 可能和目录名不同（例：站点根目录叫 xuecha-yecha-stage，域名是 gushuwu.com）
    if ($siteKey === statx_collect_site_key()) { $cands[] = dirname(__DIR__, 2); }

    $parent = rtrim(dirname(statx_sdb_site_root()), '/');
    foreach ([$h, preg_replace('~^www\.~', '', $h) ?? ''] as $x) {
        if ($x === '') { continue; }
        $cands[] = $parent . '/' . $x;              // ★ 同主机通用布局（/var/www/x、/srv/web/x…）
        $cands[] = '/www/wwwroot/' . $x;            // 宝塔布局（保留为候选之一）
    }
    // ⑤ 显式配置的额外根（逗号/换行分隔）
    $extra = trim((string)statx_opt('collect.roots', ''));
    if ($extra !== '') {
        foreach (preg_split('~[\s,]+~', $extra) ?: [] as $e) {
            if (trim($e) !== '') { $cands[] = rtrim(trim($e), '/') . '/' . $h; $cands[] = rtrim(trim($e), '/'); }
        }
    }
    foreach (array_unique($cands) as $c) { if (statx_collect_is_root($c)) { return rtrim($c, '/'); } }
    return null;
}

/**
 * 播种站点登记表（SQLite 版）：
 *  ① 本站实例自己（site_key = collect.site_key）
 *  ② 能力② 目标表里出现的各 site_key（host 取目标 URL 的 host）—— 复用已有资产，不重复维护
 * enabled 只对「本机能找到 YeCha 站点根目录」的站置 1（其余留 0 并注明原因），
 * 这样 rt.php 的 CORS Origin 白名单天然只包含我们自己的站。
 */
/**
 * 规整成一个**合法 host**（含点的域名）；不合法返回 ''。
 * 用于：① 播种时推导本站 host ② 清除历史遗留的无效登记。
 */
function statx_collect_valid_host(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') { return ''; }
    if (str_contains($raw, '://')) {
        $raw = (string)(parse_url($raw, PHP_URL_HOST) ?? '');
    } else {
        $raw = (string)(parse_url('https://' . $raw, PHP_URL_HOST) ?? '');
    }
    $raw = mb_strtolower(preg_replace('~^www\.~', '', trim($raw)) ?? '');
    // 必须含点、只允许 [a-z0-9.-]、且不能以点/横线开头或结尾
    if (!preg_match('~^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$~', $raw)) { return ''; }
    return $raw;
}

/**
 * ★ r10【根因③】清除「无效登记」：host 为空或不含点（历史播种产物）。
 * 走插件登记表接口，不裸 SQL；返回被清除的 site_key 列表。
 */
function statx_collect_purge_invalid_sites(): array
{
    $gone = [];
    foreach (statx_collect_sites() as $s) {
        $k = (string)$s['site_key'];
        if ($k === '') { continue; }
        if (statx_collect_valid_host((string)$s['host']) !== '') { continue; }
        try {
            statx_sdb_run('DELETE FROM ' . STATX_SDB_SITES . ' WHERE site_key=?', [$k]);
            $gone[] = $k . '(host=' . (string)$s['host'] . ')';
        } catch (Throwable $e) { /* 删不掉不该拖垮播种 */ }
    }
    return $gone;
}

function statx_collect_seed_sites(): array
{
    $out = ['added' => 0, 'kept' => 0, 'rows' => []];
    $self = statx_collect_site_key();
    $rows = [];
    // ★ r10【根因③】原来写 `'host' => $self`。site_key 常常**不带点**（如 'tj-stat'），
    //   于是播种出一行 host 不含点的**无效登记**：rt.php 的 origins 白名单要求 host 含点，
    //   该行被静默丢弃 ⇒ 既污染站点表、又让「本站实例」在白名单里查无此人。
    //   现在：只播种**能推导出合法 host（含点）**的站点；推导不出就**不播种**（宁缺勿错）。
    $selfHost = statx_collect_valid_host($self);
    if ($selfHost !== '') {
        $rows[$self] = ['host' => $selfHost, 'label' => (string)get_option('site.name', $self), 'sort' => 1];
    } else {
        $out['skipped_self'] = '本站 site_key 不是域名（' . $self . '）且 site.url 未配置 → 不播种该行';
    }

    try {
        $T = statx_t('targets');
        foreach (Db::rows("SELECT site_key, label, url FROM {$T} GROUP BY site_key") as $r) {
            $k = (string)$r['site_key'];
            if ($k === '' || isset($rows[$k])) { continue; }
            $host = (string)parse_url((string)$r['url'], PHP_URL_HOST);
            $rows[$k] = ['host' => $host, 'label' => (string)$r['label'], 'sort' => 50];
        }
    } catch (Throwable $e) { /* 能力② 目标表缺失也不影响采集 */ }

    foreach ($rows as $k => $d) {
        $exists = statx_collect_site((string)$k) !== null;
        if (!$exists) {
            $root = statx_collect_local_root((string)$k, (string)$d['host']);
            statx_collect_site_save((string)$k, [
                'label'      => mb_substr((string)$d['label'], 0, 160),
                'host'       => mb_substr((string)$d['host'], 0, 160),
                'enabled'    => $root !== null ? 1 : 0,
                'local_root' => (string)($root ?? ''),
                'sort'       => (int)$d['sort'],
                'note'       => $root !== null ? '自动识别：本机 YeCha 站点' : '自动识别：本机未找到 YeCha 站点根目录',
            ]);
            $out['added']++;
        } else {
            $out['kept']++;
        }
    }

    // 本站实例**一定可写**：显式纠正（site_key 与目录名不同时，上面的目录名推断会漏）
    $selfRoot = dirname(__DIR__, 2);
    if (statx_collect_is_root($selfRoot)) {
        statx_collect_site_save($self, ['local_root' => $selfRoot, 'enabled' => 1,
            'note' => '本站实例（统计服务器）']);
        if (statx_collect_site($self) === null) {
            // ★ r10【根因③ 真凶】原来这里是 `'host' => $self` —— 把 **site_key 当 host** 写进去。
            //   本站 site_key 常是 'tj-stat' 这种**不带点**的名字 ⇒ 播种出一行无效登记；
            //   而 rt.php 的 origins 白名单要求 host 含点，于是该行被静默丢弃，
            //   「本站实例」在白名单里查无此人。
            //   现在：能推导出合法 host（含点）才写 host；推不出来就**不设置该字段**（宁缺勿错）。
            $selfRow = ['label' => $self, 'enabled' => 1, 'local_root' => $selfRoot,
                        'sort' => 1, 'note' => '本站实例'];
            $selfHost = statx_collect_valid_host($self);
            if ($selfHost === '' && function_exists('get_option')) {
                $selfHost = statx_collect_valid_host((string)get_option('site.url', ''));
            }
            if ($selfHost !== '') { $selfRow['host'] = $selfHost; }
            statx_collect_site_save($self, $selfRow);
        }
    }
    // ★ r10【根因③】顺手清掉历史遗留的「无效登记」（host 为空或**不含点**）。
    //   走插件自己的登记接口（statx_collect_site_save），不裸 SQL。
    $out['purged_invalid'] = statx_collect_purge_invalid_sites();

    // 自动发现各站 stats.foot / stats.head 里 tj 的老 token（→ /s3.js 平滑迁移）
    $out['tokens'] = statx_collect_discover_tokens();
    return $out;
}

/**
 * 自动发现 tj 老 token：读各站自己的 stats.foot/head，从 `tj.51puer.com/s3.js" data-site="<16位hex>"`
 * 里抽出 token 存进 ye_statx_sites.tj_token。
 * 用途：新端点同时提供 `/s3.js`，**站点 HTML 一个字都不用改**，只切 DNS 就完成迁移。
 */
function statx_collect_discover_tokens(): int
{
    $n = 0;
    foreach (statx_collect_sites() as $s) {
        $k = (string)$s['site_key'];
        if ((string)$s['tj_token'] !== '') { continue; }
        $root = statx_collect_local_root($k, (string)$s['host']);
        if ($root === null) { continue; }
        foreach (['foot', 'head'] as $w) {
            $v = statx_collect_local_option($root, 'stats.' . $w, '');
            if ($v === '' || !str_contains($v, 'tj.51puer.com')) { continue; }
            if (preg_match('~tj\.51puer\.com/s3\.js[^>]*?data-site="([0-9a-f]{8,32})"~i', $v, $m)) {
                statx_collect_site_save($k, ['tj_token' => $m[1]]);
                $n++;
                break;
            }
        }
    }
    return $n;
}

/* ============================== spool ============================== */

function statx_collect_spool_stat(): array
{
    $dir = statx_collect_spool_dir();
    $out = ['dir' => $dir, 'files' => 0, 'bytes' => 0, 'lines' => 0, 'newest' => '', 'exists' => is_dir($dir)];
    if (!is_dir($dir)) { return $out; }
    $fs = glob($dir . '/*.log') ?: [];
    foreach ($fs as $f) {
        $out['files']++;
        $out['bytes'] += (int)@filesize($f);
        $n = 0;
        $fp = @fopen($f, 'rb');
        if ($fp !== false) { while (!feof($fp)) { $b = fread($fp, 1048576); if ($b === false) { break; } $n += substr_count($b, "\n"); } fclose($fp); }
        $out['lines'] += $n;
        $m = (int)@filemtime($f);
        if ($m > 0 && $m > (int)strtotime($out['newest'] . ' UTC')) { $out['newest'] = date('Y-m-d H:i:s', $m); }
    }
    return $out;
}

/**
 * 收 spool → ye_statx_hits（**批量多行 INSERT**，一批 ≤500 行 / 单条语句）
 * · site_key 必须在 ye_statx_sites 且 enabled=1，否则丢弃（防伪造上报把库写脏）
 * · 收完先把文件 rename 成 .ro 再读 —— 端点此刻写的是新文件，不丢数据
 * · 全部时间由 spool 里的 unix 秒转 PHP date()，**不用 MySQL 函数**
 */
/**
 * 收 spool → SQLite `hits`（**事务内批量 prepared INSERT**）
 * · site_key 必须在 sites 表且 enabled=1，否则丢弃（防伪造上报把库写脏）
 * · 收完先把文件 rename 成 .ro 再读 —— 端点此刻写的是新文件，不丢数据
 * · 全部时间由 spool 里的 unix 秒转 PHP date()（**不在 SQL 里做时间运算**）
 * · 本批新增：v2 行（sid/uid/ref/utm/坐标/事件名）、`is_new` 判定（uid 是否首次出现）、
 *   数值夹紧与文本再清洗（spool 是 /tmp 下的文件，仍按不可信输入对待）
 * · **批次 2 新增**：识别 spool **v3（28 列）**，多收 region/browser/os/ipver 四列；
 *   并由完整 `ref` 派生 `ref_type`（四分类）与 `kw`（关键词）—— 看板因此只需 GROUP BY + LIMIT
 * · **向后兼容**：v2（24 列）与老格式（10 列、无版本标记）的行仍照旧解析，避免切换瞬间丢数据
 */
function statx_collect_flush(int $maxRows = 200000, int $batch = 500): array
{
    statx_sync_tz();
    $dir = statx_collect_spool_dir();
    $out = ['files' => 0, 'rows' => 0, 'pv' => 0, 'cl' => 0, 'ev' => 0, 'newv' => 0,
            'bot_rows' => 0, 'dropped' => 0, 'dropped_site' => 0, 'batches' => 0, 'error' => ''];
    if (!is_dir($dir)) { return $out; }

    $ok = [];
    foreach (statx_collect_sites(['enabled' => 1]) as $s) { $ok[(string)$s['site_key']] = (string)$s['host']; }
    if (!$ok) { $out['error'] = 'sites 表里没有启用中的站点（先跑 --task=collect-init）'; return $out; }

    $COLS = ['ts', 'd', 'site_key', 'host', 'path', 'kind', 'is_landing', 'ref', 'ref_domain', 'ref_kind',
             'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
             'sid', 'uid', 'is_new', 'visitor_hash', 'width', 'height', 'vw', 'vh', 'lang',
             'x', 'y', 'en', 'device', 'is_bot', 'bot_cls', 'bot_name',
             // ★ 批次 2（界面层）新增 6 列 —— 目的只有一个：**让看板只做 GROUP BY + LIMIT**
             //   region  由端点在「还看得到 IP 的那一刻」算好（IP 本身不落盘）
             //   browser/os/ipver 由端点从 UA / IP 直接得出
             //   ref_type/kw 在**入库这里**由完整 ref 派生（参见 derive.php 的取舍说明）
             'region', 'browser', 'os', 'ipver', 'ref_type', 'kw'];

    try {
        $pdo = statx_sdb();
    } catch (Throwable $e) {
        $out['error'] = 'sqlite 打不开：' . $e->getMessage();
        return $out;
    }

    // 已知 uid（用于 is_new）：只取保留窗口内的，避免全表读
    $known = [];
    $since = date('Y-m-d', strtotime('-' . max(1, statx_cfg_int('collect.keep_days')) . ' day'));
    foreach (statx_sdb_all("SELECT DISTINCT uid FROM " . STATX_SDB_HITS . " WHERE uid<>'' AND d>=?", [$since]) as $r) {
        $known[(string)$r['uid']] = 1;
    }

    $ins = $pdo->prepare('INSERT INTO ' . STATX_SDB_HITS . ' (' . implode(',', $COLS) . ') VALUES ('
        . implode(',', array_fill(0, count($COLS), '?')) . ')');

    foreach (glob($dir . '/*.log') ?: [] as $f) {
        if ($out['rows'] >= $maxRows) { break; }
        $ro = $f . '.ro';
        if (is_link($f)) { continue; }
        if (!@rename($f, $ro)) { continue; }          // 抢占；别的进程正在收就跳过
        $out['files']++;

        $buf = [];
        $flush = function () use (&$buf, &$out, $pdo, $ins, $batch) {
            if (!$buf) { return; }
            foreach (array_chunk($buf, $batch) as $chunk) {
                try {
                    $pdo->beginTransaction();
                    foreach ($chunk as $row) { $ins->execute($row); }
                    $pdo->commit();
                    $out['batches']++;
                } catch (Throwable $e) {
                    try { if ($pdo->inTransaction()) { $pdo->rollBack(); } } catch (Throwable $x) { /* 忽略 */ }
                    $out['error'] = 'insert 失败：' . $e->getMessage();
                    $out['dropped'] += count($chunk);
                }
            }
            $buf = [];
        };

        $fp = @fopen($ro, 'rb');
        if ($fp === false) { @unlink($ro); continue; }
        while (($line = fgets($fp)) !== false) {
            if ($out['rows'] >= $maxRows) { break; }
            $p = explode("\t", rtrim($line, "\r\n"));
            // 版本标记：v3 = 28 列（批次 2，含地域/浏览器/系统/IP 版本）
            //           v2 = 24 列（批次 1）
            //           v1 = 10 列（批次 1 之前）
            // ★ 带标记但列数不对的行一律丢弃（绝不退化成老格式误解析）
            $ver = (string)($p[0] ?? '');
            if ($ver === 'v3' && count($p) < 28) { $out['dropped']++; continue; }
            if ($ver === 'v2' && count($p) < 24) { $out['dropped']++; continue; }
            $v3 = ($ver === 'v3');
            $v2 = ($ver === 'v2');
            if (!$v2 && !$v3 && count($p) < 10) { $out['dropped']++; continue; }

            if ($v2 || $v3) {
                $ts   = (int)$p[1];
                $site = (string)$p[2];
                $host = (string)$p[3];
                $path = rawurldecode((string)$p[4]);
                $kind = in_array((string)$p[5], ['pv', 'cl', 'ev'], true) ? (string)$p[5] : 'pv';
                $rkind = in_array((string)$p[6], ['none', 'in', 'out'], true) ? (string)$p[6] : 'none';
                $rdom = (string)$p[7];
                // ★ r12x：入库侧把敏感参数**再剥一次**（深度防御）——
                //   端点已剥过；这里兜的是"旧 spool 文件"与"别的写入方"（spool 是 /tmp 下的文件，
                //   本函数按不可信输入对待）。statx_ref_strip_sensitive() 幂等，重复剥无副作用。
                $ref  = statx_ref_strip_sensitive(rawurldecode((string)$p[8]));
                $dev  = (string)$p[9];
                $cls  = (string)$p[10];
                $bname = (string)$p[11];
                $vhash = (string)$p[12];
                $sid  = (string)$p[13];
                $uid  = (string)$p[14];
                $w = (int)$p[15]; $h = (int)$p[16]; $vw = (int)$p[17]; $vh = (int)$p[18];
                $lang = (string)$p[19];
                $en  = (string)$p[20];
                $x = (int)$p[21]; $y = (int)$p[22];
                $utmRaw = (string)$p[23];
                $region = $v3 ? (string)$p[24] : '';
                $browser = $v3 ? (string)$p[25] : '';
                $osCol   = $v3 ? (string)$p[26] : '';
                $ipver   = $v3 ? (int)$p[27] : 0;
            } else {
                // v1（批次 1 之前的端点格式）：ts site host path ref_kind ref_domain device cls bot_name vhash
                $ts   = (int)$p[0];
                $site = (string)$p[1];
                $host = (string)$p[2];
                $path = rawurldecode((string)$p[3]);
                $kind = 'pv'; $rkind = (string)$p[4]; $rdom = (string)$p[5]; $ref = '';
                $dev = (string)$p[6]; $cls = (string)$p[7]; $bname = (string)$p[8]; $vhash = (string)$p[9];
                $sid = ''; $uid = ''; $w = 0; $h = 0; $vw = 0; $vh = 0; $lang = '';
                $en = ''; $x = 0; $y = 0; $utmRaw = '';
                $region = ''; $browser = ''; $osCol = ''; $ipver = 0;
            }

            if (!isset($ok[$site])) { $out['dropped']++; $out['dropped_site']++; continue; }
            if ($ts < 1000000000 || $ts > time() + 86400) { $out['dropped']++; continue; }

            $cls = preg_replace('~[^a-z]~', '', $cls) ?? '';
            $isBot = ($cls !== '' && $cls !== 'human') ? 1 : 0;
            $dev = preg_replace('~[^a-z]~', '', $dev) ?? '';
            $uid = substr(preg_replace('~[^A-Za-z0-9._\-]~', '', $uid) ?? '', 0, 32);
            $sid = substr(preg_replace('~[^A-Za-z0-9._\-]~', '', $sid) ?? '', 0, 32);
            $isNew = ($uid !== '' && !isset($known[$uid])) ? 1 : 0;
            if ($uid !== '') { $known[$uid] = 1; }

            $utm = ['utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '', 'utm_term' => '', 'utm_content' => ''];
            if ($utmRaw !== '') {
                foreach (explode('&', $utmRaw) as $pair) {
                    $kv = explode('=', $pair, 2);
                    if (count($kv) !== 2 || !isset($utm[$kv[0]])) { continue; }
                    $utm[$kv[0]] = mb_substr(rawurldecode($kv[1]), 0, 100);
                }
            }

            // ★ 批次 2：来源四分类 + 关键词，在**入库这里**由完整 ref 派生一次（看板即可只做 GROUP BY）
            //   v1/v2 老行 & 缺 ref 的行也会走到这里（结果自然是 direct）；ipver 由端点给，老行为 0
            $di = statx_derive_ref($ref, $host);
            $ipverV = in_array($ipver, [4, 6], true) ? $ipver : 0;

            $buf[] = [
                date('Y-m-d H:i:s', $ts),
                date('Y-m-d', $ts),
                $site,
                mb_substr($host, 0, 160),
                mb_substr($path, 0, 255),
                $kind,
                ($rkind === 'in') ? 0 : 1,             // 直达 / 站外来源 = 落地页
                mb_substr($ref, 0, 500),
                mb_substr($rdom, 0, 128),
                $rkind,
                $utm['utm_source'], $utm['utm_medium'], $utm['utm_campaign'], $utm['utm_term'], $utm['utm_content'],
                $sid,
                $uid,
                $isNew,
                substr($vhash, 0, 16),
                max(0, min(20000, $w)), max(0, min(20000, $h)),
                max(0, min(20000, $vw)), max(0, min(20000, $vh)),
                mb_substr(preg_replace('~[\x00-\x1f\x7f]~', '', $lang) ?? '', 0, 16),
                max(0, min(100000, $x)), max(0, min(100000, $y)),
                mb_substr($en, 0, 48),
                $dev !== '' ? mb_substr($dev, 0, 8) : 'unknown',
                $isBot,
                mb_substr($cls, 0, 8),
                mb_substr($bname, 0, 32),
                mb_substr(preg_replace('~[\x00-\x1f\x7f]~', '', $region) ?? '', 0, 64),
                mb_substr(preg_replace('~[^A-Za-z]~', '', $browser) ?? '', 0, 16),
                mb_substr(preg_replace('~[^A-Za-z]~', '', $osCol) ?? '', 0, 16),
                $ipverV,
                $di['type'],
                mb_substr($di['kw'], 0, 100),
            ];
            $out['rows']++;
            $out[$kind] = ($out[$kind] ?? 0) + 1;
            if ($isNew) { $out['newv']++; }
            if ($isBot) { $out['bot_rows']++; }
            if (count($buf) >= $batch) { $flush(); }
        }
        fclose($fp);
        $flush();
        @unlink($ro);
    }

    // 站点登记回填（老 MySQL 版这几列一直是空的 —— 顺手补上，看板矩阵才有意义）
    foreach (array_keys($ok) as $k) {
        try {
            statx_sdb_run('UPDATE ' . STATX_SDB_SITES . ' SET hits_total=(
                    SELECT COUNT(*) FROM ' . STATX_SDB_HITS . ' WHERE site_key=?),
                    first_hit_at=COALESCE(first_hit_at,(SELECT MIN(ts) FROM ' . STATX_SDB_HITS . ' WHERE site_key=?)),
                    last_hit_at=(SELECT MAX(ts) FROM ' . STATX_SDB_HITS . ' WHERE site_key=?)
                WHERE site_key=?', [$k, $k, $k, $k]);
        } catch (Throwable $e) { /* 回填失败不影响收件 */ }
    }
    statx_sdb_own();
    return $out;
}

/* ============================== 汇总 / 保留（SQLite 版） ============================== */

/**
 * 把「已完整过去的日子」的明细汇总进 hits_rollup（**幂等**：`ON CONFLICT … DO UPDATE` 重算）。
 * · 口径：pv = 人类 PV（kind='pv' AND is_bot=0）；uv = 有 uid 用 uid、否则退回逐日轮换的 visitor_hash；
 *        sess = DISTINCT sid；newv = is_new=1 的条数；landing/ref_ext/bots 与老版同名同义
 * · `d` 是写入时就存好的北京日期列 ⇒ 这里**不需要任何时区运算**（tj 的 `date(ts,'+8 hours')` 被省掉了）
 */
function statx_collect_rollup(int $daysBack = 3): array
{
    statx_sync_tz();
    $H = STATX_SDB_HITS;
    $D = STATX_SDB_ROLLUP;
    $out = ['days' => 0, 'rows' => 0, 'error' => ''];

    $from  = date('Y-m-d', strtotime('-' . max(1, $daysBack) . ' day'));
    $today = date('Y-m-d');                                  // ★ PHP 侧的今天（不在 SQL 里取 now）
    try {
        $groups = statx_sdb_all("SELECT site_key, d FROM {$H} WHERE d>=? AND d<? GROUP BY site_key, d", [$from, $today]);
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
        return $out;
    }

    foreach ($groups as $g) {
        $site = (string)$g['site_key'];
        $d    = (string)$g['d'];

        $a = statx_sdb_one("SELECT COUNT(*) pv,
                COUNT(DISTINCT COALESCE(NULLIF(uid,''), visitor_hash)) uv,
                COUNT(DISTINCT NULLIF(sid,'')) sess,
                SUM(is_landing=1) landing, SUM(ref_kind='out') ref_ext, SUM(is_new=1) newv
                FROM {$H} WHERE site_key=? AND d=? AND kind='pv' AND is_bot=0", [$site, $d]) ?? [];
        $bots = (int)statx_sdb_val("SELECT COUNT(*) FROM {$H} WHERE site_key=? AND d=? AND kind='pv' AND is_bot=1",
            [$site, $d]);

        $top = function (string $sql, array $par) : array {
            $r = [];
            try {
                foreach (statx_sdb_all($sql, $par) as $x) {
                    $k = (string)reset($x);
                    $r[] = ['k' => $k, 'n' => (int)($x['n'] ?? 0)];
                }
            } catch (Throwable $e) { /* 单项失败不影响整行 */ }
            return $r;
        };
        $refTop  = $top("SELECT COALESCE(NULLIF(ref_domain,''),'(直接访问)') k, COUNT(*) n FROM {$H}
                WHERE site_key=? AND d=? AND kind='pv' AND is_bot=0 AND ref_kind='out'
                GROUP BY k ORDER BY n DESC LIMIT 10", [$site, $d]);
        $pageTop = $top("SELECT path k, COUNT(*) n FROM {$H} WHERE site_key=? AND d=? AND kind='pv' AND is_bot=0
                GROUP BY path ORDER BY n DESC LIMIT 10", [$site, $d]);
        $devTop  = $top("SELECT device k, COUNT(*) n FROM {$H} WHERE site_key=? AND d=? AND kind='pv' AND is_bot=0
                GROUP BY device ORDER BY n DESC LIMIT 6", [$site, $d]);
        $botTop  = $top("SELECT bot_name k, COUNT(*) n FROM {$H} WHERE site_key=? AND d=? AND kind='pv' AND is_bot=1
                GROUP BY bot_name ORDER BY n DESC LIMIT 10", [$site, $d]);

        statx_sdb_run("INSERT INTO {$D} (site_key,d,pv,uv,sess,newv,landing,ref_ext,bot_hits,
                ref_top,page_top,dev_top,bot_top,built_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(site_key,d) DO UPDATE SET pv=excluded.pv, uv=excluded.uv, sess=excluded.sess,
                newv=excluded.newv, landing=excluded.landing, ref_ext=excluded.ref_ext, bot_hits=excluded.bot_hits,
                ref_top=excluded.ref_top, page_top=excluded.page_top, dev_top=excluded.dev_top,
                bot_top=excluded.bot_top, built_at=excluded.built_at",
            [$site, $d, (int)($a['pv'] ?? 0), (int)($a['uv'] ?? 0), (int)($a['sess'] ?? 0), (int)($a['newv'] ?? 0),
             (int)($a['landing'] ?? 0), (int)($a['ref_ext'] ?? 0), $bots,
             json_encode($refTop, JSON_UNESCAPED_UNICODE), json_encode($pageTop, JSON_UNESCAPED_UNICODE),
             json_encode($devTop, JSON_UNESCAPED_UNICODE), json_encode($botTop, JSON_UNESCAPED_UNICODE),
             date('Y-m-d H:i:s')]);
        $out['rows']++;
    }
    $out['days'] = count($groups);
    return $out;
}

/**
 * 保留清理（SQLite 版）：① rollup 兜底（保证被删的日子已汇总）
 *   ② **分批删**：人类明细 30 天 · **爬虫明细 10 天**（tj 实测爬虫占写入 34%、价值最低）
 *   ③ 按天汇总永久保留（不删）· ④ incremental_vacuum + optimize（**绝不跑整库 VACUUM**）
 *
 * 分批写法见 sdb.php `statx_sdb_delete_batch()`：`DELETE … WHERE rowid IN (SELECT rowid … LIMIT 5000)`。
 */
function statx_collect_prune(?int $keepDays = null, int $batch = 5000): array
{
    statx_sync_tz();
    $keepDays = max(1, $keepDays ?? statx_cfg_int('collect.keep_days'));
    $botDays  = max(1, statx_cfg_int('collect.keep_bot_days'));
    $out = ['rollup' => 'skip', 'deleted' => 0, 'batches' => 0, 'deleted_bot' => 0, 'bot_batches' => 0,
            'rollup_deleted' => 0, 'keep_days' => $keepDays, 'keep_bot_days' => $botDays,
            'maintain' => [], 'error' => ''];

    try {
        $min = (string)statx_sdb_val('SELECT MIN(d) FROM ' . STATX_SDB_HITS);
        if ($min !== '') {
            $days = (int)floor((strtotime(date('Y-m-d')) - strtotime($min)) / 86400);
            if ($days > 0) {
                $r = statx_collect_rollup(min(400, $days));
                $out['rollup'] = 'ok(' . (int)$r['rows'] . ')';
            }
        }

        $cut = date('Y-m-d', strtotime('-' . $keepDays . ' day'));
        $cb  = date('Y-m-d', strtotime('-' . $botDays . ' day'));

        $b = statx_sdb_delete_batch(STATX_SDB_HITS, "is_bot=1 AND d<?", [$cb], $batch);
        $out['deleted_bot'] = $b['deleted'];
        $out['bot_batches'] = $b['batches'];
        $out['deleted']     = 0;   // 上面那批已含在「bot」计数里，这里只统计人类明细
        $b2 = statx_sdb_delete_batch(STATX_SDB_HITS, "is_bot=0 AND d<?", [$cut], $batch);
        $out['deleted'] = $b2['deleted'];
        $out['batches'] = $b2['batches'];

        // 按天汇总是**永久**保留（站长口径）⇒ 这里 0 删除；保留这条语句是为了将来改口径时一处生效
        $out['rollup_deleted'] = 0;

        $out['maintain'] = statx_sdb_maintain();
        statx_sdb_own();
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
    }
    return $out;
}

/* ============================== 「一行 JS」注入 ============================== */

/** 本机站点根目录 → DB 凭据（**正则解析，绝不 require 其它站的 config**：常量重定义会静默沿用本站值） */
function statx_collect_parse_db(string $root): ?array
{
    $f = rtrim($root, '/') . '/config.inc.php';
    if (!is_file($f)) { return null; }
    $src = (string)file_get_contents($f);
    $out = [];
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_PREFIX'] as $k) {
        if (preg_match("~define\\(\\s*'" . $k . "'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'~", $src, $m)) {
            $out[$k] = stripcslashes($m[1]);
        }
    }
    return isset($out['DB_NAME'], $out['DB_USER']) ? $out : null;
}

function statx_collect_local_pdo(string $root): ?PDO
{
    static $cache = [];
    if (array_key_exists($root, $cache)) { return $cache[$root]; }
    $cache[$root] = null;
    $c = statx_collect_parse_db($root);
    if ($c === null) { return null; }
    try {
        $cache[$root] = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $c['DB_HOST'] ?? '127.0.0.1', $c['DB_PORT'] ?? '3306', $c['DB_NAME']),
            $c['DB_USER'], $c['DB_PASS'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (Throwable $e) { $cache[$root] = null; }
    return $cache[$root];
}

/** 目标站 option 读（走该站自己的库） */
function statx_collect_local_option(string $root, string $name, string $def = ''): string
{
    $pdo = statx_collect_local_pdo($root);
    if ($pdo === null) { return $def; }
    try {
        $c = statx_collect_parse_db($root);
        $T = ($c['DB_PREFIX'] ?? 'ye_') . 'options';
        $st = $pdo->prepare("SELECT value FROM {$T} WHERE name=? LIMIT 1");
        $st->execute([$name]);
        $v = $st->fetchColumn();
        return $v === false ? $def : (string)$v;
    } catch (Throwable $e) { return $def; }
}

/** 目标站 option 写（DELETE + INSERT，与 core/bootstrap.php::set_option 同语义） */
function statx_collect_local_option_set(string $root, string $name, string $value, int $autoload = 1): bool
{
    $pdo = statx_collect_local_pdo($root);
    if ($pdo === null) { return false; }
    try {
        $c = statx_collect_parse_db($root);
        $T = ($c['DB_PREFIX'] ?? 'ye_') . 'options';
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM {$T} WHERE name=?")->execute([$name]);
        $pdo->prepare("INSERT INTO {$T} (name,value,autoload) VALUES (?,?,?)")->execute([$name, $value, $autoload]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        try { $pdo->rollBack(); } catch (Throwable $e2) {}
        return false;
    }
}

/**
 * 目标站全页缓存失效：直接删它 `var/cache/pages/` 下的缓存文件（避免跨站 bootstrap）。
 * ★ 2026-09-14 可移植化：缓存目录/后缀都改成可配（选项 `collect.cache_glob`，`{root}` 占位），
 *   老实现写死 `var/cache/pages/*.cache`；内核哪天改布局，注入后的实证校验会读到旧 HTML 并误判失败。
 */
function statx_collect_local_cache_flush(string $root): int
{
    $root = rtrim($root, '/');
    $pat  = trim((string)statx_opt('collect.cache_glob', ''));
    $n = 0;
    if ($pat !== '') {
        foreach (glob(str_replace('{root}', $root, $pat)) ?: [] as $f) { if (is_file($f) && @unlink($f)) { $n++; } }
        return $n;
    }
    foreach ([$root . '/var/cache/pages/*.cache', $root . '/var/cache/*/*.cache'] as $p) {
        foreach (glob($p) ?: [] as $f) { if (is_file($f) && @unlink($f)) { $n++; } }
    }
    return $n;
}

/** 「一行 JS」文本（含幂等标记；标记之外的旁站统计标签一个字都不动）
 *  ⚠️ 注入单元**自带前导换行**：这样「摘除整个单元」= 逐字节回到注入前的 option 值，
 *     回滚才真正是「删一行」而不是「删一行 + 少一个换行」。 */
function statx_collect_tag(string $siteKey, array $opt = []): array
{
    $origin = rtrim((string)($opt['origin'] ?? statx_collect_pub_origin()), '/');
    $mode   = (string)($opt['mode'] ?? statx_cfg('collect.inject_mode'));   // auto | pub | static | path
    $ver    = (string)(statx_collect_rt_read()['jsver'] ?? '');

    if ($mode === 'auto') {
        // ★ 2026-09-14 新增的默认值：能用**站内**就用站内（数据不出站、不依赖别人的域名）。
        //   只有站内端点探测失败时才退回 pub（外部域名）。
        $mode = 'path';
    }
    if ($mode === 'pub' && $origin === '') {
        // 老默认是 pub + 空 origin ⇒ 会拼出一个没有域名的 src。宁可退回站内路径。
        $mode = 'path';
    }

    if ($mode === 'static') {                                  // 同机插件自带静态副本（零 PHP 开销）
        $src = statx_collect_static_script();
        $ep  = '';
    } elseif ($mode === 'path') {                              // 站点自身的 /st.js 端点
        $src = '/st.js' . ($ver !== '' ? ('?v=' . $ver) : '');
        $ep  = '';
    } else {                                                   // pub：集中式端点域名
        $src = $origin . '/st.js' . ($ver !== '' ? ('?v=' . $ver) : '');
        $ep  = $origin . statx_collect_path_of('collect.endpoint', '/st');
    }

    $attr = $ep !== '' ? (' data-ep="' . e($ep) . '"') : '';
    $tag  = '<script async src="' . e($src) . '" data-site="' . e($siteKey) . '"' . $attr . '></script>';
    return [
        'tag'   => "\n<!-- statx:begin -->\n" . $tag . "\n<!-- statx:end -->",
        'src'   => $src,
        'ep'    => $ep !== '' ? $ep : ('(脚本同源) ' . $src),
        'where' => '',
    ];
}

function statx_collect_path_of(string $optKey, string $def): string
{
    $p = '/' . ltrim((string)statx_cfg($optKey), '/');
    return $p === '/' ? $def : $p;
}

/**
 * 从既有文本里摘掉**本插件自己的**注入单元。
 * ⚠️⚠️ 红线：**绝不误删旁站（tj）的标签**。只做两件事，且都必须能唯一指认「这是我们写的」：
 *   ① 删掉注入单元 `[\n]<!-- statx:begin -->…<!-- statx:end -->`（唯一标识，绝无歧义；
 *      前导换行一并删掉 —— 它也是我们加的，删掉才逐字节回到原值）
 *   ② 兜底：删掉 `src` **完全等于** `<本插件的集中端点 origin>/st.js…` 的 script
 *      —— 刻意**不匹配 `/s3.js`**：tj 的标签恰好就是 `tj.51puer.com/s3.js`，
 *      早期版本在这里用过 `/s3.js` 的宽松正则，导致把 tj 的 script 一起删掉了（已修，见报告「当天踩坑」）。
 *   **除被删的单元外，一个字节都不动**（不 trim、不折叠空行）。
 */
function statx_collect_strip(string $text, string $origin = ''): string
{
    $t = preg_replace('~\n?<!--\s*statx:begin\s*-->.*?<!--\s*statx:end\s*-->~s', '', $text) ?? $text;
    if ($origin !== '') {
        $pat = '~<script[^>]*\bsrc="' . preg_quote(rtrim($origin, '/') . '/st.js', '~') . '[^"]*"[^>]*>\s*</script>~i';
        $t = preg_replace($pat, '', $t) ?? $t;
    }
    return $t;
}

/** 注入：先摘掉旧单元，再原样拼上新单元（不做任何 trim / 规范化） */
function statx_collect_prefix(string $cur, string $tag, string $origin): string
{
    return statx_collect_strip($cur, $origin) . $tag;
}

/** 目标站的 HTTP 首页地址 */
function statx_collect_site_url(array $s): string
{
    $h = (string)$s['host'];
    if ($h === '') { $h = (string)$s['site_key']; }
    return 'https://' . $h . '/';
}

/**
 * 抓目标站首页做**实证校验**（不看状态码，看内容标记）：
 *   ① 首页必须 200；② 必须能搜到脚本地址；③ 必须能搜到 data-site="<key>"
 * 返回 ['ok'=>bool,'code'=>int,'bytes'=>int,'src'=>bool,'site'=>bool,'ttfb'=>int,'note'=>string]
 */
function statx_collect_verify_site(string $siteKey, array $opt = []): array
{
    $s = statx_collect_site($siteKey);
    $t = statx_collect_tag($siteKey, $opt);
    $src = (string)$t['src'];
    $url = (string)($opt['url'] ?? ($s !== null ? statx_collect_site_url($s) : 'https://' . $siteKey . '/'));
    if (!empty($opt['bust'])) { $url = statx_bust($url); }

    $r = statx_http_chain($url, statx_ua(), 20, 8, true, 5);
    $body = (string)($r['body'] ?? '');
    $code = (int)($r['final_code'] ?? 0);
    $hitSrc  = $src !== '' && str_contains($body, $src);
    $hitSite = str_contains($body, 'data-site="' . $siteKey . '"');
    $ok = ($code === 200) && $hitSrc && $hitSite;
    $note = [];
    if ($code !== 200) { $note[] = '首页 HTTP ' . $code . (!empty($r['err']) ? (' / ' . $r['err']) : ''); }
    if (!$hitSrc)  { $note[] = 'HTML 里找不到脚本地址 ' . $src; }
    if (!$hitSite) { $note[] = 'HTML 里找不到 data-site="' . $siteKey . '"'; }
    return ['ok' => $ok, 'code' => $code, 'bytes' => strlen($body), 'src' => $hitSrc, 'site' => $hitSite,
            'ttfb' => (int)($r['ttfb'] ?? 0), 'total' => (int)($r['total'] ?? 0),
            'note' => $ok ? '首页 HTML 中已实证出现该行 JS' : implode('；', $note)];
}

/** 端点自检：probe=1（**不写盘、不做任何查询**），返回 X-Statx 内容标记 */
function statx_collect_probe_endpoint(string $epUrl, array $opt = []): array
{
    $url = $epUrl . (str_contains($epUrl, '?') ? '&' : '?') . 's=' . rawurlencode((string)($opt['site'] ?? 'probe')) . '&probe=1';
    $r = statx_collect_http($url, ['insecure' => (bool)($opt['insecure'] ?? false), 'resolve' => (string)($opt['resolve'] ?? '')]);
    return ['code' => $r['code'], 'marker' => $r['headers']['x-statx'] ?? '', 'bytes' => strlen($r['body']),
            'total' => $r['total'], 'err' => $r['err'], 'url' => $url];
}

/** 脚本自检：能取到 + 是 JS + 体积 */
function statx_collect_probe_script(string $jsUrl, array $opt = []): array
{
    $url = $jsUrl . (str_contains($jsUrl, '?') ? '&' : '?') . '_h=' . dechex(time());
    $r = statx_collect_http($url, ['insecure' => (bool)($opt['insecure'] ?? false), 'resolve' => (string)($opt['resolve'] ?? '')]);
    $ct = (string)($r['headers']['content-type'] ?? '');
    $ok = ($r['code'] === 200) && str_contains($ct, 'javascript') && str_contains($r['body'], 'data-site');
    return ['ok' => $ok, 'code' => $r['code'], 'ct' => $ct, 'bytes' => strlen($r['body']), 'total' => $r['total'],
            'cache' => (string)($r['headers']['cache-control'] ?? ''), 'err' => $r['err'], 'url' => $url];
}

/**
 * 极简 HTTP 客户端（只为注入校验/端点自检服务；**不动 core、不改 lib.php**）
 * 支持 resolve（绕 DNS 验收：--resolve tj.51puer.com:443:127.0.0.1）与 insecure（自签证书占位期）。
 */
function statx_collect_http(string $url, array $opt = []): array
{
    $ch = curl_init($url);
    if ($ch === false) { return ['code' => 0, 'body' => '', 'headers' => [], 'total' => 0, 'err' => 'curl_init failed']; }
    $head = [];
    $body = '';
    $method = strtoupper((string)($opt['method'] ?? 'GET'));
    $hdr = (array)($opt['headers'] ?? []);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => (bool)($opt['follow'] ?? true),
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_USERAGENT      => (string)($opt['ua'] ?? statx_ua()),
        CURLOPT_ENCODING       => '',
        CURLOPT_CONNECTTIMEOUT => (int)($opt['connect'] ?? 8),
        CURLOPT_TIMEOUT        => (int)($opt['timeout'] ?? 20),
        CURLOPT_SSL_VERIFYPEER => !($opt['insecure'] ?? false),
        CURLOPT_SSL_VERIFYHOST => ($opt['insecure'] ?? false) ? 0 : 2,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_NOBODY         => (bool)($opt['nobody'] ?? false),
    ]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POSTFIELDS, (string)($opt['body'] ?? '')); }
    if (($opt['origin'] ?? '') !== '') { $hdr[] = 'Origin: ' . $opt['origin']; }
    if ($hdr) { curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr); }
    if (($opt['resolve'] ?? '') !== '') {
        $p = explode(':', (string)$opt['resolve']);
        if (count($p) >= 3) { curl_setopt($ch, CURLOPT_RESOLVE, [$p[0] . ':' . (int)$p[1] . ':' . $p[2]]); }
    }
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $line) use (&$head) {
        $i = strpos($line, ':');
        if ($i > 0) { $head[strtolower(trim(substr($line, 0, $i)))] = trim(substr($line, $i + 1)); }
        return strlen($line);
    });
    $body = (string)curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    return [
        'code' => (int)($info['http_code'] ?? 0),
        'body' => $body,
        'headers' => $head,
        'total' => (int)round(((float)($info['total_time'] ?? 0)) * 1000),
        'ttfb'  => (int)round(((float)($info['starttransfer_time'] ?? 0)) * 1000),
        'err'   => $err,
    ];
}

/**
 * 该站是否启用 home-static（内核插件，目录 plugins/home-static）。
 * ★ 判定与 home-static 自己的 hs_front() 完全同口径：**选项未设置 = 启用**（`!== '1'` 才算关）。
 *   若该插件目录不存在 → 不适用（返回 false）。
 */
function statx_collect_home_static_state(string $root): array
{
    $root = rtrim($root, '/');
    if (!is_dir($root . '/plugins/home-static') || !is_file($root . '/plugins/home-static/inc.php')) {
        return ['plugin' => false, 'enabled' => false, 'files' => 0, 'stale' => false, 'ttl' => 0, 'dir' => ''];
    }
    // ⚠️ statx_collect_local_option() 的第 3 参是 `string $def`：只能用 ''，不能传 null。
    //    '' = 选项未设置 ⇒ 与 hs_front() 同口径视为**启用**（只有显式 '0' 才算关）。
    $en  = statx_collect_local_option($root, 'home_static.enabled', '');
    $ttl = (int)(statx_collect_local_option($root, 'home_static.ttl', '') ?: 600);
    $ttl = max(30, $ttl);
    $dir = $root . '/var/home-static';
    $n = 0; $newest = 0;
    if (is_dir($dir)) {
        foreach (glob($dir . '/*/index.html') ?: [] as $f) { $n++; $newest = max($newest, (int)@filemtime($f)); }
        if (is_file($dir . '/index.html')) { $n++; $newest = max($newest, (int)@filemtime($dir . '/index.html')); }
    }
    return ['plugin' => true, 'enabled' => ($en !== '0'), 'files' => $n,
            'stale' => ($n > 0 && $newest > 0 && (time() - $newest) > $ttl), 'ttl' => $ttl, 'dir' => $dir];
}

/**
 * ★ r10【根因②】重建目标站的 home-static 静态首页。
 * 为什么必须有这一步：启用 home-static 的站在**静态文件新鲜（TTL 内）**时由 hs_front()
 * **直出磁盘文件**，动态渲染根本不执行。于是「写 stats.foot + 清页缓存」只是**写成功**，
 * 裸 URL 仍命中旧静态 HTML ⇒ 实证失败 ⇒ 自动回滚（r9 实测：清了 52250 个缓存仍回滚）。
 *
 * 实现方式：**在目标站自己的进程里** require 它的 core/bootstrap.php（拿到 get_option/set_option/curl），
 * 再调用 home-static 插件**原生**的 hs_generate() —— 不复制它的目录/语言/TTL 逻辑，避免两份实现漂移。
 * 关键点：显式把 host 传进去（HS_HOST），否则 CLI 下 hs_fetch() 会因 nginx default_server
 * 而抓到**别的站**。
 *
 * 返回 ['ok'=>bool,'state'=>array,'gen'=>array,'out'=>string]
 */
function statx_collect_rebuild_home_static(string $siteKey, string $root, string $host, array $opt = []): array
{
    $st = statx_collect_home_static_state($root);
    if (!$st['plugin']) { return ['ok' => true, 'skipped' => '该站未安装 home-static 插件', 'state' => $st, 'gen' => []]; }
    if (!$st['enabled']) { return ['ok' => true, 'skipped' => '该站 home-static 已关闭（home_static.enabled=0）', 'state' => $st, 'gen' => []]; }
    // ★ 不用子进程：目标站的 config.inc.php/bootstrap.php 在 CLI 下 require 会直接
    //   触发前台渲染并吐出整页 HTML（实测：要求子进程输出 JSON，结果收到 94206B 的首页），
    //   既拿不到干净返回值，也确实"跑了一次不该跑的渲染"。
    //   改为**进程内正规 HTTP 回环**：只 require home-static 的 inc.php（纯函数库，无副作用），
    //   用 hs_fetch() 抓动态 HTML，再按 hs_langs() 的映射落盘。
    $hsInc = $root . '/plugins/home-static/inc.php';
    if (!is_file($hsInc)) { return ['ok' => false, 'error' => 'home-static inc.php 不存在', 'state' => $st, 'gen' => []]; }
    // 纯函数库：不 require 目标站的 config/bootstrap，因此不会触发前台渲染
    if (!function_exists('hs_fetch') || !function_exists('hs_dir')) {
        try { require_once $hsInc; } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'require home-static inc.php 失败：' . $e->getMessage(), 'state' => $st, 'gen' => []];
        }
    }
    if (!function_exists('hs_fetch')) { return ['ok' => false, 'error' => 'hs_fetch 不可用', 'state' => $st, 'gen' => []]; }
    // 语言→前缀映射：优先用 home-static 自己的 hs_langs()（权威），否则按单语处理
    $langs = [];
    if (function_exists('hs_langs')) {
        try { $langs = (array)hs_langs(); } catch (Throwable $e) { $langs = []; }
    }
    if (!$langs) { $langs = ['' => '']; }
    $dir = rtrim($root, '/') . '/var/home-static';
    $gen = []; $files = []; $detailed = [];
    foreach ($langs as $lg => $prefix) {
        $prefix = (string)$prefix;
        $html = '';
        if (function_exists('hs_fetch')) {
            try { $html = (string)hs_fetch($prefix, $host); } catch (Throwable $e) { $html = ''; }
        }
        $okOne = false;
        if (strlen($html) > 500 && stripos($html, '<html') !== false && stripos($html, '</html>') !== false) {
            $sub = $prefix === '' ? $dir : $dir . '/' . $lg;
            if (!is_dir($sub)) { @mkdir($sub, 0755, true); }
            $file = $sub . '/index.html';
            $okOne = @file_put_contents($file, $html) !== false;
            if ($okOne) { @chmod($file, 0644); $files[] = $file; }
            $detailed[(string)$lg] = ['ok' => $okOne, 'bytes' => strlen($html), 'file' => $file,
                                      'data_site' => str_contains($html, 'data-site="' . $siteKey . '"')];
        } else {
            $detailed[(string)$lg] = ['ok' => false, 'bytes' => strlen($html), 'error' => '抓取内容不是完整 HTML'];
        }
        $gen[(string)$lg] = $okOne;
    }
    sort($files);
    $anyOk = in_array(true, $gen, true);
    if (!$anyOk) {
        return ['ok' => false, 'error' => '所有语言的动态抓取都失败（hs_fetch 返回空/非 HTML）',
                'state' => $st, 'gen' => $gen, 'detail' => $detailed];
    }
    // 与 home-static 原生 hs_generate() 一致：记录生成时间（供 hs_front() 的 TTL 判定）
    statx_collect_local_option_set($root, 'hs.gen_at', (string)time());
    clearstatcache(true);
    $after = statx_collect_home_static_state($root);
    return ['ok' => true, 'state' => $after, 'before' => $st, 'gen' => $gen, 'files' => $files,
            'detail' => $detailed, 'host' => $host];
}

/**
 * ★ r10【根因②·验收判据】**裸 URL 三方一致**（不带查询串）：
 *   ① 源站裸 /（PHP 侧用 --resolve 钉回环直连，绕 CF/CDN）
 *   ② 公网裸 /（正常 DNS，可能经 CF）
 *   ③ 磁盘静态文件 var/home-static/index.html
 *   三者必须【字节数 + sha256 + 含 data-site="<key>"】全等。
 * ⚠️ 只看带查询串的动态渲染会漏掉 home-static 直出的情况——这正是 r9 误判的根因。
 */
function statx_collect_verify_bare_triple(string $siteKey, string $host, string $root, array $opt = []): array
{
    $res = ['ok' => false, 'parts' => [], 'note' => ''];
    $ua = statx_ua();
    // ① 源站裸 /（PHP 侧用 --resolve 钉回环直连，绕 CF/CDN；SNI 与 Host 都是正确域名）
    $r1 = statx_collect_http('https://' . $host . '/', ['resolve' => $host . ':443:127.0.0.1',
        'insecure' => true, 'ua' => $ua, 'follow' => false, 'timeout' => 25]);
    // ② 公网裸 /（正常 DNS，可能经 CF）
    $r2 = statx_collect_http('https://' . $host . '/', ['ua' => $ua, 'follow' => true, 'timeout' => 25]);
    // ③ 磁盘静态文件（可能不存在 —— 未启用 home-static 的站本来就没有）
    $f = rtrim($root, '/') . '/var/home-static/index.html';
    $hasStatic = is_file($f);
    $r3 = ['code' => $hasStatic ? 200 : 0, 'body' => $hasStatic ? (string)@file_get_contents($f) : ''];
    foreach ([['origin', '源站裸/', $r1], ['public', '公网裸/', $r2], ['static', '磁盘静态', $r3]] as $x) {
        $b = (string)$x[2]['body'];
        $res['parts'][$x[0]] = ['label' => $x[1], 'code' => (int)$x[2]['code'], 'bytes' => strlen($b),
            'sha256' => $b === '' ? '' : hash('sha256', $b),
            'data_site' => $b !== '' && str_contains($b, 'data-site="' . $siteKey . '"'),
            'file' => $x[0] === 'static' ? $f : '',
            'present' => $x[0] !== 'static' || $hasStatic];
    }
    $hsState = statx_collect_home_static_state($root);
    $res['home_static_enabled'] = (bool)$hsState['enabled'];
    $res['home_static_files'] = (int)$hsState['files'];
    // 静态文件是否**应存在**：home-static 启用且该站已有静态文件（或本次刚重建出来）
    $res['static_applicable'] = $hasStatic;
    $o = $res['parts']['origin']; $p = $res['parts']['public']; $s = $res['parts']['static'];
    $res['origin_equals_public'] = ($o['code'] !== 0 && $p['code'] !== 0 && $o['sha256'] !== ''
        && $o['sha256'] === $p['sha256'] && $o['data_site'] && $p['data_site']);
    $res['static_equals_origin'] = $hasStatic ? ($s['sha256'] !== '' && $s['sha256'] === $o['sha256'] && $s['data_site'])
                                             : null;   // null = 不适用
    $res['ok'] = (bool)$res['origin_equals_public'] && ($hasStatic ? (bool)$res['static_equals_origin'] : true);
    $n = [];
    if (!$res['origin_equals_public']) {
        $n[] = '源站裸/ 与 公网裸/ 不一致（源站 ' . (int)$o['bytes'] . 'B code=' . (int)$o['code']
             . ' data-site=' . ((int)(bool)$o['data_site']) . ' / 公网 ' . (int)$p['bytes'] . 'B code='
             . (int)$p['code'] . ' data-site=' . ((int)(bool)$p['data_site']) . '）';
    }
    if ($hasStatic && !$res['static_equals_origin']) {
        $n[] = '磁盘静态与源站不一致（静态 ' . (int)$s['bytes'] . 'B data-site=' . ((int)(bool)$s['data_site']) . '）';
    }
    if (!$hasStatic) { $n[] = '该站无 home-static 静态文件 → 静态比对不适用（只比源站/公网）'; }
    $res['note'] = $res['ok']
        ? ('✅ 源站裸/ = 公网裸/' . ($hasStatic ? ' = 磁盘静态' : '（无静态文件，不适用）') . '；sha256 全等且含 data-site')
        : ('❌ ' . implode('；', $n));
    return $res;
}

/**
 * 一键写入「一行 JS」：写 stats.foot（写成 head 兼容 —— 若站点的主题不输出 foot 则自动改投 stats.head）
 * 流程：① 摘掉旧的本插件块 ② 追加新块 ③ 清该站全页缓存 ④ **重建 home-static 静态首页**（启用时）
 *       ⑤ **裸 URL 三方一致**实证；失败则自动换 head 再来一次
 * ★ r10 补的 ④ 是【根因②】：启用 home-static 的站在 TTL 内由 hs_front() 直出磁盘文件、
 *   动态渲染不执行 ⇒ 只清页缓存会「写成功 → 实证失败 → 回滚」。两件事必须都做。
 */
function statx_collect_inject(string $siteKey, array $opt = []): array
{
    statx_sync_tz();
    $s = statx_collect_site($siteKey);
    if ($s === null) { return ['ok' => false, 'error' => '站点未登记：' . $siteKey]; }
    $root = statx_collect_local_root($siteKey, (string)$s['host']);
    if ($root === null) { return ['ok' => false, 'error' => '本机未找到该站的 YeCha 站点根目录（不能跨机写 option）']; }
    if (statx_collect_local_pdo($root) === null) { return ['ok' => false, 'error' => '无法用该站自己的凭据连接其数据库']; }
    // 目标站的 host（用于 home-static 回环重建与三方一致校验）：登记 host 优先，其次域名形态的 site_key
    $host = statx_collect_valid_host((string)$s['host']);
    if ($host === '') { $host = statx_collect_valid_host($siteKey); }
    if ($host === '') { return ['ok' => false, 'error' => '该站没有合法的 host（不含点的登记不能用）']; }

    $t = statx_collect_tag($siteKey, $opt);
    $wheres = !empty($opt['where']) ? [(string)$opt['where']] : ['foot', 'head'];
    $log = [];
    $final = ['ok' => false, 'where' => '', 'note' => '未尝试任何位置'];

    foreach ($wheres as $w) {
        $name = 'stats.' . $w;
        $cur  = statx_collect_local_option($root, $name, '');
        $new  = statx_collect_prefix($cur, $t['tag'], rtrim((string)($opt['origin'] ?? statx_collect_pub_origin()), '/'));
        if (!statx_collect_local_option_set($root, $name, $new)) {
            $log[] = $w . ': 写入 option 失败'; continue;
        }
        $flushed = statx_collect_local_cache_flush($root);
        // ★ ④ 重建 home-static 静态首页（启用该插件时）——缺这一步就会「写成功→实证失败→回滚」
        $hs = statx_collect_rebuild_home_static($siteKey, $root, $host, $opt);
        if (!empty($hs['skipped'])) {
            $log[] = $w . ': home-static 跳过（' . (string)$hs['skipped'] . '）';
        } elseif (empty($hs['ok'])) {
            $log[] = $w . ': home-static 重建**失败**（' . (string)($hs['error'] ?? '?') . '）';
        } else {
            $log[] = $w . ': 已重建 home-static 静态首页 '
                . json_encode((array)($hs['gen'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . ' 文件=' . count((array)($hs['files'] ?? []));
        }
        $v = statx_collect_verify_site($siteKey, $opt + ['bust' => true]);
        // ★ 验收判据：**裸 URL 三方一致**（源站裸/ = 公网裸/ = 磁盘静态；字节+sha256+data-site）
        $tri = statx_collect_verify_bare_triple($siteKey, $host, $root, $opt);
        $v['triple'] = $tri;
        $v['home_static'] = ['enabled' => (bool)($hs['state']['enabled'] ?? false),
                             'files' => (int)($hs['state']['files'] ?? 0),
                             'rebuilt' => (bool)($hs['ok'] ?? false) && empty($hs['skipped'])];
        if ($v['ok'] && !$tri['ok']) {
            $v['ok'] = false;
            $v['note'] = '动态渲染已实证，但**裸 URL 三方不一致**：' . $tri['note']
                . '（源站=' . (int)$tri['parts']['origin']['bytes'] . 'B/公网=' . (int)$tri['parts']['public']['bytes']
                . 'B/静态=' . (int)$tri['parts']['static']['bytes'] . 'B）';
        }
        $log[] = $w . ': 裸 URL 三方一致 → ' . ($tri['ok'] ? '通过' : ('未通过（' . $tri['note'] . '）'));
        statx_collect_site_save($siteKey, [
            'inject_where' => $w, 'inject_tag' => $t['tag'], 'inject_at' => date('Y-m-d H:i:s'),
            'script_url' => $t['src'], 'endpoint_url' => $t['ep'],
            'verify_at' => date('Y-m-d H:i:s'), 'verify_ok' => $v['ok'] ? 1 : 0,
            'verify_note' => mb_substr($v['note'], 0, 255),
        ]);
        if ($v['ok']) { return ['ok' => true, 'where' => $w, 'tag' => $t['tag'], 'src' => $t['src'],
                                'ep' => $t['ep'], 'verify' => $v, 'flush' => $flushed,
                                'home_static' => $v['home_static'], 'triple' => $tri, 'log' => $log]; }
        $final = ['ok' => false, 'where' => $w, 'note' => $v['note']];
        // 失败 → 把这次尝试撤掉，换下一个位置
        statx_collect_local_option_set($root, $name, $cur);
        statx_collect_local_cache_flush($root);
    }
    return ['ok' => false, 'error' => '写入后首页未实证到该行 JS', 'detail' => $final, 'log' => $log];
}

/** 一键移除（回滚 = 删一行）：只摘本插件块，旁站统计标签原样保留 */
function statx_collect_remove(string $siteKey, array $opt = []): array
{
    $s = statx_collect_site($siteKey);
    if ($s === null) { return ['ok' => false, 'error' => '站点未登记：' . $siteKey]; }
    $root = statx_collect_local_root($siteKey, (string)$s['host']);
    if ($root === null) { return ['ok' => false, 'error' => '本机未找到该站点根目录']; }
    $n = 0;
    $origin = rtrim((string)($opt['origin'] ?? statx_collect_pub_origin()), '/');
    foreach (['foot', 'head'] as $w) {
        $name = 'stats.' . $w;
        $cur = statx_collect_local_option($root, $name, '');
        if ($cur === '') { continue; }
        $clean = statx_collect_strip($cur, $origin);
        if (trim($clean) === trim($cur)) { continue; }
        if (statx_collect_local_option_set($root, $name, $clean)) { $n++; }
    }
    $flushed = statx_collect_local_cache_flush($root);
    // ★ r10：与注入同源的问题——清页缓存不够，必须**同时重建 home-static 静态首页**，
    //   否则 TTL 内裸 URL 仍直出「还带着统计代码」的旧静态页，移除当场不生效。
    $h = statx_collect_valid_host((string)$s['host']);
    if ($h === '') { $h = statx_collect_valid_host($siteKey); }
    $hs = $h !== '' ? statx_collect_rebuild_home_static($siteKey, $root, $h, $opt)
                    : ['ok' => true, 'skipped' => '该站无合法 host，跳过静态重建'];
    statx_collect_site_save($siteKey, ['inject_where' => '', 'inject_tag' => '',
        'verify_at' => date('Y-m-d H:i:s'), 'verify_ok' => 0,
        'verify_note' => '已移除注入（' . $n . ' 个 option）；home-static '
            . (!empty($hs['skipped']) ? ('跳过：' . $hs['skipped']) : (!empty($hs['ok']) ? '已重建' : '重建失败'))]);
    return ['ok' => true, 'cleared' => $n, 'flush' => $flushed, 'home_static' => $hs];
}

/* ============================== 看板取数（SQLite 版） ============================== */

/**
 * 概况 KPI：today / d7 / d30 的 PV、UV、爬虫。
 * 口径变化（批次 1）：
 *   · PV 只数 `kind='pv'` —— 点击/事件行（kind=cl/ev）**不再混进 PV**
 *   · UV 优先用客户端 uid（`COALESCE(NULLIF(uid,''), visitor_hash)`）—— 有 uid 时它比逐日轮换的
 *     visitor_hash 更准（visitor_hash 每天换盐，跨天重复访问会被算两次）
 */
function statx_collect_kpi(): array
{
    statx_sync_tz();
    $H = STATX_SDB_HITS;
    $out = ['error' => ''];
    try {
        foreach (['today' => 0, 'd7' => 6, 'd30' => 29] as $k => $back) {
            $from = date('Y-m-d', strtotime('-' . $back . ' day'));
            $b = statx_sdb_one("SELECT COUNT(*) pv, COUNT(DISTINCT COALESCE(NULLIF(uid,''), visitor_hash)) uv
                    FROM {$H} WHERE d>=? AND kind='pv' AND is_bot=0", [$from]) ?? [];
            $bot = (int)statx_sdb_val("SELECT COUNT(*) FROM {$H} WHERE d>=? AND kind='pv' AND is_bot=1", [$from]);
            $out[$k] = ['pv' => (int)($b['pv'] ?? 0), 'uv' => (int)($b['uv'] ?? 0), 'bot' => $bot];
        }
        $out['last_hit'] = (string)statx_sdb_val("SELECT MAX(ts) FROM {$H} WHERE kind='pv'");
        $out['hits_total'] = (int)statx_sdb_val("SELECT COUNT(*) FROM {$H}");
        $out['clicks'] = (int)statx_sdb_val("SELECT COUNT(*) FROM {$H} WHERE kind='cl'");
    } catch (Throwable $e) {
        foreach (['today', 'd7', 'd30'] as $k) { $out[$k] = ['pv' => 0, 'uv' => 0, 'bot' => 0]; }
        $out['last_hit'] = '';
        $out['hits_total'] = 0;
        $out['clicks'] = 0;
        $out['error'] = $e->getMessage();
    }
    return $out;
}

/** 近 N 天趋势（按天，PV/UV/爬虫） */
function statx_collect_series(int $days = 30, string $siteKey = ''): array
{
    statx_sync_tz();
    $H = STATX_SDB_HITS;
    $from = date('Y-m-d', strtotime('-' . max(1, $days - 1) . ' day'));
    $par = [$from];
    $w = '';
    if ($siteKey !== '') { $w = ' AND site_key=?'; $par[] = $siteKey; }
    $byDay = [];
    try {
        foreach (statx_sdb_all("SELECT d, COUNT(*) pv, COUNT(DISTINCT COALESCE(NULLIF(uid,''), visitor_hash)) uv
                FROM {$H} WHERE d>=? AND kind='pv' AND is_bot=0{$w} GROUP BY d ORDER BY d", $par) as $r) {
            $byDay[(string)$r['d']] = ['pv' => (int)$r['pv'], 'uv' => (int)$r['uv'], 'bot' => 0];
        }
        foreach (statx_sdb_all("SELECT d, COUNT(*) n FROM {$H} WHERE d>=? AND kind='pv'{$w} AND is_bot=1 GROUP BY d", $par) as $r) {
            $d = (string)$r['d'];
            $byDay[$d]['bot'] = (int)$r['n'];
            $byDay[$d] += ['pv' => $byDay[$d]['pv'] ?? 0, 'uv' => $byDay[$d]['uv'] ?? 0];
        }
    } catch (Throwable $e) { /* 库不可用时给零曲线，后台页仍渲染 */ }
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime('-' . $i . ' day'));
        $out[] = ['d' => $d] + ($byDay[$d] ?? ['pv' => 0, 'uv' => 0, 'bot' => 0]);
    }
    return $out;
}

/** TOP 榜（ref 来源域名 / page 路径 / device / bot_name） */
function statx_collect_top(string $dim, int $days = 30, string $siteKey = '', int $limit = 10, bool $includeBots = false): array
{
    statx_sync_tz();
    $H = STATX_SDB_HITS;
    $col = $dim === 'ref' ? 'ref_domain' : ($dim === 'page' ? 'path' : ($dim === 'device' ? 'device' : 'bot_name'));
    $from = date('Y-m-d', strtotime('-' . max(0, $days - 1) . ' day'));
    $par = [$from];
    $w = '';
    if ($siteKey !== '') { $w .= ' AND site_key=?'; $par[] = $siteKey; }
    if ($dim === 'ref') { $w .= " AND ref_kind='out'"; }
    $w .= $includeBots ? '' : ' AND is_bot=0';
    if ($dim === 'bot') { $w .= ' AND is_bot=1'; }
    try {
        return statx_sdb_all("SELECT {$col} k, COUNT(*) n FROM {$H}
                WHERE d>=? AND kind='pv'{$w} AND {$col}<>'' GROUP BY {$col} ORDER BY n DESC LIMIT " . max(1, $limit), $par);
    } catch (Throwable $e) { return []; }
}

/** 每站一行（与健康矩阵相邻）：今日 PV/UV + 爬虫 + 注入状态 */
function statx_collect_site_rows(int $days = 1): array
{
    statx_sync_tz();
    $H = STATX_SDB_HITS;
    $from = date('Y-m-d', strtotime('-' . max(0, $days - 1) . ' day'));
    $agg = [];
    try {
        foreach (statx_sdb_all("SELECT site_key, COUNT(*) pv,
                COUNT(DISTINCT COALESCE(NULLIF(uid,''), visitor_hash)) uv FROM {$H}
                WHERE d>=? AND kind='pv' AND is_bot=0 GROUP BY site_key", [$from]) as $r) {
            $agg[(string)$r['site_key']]['pv'] = (int)$r['pv'];
            $agg[(string)$r['site_key']]['uv'] = (int)$r['uv'];
        }
        foreach (statx_sdb_all("SELECT site_key, COUNT(*) n FROM {$H}
                WHERE d>=? AND kind='pv' AND is_bot=1 GROUP BY site_key", [$from]) as $r) {
            $agg[(string)$r['site_key']]['bot'] = (int)$r['n'];
        }
    } catch (Throwable $e) { /* 同上 */ }
    $rows = [];
    foreach (statx_collect_sites() as $s) {
        $k = (string)$s['site_key'];
        $rows[] = $s + ['pv' => $agg[$k]['pv'] ?? 0, 'uv' => $agg[$k]['uv'] ?? 0, 'bot' => $agg[$k]['bot'] ?? 0,
                        'local' => statx_collect_local_root($k, (string)$s['host']) !== null];
    }
    return $rows;
}

/* ============================== 采集字段自检（批次 1 新增） ============================== */

/**
 * 最近 N 条明细（含本批全部新字段）—— 给 `--task=collect-fields` 与验收用。
 * 只读；默认不过滤站点，可给 `$site` 缩小范围。
 */
function statx_collect_fields(int $limit = 20, string $site = '', string $kind = ''): array
{
    $sql = 'SELECT id,ts,d,site_key,host,path,kind,is_landing,ref,ref_domain,ref_kind,
                   utm_source,utm_medium,utm_campaign,utm_term,utm_content,
                   sid,uid,is_new,visitor_hash,width,height,vw,vh,lang,x,y,en,device,is_bot,bot_cls,bot_name
            FROM ' . STATX_SDB_HITS;
    $par = [];
    $w = [];
    if ($site !== '') { $w[] = 'site_key=?'; $par[] = $site; }
    if ($kind !== '') { $w[] = 'kind=?'; $par[] = $kind; }
    if ($w) { $sql .= ' WHERE ' . implode(' AND ', $w); }
    $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit));
    try { return statx_sdb_all($sql, $par); } catch (Throwable $e) { return []; }
}

/**
 * 访客/会话画像（本批「跨天可关联」的最小证据）：按 uid 聚合的次数/页面数/会话数/首末时间。
 * 只读，用于 `--task=collect-fields --by=uid` 与后续「留存 D+1/D+3/D+7」的地基。
 */
function statx_collect_visitors(int $days = 30, string $site = '', int $limit = 20): array
{
    $from = date('Y-m-d', strtotime('-' . max(0, $days - 1) . ' day'));
    $sql = "SELECT COALESCE(NULLIF(uid,''), visitor_hash) uk,
                   CASE WHEN uid<>'' THEN 'uid' ELSE 'vhash' END src,
                   COUNT(*) pv, COUNT(DISTINCT path) pages, COUNT(DISTINCT NULLIF(sid,'')) sess,
                   MIN(ts) first_ts, MAX(ts) last_ts, COUNT(DISTINCT d) days
            FROM " . STATX_SDB_HITS . " WHERE d>=? AND kind='pv' AND is_bot=0";
    $par = [$from];
    if ($site !== '') { $sql .= ' AND site_key=?'; $par[] = $site; }
    $sql .= ' GROUP BY uk ORDER BY last_ts DESC, pv DESC LIMIT ' . max(1, min(200, $limit));
    try { return statx_sdb_all($sql, $par); } catch (Throwable $e) { return []; }
}

/** 会话深度分布 / 跳出（下一批「深度/流向」的地基；本批就能跑，用来证明 sid 真的可用） */
function statx_collect_depth(int $days = 30, string $site = ''): array
{
    $from = date('Y-m-d', strtotime('-' . max(0, $days - 1) . ' day'));
    $sql = "SELECT depth, COUNT(*) sessions FROM (
                SELECT sid, COUNT(*) depth FROM " . STATX_SDB_HITS . "
                WHERE d>=? AND kind='pv' AND is_bot=0 AND sid<>''";
    $par = [$from];
    if ($site !== '') { $sql .= ' AND site_key=?'; $par[] = $site; }
    $sql .= ' GROUP BY sid) GROUP BY depth ORDER BY depth';
    try { return statx_sdb_all($sql, $par); } catch (Throwable $e) { return []; }
}

/* ============================== 异地备份（按天汇总邮件） ============================== */

/**
 * 异地备份：把**按天汇总**导出成小体积 CSV，作为邮件正文发到收件人。
 * ★ 只发汇总，**绝不发明细**（明细含访客标识）；CSV 里没有任何 IP、没有任何 uid/sid/visitor_hash。
 * ★ 说明：core/Mail.php 只支持 text/plain（无附件），为不改内核，这里用正文 CSV；
 *   若将来 core 支持附件，只需把 $body 挪到 attachment 即可。
 */
function statx_collect_backup(int $days = 7, string $to = ''): array
{
    statx_sync_tz();
    $to = $to !== '' ? $to : statx_notify_to();
    $D = STATX_SDB_ROLLUP;
    $H = STATX_SDB_HITS;
    $from = date('Y-m-d', strtotime('-' . max(1, $days - 1) . ' day'));
    $today = date('Y-m-d');

    $rows = [];
    try {
        // ① 已汇总的历史（hits_rollup）
        foreach (statx_sdb_all("SELECT site_key,d,pv,uv,landing,ref_ext,bot_hits FROM {$D}
                WHERE d>=? ORDER BY d DESC, site_key", [$from]) as $r) {
            $rows[(string)$r['site_key'] . '|' . (string)$r['d']] = $r;
        }
        // ② 今天（还没被 rollup，直接从明细现算）——这样第一天就有东西可备份
        foreach (statx_sdb_all("SELECT site_key, COUNT(*) pv FROM {$H}
                WHERE d=? AND kind='pv' AND is_bot=0 GROUP BY site_key", [$today]) as $r) {
            $k = (string)$r['site_key'];
            $one = function (string $extra) use ($H, $k, $today): int {
                return (int)statx_sdb_val("SELECT COUNT(*) FROM {$H} WHERE site_key=? AND d=? AND kind='pv' AND {$extra}",
                    [$k, $today]);
            };
            $rows[$k . '|' . $today] = [
                'site_key' => $k, 'd' => $today,
                'pv'       => (int)$r['pv'],
                'uv'       => (int)statx_sdb_val("SELECT COUNT(DISTINCT COALESCE(NULLIF(uid,''), visitor_hash)) FROM {$H}
                                 WHERE site_key=? AND d=? AND kind='pv' AND is_bot=0", [$k, $today]),
                'landing'  => $one('is_bot=0 AND is_landing=1'),
                'ref_ext'  => $one("is_bot=0 AND ref_kind='out'"),
                'bot_hits' => $one('is_bot=1'),
            ];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'SQLite 读取失败：' . $e->getMessage()];
    }
    ksort($rows);

    $csv = "site_key,date,pv,uv,landing,ref_ext,bot_hits\n";
    foreach ($rows as $r) {
        $csv .= $r['site_key'] . ',' . $r['d'] . ',' . (int)$r['pv'] . ',' . (int)$r['uv'] . ','
              . (int)$r['landing'] . ',' . (int)$r['ref_ext'] . ',' . (int)$r['bot_hits'] . "\n";
    }
    $body = "StatX 统计异地备份（按天汇总，近 {$days} 天）\n"
          . '生成时间：' . date('Y-m-d H:i:s') . '（' . date_default_timezone_get() . "）\n"
          . "口径：pv/uv 仅计人类流量（爬虫单独在 bot_hits）；uv = 当日访客去重（优先客户端随机 uid）。\n"
          . "隐私：本文件**不含 IP、不含访客标识原文、不含明细行**，只有按天计数。\n\n"
          . $csv . "\n"
          . '站点数：' . count(array_unique(array_column($rows, 'site_key'))) . ' · 行数：' . count($rows) . "\n";
    if (!$rows) { return ['ok' => false, 'error' => '近 ' . $days . ' 天没有可汇总的数据（明细与 rollup 都是空的）']; }
    $r = statx_mail_send($to, '[StatX] 统计按天汇总备份 ' . date('Y-m-d'), $body);
    return ['ok' => $r === true, 'to' => $to, 'rows' => count($rows), 'bytes' => strlen($body),
            'error' => $r === true ? '' : (string)$r];
}
