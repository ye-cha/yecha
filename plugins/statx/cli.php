<?php
declare(strict_types=1);
/**
 * StatX · 统一 CLI 入口（cron 只用这一个文件）
 *
 * 用法（绝对路径 + 建议 nice/ionice）：
 *   php plugins/statx/cli.php --task=health              # 一轮健康探测（含去抖/告警/每日汇总）
 *   php plugins/statx/cli.php --task=health --tier=P0    # 只跑 P0
 *   php plugins/statx/cli.php --task=health --target=3   # 强制探测指定目标（忽略间隔）
 *   php plugins/statx/cli.php --task=health --no-retry   # 不重试（快速巡检）
 *   （每轮 health 还会顺带跑一次**内部自检**：读备份看守 /root/tj-backup-watch 的
 *     state.json mtime + 最近一次 rc；失联或 rc!=0 即走同一条告警通道发信。）
 *   php plugins/statx/cli.php --task=rollup              # 明细 → 按天汇总（幂等）
 *   php plugins/statx/cli.php --task=prune [--yes]        # 保留：汇总 + 分批删除（30 天明细 / 24 个月汇总）
 *       ★ 不加 --yes = 只预览（打印将删行数 + 前 10 行样本），不执行删除
 *   php plugins/statx/cli.php --task=notify              # 只补发未送达的告警
 *   php plugins/statx/cli.php --task=list                # 列出目标
 *   php plugins/statx/cli.php --task=mailimport          # 从 stdin 导入 SMTP 配置（JSON；不落盘）
 *   php plugins/statx/cli.php --task=mailtest --to=x@y   # 试发一封告警样式邮件
 *   php plugins/statx/cli.php --task=migrate             # 建表 + 播种首批目标（幂等）
 *   php plugins/statx/cli.php --task=selftest --target=3 --ua=probe-bare   # 裸 UA 对照组
 *   php plugins/statx/cli.php --task=rltest --target=N --n=60              # 429 识别对照组
 *
 * —— 能力①（自建统计采集）——
 *   ★ 批次 1 起：采集数据落在**插件自带的 SQLite**（默认 /www/gushu-statx/statx.db，站点根之外）；
 *     老的 4 张 ye_statx_* 采集表停止写入（collect-init 会把站点登记与最近明细一次性搬过去）。
 *   php plugins/statx/cli.php --task=collect-init                    # 建 SQLite 表 + 搬老数据 + 播种站点 + 生成 rt.php
 *   php plugins/statx/cli.php --task=collect-init --no-import        # 同上，但不从 MySQL 老表搬数据
 *   php plugins/statx/cli.php --task=collect                         # 只收 spool → SQLite hits（批量 INSERT）
 *   php plugins/statx/cli.php --task=collect-rollup                  # ① 收 spool ② hits → hits_rollup（cron 每分钟）
 *   php plugins/statx/cli.php --task=collect-prune [--keep=30] [--yes] # 保留清理（cron 每天）
 *       ★ 不加 --yes = **预览模式**：只打印「将删 N 行 + 前 10 行样本」，**不删任何行**；只有显式 --yes 才真删
 *   php plugins/statx/cli.php --task=collect-status                  # 采集自检：开关/rt.php/积压/存储层/各站注入实证
 *   php plugins/statx/cli.php --task=collect-fields [--by=uid|depth|storage]  # 本批新字段落库实证 / 访客画像 / 会话深度
 *   php plugins/statx/cli.php --task=collect-selftest --n=12000      # 用测试 site_key 造数据验证「明细→汇总→分批删」
 *   php plugins/statx/cli.php --task=collect-selftest --purge [--yes] # 清掉自测数据（不加 --yes 只预览，不删）
 *   php plugins/statx/cli.php --task=sdb                             # SQLite 存储层一览（路径/权限/PRAGMA/表索引字节）
 *   php plugins/statx/cli.php --task=links-health [--site=yecha.org] # 链接体检**只读**接口（复用既有 links 体检，零出站）
 *   php plugins/statx/cli.php --task=collect-inject --site=<key>     # 一键写入「一行 JS」并**抓首页实证**
 *   php plugins/statx/cli.php --task=collect-remove --site=<key>     # 一键移除（回滚 = 删一行）
 *   php plugins/statx/cli.php --task=collect-test --site=<key>       # 对照组：真实 Chrome UA vs 裸 UA/爬虫 UA
 *   php plugins/statx/cli.php --task=collect-backup [--to=x@y]       # 异地备份：按天汇总 CSV 邮件（无明细/无 IP）
 *   php plugins/statx/cli.php --task=collect-endpoint [--url=…]      # 端点/脚本自检（支持 --resolve 绕 DNS 验收）
 *
 * 退出码：0 正常；1 有目标失败；2 配置/参数错误；3 探针配置故障（guard 拦了探针）
 */

$ROOT = dirname(__DIR__, 2);
require $ROOT . '/config.inc.php';
require $ROOT . '/core/bootstrap.php';
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/selfcheck.php';   // 内部自检（备份看守自身，无 HTTP 目标）

$argvv = $argv ?? [];
function statx_arg(string $name, ?string $def = null): ?string
{
    global $argvv;
    foreach ($argvv as $a) {
        if ($a === '--' . $name) { return '1'; }
        if (str_starts_with($a, '--' . $name . '=')) { return substr($a, strlen($name) + 3); }
    }
    return $def;
}
function statx_out(string $s): void { fwrite(STDOUT, $s . "\n"); }

/* ★ 2026-09-14：删行路径的「先预览后确认」防护
   —— 教训：曾把老部署里 16 位随机 hex 的 data-site token 误判为测试数据，删掉 361 行真实数据。
   规则：**不加 --yes 只打印「将删 N 行 + 前 10 行样本」，绝不执行删除**；只有显式 --yes 才真删。 */
function statx_cli_want_delete(): bool
{
    return statx_arg('yes') !== null;
}

/** 预览一处将删的行：行数 + 前 $sample 行样本。**零写入**（只 SELECT）。 */
function statx_cli_preview(
    string $label,
    string $countSql, array $countArgs,
    string $sampleSql, array $sampleArgs,
    int $sample = 10
): void {
    $n = (int)statx_sdb_val($countSql, $countArgs);
    statx_out($label);
    statx_out('  → 将删 ' . $n . ' 行');
    if ($n <= 0) { statx_out('  （没有匹配的行，无需删除）'); return; }
    $rows = statx_sdb_all($sampleSql . ' LIMIT ' . max(1, $sample), $sampleArgs);
    statx_out('  前 ' . count($rows) . ' 行样本（★ 逐行确认；site_key 看着像测试数据也**不要**擅自认定）：');
    foreach ($rows as $r) {
        statx_out('    ' . json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

/** 无 --yes 时的统一出口：打印全部预览后退出，**不做任何删除**。 */
function statx_cli_preview_exit(string $taskLabel, string $site = '', int $keepDays = 0, int $keepBotDays = 0): never
{
    statx_out('============================================================');
    statx_out('== ' . $taskLabel . ' · 预览模式（未加 --yes，**不会删除任何行**）==');
    statx_out('============================================================');
    if ($site !== '') {
        statx_cli_preview(
            '[明细 hits] site_key=' . $site,
            'SELECT COUNT(*) FROM ' . STATX_SDB_HITS . ' WHERE site_key=?', [$site],
            'SELECT id, ts, site_key, path, kind, is_bot FROM ' . STATX_SDB_HITS . ' WHERE site_key=? ORDER BY id', [$site]
        );
        statx_cli_preview(
            '[按天汇总 hits_rollup] site_key=' . $site,
            'SELECT COUNT(*) FROM ' . STATX_SDB_ROLLUP . ' WHERE site_key=?', [$site],
            'SELECT * FROM ' . STATX_SDB_ROLLUP . ' WHERE site_key=? ORDER BY d', [$site]
        );
        statx_out('★ 重跑并加 --yes 才会真删：php plugins/statx/cli.php --task=collect-selftest --site=' . $site . ' --purge --yes');
        exit(0);
    }
    $humanCut = date('Y-m-d H:i:s', strtotime('-' . max(1, $keepDays) . ' day'));
    $botCut   = date('Y-m-d H:i:s', strtotime('-' . max(1, $keepBotDays) . ' day'));
    statx_cli_preview(
        '[人类明细] ts < ' . $humanCut . '（keep_days=' . $keepDays . '）',
        'SELECT COUNT(*) FROM ' . STATX_SDB_HITS . ' WHERE is_bot=0 AND ts < ?', [$humanCut],
        'SELECT id, ts, site_key, host, path, kind FROM ' . STATX_SDB_HITS . ' WHERE is_bot=0 AND ts < ? ORDER BY ts', [$humanCut]
    );
    statx_cli_preview(
        '[爬虫明细] ts < ' . $botCut . '（keep_bot_days=' . $keepBotDays . '）',
        'SELECT COUNT(*) FROM ' . STATX_SDB_HITS . ' WHERE is_bot=1 AND ts < ?', [$botCut],
        'SELECT id, ts, site_key, host, path, bot_name FROM ' . STATX_SDB_HITS . ' WHERE is_bot=1 AND ts < ? ORDER BY ts', [$botCut]
    );
    statx_out('[按天汇总 hits_rollup] 永久保留 —— 本任务不会删除它');
    statx_out('★ 重跑并加 --yes 才会真删：php plugins/statx/cli.php --task=collect-prune --keep=' . $keepDays . ' --yes');
    exit(0);
}
$task = (string)(statx_arg('task', 'health') ?? 'health');

/* ------------------------------------------------------------------ */
/* ★ 2026-09-14 环境预检（可移植化）：
    老实现里「缺扩展」的后果是**未捕获的致命错误**或者**静默的假象**（看板显示 0 PV 而不是
    「采集没在跑」）。这里统一给出可执行的结论，并让需要该扩展的任务干净地退出，
    而不是把 PHP fatal 丢进 cron 日志。 */

/** 环境报告：扩展 / 关键函数 / 库落位（后台「系统环境」页也读它） */
function statx_env_report(): array
{
    $ext = [];
    foreach (['pdo_sqlite' => 'SQLite 采集库（**必须**）',
              'pdo_mysql'  => 'YeCha 内容库 / 健康检测表（**必须**）',
              'curl'       => '站点健康探测 / 注入实证（健康检测需要）',
              'mbstring'   => '全站字符串处理（**必须**）',
              'json'       => 'rt.php 与接口（**必须**）',
              'posix'      => '库文件属主自愈（可选）'] as $k => $why) {
        $ext[$k] = ['ok' => extension_loaded($k), 'why' => $why];
    }
    $sqliteVer = '';
    try {
        $sqliteVer = (string)statx_sdb_val('SELECT sqlite_version()');
    } catch (Throwable $e) { $sqliteVer = ''; }
    return [
        'php'         => PHP_VERSION,
        'sapi'        => PHP_SAPI,
        'php_bin'     => PHP_BINARY,
        'ext'         => $ext,
        'sqlite_ver'  => $sqliteVer,
        'sqlite_upsert_ok' => $sqliteVer !== '' && version_compare($sqliteVer, '3.24.0', '>='),
        'sqlite_path' => statx_sdb_path(),
        'sqlite_src'  => statx_sdb_path_source(),
        'plugin_writable' => is_writable(__DIR__),
        'spool'       => statx_collect_spool_dir(),
        'spool_writable'  => statx_sdb_dir_usable(statx_collect_spool_dir() . '/x'),
        'site_root'   => statx_sdb_site_root(),
    ];
}

/** 需要 curl 的任务（健康探测 / 注入实证）：缺了就干净退出，不抛 fatal */
function statx_require_curl(string $task): void
{
    if (extension_loaded('curl')) { return; }
    statx_out('⚠ 任务 ' . $task . ' 需要 PHP curl 扩展，但本机 CLI 没有加载它。');
    statx_out('  → 健康检测/注入实证不可用；采集与汇总（collect*）不受影响。');
    statx_out('  → 装好 curl 后重跑即可：apt install php-curl / 面板里勾选 curl 扩展。');
    exit(2);
}

if ($task === 'ui') {
    /* ★★ r11：【隐藏宿主后台外壳顶部】的选项写入接口（走 statx_opt_set()，不裸 SQL）。
     *   用法：
     *     php plugins/statx/cli.php --task=ui                      # 只查看当前值
     *     php plugins/statx/cli.php --task=ui --hide-host-chrome=1  # 独立统计站：收起内核顶部
     *     php plugins/statx/cli.php --task=ui --hide-host-chrome=0  # 嵌在别人博客后台：恢复默认
     *   ★ 默认 0（关）—— 别人的站点不受任何影响。 */
    $want = statx_arg('hide-host-chrome');
    if ($want !== null) {
        $v = ((int)$want === 1) ? '1' : '0';
        statx_opt_set('ui.hide_host_chrome', $v);
        statx_out('ui.hide_host_chrome = ' . $v . ($v === '1'
            ? '（已开启：/admin/?s=plug_statx* 将收起内核顶部并把浏览器标题换成「统计 · <站点名>」）'
            : '（已关闭：宿主后台顶部原样保留 = 出厂默认）'));
    }
    statx_out('当前 statx.ui.hide_host_chrome = ' . var_export(statx_opt('ui.hide_host_chrome', '0'), true));
    statx_out('适用建议：**独立统计站**建议设 1；把 statx 嵌进别人博客后台时建议保持 0（默认）。');
    exit(0);
}

if (($task === 'env')) {
    $e = statx_env_report();
    statx_out('PHP ' . $e['php'] . ' (' . $e['sapi'] . ') · ' . $e['php_bin']);
    foreach ($e['ext'] as $k => $v) { statx_out(sprintf('  %-10s %-4s %s', $k, $v['ok'] ? 'OK' : 'MISSING', $v['why'])); }
    statx_out('SQLite ' . ($e['sqlite_ver'] !== '' ? $e['sqlite_ver'] : '(打不开库)')
        . ' · UPSERT(≥3.24)=' . ($e['sqlite_upsert_ok'] ? 'OK' : 'NO'));
    statx_out('库: ' . $e['sqlite_path'] . '  (来源: ' . $e['sqlite_src'] . ')');
    statx_out('spool: ' . $e['spool'] . ' writable=' . ($e['spool_writable'] ? 'yes' : 'NO'));
    statx_out('插件目录可写=' . ($e['plugin_writable'] ? 'yes' : 'NO（rt.php 无法生成！chown/chmod 插件目录）'));

    /* ★★ 2026-09-14 事故后新增：公共目录安全自检。
       事故：落库路径命中临时目录兜底时，statx_sdb_own() 对 dirname()（= /tmp）无条件
       chmod 0750 + chown www:www ⇒ 同机 MySQL（tmpdir=/tmp）signal 11 崩溃且起不来，
       4 个站 500，持续 1,077 秒。本段确认「插件没有、也不会把公共目录改权限」。
       只读；发现公共目录权限异常时**只报告，不改动**（改权限是运维的决定）。 */
    $sec = statx_sdb_security_audit();
    statx_out('');
    statx_out('==== 公共目录安全自检（2026-09-14 /tmp 事故防线）====');
    statx_out('  结论: ' . ($sec['ok'] ? 'PASS —— 插件未改动任何公共目录' : '⚠ FAIL —— 见下方 [FAIL] 项'));
    foreach ($sec['checks'] as $c) {
        statx_out(sprintf('  %-7s %-24s %s', $c['ok'] ? '[OK]' : '[FAIL]', $c['id'], $c['detail']));
    }
    if ($sec['warn'] !== '') { statx_out('  ⚠ ' . $sec['warn']); }
    statx_out('  说明: 库只应落在 <站点根>/var/statx/；/tmp 必须是 1777（世界可写 + 粘滞位）。');
    statx_out('  注: 显示「没有到点的目标」时 health 返回 1 是本任务既有约定（本轮无需探测），非本轮故障。');
    exit($sec['ok'] ? 0 : 1);
}

/* ------------------------------------------------------------------ */

if ($task === 'list') {
    $rows = statx_targets();
    statx_out(sprintf('%-4s %-5s %-6s %-6s %-9s %-4s %-6s %-4s %s', 'ID', 'TIER', 'EN', 'INT', 'LAST', 'CODE', 'CLS', 'TLS', 'URL'));
    foreach ($rows as $r) {
        statx_out(sprintf('%-4d %-5s %-6s %-6s %-9s %-4s %-6s %-4s %s',
            (int)$r['id'], (string)$r['tier'], (int)$r['enabled'] === 1 ? 'on' : 'off',
            (int)$r['interval_s'] . 's', (string)($r['last_ts'] ?? '-'), (int)$r['last_code'],
            (string)($r['last_cls'] !== '' ? $r['last_cls'] : 'ok'), (string)($r['tls_days'] ?? '-'),
            (string)$r['url']));
    }
    statx_out('共 ' . count($rows) . ' 个目标');
    exit(0);
}

if ($task === 'migrate') {
    $log = statx_ensure_schema();
    foreach ($log as $l) { statx_out('schema: ' . $l); }
    $s = statx_seed_targets();
    statx_out('seed: added=' . $s['added'] . ' kept=' . $s['kept']);
    $cs = statx_collect_seed_sites();
    statx_out('collect seed: added=' . $cs['added'] . ' kept=' . $cs['kept']);
    $rt = statx_collect_rt_write();
    statx_out('rt.php: written=' . (!empty($rt['_written']) ? 'yes' : 'no') . ' on=' . (int)$rt['on']
        . ' site=' . (string)$rt['site'] . ' origins=' . count((array)$rt['origins']));
    exit(0);
}

/* ==================================================================== */
/* ====================== 能力①：自建统计采集 ========================= */
/* ==================================================================== */

if ($task === 'collect-init') {
    statx_sync_tz();
    // ★ 先定 site_key，再播种：站点登记要靠它认领「本站实例」的站点根目录（否则 gushuwu.com 会被误判为不可写）
    $want = (string)(statx_arg('site', '') ?? '');
    if ($want !== '') {
        statx_opt_set('collect.site_key', $want);
        statx_out('collect.site_key = ' . $want);
    }
    foreach (statx_collect_ensure_schema() as $l) { statx_out('schema: ' . $l); }
    // ★ 批次 1：把老的 MySQL 采集表（站点登记 + 保留窗口内的明细）一次性搬进 SQLite（幂等，空库才搬）
    if (statx_arg('no-import') === null) {
        $im = statx_collect_import_mysql();
        statx_out('import(MySQL→SQLite): sites=' . $im['sites'] . ' hits=' . $im['hits']
            . ($im['skipped'] !== '' ? (' skipped=' . $im['skipped']) : '')
            . ($im['error'] !== '' ? (' error=' . $im['error']) : ''));
    }
    $cs = statx_collect_seed_sites();
    statx_out('sites: added=' . $cs['added'] . ' kept=' . $cs['kept'] . ' tj_token 自动发现=' . (int)($cs['tokens'] ?? 0));
    if (statx_arg('enable') !== null) { statx_opt_set('collect.enabled', '1'); statx_out('collect.enabled = 1'); }
    if (statx_arg('disable') !== null) { statx_opt_set('collect.enabled', '0'); statx_out('collect.enabled = 0'); }
    $sp = (string)(statx_arg('spool', '') ?? '');
    if ($sp !== '') { statx_opt_set('collect.spool_dir', $sp); }
    $rt = statx_collect_rt_write();
    statx_out('rt.php written=' . (!empty($rt['_written']) ? 'yes' : 'no')
        . ' on=' . (int)$rt['on'] . ' site=' . (string)$rt['site']
        . ' path=' . (string)$rt['path'] . ' script=' . (string)$rt['script']
        . ' compat=' . (string)$rt['compat'] . ' jsver=' . (string)$rt['jsver']
        . ' spool=' . (string)$rt['spool'] . ' origins=' . count((array)$rt['origins'])
        . ' tjmap=' . json_encode($rt['tjmap'] ?? [], JSON_UNESCAPED_SLASHES));
    statx_out('origins: ' . implode(' ', (array)$rt['origins']));
    $st = statx_collect_storage();
    $fi = $st['file'];
    statx_out('storage(SQLite): ' . $fi['path'] . ' (来源 ' . (string)($fi['source'] ?? '?') . ')'
        . ' exists=' . ($fi['exists'] ? 'yes' : 'no')
        . ' bytes=' . $fi['bytes'] . ' wal=' . $fi['wal'] . ' mode=' . $fi['mode']
        . ' owner=' . $fi['owner'] . ':' . $fi['group'] . ' outside_webroot=' . ($fi['outside_webroot'] ? 'yes' : 'NO')
        . ' writable=' . ($fi['writable'] ? 'yes' : 'NO')
        . ' user_version=' . (int)($st['stats']['user_version'] ?? -1));
    // ★ 可移植性自检：缺 pdo_sqlite / 插件目录不可写 / spool 不可写 —— 这三种情况
    //   在旧版里都表现为「看板一片 0」，在这里必须明说。
    $env = statx_env_report();
    if (!$env['ext']['pdo_sqlite']['ok']) { statx_out('⚠ 缺 pdo_sqlite 扩展：采集库无法读写（其它功能不受影响）'); }
    if (!$env['plugin_writable']) { statx_out('⚠ 插件目录不可写：rt.php 生成会失败 → chown -R <PHP用户> ' . __DIR__); }
    statx_out('spool: ' . $env['spool'] . ' writable=' . ($env['spool_writable'] ? 'yes' : 'NO'));
    statx_out('--- sites 登记 ---');
    foreach (statx_collect_sites() as $s) {
        statx_out(sprintf('  %-20s en=%d host=%-22s root=%-34s note=%s',
            (string)$s['site_key'], (int)$s['enabled'], (string)$s['host'],
            (string)($s['local_root'] !== '' ? $s['local_root'] : '-'), mb_substr((string)$s['note'], 0, 40)));
    }
    // ★ 装机自检（第一屏就要能看出「装好没有、还差什么」）
    $e2 = statx_env_report();
    statx_out('--- 环境自检 ---');
    foreach ($e2['ext'] as $k => $v) { if (!$v['ok']) { statx_out('  ✗ ' . $k . ' 未安装（' . $v['why'] . '）'); } }
    statx_out('  PHP ' . $e2['php'] . ' · SQLite ' . ($e2['sqlite_ver'] !== '' ? $e2['sqlite_ver'] : '?')
        . ' · 插件目录可写=' . ($e2['plugin_writable'] ? 'yes' : 'NO'));
    if (!$e2['ext']['pdo_sqlite']['ok']) { statx_out('  ✗ pdo_sqlite 缺失 ⇒ 采集库用不了（请装 php-sqlite3）'); }
    if (!$e2['plugin_writable'])        { statx_out('  ✗ 插件目录不可写 ⇒ rt.php 生成失败（chown -R <PHP用户> ' . __DIR__ . '）'); }
    if (!$e2['spool_writable'])         { statx_out('  ✗ spool 目录不可写 ⇒ 访客数据会丢（' . $e2['spool'] . '）'); }
    if ($e2['sqlite_upsert_ok'] === false && $e2['sqlite_ver'] !== '') {
        statx_out('  ✗ SQLite ' . $e2['sqlite_ver'] . ' < 3.24 ⇒ 按天汇总的 UPSERT 语法不支持，请升级 SQLite');
    }
    statx_out('下一行是什么：把后台「采集与站点管理」里给出的那一行 <script> 放进你的模板（stats.foot 或 </head> 前）即可开始收数。');
    exit(0);
}

if ($task === 'collect') {
    $r = statx_collect_flush((int)(statx_arg('max', '200000') ?? 200000));
    statx_out('collect: files=' . $r['files'] . ' rows=' . $r['rows'] . ' bot_rows=' . $r['bot_rows']
        . ' dropped=' . $r['dropped'] . ' batches=' . $r['batches']
        . ($r['error'] !== '' ? (' err=' . $r['error']) : ''));
    exit(0);
}

if ($task === 'collect-rollup') {
    $f = statx_collect_flush((int)(statx_arg('max', '200000') ?? 200000));
    statx_out('collect: files=' . $f['files'] . ' rows=' . $f['rows'] . ' pv=' . $f['pv'] . ' cl=' . $f['cl']
        . ' new=' . $f['newv'] . ' bot_rows=' . $f['bot_rows']
        . ' dropped=' . $f['dropped'] . ' batches=' . $f['batches']
        . ($f['error'] !== '' ? (' err=' . $f['error']) : ''));
    $r = statx_collect_rollup((int)(statx_arg('days', '3') ?? 3));
    statx_out('collect-rollup: groups=' . $r['days'] . ' rows=' . $r['rows']
        . ($r['error'] !== '' ? (' err=' . $r['error']) : ''));
    exit(0);
}
if ($task === 'collect-prune') {
    // ★ 批次 1 新增：采集侧保留清理（按天汇总兜底 → **分批删** → 增量回收）
    //   人类明细 30 天 · 爬虫明细 10 天 · 按天汇总**永久保留**；**绝不跑整库 VACUUM**（会独占锁 + 需 2× 磁盘）
    $keep = (int)(statx_arg('keep', (string)statx_cfg('collect.keep_days')) ?? statx_cfg('collect.keep_days'));
    $batch = (int)(statx_arg('batch', '5000') ?? 5000);
    // ★ 先预览后确认：无 --yes 只打印将删行数与样本，绝不删
    if (!statx_cli_want_delete()) {
        statx_cli_preview_exit('collect-prune', '', $keep, statx_cfg_int('collect.keep_bot_days'));
    }
    $r = statx_collect_prune($keep, $batch);
    statx_out('collect-prune: keep_days=' . $r['keep_days'] . ' keep_bot_days=' . $r['keep_bot_days']
        . ' batch=' . $batch . ' rollup=' . $r['rollup']);
    statx_out('  deleted: human=' . $r['deleted'] . '（' . $r['batches'] . ' 批）'
        . ' bot=' . $r['deleted_bot'] . '（' . $r['bot_batches'] . ' 批） rollup_deleted=' . $r['rollup_deleted']);
    $m = $r['maintain'];
    statx_out('  maintain: incremental_vacuum=' . (int)($m['incremental_vacuum'] ?? 0)
        . ' freelist ' . (int)($m['freelist_before'] ?? 0) . '→' . (int)($m['freelist_after'] ?? 0)
        . ' optimize=' . (string)($m['optimize'] ?? '-'));
    if ($r['error'] !== '') { statx_out('  error: ' . $r['error']); exit(1); }
    exit(0);
}

if ($task === 'collect-fields') {
    // ★ 批次 1 新增：把**本批新采集的字段**原样打出来（验收/排障用：sid/uid/ref/utm/坐标/事件名）
    $limit = (int)(statx_arg('limit', '10') ?? 10);
    $site  = (string)(statx_arg('site', '') ?? '');
    $by    = (string)(statx_arg('by', 'rows') ?? 'rows');

    if ($by === 'uid') {
        statx_out(sprintf('%-18s %-7s %-5s %-6s %-6s %-6s %-19s %-19s', 'VISITOR', 'SRC', 'PV', 'PAGES', 'SESS', 'DAYS', 'FIRST', 'LAST'));
        foreach (statx_collect_visitors((int)(statx_arg('days', '30') ?? 30), $site, $limit) as $r) {
            statx_out(sprintf('%-18s %-7s %-5d %-6d %-6d %-6d %-19s %-19s',
                (string)$r['uk'], (string)$r['src'], (int)$r['pv'], (int)$r['pages'],
                (int)$r['sess'], (int)$r['days'], (string)$r['first_ts'], (string)$r['last_ts']));
        }
        exit(0);
    }
    if ($by === 'depth') {
        statx_out(sprintf('%-8s %s', 'DEPTH', 'SESSIONS'));
        foreach (statx_collect_depth((int)(statx_arg('days', '30') ?? 30), $site) as $r) {
            statx_out(sprintf('%-8d %d', (int)$r['depth'], (int)$r['sessions']));
        }
        exit(0);
    }
    if ($by === 'storage') {
        $st = statx_collect_storage();
        $f = $st['file'];
        statx_out('db=' . $f['path'] . ' exists=' . ($f['exists'] ? 'yes' : 'no') . ' bytes=' . $f['bytes']
            . ' wal=' . $f['wal'] . ' shm=' . $f['shm'] . ' mode=' . $f['mode'] . ' owner=' . $f['owner'] . ':' . $f['group']
            . ' outside_webroot=' . ($f['outside_webroot'] ? 'yes' : 'NO'));
        foreach (($st['stats']['pragmas'] ?? []) as $k => $v) { statx_out('  pragma ' . $k . '=' . $v); }
        foreach (($st['stats']['tables'] ?? []) as $t => $d) {
            statx_out('  table ' . $t . ' rows=' . (int)$d['rows'] . ' bytes=' . (int)($d['bytes'] ?? 0)
                . ' bytes/row=' . (string)($d['bytes_per_row'] ?? '-'));
        }
        foreach (($st['stats']['indexes'] ?? []) as $i => $d) {
            statx_out('  index ' . $i . ' on ' . (string)($d['table'] ?? '-') . ' bytes=' . (int)$d['bytes']);
        }
        exit(0);
    }

    $rows = statx_collect_fields($limit, $site, (string)(statx_arg('kind', '') ?? ''));
    statx_out('rows=' . count($rows) . '（最新 ' . $limit . ' 条；字段：sid/uid/ref/utm/w-h/vw-vh/lang/x-y/en）');
    foreach ($rows as $r) {
        statx_out(sprintf("id=%d %s [%s] %s %s kind=%s sid=%s uid=%s new=%d vh=%s scr=%dx%d vp=%dx%d lang=%s x=%d y=%d en=%s",
            (int)$r['id'], (string)$r['ts'], (string)$r['site_key'], (string)$r['host'], (string)$r['path'],
            (string)$r['kind'], (string)$r['sid'] !== '' ? (string)$r['sid'] : '-',
            (string)$r['uid'] !== '' ? (string)$r['uid'] : '-', (int)$r['is_new'],
            (string)$r['visitor_hash'], (int)$r['width'], (int)$r['height'], (int)$r['vw'], (int)$r['vh'],
            (string)$r['lang'] !== '' ? (string)$r['lang'] : '-', (int)$r['x'], (int)$r['y'],
            (string)$r['en'] !== '' ? (string)$r['en'] : '-'));
        $extra = [];
        if ((string)$r['ref'] !== '') { $extra[] = 'ref=' . mb_substr((string)$r['ref'], 0, 80) . ' (' . (string)$r['ref_domain'] . '/' . (string)$r['ref_kind'] . ')'; }
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $c) {
            if ((string)$r[$c] !== '') { $extra[] = $c . '=' . (string)$r[$c]; }
        }
        if ($extra) { statx_out('    ' . implode(' · ', $extra)); }
    }
    exit(0);
}

if ($task === 'collect-selftest') {
    // ★ 批次 1 新增：用**测试 site_key** 造数据，验证「明细 → 按天汇总 → 分批删」全链路（不污染真实站点）
    $site  = (string)(statx_arg('site', 'selftest.invalid') ?? 'selftest.invalid');
    $n     = max(1, (int)(statx_arg('n', '12000') ?? 12000));
    $batch = max(1, (int)(statx_arg('batch', '5000') ?? 5000));
    $purge = statx_arg('purge') !== null;

    if ($purge && !statx_cli_want_delete()) {
        statx_cli_preview_exit('collect-selftest --purge', $site);
    }
    if ($purge) {
        $d1 = statx_sdb_run('DELETE FROM ' . STATX_SDB_HITS . ' WHERE site_key=?', [$site]);
        $d2 = statx_sdb_run('DELETE FROM ' . STATX_SDB_ROLLUP . ' WHERE site_key=?', [$site]);
        $d3 = statx_sdb_run('DELETE FROM ' . STATX_SDB_SITES . ' WHERE site_key=?', [$site]);
        statx_out('collect-selftest --purge: hits=' . $d1 . ' rollup=' . $d2 . ' sites=' . $d3 . '（site=' . $site . '）');
        statx_sdb_maintain();
        exit(0);
    }

    // 登记测试站点（enabled=1 才能被 flush 收下；host 用 .invalid 保证永不与真实站冲突）
    statx_collect_site_save($site, ['label' => '批次1 自测（可删）', 'host' => $site, 'enabled' => 1, 'sort' => 999,
        'note' => 'collect-selftest 造的测试数据，验证完请 --purge']);

    $pdo = statx_sdb();
    $COLS = ['ts', 'd', 'site_key', 'host', 'path', 'kind', 'is_landing', 'ref', 'ref_domain', 'ref_kind',
             'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'sid', 'uid', 'is_new',
             'visitor_hash', 'width', 'height', 'vw', 'vh', 'lang', 'x', 'y', 'en', 'device', 'is_bot', 'bot_cls', 'bot_name'];
    $st = $pdo->prepare('INSERT INTO ' . STATX_SDB_HITS . ' (' . implode(',', $COLS) . ') VALUES ('
        . implode(',', array_fill(0, count($COLS), '?')) . ')');

    // 造 n 行：3 天内 · 30% 爬虫 · 20% 点击 · 每人 1~4 页（sid/uid 可聚合）
    $made = 0;
    $pdo->beginTransaction();
    for ($i = 0; $i < $n; $i++) {
        $ts  = time() - ($i % 3) * 86400 - ($i % 3600);
        $kind = ($i % 5 === 0) ? 'cl' : 'pv';
        $bot = ($i % 10 < 3) ? 1 : 0;
        $uid = 'u' . str_pad((string)($i % 900), 5, '0', STR_PAD_LEFT);
        $sid = 's' . str_pad((string)($i % 3000), 5, '0', STR_PAD_LEFT);
        $st->execute([
            date('Y-m-d H:i:s', $ts), date('Y-m-d', $ts), $site, $site,
            '/selftest/' . ($i % 40) . '.html', $kind, ($i % 2), 'https://ref.example/a?b=1', 'ref.example',
            ($i % 2) ? 'out' : 'none', '', '', '', '', '', $sid, $uid, ($i % 7 === 0) ? 1 : 0,
            substr(hash('sha256', 'selftest|' . $uid), 0, 16),
            1920, 1080, 1280, 720, 'zh-CN',
            ($kind === 'cl') ? (100 + $i % 800) : 0, ($kind === 'cl') ? (200 + $i % 600) : 0,
            ($kind === 'cl') ? 'selftest-click' : '', 'desktop', $bot, $bot ? 'ai' : 'human', $bot ? 'gptbot' : '',
        ]);
        $made++;
        if ($made % $batch === 0) { $pdo->commit(); $pdo->beginTransaction(); }
    }
    $pdo->commit();
    statx_out('collect-selftest: 造数据 ' . $made . ' 行（site=' . $site . '，3 天分布，30% 爬虫 / 20% 点击）');

    $before = ['human' => (int)statx_sdb_val('SELECT COUNT(*) FROM ' . STATX_SDB_HITS . ' WHERE site_key=? AND is_bot=0', [$site]),
               'bot'   => (int)statx_sdb_val('SELECT COUNT(*) FROM ' . STATX_SDB_HITS . ' WHERE site_key=? AND is_bot=1', [$site]),
               'cl'    => (int)statx_sdb_val('SELECT COUNT(*) FROM ' . STATX_SDB_HITS . ' WHERE site_key=? AND kind=?', [$site, 'cl'])];
    statx_out('  造完：human=' . $before['human'] . ' bot=' . $before['bot'] . ' click=' . $before['cl']);

    $r = statx_collect_rollup(400);
    statx_out('  rollup: days=' . $r['days'] . ' rows=' . $r['rows']
        . ($r['error'] !== '' ? (' error=' . $r['error']) : ''));
    $rl = statx_sdb_one('SELECT * FROM ' . STATX_SDB_ROLLUP . ' WHERE site_key=? ORDER BY d', [$site]) ?? [];
    statx_out('  rollup 行：pv=' . (int)($rl['pv'] ?? 0) . ' uv=' . (int)($rl['uv'] ?? 0)
        . ' sess=' . (int)($rl['sess'] ?? 0) . ' newv=' . (int)($rl['newv'] ?? 0)
        . ' landing=' . (int)($rl['landing'] ?? 0) . ' ref_ext=' . (int)($rl['ref_ext'] ?? 0)
        . ' bot_hits=' . (int)($rl['bot_hits'] ?? 0));

    // 保留清理：keep=1 天（只留今天）⇒ 应把前两天的删掉，且**分批**
    $keep = max(1, (int)(statx_arg('keep', '1') ?? 1));
    $p = statx_collect_prune($keep, $batch);
    statx_out('  prune（keep=' . $keep . ' batch=' . $batch . '）：human 删 ' . $p['deleted'] . '（' . $p['batches'] . ' 批）'
        . ' · bot 删 ' . $p['deleted_bot'] . '（' . $p['bot_batches'] . ' 批）· rollup 删 ' . $p['rollup_deleted']);
    $after = (int)statx_sdb_val('SELECT COUNT(*) FROM ' . STATX_SDB_HITS . ' WHERE site_key=?', [$site]);
    statx_out('  剩余明细行=' . $after . '（应等于「今天」那一份）');
    statx_out('  提醒：确认无误后跑 --task=collect-selftest --site=' . $site . ' --purge 清干净');
    exit(0);
}

if ($task === 'links-health') {
    // ★ 批次 1 第 ④ 项：**只读**复用 yecha.org 既有的 links 体检结果（本任务**零出站请求、零写入**）
    $site = (string)(statx_arg('site', 'yecha.org') ?? 'yecha.org');
    $h = statx_links_health($site, ['sort_by_idle' => statx_arg('by') !== null]);
    if (!$h['ok']) { statx_out('links-health FAIL: ' . $h['error']); exit(1); }
    $s = $h['summary'];
    statx_out('links-health site=' . $h['site'] . ' 体检时间=' . ($h['probed_at'] !== '' ? $h['probed_at'] : '-')
        . ' stale 阈值=' . $h['stale_days'] . ' 天');
    statx_out('  来源：' . $h['probe_source']);
    statx_out('  合计=' . $s['total'] . ' ok=' . $s['ok'] . ' stale(可达但长期停更)=' . $s['stale']
        . ' err=' . $s['err'] . ' down=' . $s['down'] . ' blocked(被 WAF/限频拦，不算死链)=' . $s['blocked']
        . ' unknown=' . $s['unknown'] . ' 最长停更=' . (int)($s['max_idle_days'] ?? 0) . ' 天');
    statx_out(sprintf('  %-4s %-8s %-11s %-6s %-19s %s', 'ID', 'STATE', 'IDLE(天)', 'HTTP', 'LAST_POST', 'URL'));
    foreach ($h['rows'] as $r) {
        statx_out(sprintf('  %-4d %-8s %-11d %-6s %-19s %s',
            (int)$r['id'], (string)$r['state'], (int)$r['idle_days'], (string)($r['probe_state'] !== '' ? $r['probe_state'] : '-'),
            (string)($r['last_post_at'] ?? '-'), (string)$r['url']));
    }
    statx_out('  ★ 本任务不做任何探测：探针是 yecha.org 既有的 tools/links-probe.php（cron 每 6h）；下一批只做“扩目标类别 + 后台区块”。');
    exit(0);
}

if ($task === 'sdb') {
    // ★ 批次 1 新增：SQLite 存储层一览（路径/权限/PRAGMA/表·索引字节）—— 与 collect-fields --by=storage 同源
    $st = statx_collect_storage();
    $f = $st['file'];
    statx_out('sqlite: ' . $f['path']);
    statx_out('  exists=' . ($f['exists'] ? 'yes' : 'no') . ' bytes=' . $f['bytes'] . ' wal=' . $f['wal'] . ' shm=' . $f['shm']
        . ' mode=' . $f['mode'] . ' owner=' . $f['owner'] . ':' . $f['group'] . ' perm_ok=' . ($f['perm_ok'] ? 'yes' : 'no')
        . ' outside_webroot=' . ($f['outside_webroot'] ? 'yes' : 'NO'));
    statx_out('  schema user_version=' . (int)($st['stats']['user_version'] ?? -1) . ' dbstat=' . (($st['stats']['dbstat'] ?? false) ? 'yes' : 'no')
        . ' page_size=' . (int)($st['stats']['page_size'] ?? 0) . ' pages=' . (int)($st['stats']['page_count'] ?? 0)
        . ' freelist=' . (int)($st['stats']['freelist'] ?? 0));
    foreach (($st['stats']['pragmas'] ?? []) as $k => $v) { statx_out('  pragma ' . $k . '=' . $v); }
    foreach (($st['stats']['tables'] ?? []) as $t => $d) {
        statx_out('  table ' . $t . ' rows=' . (int)$d['rows'] . ' bytes=' . (int)($d['bytes'] ?? 0)
            . ' bytes/row=' . (string)($d['bytes_per_row'] ?? '-'));
    }
    foreach (($st['stats']['indexes'] ?? []) as $i => $d) {
        statx_out('  index ' . $i . ' on ' . (string)($d['table'] ?? '-') . ' bytes=' . (int)$d['bytes']);
    }
    statx_out('  native DELETE…LIMIT 支持：' . (statx_sdb_native_delete_limit() ? 'yes' : 'no')
        . '（本层统一用 rowid IN (SELECT … LIMIT n) 的等价可移植写法）');
    exit(0);
}
if ($task === 'collect-status') {
    statx_sync_tz();
    $rt = statx_collect_rt_read();
    $sp = statx_collect_spool_stat();
    statx_out('collect.enabled=' . statx_cfg('collect.enabled')
        . ' rt.php=' . (is_file(statx_collect_rt_path()) ? 'yes' : 'NO')
        . ' rt.on=' . (int)($rt['on'] ?? 0) . ' site=' . (string)($rt['site'] ?? '-')
        . ' mode=' . statx_cfg('collect.inject_mode') . ' pub=' . statx_collect_pub_origin());
    statx_out('spool: dir=' . $sp['dir'] . ' exists=' . ($sp['exists'] ? 'yes' : 'no')
        . ' files=' . $sp['files'] . ' lines=' . $sp['lines'] . ' bytes=' . $sp['bytes']);
    // ★ 批次 1：存储层一览（SQLite：位置/权限/大小/行数/单行字节）
    $st = statx_collect_storage();
    $fi = $st['file'];
    statx_out('storage: ' . $fi['path'] . ' exists=' . ($fi['exists'] ? 'yes' : 'no')
        . ' bytes=' . $fi['bytes'] . ' wal=' . $fi['wal'] . ' shm=' . $fi['shm']
        . ' mode=' . $fi['mode'] . ' owner=' . $fi['owner'] . ':' . $fi['group']
        . ' perm_ok=' . ($fi['perm_ok'] ? 'yes' : 'no') . ' outside_webroot=' . ($fi['outside_webroot'] ? 'yes' : 'NO')
        . ' user_version=' . (int)($st['stats']['user_version'] ?? -1)
        . ($st['error'] !== '' ? (' ERR=' . $st['error']) : ''));
    foreach (($st['stats']['tables'] ?? []) as $t => $d) {
        statx_out('  table ' . $t . ': rows=' . (int)$d['rows'] . ' bytes=' . (int)($d['bytes'] ?? 0)
            . ' bytes/row=' . (string)($d['bytes_per_row'] ?? '-'));
    }
    $k = statx_collect_kpi();
    statx_out('kpi: today pv=' . $k['today']['pv'] . ' uv=' . $k['today']['uv'] . ' bot=' . $k['today']['bot']
        . ' | 7d pv=' . $k['d7']['pv'] . ' uv=' . $k['d7']['uv'] . ' bot=' . $k['d7']['bot']
        . ' | 30d pv=' . $k['d30']['pv'] . ' uv=' . $k['d30']['uv']
        . ' | hits_total=' . $k['hits_total'] . ' clicks=' . ($k['clicks'] ?? 0)
        . ' last_hit=' . ($k['last_hit'] !== '' ? $k['last_hit'] : '-')
        . ($k['error'] !== '' ? (' ERR=' . $k['error']) : ''));
    statx_out(sprintf('%-22s %-6s %-22s %-8s %-6s %-6s %-9s %s', 'SITE', 'EN', 'HOST', 'INJECT', 'VERIFY', 'PV', 'LASTHIT', 'NOTE'));
    foreach (statx_collect_site_rows(1) as $r) {
        statx_out(sprintf('%-22s %-6s %-22s %-8s %-6s %-6s %-9s %s',
            (string)$r['site_key'], (int)$r['enabled'] === 1 ? 'on' : 'off', (string)$r['host'],
            (string)($r['inject_where'] !== '' ? $r['inject_where'] : '-'),
            (int)$r['verify_ok'] === 1 ? 'ok' : 'no', (string)(int)$r['pv'],
            (string)($r['last_hit_at'] ?? '-'), mb_substr((string)$r['verify_note'], 0, 60)));
    }
    exit(0);
}

if ($task === 'collect-inject' || $task === 'collect-remove') {
    $key = (string)(statx_arg('site', '') ?? '');
    if ($key === '') { statx_out('需要 --site=<site_key>'); exit(2); }
    $opt = [];
    if (($w = (string)(statx_arg('where', '') ?? '')) !== '') { $opt['where'] = $w; }
    if (($m = (string)(statx_arg('mode', '') ?? '')) !== '') { $opt['mode'] = $m; }
    if (($o = (string)(statx_arg('origin', '') ?? '')) !== '') { $opt['origin'] = $o; }
    if (($u = (string)(statx_arg('url', '') ?? '')) !== '') { $opt['url'] = $u; }
    if (($rs = (string)(statx_arg('resolve', '') ?? '')) !== '') { $opt['resolve'] = $rs; $opt['insecure'] = true; }

    if ($task === 'collect-remove') {
        $r = statx_collect_remove($key, $opt);
        statx_out('collect-remove: ' . json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        exit($r['ok'] ? 0 : 1);
    }
    $r = statx_collect_inject($key, $opt);
    statx_out('collect-inject: ok=' . ($r['ok'] ? 'yes' : 'no'));
    if (!empty($r['error'])) { statx_out('  error: ' . (string)$r['error']); }
    if (!empty($r['detail'])) { statx_out('  detail: ' . json_encode($r['detail'], JSON_UNESCAPED_UNICODE)); }
    foreach ((array)($r['log'] ?? []) as $l) { statx_out('  ' . $l); }
    if (!empty($r['tag'])) { statx_out('  where=' . (string)$r['where'] . '  tag=' . str_replace("\n", ' ', (string)$r['tag'])); }
    if (!empty($r['verify'])) {
        $v = $r['verify'];
        statx_out('  verify: code=' . (int)$v['code'] . ' bytes=' . (int)$v['bytes']
            . ' src_in_html=' . ((int)$v['src']) . ' data-site_in_html=' . ((int)$v['site'])
            . ' ttfb=' . (int)$v['ttfb'] . 'ms · ' . (string)$v['note']);
    }
    exit($r['ok'] ? 0 : 1);
}

if ($task === 'collect-endpoint') {
    // ★★ 根因级预检（本地判定，不发请求）：先看内核到底有没有把 boot.php 载进来
    $pre = statx_cli_boot_precheck();
    statx_out('--- 内核引导预检（本插件能不能被 Plugins::boot() 载入）---');
    foreach ($pre['calls'] as $c) {
        statx_out(($c[1] ? '  ✔ ' : '  ✘ ') . $c[0] . '  ⇒  ' . $c[2]);
    }
    if (($pre['fatal'] ?? '') !== '') {
        statx_out('--- ★ 已定位根因：boot.php 未被载入（原因：' . $pre['fatal'] . '），端点不可能通 ---');
        foreach ($pre['advice'] as $line) { statx_out('  ' . $line); }
        statx_out('  ★ 说明：这种情况下 /st.js 与 /st 会 302/404，且若该站开了 seo.404_home=1，'
            . '会被进一步掩盖成「302 回首页」——不要先去查 nginx。');
        exit(1);
    }
    $rt = statx_collect_rt_read();
    $origin = (string)(statx_arg('origin', statx_collect_pub_origin()) ?? statx_collect_pub_origin());
    $opt = [];
    if (($rs = (string)(statx_arg('resolve', '') ?? '')) !== '') { $opt['resolve'] = $rs; $opt['insecure'] = true; }
    $js = (string)(statx_arg('js', $origin . (string)($rt['script'] ?? '/st.js')) ?? '');
    $ep = (string)(statx_arg('url', $origin . (string)($rt['path'] ?? '/st')) ?? '');
    $a = statx_collect_probe_script($js, $opt);
    statx_out('script: code=' . $a['code'] . ' ct=' . $a['ct'] . ' bytes=' . $a['bytes']
        . ' total=' . $a['total'] . 'ms cache=' . ($a['cache'] !== '' ? $a['cache'] : '-')
        . ' ok=' . ($a['ok'] ? 'yes' : 'no') . ($a['err'] !== '' ? (' err=' . $a['err']) : ''));
    $b = statx_collect_probe_endpoint($ep, $opt + ['site' => (string)($rt['site'] ?? 'probe')]);
    statx_out('endpoint: code=' . $b['code'] . ' X-Statx=' . ($b['marker'] !== '' ? $b['marker'] : '-')
        . ' bytes=' . $b['bytes'] . ' total=' . $b['total'] . 'ms'
        . ($b['err'] !== '' ? (' err=' . $b['err']) : ''));
    // 预检（CORS）★ 2026-09-14：origin 用**本站自己**（老实现写死 https://gushuwu.com，
    //   别的用户跑这一步会必然拿到空 ACAO，从而误判成 CORS 坏了）
    $probeOrigin = $origin;
    if ($probeOrigin === '' && !empty($rt['origins']) && is_array($rt['origins'])) { $probeOrigin = (string)$rt['origins'][0]; }
    $c = statx_collect_http($ep, $opt + ['method' => 'OPTIONS', 'origin' => $probeOrigin]);
    statx_out('preflight: origin=' . ($probeOrigin !== '' ? $probeOrigin : '(无)')
        . ' code=' . $c['code'] . ' ACAO=' . (string)($c['headers']['access-control-allow-origin'] ?? '-')
        . ' ACAM=' . (string)($c['headers']['access-control-allow-methods'] ?? '-'));

    // ★★ 2026-09-14 新增「结论 + 怎么办」：这一条是**装机时最容易踩、又最难自诊断**的失败模式。
    //    实测（同机新站，宝塔默认 vhost）：/st.js 返回 nginx 的 404 或内核的 302 → 插件**根本没被调用**，
    //    而看板上只会显示「0 PV」，看不出是没插装、没 cron，还是端点没通。
    //    判定依据（不需要看 nginx 配置）：
    //      · 脚本可取到，且带 X-Statx 头  → 插件接管了请求，端点通
    //      · 脚本 404                     → nginx 把 .js 当静态文件抢走了（宝塔默认 location ~ .*\.(js|css)?$ 会）
    //      · 脚本 200/302 但**没有** X-Statx → 请求进了内核路由，没进插件（register_file 没生效或被重定向）
    $scriptOk = ($a['code'] === 200 && $a['bytes'] > 200 && str_contains($a['ct'], 'javascript'));
    $marker   = (string)($a['headers']['x-statx'] ?? '');
    // ★ 判定口径（实测校准）：能被判成「端点已通」的**充分条件**是
    //   「200 + application/javascript + 体积合理」——这一条已经证明请求被插件接住了，
    //   因为内核/nginx 都不会返回这个组合。X-Statx 头只是额外证据（某些 CDN/缓存会剥掉它）。
    $looksLikeHtml = ($a['bytes'] > 20000) || str_contains($a['ct'], 'html');
    statx_out('--- 结论 ---');
    if ($scriptOk && !$looksLikeHtml) {
        statx_out('✔ 端点已通：' . (string)($rt['script'] ?? '/st.js') . ' 返回了插件自己的脚本'
            . '（' . (int)$a['bytes'] . 'B ' . $a['ct'] . ($marker !== '' ? '，X-Statx=' . $marker : '') . '）');
        if ($b['code'] !== 200) {
            statx_out('   注意：上报端点 ' . (string)($rt['path'] ?? '/st') . ' 返回 ' . (int)$b['code']
                . '，请再跑一次本命令确认不是偶发。');
        }
    } elseif ($looksLikeHtml) {
        statx_out('✘ 端点**没有**被插件接管：请求拿到了 HTML（' . (int)$a['bytes'] . 'B ' . $a['ct'] . '），');
        statx_out('   说明它进了内核路由，没进插件的 register_file（register_file 本应在 Router 之前拦下）。');
        statx_out('   排查顺序：① 后台「插件」页 statx = 启用（本命令已确认 rt.on=' . (int)($rt['on'] ?? 0) . '）；');
        statx_out('   ② 内核 ≥ 1.0.0-beta.2（需 Plugins::boot() 在 Router 之前载入 register_file）；');
        statx_out('   ③ nginx 是否把 .js 当静态文件抢走了（见下面 404 分支的处理）；');
        statx_out('   ④ 该站是否开了「未知路由跳首页」seo.404_home=1（会把 302 打到首页，掩盖真实原因）；');
        statx_out('   ⑤ 先用兜底路径确认插件本身：' . $origin . '/index.php?st=js');
    } elseif ((int)$a['code'] === 404) {
        statx_out('✘ 端点 404：nginx 很可能把 .js 当静态文件抢走了（宝塔默认 vhost 的 `location ~ .*\.(js|css)?$` 会）。');
        statx_out('   加两条精确匹配 location 到该站 server{} 里，然后 nginx -t && nginx -s reload：');
        statx_out('     location = ' . (string)($rt['script'] ?? '/st.js') . ' { try_files $uri /index.php?$query_string; }');
        statx_out('     location = ' . (string)($rt['compat'] ?? '/s3.js') . ' { try_files $uri /index.php?$query_string; }');
    } else {
        statx_out('✘ 端点异常：HTTP ' . (int)$a['code'] . '（' . ($a['err'] !== '' ? $a['err'] : '无错误详情') . '）');
        statx_out('   若状态码是 301/302 且 Location 指回首页，多半是该站开了「未知路由跳首页」(seo.404_home=1)，');
        statx_out('   也就是请求根本没到插件；按上面的 nginx 两条 location 处理。');
        statx_out('   兜底验证插件本身：' . $origin . '/index.php?st=js');
    }
    exit(($a['ok'] && $b['code'] === 200) ? 0 : 1);
}

if ($task === 'collect-test') {
    // 对照组：真实 Chrome UA 必须计入；裸 UA / 爬虫 UA / 空 UA 必须**不计入人类 PV**
    $key = (string)(statx_arg('site', statx_collect_site_key()) ?? '');
    $rt  = statx_collect_rt_read();
    $ep  = (string)(statx_arg('url', statx_collect_pub_origin() . (string)($rt['path'] ?? '/st')) ?? '');
    $opt = [];
    if (($rs = (string)(statx_arg('resolve', '') ?? '')) !== '') { $opt['resolve'] = $rs; $opt['insecure'] = true; }
    $path = (string)(statx_arg('path', '/__statx_selftest__') ?? '/__statx_selftest__');
    $cases = [
        'chrome'   => statx_ua(),
        'curl'     => 'curl/8.5.0',
        'requests' => 'python-requests/2.32.3',
        'gptbot'   => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot',
        'empty'    => '',
    ];
    foreach ($cases as $lbl => $ua) {
        $url = $ep . (str_contains($ep, '?') ? '&' : '?') . 's=' . rawurlencode($key)
             . '&h=' . rawurlencode($key) . '&p=' . rawurlencode($path) . '&r=';
        $r = statx_collect_http($url, $opt + ['ua' => $ua]);
        statx_out(sprintf('%-9s UA=%-28s code=%d X-Statx=%-14s bytes=%d total=%dms',
            $lbl, mb_substr($ua === '' ? '(空)' : $ua, 0, 28), $r['code'],
            (string)($r['headers']['x-statx'] ?? '-'), strlen($r['body']), $r['total']));
        usleep(700000);   // 红线：请求间隔 ≥0.7s
    }
    statx_out('—— 现在跑：php plugins/statx/cli.php --task=collect-rollup，然后 --task=collect-fields 看落库行');
    exit(0);
}

if ($task === 'collect-backup') {
    $to = (string)(statx_arg('to', (string)statx_cfg('collect.backup_to')) ?? '');
    $r = statx_collect_backup((int)(statx_arg('days', '7') ?? 7), $to);
    statx_out('collect-backup: ok=' . ($r['ok'] ? 'yes' : 'no') . ' to=' . (string)$r['to']
        . ' rows=' . (int)$r['rows'] . ' bytes=' . (int)$r['bytes']
        . (!empty($r['error']) ? (' err=' . (string)$r['error']) : ''));
    exit($r['ok'] ? 0 : 1);
}

if ($task === 'mailimport') {
    // 从 stdin 读 JSON（管道传入，不落盘、不打印）。字段：host/port/ssl/user/from/pass/to
    $raw = stream_get_contents(STDIN);
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) { fwrite(STDERR, "mailimport: stdin 不是合法 JSON\n"); exit(2); }
    $map = ['host' => 'mail.host', 'port' => 'mail.port', 'ssl' => 'mail.ssl', 'user' => 'mail.user', 'from' => 'mail.from'];
    foreach ($map as $k => $opt) {
        if (isset($j[$k]) && (string)$j[$k] !== '') { statx_opt_set($opt, (string)$j[$k]); }
    }
    if (!empty($j['to'])) { statx_opt_set('notify_to', (string)$j['to']); }
    $passLen = 0;
    if (!empty($j['pass'])) {
        if (function_exists('yc_opt_set')) { yc_opt_set('statx.mail.pass', (string)$j['pass']); $passLen = strlen((string)$j['pass']); }
        else { fwrite(STDERR, "mailimport: 缺少 yc_opt_set()\n"); exit(2); }
    }
    $c = statx_mail_conf();
    statx_out('mailimport ok: host=' . $c['host'] . ':' . (int)$c['port'] . ' ssl=' . $c['ssl']
        . ' user=' . ($c['user'] !== '' ? '(set)' : '(empty)')
        . ' from=' . $c['from'] . ' pass=' . ($passLen > 0 ? '(imported, len=' . $passLen . ', 已用本站 SECRET 加密)' : '(未提供)')
        . ' notify_to=' . statx_notify_to());
    exit(0);
}

if ($task === 'mailtest') {
    $to = (string)(statx_arg('to', statx_notify_to()) ?? '');
    $c  = statx_mail_conf();
    // --smtp=host:port[:ssl] —— 仅本次调用生效的临时覆盖（不落库），用于本机 SMTP 接收器做链路自检
    $ov = statx_arg('smtp', '');
    if ($ov !== null && $ov !== '' && $ov !== '1') {
        $p = explode(':', (string)$ov);
        $c['host'] = $p[0];
        $c['port'] = (int)($p[1] ?? 25);
        $c['ssl']  = (string)($p[2] ?? '0');
        if (($c['from'] ?? '') === '') { $c['from'] = 'statx@localhost'; }
        $c['src'] = 'cli-override(临时，不落库)';
    }
    if (!statx_mail_ready($c)) { statx_out('FAIL: SMTP 未配置（' . $c['host'] . ' / ' . $c['from'] . '）'); exit(2); }
    if ($to === '') { statx_out('FAIL: 未指定收件人'); exit(2); }
    $r = statx_mail_send($to, '[站点健康] 邮件通道自检', "这是 StatX 站点健康检测的邮件通道自检。\n"
        . '时间：' . date('Y-m-d H:i:s') . "\n配置来源：" . $c['src'] . ' / ' . $c['host'] . ':' . (int)$c['port']
        . ' (ssl=' . $c['ssl'] . ")\n收到即说明告警链路可用。\n", $c);
    statx_out($r === true ? ('OK: 已发送给 ' . $to) : ('FAIL: ' . (string)$r));
    exit($r === true ? 0 : 1);
}

if ($task === 'rollup') {
    $r = statx_rollup((int)(statx_arg('days', '3') ?? 3));
    $c = $r['collect'] ?? ['days' => 0, 'rows' => 0, 'bot_rows' => 0];
    statx_out('rollup: groups=' . $r['days'] . ' rows=' . $r['rows']
        . ' · collect: groups=' . $c['days'] . ' rows=' . $c['rows'] . ' bot_rows=' . $c['bot_rows']);
    exit(0);
}

if ($task === 'prune') {
    // ★ 先预览后确认（能力② 走 MySQL 表）。无 --yes 时打印各表将删行数后退出，不删。
    if (!statx_cli_want_delete()) { statx_prune_preview(); }
    $r = statx_prune();
    $c = $r['collect'] ?? ['deleted' => 0, 'batches' => 0, 'daily_deleted' => 0, 'bots_deleted' => 0, 'rollup' => 'skip'];
    statx_out('prune: deleted=' . $r['deleted'] . ' batches=' . $r['batches']
        . ' daily_deleted=' . $r['daily_deleted'] . ' rollup=' . ($r['rollup'] ?? 'skip')
        . ' · collect: deleted=' . $c['deleted'] . ' batches=' . $c['batches']
        . ' daily_deleted=' . $c['daily_deleted'] . ' bots_deleted=' . $c['bots_deleted']
        . ' rollup=' . ($c['rollup'] ?? 'skip'));
    exit(0);
}

if ($task === 'notify') {
    $ids = statx_pending_notifications();
    $r = statx_notify($ids);
    statx_out('notify: pending=' . count($ids) . ' sent=' . $r['sent'] . ' failed=' . $r['failed']
        . ' skipped=' . $r['skipped'] . ($r['error'] !== '' ? (' err=' . $r['error']) : ''));
    exit($r['failed'] > 0 ? 1 : 0);
}

/* ------------------------- 对照组：裸 UA ------------------------- */
if ($task === 'selftest') {
    // 用「非浏览器 UA」打自己的站 → 应当被自家 guardweb 拦 → 必须报「探针配置故障」
    $id = (int)(statx_arg('target', '0') ?? 0);
    $t = statx_target($id);
    if ($t === null) { statx_out('selftest: 目标不存在'); exit(2); }
    // 默认用 curl/8.5.0 —— 已实测会被 guardweb 的 is_scraper() 命中（python-requests / curl/ / wget / go-http-client 等）
    $ua = (string)(statx_arg('ua', 'curl/8.5.0') ?? 'curl/8.5.0');
    foreach ([['bare', $ua], ['browser', statx_ua()]] as [$lbl, $u]) {
        $r = statx_probe($t, ['ua' => $u]);
        statx_out(sprintf('[%s] UA=%s', $lbl, mb_substr($u, 0, 60)));
        statx_out(sprintf('    code=%d cls=%s label=%s is_fail=%d is_probe=%d size=%d',
            (int)$r['http_code'], $r['err_class'] !== '' ? $r['err_class'] : 'ok', $r['err_label'],
            (int)$r['is_fail'], (int)$r['is_probe'], (int)$r['size_bytes']));
        statx_out('    chain: ' . $r['hops']);
    }
    exit(0);
}

/* ---------------------- 对照组：429 连续打 ---------------------- */
if ($task === 'rltest') {
    $id = (int)(statx_arg('target', '0') ?? 0);
    $n  = max(1, min(300, (int)(statx_arg('n', '40') ?? 40)));
    $t = statx_target($id);
    if ($t === null) { statx_out('rltest: 目标不存在'); exit(2); }

    // ⚠️ 必须并发 2 打才能超过被监控站的 limit_req（单连接串行速率低于 6r/s，永远打不出 429）
    //    仍然严守红线：并发 ≤2、只 GET、只打目标自身的 URL
    $jobs = [];
    for ($i = 0; $i < $n; $i++) {
        $url = (string)$t['url'];
        if ((int)$t['buster'] === 1) { $url = statx_bust($url); }
        $jobs['r' . $i] = [
            'url' => $url, 'ua' => statx_ua(),
            'timeout' => max(3, (int)$t['timeout_s']), 'connect' => 5,
            'follow' => (int)$t['follow'], 'max_redirects' => max(1, (int)$t['max_redirects']),
        ];
    }
    $chains = statx_chain_many($jobs, STATX_MAX_CONCURRENCY);

    $tally = []; $rec = []; $first429 = null;
    foreach ($jobs as $k => $j) {
        $r = statx_classify($t, $chains[$k] ?? [], []);
        $rec[] = $r;
        $cls = $r['err_class'] !== '' ? $r['err_class'] : 'ok';
        $tally[$cls] = ($tally[$cls] ?? 0) + 1;
        if ($first429 === null && (int)$r['is_429'] === 1) { $first429 = count($rec); }
    }
    statx_out('rltest: 目标 #' . $id . ' ' . $t['url'] . ' × ' . $n . ' 次（并发 ' . STATX_MAX_CONCURRENCY . '）');
    foreach ($tally as $k => $v) {
        statx_out(sprintf('  %-16s %d 次（%s）', $k, $v, statx_err_label($k === 'ok' ? '' : $k)));
    }
    statx_out('  首次 429 出现在第 ' . ($first429 ?? '-') . ' 次'
        . ($first429 !== null ? ('；该条 is_429=' . (int)$rec[$first429 - 1]['is_429'] . ' is_fail=' . (int)$rec[$first429 - 1]['is_fail']) : ''));

    if (statx_arg('no-store') === null) {
        foreach ($rec as $r) { statx_store($id, $r); }
        if ($first429 !== null) {
            $ids = statx_apply($id, $t, $rec[$first429 - 1]);   // 演示：429 不计失败、只把间隔翻倍
            statx_out('  状态机：触发事件数=' . count($ids) . '（应为 0，因为 429 不是故障）');
            $after = statx_target($id);
            statx_out('  目标 rl_until=' . (string)($after['rl_until'] ?? '-') . ' fail_streak=' . (int)$after['fail_streak']);
        }
        statx_out('  已落库 ' . count($rec) . ' 条');
    }
    exit(0);
}

/* ================= 批次 2：看板自检（验收用） ================= */

if ($task === 'dash') {
    // 每个视图 × 每个时间范围的**服务端耗时 + SQL 条数 + 渲染字节数**（不经过 HTTP，纯函数调用）
    // ★ 批次 3：dash.php 已退化成**纯取数层**，页面渲染在 pages.php/adv.php 里；
    //   所以这里必须把新的页面层也 require 进来（否则 statx_dash_page() 未定义 → 未捕获致命错误 → exit 255）。
    require_once __DIR__ . '/pages.php';
    require_once __DIR__ . '/adv.php';
    $site = (string)(statx_arg('site', '') ?? '');
    $only = (string)(statx_arg('view', '') ?? '');
    $onlyR = (string)(statx_arg('range', '') ?? '');
    // ★ 批次 3：界面按老版 tj 重做 ⇒ 页面名与时间档都变了，这里同步成**真实存在的页面**。
    //   可用 --view=<页面 id> 只跑一个；可用 --range=<档> 只跑一个时间档。
    $views = ['overview' => '网站概况', 'traffic' => '流量分析', 'trend' => '趋势分析',
              'pages' => '受访页面', 'source' => '全部来源', 'env' => '系统环境',
              'depth' => '访问深度', 'region' => '地域分布', 'dash' => '仪表盘'];
    $ranges = ['today' => '今日', 'yesterday' => '昨日', '7d' => '7 天', '30d' => '30 天', '90d' => '90 天'];
    $worst = ['v' => '', 'r' => '', 'ms' => 0.0];
    $tot = 0; $n = 0;
    statx_out(sprintf('%-8s %-6s %10s %8s %10s %10s  %s', 'VIEW', 'RANGE', 'HTML(B)', 'SQL(条)', 'SQL(ms)', 'WALL(ms)', 'ERR'));
    foreach ($views as $v => $vlab) {
        if ($only !== '' && $only !== $v) { continue; }
        foreach ($ranges as $r => $rlab) {
            if ($onlyR !== '' && $onlyR !== $r) { continue; }
            $_GET = ['p' => $v, 'r' => $r, 'site' => $site];
            unset($GLOBALS['statx_dash_qstat'], $GLOBALS['statx_dash_err']);
            $t1 = microtime(true);
            $html = statx_dash_page();
            $wall = (microtime(true) - $t1) * 1000;
            $qs = statx_dash_qstat();
            $err = (string)($GLOBALS['statx_dash_err'] ?? '');
            $tot += $wall; $n++;
            if ($wall > $worst['ms']) { $worst = ['v' => $vlab, 'r' => $rlab, 'ms' => $wall]; }
            statx_out(sprintf('%-8s %-6s %10d %8d %10.1f %10.1f  %s', $vlab, $rlab, strlen($html),
                (int)$qs['n'], (float)$qs['ms'], $wall, $err !== '' ? mb_substr($err, 0, 60) : '-'));
        }
    }
    statx_out(sprintf('dash: %d 个视图/范围组合 · 平均 %.1f ms · 最慢 %s/%s = %.1f ms · site=%s',
        $n, $n > 0 ? $tot / $n : 0, $worst['v'], $worst['r'], $worst['ms'], $site !== '' ? $site : '(自动)'));
    exit(0);
}

if ($task === 'geo') {
    // 地域读取器自检：库信息 + 一批已知 IP 的解析结果 + 单次耗时
    $ip = (string)(statx_arg('ip', '') ?? '');
    $info = statx_geo_db_info();
    statx_out('geo db: found=' . ($info['found'] !== '' ? $info['found'] : '(无)') . ' bytes=' . $info['bytes']
        . ' readable=' . ($info['readable'] ? 'yes' : 'NO') . ' structure=v' . $info['version']
        . ' ipVersion=' . $info['ip_version'] . ' segSize=' . $info['segment']);
    if ($ip !== '') {
        $r = statx_geo_lookup($ip);
        statx_out('  lookup ' . $ip . ' => ' . ($r !== '' ? $r : '(未覆盖)') . '  ·  short=' . statx_geo_short($r));
        exit(0);
    }
    // ★ 2026-09-14：探测锚点只用**通用**地址（老实现在这里夹带了统计服务器自己的两个 IP）
    $probe = ['8.8.8.8', '114.114.114.114', '223.5.5.5', '1.1.1.1',
              '2606:4700:4700::1111', '2001:4860:4860::8888', '127.0.0.1'];
    $hit = 0;
    foreach ($probe as $p) {
        $r = statx_geo_lookup($p);
        if ($r !== '') { $hit++; }
        statx_out(sprintf('  %-42s => %s', $p, $r !== '' ? $r : '(未覆盖)'));
    }
    $t2 = microtime(true);
    for ($i = 0; $i < 300; $i++) { statx_geo_lookup('114.114.114.114'); }
    $ms4 = (microtime(true) - $t2) * 1000 / 300;
    $t2 = microtime(true);
    for ($i = 0; $i < 300; $i++) { statx_geo_lookup('2408:8220:7010:bcb0:152e:4f45:76fb:1ab0'); }
    $ms6 = (microtime(true) - $t2) * 1000 / 300;
    statx_out(sprintf('  命中 %d/%d · 稳态 v4=%.4f ms/次 · v6=%.4f ms/次', $hit, count($probe), $ms4, $ms6));
    exit(0);
}

/* --------------------------- 一轮健康探测 --------------------------- */
if ($task !== 'health') { statx_out('unknown task: ' . $task); exit(2); }
statx_require_curl('health');   // ★ 缺 curl 就干净退出，别把 fatal 丢给 cron
statx_sync_tz();   // ★ 让 MySQL 会话时区与 PHP 一致
if (statx_cfg('enabled') !== '1') { statx_out('statx.enabled=0 —— 健康检测已停用'); exit(0); }

/* ---- 内部自检：备份看守自身（无 HTTP 目标，见 selfcheck.php）----
 * 刻意放在「到点判定」之前：健康任务在没有任何 HTTP 目标到点的那一轮会 exit(0)，
 * 若把自检放在那之后，「看守失联」就会在那些轮次里被漏掉。 */
try {
    statx_out(statx_selfcheck_round(statx_arg('dry') !== null));
} catch (Throwable $e) {
    // 自检是**附加项**，绝不能拖垮主健康检测：它出错只记一行、放行，
    // 让本轮 20+ 个 HTTP 目标照常探测（否则一个自检异常会变成全局停摆）。
    statx_out('selfcheck: 内部自检自身出错（不影响本轮其它目标探测）：' . $e->getMessage());
}

$t0 = microtime(true);
$forceIds = array_filter(array_map('intval', explode(',', (string)(statx_arg('target', '') ?? ''))));
$tier = (string)(statx_arg('tier', '') ?? '');
$noRetry = statx_arg('no-retry') !== null;
$dry = statx_arg('dry') !== null;

$T = statx_t('targets');
$sql = "SELECT * FROM {$T} WHERE enabled=1";
$par = [];
if ($tier !== '') { $sql .= ' AND tier=?'; $par[] = $tier; }
$sql .= ' ORDER BY sort, id';

/*
 * ★ 「到点判定」放在 PHP 侧算（不用 MySQL 的 NOW()）
 * 原因：ts/last_ts/rl_until 都是 PHP date() 写的（Asia/Shanghai），而 MySQL 会话是 UTC，
 *      两边差 8 小时 → 用 `last_ts <= NOW() - INTERVAL interval_s SECOND` 判定会**连续 8 小时恒为假**，
 *      表现为「首轮之后再没有目标到点」。这里用同一个时钟算，天然一致。
 */
$now = time();
$all = Db::rows($sql, $par);
$targets = [];
foreach ($all as $t) {
    if ($forceIds) {
        if (in_array((int)$t['id'], $forceIds, true)) { $targets[] = $t; }
        continue;
    }
    $last = (string)($t['last_ts'] ?? '');
    if ($last !== '' && (strtotime($last) + (int)$t['interval_s']) > $now) { continue; }
    $rl = (string)($t['rl_until'] ?? '');
    if ($rl !== '' && strtotime($rl) > $now) { continue; }
    $targets[] = $t;
}

if (!$targets) { statx_out('health: 没有到点的目标（下一轮再来）'); exit(0); }

$budget = max(10, statx_cfg_int('max_round_s'));
$deadline = $t0 + $budget;
$conc = max(1, min(STATX_MAX_CONCURRENCY, statx_cfg_int('concurrency')));
$retries = $noRetry ? 0 : max(0, min(STATX_MAX_ATTEMPTS - 1, statx_cfg_int('retries')));

$byKey = [];
foreach ($targets as $t) { $byKey[(string)$t['id']] = $t; }
// 体积中位数（近 7 天成功样本，用于"突降"判断）
$median = [];
if ((int)statx_arg('no-median', '0') !== 1) {
    foreach ($byKey as $k => $t) { $median[$k] = statx_size_median((int)$t['id']); }
}

statx_out('health: 目标 ' . count($byKey) . ' 个 · 并发 ' . $conc . ' · 每目标最多 ' . ($retries + 1)
    . ' 次请求 · 预算 ' . $budget . 's');

$results = statx_probe_jobs_with_retry($byKey, ['median' => $median], $deadline, $retries, $conc);

$nOk = 0; $nFail = 0; $nProbe = 0; $n429 = 0; $requests = 0;
$notifyIds = [];

foreach ($byKey as $k => $t) {
    $r = $results[$k];
    if ((int)$r['attempts'] === 0) { statx_out(sprintf('  #%-3s SKIP  预算耗尽，本轮未探测', $k)); continue; }
    $requests += (int)$r['attempts'];
    if (!$dry) {
        statx_store((int)$t['id'], $r);
        $ids = statx_apply((int)$t['id'], $t, $r);
        foreach ($ids as $eid) { $notifyIds[] = $eid; }
    }
    $state = $r['ok'] ? 'OK  ' : ((int)$r['is_429'] === 1 ? 'RL  ' : ((int)$r['is_probe'] === 1 ? 'PROBE' : 'FAIL'));
    if ($r['ok']) { $nOk++; } elseif ((int)$r['is_429'] === 1) { $n429++; } elseif ((int)$r['is_probe'] === 1) { $nProbe++; } else { $nFail++; }
    statx_out(sprintf('  #%-3s %-5s %-3s ttfb=%-5s total=%-5s size=%-7s %s | %s',
        $k, $state, (int)$r['http_code'], (int)$r['ttfb_ms'], (int)$r['total_ms'], (int)$r['size_bytes'],
        $r['err_class'] !== '' ? $r['err_label'] : '正常', $r['hops']));
}

$notifyIds = array_values(array_unique($notifyIds));
$nres = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'error' => ''];
if ($notifyIds && !$dry) { $nres = statx_notify($notifyIds); }

// 每日汇总（只在有数据时跑，幂等）
$ru = ['days' => 0, 'rows' => 0];
if (!$dry) { $ru = statx_rollup(3); }

$el = round(microtime(true) - $t0, 2);
statx_out(sprintf('health done: ok=%d fail=%d probe_fault=%d rate_limited=%d · 请求 %d 次 · 并发 %d · 耗时 %ss',
    $nOk, $nFail, $nProbe, $n429, $requests, $conc, $el));
statx_out(sprintf('  rollup: groups=%d rows=%d · 待通知事件 %d（sent=%d failed=%d%s）',
    $ru['days'], $ru['rows'], count($notifyIds), $nres['sent'], $nres['failed'],
    $nres['error'] !== '' ? (' err=' . mb_substr($nres['error'], 0, 120)) : ''));
exit($nFail > 0 ? 1 : ($nProbe > 0 ? 3 : 0));

/** 能力②（MySQL ye_statx_* 表）prune 的**只读预览**：打印各表将删行数后退出。 */
function statx_prune_preview(): never
{
    $keepDays = max(1, statx_cfg_int('keep_days'));
    $months   = max(1, statx_cfg_int('keep_months'));
    $cut      = date('Y-m-d H:i:s', strtotime('-' . $keepDays . ' day'));
    $cutM     = date('Y-m-d', strtotime('-' . $months . ' month'));
    statx_out('============================================================');
    statx_out('== prune · 预览模式（未加 --yes，**不会删除任何行**）==');
    statx_out('============================================================');
    $c = statx_t('checks');
    $d = statx_t('daily');
    statx_out('[明细 ' . $c . '] ts < ' . $cut . '（keep_days=' . $keepDays . '）');
    statx_out('  → 将删 ' . (int)Db::one("SELECT COUNT(*) FROM {$c} WHERE ts < ?", [$cut]) . ' 行');
    statx_out('[汇总 ' . $d . '] d < ' . $cutM . '（keep_months=' . $months . '）');
    statx_out('  → 将删 ' . (int)Db::one("SELECT COUNT(*) FROM {$d} WHERE d < ?", [$cutM]) . ' 行');
    statx_out('★ 重跑并加 --yes 才会真删：php plugins/statx/cli.php --task=prune --yes');
    exit(0);
}

/* ★★ 2026-09-14：collect-endpoint 的**根因级预检**。
   背景（实测教训）：干净装的新站，/st.js 与 /st 都 302 回首页，看着像 nginx 抢静态或路由问题，
   真因却是 core/Plugins.php::compat() 的版本归一有 bug：
     老实现 preg_replace('/[-+].*$/','', '1.0 Beta Build20260910') 去不掉**空格**后缀，
     version_compare('1.0 Beta Build20260910', '1.0.0', '>=') === false
     ⇒ **所有第三方插件的 register_file 一律不被 include**（boot.php 永远没被 require），
     于是端点根本不存在，请求掉进内核路由，再被 seo.404_home=1 掩盖成「302 回首页」。
   ★ 这个预检**不需要发任何 HTTP 请求**就能给出结论，比看状态码快得多，也准得多。 */
function statx_cli_boot_precheck(): array
{
    $out = ['calls' => [], 'fatal' => '', 'advice' => []];

    /* ★★ 2026-09-14（r3）：先看**独立入口**在不在 —— 这条通道不经 Plugins::boot()，
       所以内核 compat() 有没有 bug 都不影响它。它是「不改内核也能用」的那条路。 */
    $stRoot = dirname(__DIR__, 2);
    $stName = '';
    foreach ((array)@glob($stRoot . '/*.php') as $stF) {
        $stB = basename($stF);
        if (in_array($stB, ['index.php', 'config.inc.php', 'config.inc.php.example'], true)) { continue; }
        if (str_contains((string)@file_get_contents($stF, false, null, 0, 4096), 'YC_STATX_STANDALONE')) { $stName = $stB; break; }
    }
    $out['standalone'] = $stName;
    $out['calls'][] = ['独立入口（不经内核）', $stName !== '',
        $stName !== ''
            ? ('站点根/' . $stName . ' 已就位 → 端点走它，**与内核 compat() 无关**')
            : '站点根没有独立入口副本（未安装；内核 compat() 正常时不需要它）'];
    // ① 插件是否被内核扫到
    $m = class_exists('Plugins') ? Plugins::ext('statx') : null;
    $out['calls'][] = ['Plugins::ext(\'statx\')', $m !== null, $m !== null ? ('dir=' . ($m['dir'] ?? '?') . ' ver=' . ($m['ver'] ?? '?')) : '未扫到 → 目录/plugin.php 缺失，或被 plugin.<id>.removed=1 跳过'];
    if ($m === null) { return $out; }

    // ② 开关
    $on = Plugins::isOn('statx');
    $out['calls'][] = ['Plugins::isOn(\'statx\')', $on, 'plugin.statx.enabled=' . var_export(get_option('plugin.statx.enabled', '1'), true)
        . ' removed=' . var_export(get_option('plugin.statx.removed', '0'), true)];

    // ③ ★ 版本兼容（本次真凶就在这里）
    $compat = Plugins::compat($m);
    $needCore = (string)($m['requires']['core'] ?? '');
    $cur = defined('YC_VERSION') ? (string)YC_VERSION : '(未定义)';
    $out['calls'][] = ['Plugins::compat()', $compat,
        'YC_VERSION=' . var_export($cur, true) . ' requires.core=' . var_export($needCore, true)
        . ' → version_compare(' . var_export($cur, true) . ',' . var_export($needCore, true) . ',\'>=\') = '
        . var_export($compat, true)];
    if (!$compat) {
        $out['fatal'] = 'compat';
        $out['advice'][] = '内核版本归一 bug：YC_VERSION 带**空格**后缀（如 "1.0 Beta Build20260910"），'
            . '而 core/Plugins.php::compat() 用 preg_replace("/[-+].*$/","") 去不掉空格后缀，'
            . 'version_compare() 因此判 false ⇒ **register_file(boot.php) 从未被 require**，端点自然不存在。';
        $out['advice'][] = '★ 两条路，任选一条（都不必改插件代码）：';
        $out['advice'][] = '';
        $out['advice'][] = '  (A) 【推荐 · 不必改内核】启用插件的**独立入口**（本包自带）：';
        $out['advice'][] = '      php plugins/statx/install-standalone.php            # 安装（在站点根放一份副本）';
        $out['advice'][] = '      php plugins/statx/install-standalone.php --nginx    # 顺带写 nginx 两行（nginx -t 失败自动还原）';
        $out['advice'][] = '      ⇒ 端点从此走 plugins/statx/st.php（站点根的副本），**完全不经过 Plugins::boot()**，';
        $out['advice'][] = '        所以内核 compat() 有没有 bug 都不影响它；**顺带还更快**（少了内核 bootstrap 的 PDO/选项表开销）。';
        $out['advice'][] = '      ⇒ 验收：curl -sI https://<你的域名>/st.js 期望 200 + application/javascript + X-Statx: js';
        $out['advice'][] = '';
        $out['advice'][] = '  (B) 【治本】给 core/Plugins.php::compat() 打版本归一补丁（让**所有**第三方插件都恢复）：';
        $out['advice'][] = '      $norm = static function (string $v): string {';
        $out['advice'][] = '          if (preg_match(\'/^\s*(\d+(?:\.\d+)*)/\', $v, $mm)) { $v = $mm[1]; }';
        $out['advice'][] = '          else { $v = (string)preg_replace(\'/[\s\-+].*$/\', \'\', $v); }';
        $out['advice'][] = '          $pp = explode(\'.\', $v); while (count($pp) < 3) { $pp[] = \'0\'; }';
        $out['advice'][] = '          return implode(\'.\', $pp); };';
        $out['advice'][] = '      return version_compare($norm(YC_VERSION), $norm($needCore), \'>=\');';
        $out['advice'][] = '      ★ 必须**同时**做「按空白切」与「补足三段」；只做前者（version_compare("1.0","1.0.0",">=")===false）仍会被挡。';
        $out['advice'][] = '      ★ 2026-09-14 起这条补丁已合回**上游发行包**（yecha-blog-1.0-beta-build20260911.zip 起），';
        $out['advice'][] = '        所以新站不再需要用户自己动手；你这里踩到，说明本站装的是 20260910 或更早的包。';
        $out['advice'][] = '';
        $out['advice'][] = '  ✘ 不要再试「把 plugin.php 的 requires 放宽」——**没用**：内核是在**读 requires 之前**';
        $out['advice'][] = '    就因为 compat() 把插件 continue 掉了；而且改成 \'1.0\' 也仍是 false。';        $out['advice'][] = '      $norm = static function (string $v): string {';
        $out['advice'][] = '          if (preg_match(\'/^\s*(\d+(?:\.\d+)*)/\', $v, $mm)) { $v = $mm[1]; }';
        $out['advice'][] = '          else { $v = (string)preg_replace(\'/[\s\-+].*$/\', \'\', $v); }';
        $out['advice'][] = '          $pp = explode(\'.\', $v); while (count($pp) < 3) { $pp[] = \'0\'; }';
        $out['advice'][] = '          return implode(\'.\', $pp); };';
        $out['advice'][] = '      return version_compare($norm(YC_VERSION), $norm($needCore), \'>=\');';
        $out['advice'][] = '      ★ 必须**同时**做「按空白切」和「补足三段」，只做前者 '
            . '(version_compare("1.0","1.0.0",">=")===false) 仍会被挡。';
        return $out;
    }

    // ④ register_file 能否取到
    $rf = (string)($m['register_file'] ?? '');
    $path = $rf !== '' ? (Plugins::extDir() . '/' . ($m['dir'] ?? '') . '/' . $rf) : '';
    $rfOk = ($path !== '' && is_file($path) && is_readable($path));
    $out['calls'][] = ['register_file 可读', $rfOk, $rf !== '' ? ($path . (is_file($path) ? '' : ' ← 文件不存在!')) : '(manifest 未声明 register_file)'];

    // ⑤ ★ 最强证据：boot.php 载入后才会有的东西，在内存里存不存在
    $marker = function_exists('statx_collect_rt_write');   // 由 boot.php → inc.php 链上提供
    $out['calls'][] = ['boot.php 是否真的被 require 过', $marker,
        $marker ? 'statx_collect_rt_write() 已存在 → boot.php 已生效'
                : '★ 该函数不存在 → boot.php **从未被 require_once**（端点必然 302/404）'];

    // ⑥ 端点文件与 rt.php
    $out['calls'][] = ['endpoint.php 存在', is_file(__DIR__ . '/endpoint.php'), __DIR__ . '/endpoint.php'];
    $out['calls'][] = ['rt.php 存在', is_file(__DIR__ . '/rt.php'), __DIR__ . '/rt.php'];
    $out['calls'][] = ['rt.php 属主可写', is_writable(__DIR__ . '/rt.php'),
        '属主=' . (function_exists('posix_getpwuid') ? (string)(posix_getpwuid((int)@fileowner(__DIR__ . '/rt.php'))['name'] ?? '?') : '?')
        . ' 权限=' . substr(sprintf('%o', @fileperms(__DIR__ . '/rt.php')), -4)
        . '（若不可写：后台保存/collect-init 会静默失效 —— 常见于「用 root 跑了一次 CLI」）'];

    if (!$marker) { $out['fatal'] = 'boot'; $out['advice'][] = 'boot.php 没被载入，但 compat() 通过 → 检查 register_file 名字、opcache 陈旧、或该站 .user.ini/open_basedir 挡了 require。'; }
    if (!$marker && $stName !== '') {
        $out['advice'][] = '★ 注意：本插件的**独立入口已就位**（站点根/' . $stName . '）—— 端点在走它，'
            . '与内核是否载入 boot.php **无关**；上面这项失败只影响「内核侧」的能力（如后台注入入口）。';
    }    return $out;
}