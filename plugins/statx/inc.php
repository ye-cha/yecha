<?php
declare(strict_types=1);
/**
 * StatX · 共享层（建表 / 目标 / 落库 / 状态机 / 告警 / 保留）
 * 依赖：core/Db.php、core/Mail.php、core/helpers.php(yc_opt/yc_opt_set)、lib.php（探针引擎）
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/sdb.php';       // 能力① 的**插件自带 SQLite 存储层**（批次 1 新增；只定义函数，不连库）
require_once __DIR__ . '/collect.php';   // 能力①：采集侧（SQLite 3 张表 / rt.php / spool 收件 / 汇总 / 注入）
require_once __DIR__ . '/links.php';     // 能力①+：链接体检**只读接口**（复用 yecha.org 既有的 links 体检数据）
require_once __DIR__ . '/derive.php';    // 批次 2：来源四分类 / 关键词 / 浏览器 / 操作系统（纯函数，零副作用）
require_once __DIR__ . '/geo.php';       // 批次 2：地域（ip2region xdb 读取器；库文件缺失即降级为空串）

/* ============================ 表名 & 选项 ============================ */

function statx_t(string $suffix): string { return Db::t('statx_health_' . $suffix); }

/** 插件自有选项：statx.<key>（默认值集中在这里，便于后台/CLI 共用） */
function statx_opt(string $key, mixed $def = null): mixed
{
    return get_option('statx.' . $key, $def);
}
function statx_opt_set(string $key, mixed $v): void { set_option('statx.' . $key, $v); }

function statx_defaults(): array
{
    return [
        'enabled'          => '1',    // 健康检测总开关
        'concurrency'      => '2',    // 并发上限（≤2）
        'connect_timeout'  => '5',
        'max_round_s'      => '110',  // 一轮总预算（秒），超出则放弃剩余重试
        'retries'          => '2',    // 失败重试次数（20s/60s 退避）
        'fail_threshold'   => '3',    // 连续 N 轮失败才告警
        'ok_threshold'     => '2',    // 连续 N 轮成功才报恢复
        'slow_threshold'   => '6',    // P95 连续 N 轮超阈值才告警
        'keep_days'        => '30',   // 明细保留天数
        'keep_months'      => '24',   // 按天汇总保留月数
        'tls_warn_days'    => '21',   // TLS 剩余天数告警阈值
        'maint_until'      => '',     // 维护窗口截止（Y-m-d H:i:s），窗口内只记录不告警
        'notify_to'        => '',     // 告警收件人（空 → 回退 mail.notify_to）
        'notify_enabled'   => '1',
        // 邮件配置（默认空 → 回退本站 mail.*；由 --task=mailimport 从其它站一次导入）
        'mail.host'        => '',
        'mail.port'        => '',
        'mail.ssl'         => '',
        'mail.user'        => '',
        'mail.from'        => '',
        // mail.pass 单独走 yc_opt_set() 加密存储，不在此列默认值
        // ⚠️ 能力①（自建统计采集）：本版**已实现**。下面这些值决定端点行为，
        //    并由 statx_collect_rt_write() 固化进 plugins/statx/rt.php
        //    —— 端点读 rt.php 即可工作，**全程不做任何 SQL**。
        'collect.enabled'  => '0',    // 采集总开关（0=端点整体不存在；启用后由 --task=collect-init 打开）
        'collect.endpoint' => '/st',  // 上报端点路径
        'collect.script'   => '/st.js', // 采集脚本路径
        'collect.compat'   => '1',    // 同时提供 /s3.js（老脚本路径）→ 切域名即可迁移、站点 HTML 都不用改
        'collect.site_key' => '',     // 本站 key（空 → 站点地址的 host → 站点根目录名，见 statx_collect_site_key()）
        'collect.spool_dir' => '',    // 空 → 系统临时目录/statx-spool（不可写则站点根/var/statx-spool）
        'collect.spool_max' => '33554432', // 单个 spool 文件上限 32MB（防上报洪水写满磁盘）
        'collect.keep_days' => '30',  // 明细保留天数（与能力② keep_days 同机制）
        'collect.keep_bot_days' => '10', // **爬虫明细**保留天数 —— 批次 1 新增
        'collect.keep_months' => '24',// 按天汇总保留月数（本批起按天汇总是**永久**保留，此值仅留作将来改口径）
        // ★ 2026-09-14 可移植化：空 → 自动用**本站**域名（绝不回退到任何第三方域名）
        'collect.pub_origin' => '',   // 采集端点对外域名（空 → 自动识别本站；识别不到则必须手填）
        'collect.inject_mode' => 'auto', // auto=端点自测通过就用站内路径 / path=站内 /st.js / static=站内静态副本 / pub=外部域名
        'collect.backup_to' => '',    // 异地备份收件人（空 → 回退 statx.notify_to）
        'pv_drop_pct'      => '80',   // 二期：流量骤降阈值（%）
        // ★ 2026-09-14 新增（都是「可配置 + 合理默认」，空值一律表示自动）
        'collect.db_path'  => '',     // SQLite 库路径（空 → 站点根/var/statx/statx.db；旧安装沿用旧路径）
        'collect.roots'    => '',     // 同机其它 YeCha 站点根的父目录（逗号分隔；空 → 站点根的上一级 + 宝塔 wwwroot）
        'collect.cache_glob' => '',   // 目标站全页缓存通配（空 → <root>/var/cache/pages/*.cache）
        'collect.db_owner' => '',     // 库文件属主（空 → 站点根目录的属主，即本机真正的 Web 用户）
        'geo.db'           => '',     // ip2region xdb 路径（空 → 见 statx_geo_db_paths()，找不到则地域列留空）
        'health.seed_file' => '',     // 健康目标示例清单（空 → 只播种**本站自己**；见 status-fixtures/ 目录）
        'health.pin_site_key' => '',  // 健康矩阵置顶的 site_key（空 → 不置顶，按 sort 排）

        // ★★ r11 新增：**隐藏宿主（内核）后台外壳顶部**（默认 0 = 关，行为与本版之前**完全一致**）
        //    · 0（默认）：宿主后台顶部原样保留 —— 欢迎语 / 查看站点 / 站点块 / 内核 <title>。
        //      ⇒ 别人把 statx 装进**自己的** YeCha 后台时，看到的是**他自己的**品牌，不受影响。
        //    · 1（开）：把内核顶部整条收起，并把浏览器标题换成插件自己的「统计 · <站点名>」。
        //      ⇒ **只对「独立统计站」成立**：那种实例里宿主后台没有任何别的东西要看，
        //        内核的「查看站点 ↗」通常还指向一个 404 的站根。
        //    · 只作用于 `/admin/?s=plug_statx*` 自己这几个页面，同机其它站点/其它后台**零影响**。
        //    · 设置方式：`php plugins/statx/cli.php --task=ui --hide-host-chrome=1`（见 README）。
        'ui.hide_host_chrome' => '0',
    ];
}

function statx_cfg(string $key): string
{
    $d = statx_defaults();
    return (string)statx_opt($key, $d[$key] ?? '');
}
function statx_cfg_int(string $key): int { return (int)statx_cfg($key); }

/**
 * 后台本插件的绝对 URL（告警邮件里用）。
 * ★ 2026-09-14：老实现把 `https://gushuwu.com/admin/?s=plug_statx` 写死在邮件正文里 ——
 *   别的用户收到的告警会指向统计服务器自己的站。现在按「本站域名 + 当前请求路径」推导。
 */
function statx_admin_url(): string
{
    $base = '';
    if (PHP_SAPI !== 'cli') {
        $h = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if (preg_match('~^[a-z0-9.\-]+(:\d+)?$~', $h)) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . $h;
        }
    }
    if ($base === '' && function_exists('get_option')) {
        $u = trim((string)get_option('site.url', ''));
        if ($u !== '') { $base = rtrim(preg_match('~^https?://~i', $u) ? $u : ('https://' . $u), '/'); }
    }
    if ($base === '' && function_exists('statx_collect_pub_origin')) { $base = statx_collect_pub_origin(); }
    return $base . '/admin/?s=plug_statx';
}

/**
 * ★ 时区一致性（实测踩到的坑，必须修）
 * 本插件的 ts / last_ts / rl_until / notified_at 全部由 PHP `date()` 写入（= TZ 常量，实测 Asia/Shanghai），
 * 而 MySQL 会话的 NOW()/CURDATE() 用的是 @@system_time_zone（实测 UTC）→ 两边差 **7.95 小时**。
 * 后果：`last_ts <= NOW() - INTERVAL interval_s SECOND` **连续 8 小时恒为假** →
 *       「到点判定」在首轮之后不再放行任何目标（探针等于每 8 小时才跑一次）。
 * 修法：① 把 MySQL 会话时区设成与 PHP 一致（一处生效，覆盖全部 SQL）；
 *       ② 所有时间比较同时改成 PHP 侧算好再作参数传（即使 ① 失败也正确）。
 */
function statx_sync_tz(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    try {
        $off = (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))->format('P');
        if (preg_match('~^[+-]\d{2}:\d{2}$~', $off)) { Db::run("SET time_zone = '{$off}'"); }
    } catch (Throwable $e) { /* 不致命：所有比较已有 PHP 侧参数兜底 */ }
}

/** PHP 侧「现在」（与写入 ts 用的时钟同源） */
function statx_now(): string { return date('Y-m-d H:i:s'); }
/** PHP 侧「N 秒/天/月前」 */
function statx_ago(int $seconds): string { return date('Y-m-d H:i:s', time() - $seconds); }
function statx_ago_days(int $days): string { return date('Y-m-d H:i:s', time() - $days * 86400); }

/* ============================== 建表（幂等） ============================== */

function statx_ensure_schema(): array
{
    statx_sync_tz();
    $log = [];
    $T = statx_t('targets');
    if (!Db::rows("SHOW TABLES LIKE '{$T}'")) {
        Db::run("CREATE TABLE {$T} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_key VARCHAR(64) NOT NULL DEFAULT '',
            label VARCHAR(160) NOT NULL DEFAULT '',
            url VARCHAR(512) NOT NULL,
            kind VARCHAR(16) NOT NULL DEFAULT 'other',
            expect_codes VARCHAR(64) NOT NULL DEFAULT '200',
            require_re VARCHAR(255) NOT NULL DEFAULT '',
            forbid_re VARCHAR(255) NOT NULL DEFAULT '',
            follow TINYINT NOT NULL DEFAULT 1,
            max_redirects TINYINT NOT NULL DEFAULT 5,
            interval_s INT NOT NULL DEFAULT 300,
            timeout_s INT NOT NULL DEFAULT 15,
            slow_ms INT NOT NULL DEFAULT 2500,
            min_bytes INT NOT NULL DEFAULT 0,
            buster TINYINT NOT NULL DEFAULT 0,
            tls_check TINYINT NOT NULL DEFAULT 1,
            tls_interval_s INT NOT NULL DEFAULT 86400,
            enabled TINYINT NOT NULL DEFAULT 1,
            sort INT NOT NULL DEFAULT 100,
            tier VARCHAR(8) NOT NULL DEFAULT 'P1',
            note VARCHAR(255) NOT NULL DEFAULT '',
            last_ts DATETIME DEFAULT NULL,
            last_ok TINYINT NOT NULL DEFAULT 1,
            last_code INT NOT NULL DEFAULT 0,
            last_ms INT NOT NULL DEFAULT 0,
            last_bytes INT NOT NULL DEFAULT 0,
            last_is_probe TINYINT NOT NULL DEFAULT 0,
            last_cls VARCHAR(32) NOT NULL DEFAULT '',
            fail_streak INT NOT NULL DEFAULT 0,
            ok_streak INT NOT NULL DEFAULT 0,
            slow_streak INT NOT NULL DEFAULT 0,
            rl_until DATETIME DEFAULT NULL,
            tls_at DATETIME DEFAULT NULL,
            tls_days INT DEFAULT NULL,
            pv_baseline INT DEFAULT NULL,
            pv_drop_pct TINYINT NOT NULL DEFAULT 80,
            pv_drop_alert TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_site_url (site_key, url(160)),
            KEY k_due (enabled, interval_s, last_ts),
            KEY k_sort (sort)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $log[] = 'created ' . $T;
    }

    $C = statx_t('checks');
    if (!Db::rows("SHOW TABLES LIKE '{$C}'")) {
        Db::run("CREATE TABLE {$C} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            target_id INT UNSIGNED NOT NULL,
            ts DATETIME NOT NULL,
            ok TINYINT NOT NULL DEFAULT 0,
            is_fail TINYINT NOT NULL DEFAULT 0,
            is_probe TINYINT NOT NULL DEFAULT 0,
            is_429 TINYINT NOT NULL DEFAULT 0,
            slow TINYINT NOT NULL DEFAULT 0,
            err_class VARCHAR(32) NOT NULL DEFAULT '',
            http_code INT NOT NULL DEFAULT 0,
            redirects TINYINT NOT NULL DEFAULT 0,
            hops VARCHAR(1000) NOT NULL DEFAULT '',
            final_url VARCHAR(512) NOT NULL DEFAULT '',
            dns_ms INT NOT NULL DEFAULT 0,
            connect_ms INT NOT NULL DEFAULT 0,
            tls_ms INT NOT NULL DEFAULT 0,
            ttfb_ms INT NOT NULL DEFAULT 0,
            total_ms INT NOT NULL DEFAULT 0,
            size_bytes INT NOT NULL DEFAULT 0,
            tls_days_left INT DEFAULT NULL,
            marker_hit TINYINT NOT NULL DEFAULT 0,
            forbid_hit VARCHAR(32) NOT NULL DEFAULT '',
            attempts TINYINT NOT NULL DEFAULT 1,
            note VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY k_t_ts (target_id, ts),
            KEY k_ts (ts),
            KEY k_fail_ts (is_fail, ts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $log[] = 'created ' . $C;
    }

    $D = statx_t('daily');
    if (!Db::rows("SHOW TABLES LIKE '{$D}'")) {
        Db::run("CREATE TABLE {$D} (
            target_id INT UNSIGNED NOT NULL,
            d DATE NOT NULL,
            checks INT NOT NULL DEFAULT 0,
            fails INT NOT NULL DEFAULT 0,
            uptime_pct DECIMAL(5,2) NOT NULL DEFAULT 100.00,
            avg_ms INT NOT NULL DEFAULT 0,
            p95_ms INT NOT NULL DEFAULT 0,
            avg_ttfb_ms INT NOT NULL DEFAULT 0,
            worst_code INT NOT NULL DEFAULT 0,
            tls_days_min INT DEFAULT NULL,
            bytes_avg INT NOT NULL DEFAULT 0,
            built_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (target_id, d),
            KEY k_d (d)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $log[] = 'created ' . $D;
    }

    $E = statx_t('events');
    if (!Db::rows("SHOW TABLES LIKE '{$E}'")) {
        Db::run("CREATE TABLE {$E} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            target_id INT UNSIGNED NOT NULL,
            kind VARCHAR(24) NOT NULL,
            http_code INT NOT NULL DEFAULT 0,
            started_at DATETIME NOT NULL,
            ended_at DATETIME DEFAULT NULL,
            fail_count INT NOT NULL DEFAULT 0,
            detail VARCHAR(512) NOT NULL DEFAULT '',
            notify_state VARCHAR(16) NOT NULL DEFAULT 'pending',
            notify_to VARCHAR(255) NOT NULL DEFAULT '',
            notify_err VARCHAR(255) NOT NULL DEFAULT '',
            notify_tries TINYINT NOT NULL DEFAULT 0,
            notified_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY k_t_start (target_id, started_at),
            KEY k_open (kind, ended_at),
            KEY k_notify (notify_state, notified_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $log[] = 'created ' . $E;
    }

    // 能力① 的 4 张采集表（ye_statx_hits / _daily / _sites / _bots）—— 与上面同族、同一次幂等建表
    foreach (statx_collect_ensure_schema() as $l) { $log[] = $l; }
    return $log;
}

/* ============================== 目标 ============================== */

function statx_targets(array $where = [], string $order = 'sort, id'): array
{
    $sql = 'SELECT * FROM ' . statx_t('targets');
    $par = [];
    if ($where) {
        $bits = [];
        foreach ($where as $col => $val) {
            $bits[] = preg_replace('~[^a-z0-9_]~i', '', (string)$col) . '=?';
            $par[]  = $val;
        }
        $sql .= ' WHERE ' . implode(' AND ', $bits);
    }
    $sql .= ' ORDER BY ' . $order;
    return Db::rows($sql, $par);
}

function statx_target(int $id): ?array
{
    return Db::row('SELECT * FROM ' . statx_t('targets') . ' WHERE id=?', [$id]);
}

/** 新增/更新目标（按 site_key+url 唯一） */
function statx_target_upsert(array $d): int
{
    $T = statx_t('targets');
    $row = Db::row("SELECT id FROM {$T} WHERE site_key=? AND url=? LIMIT 1",
        [(string)($d['site_key'] ?? ''), (string)$d['url']]);
    if ($row) {
        $id = (int)$row['id'];
        $upd = $d;
        unset($upd['site_key'], $upd['url']);
        if ($upd) { Db::update('statx_health_targets', $upd, 'id=?', [$id]); }
        return $id;
    }
    return Db::insert('statx_health_targets', $d);
}

/**
 * 首批目标（P0/P1，照调研 F1 的优先级 + 本次实测校准）
 * 实测校准点：
 *   · herontool.com 首页是 301 → follow=1
 *   · atsvg.com 顶点域没有 tj token → 用标题标记
 *   · /s3.js 实测 cf-cache-status: HIT → buster=1
 *   · yecha.org/market/ 实测 403（目录无 index，nginx autoindex off）
 *     而 /market/manifest.json 才是真正被云市场依赖的文件 → 监控后者
 *   · teayear.com 实测 520（CF 源站错误）→ 默认 enabled=0，待站长确认（见报告 D6）
 */
/**
 * 首次运行要监控什么 —— ★ 2026-09-14 重写（可移植化）。
 *
 * 老实现把**统计服务器自己的 20 个目标**（tj.51puer.com / gushuwu.com / yecha.org /
 * cntales.com / laolv.org / senlincha.com / xuecha.org / herontool.com / atsvg.com / teayear.com
 * + 它们的 sitemap / 内容页 / 云市场清单）写在代码里，并且 admin.php 每次打开后台都会调用它。
 * 后果：**别人下载安装后，一打开后台就往自己库里种下 20 个监控别人家网站的目标**，
 * 打开 cron 就开始每分钟探测这些站 —— 这是不能发布的。
 *
 * 新规则：默认只播种**本站自己**（首页 / sitemap / robots，能取到哪个算哪个，全用最小断言
 * `min_bytes=0`、不带任何 require_re，所以不会误告警）；想要示例清单的用户显式配置
 * `statx.health.seed_file` 指向自己的清单文件。
 */
function statx_seed_targets(): array
{
    $rows = [];

    // ① 本站自己（唯一默认目标）
    $pub = function_exists('statx_collect_pub_origin') ? statx_collect_pub_origin() : '';
    if ($pub !== '') {
        $key   = statx_collect_site_key();
        $label = (string)(function_exists('get_option') ? get_option('site.name', $key) : $key);
        $pa    = (string)parse_url($pub, PHP_URL_PATH);
        $base  = $pa !== '' ? rtrim($pub, '/') : $pub;
        $rows[] = ['site_key' => $key, 'tier' => 'P0', 'kind' => 'home', 'sort' => 10, 'interval_s' => 300,
                   'label' => $label . ' 首页', 'url' => $base . '/',
                   'expect_codes' => '200', 'min_bytes' => 0, 'buster' => 0, 'timeout_s' => 20, 'slow_ms' => 3000,
                   'note' => '本站自己（安装时自动播种）'];
        $rows[] = ['site_key' => $key, 'tier' => 'P1', 'kind' => 'other', 'sort' => 40, 'interval_s' => 3600,
                   'label' => $label . ' sitemap', 'url' => $base . '/sitemap.xml',
                   'expect_codes' => '200', 'require_re' => '<sitemapindex|<urlset', 'min_bytes' => 100,
                   'buster' => 1, 'note' => '取不到就说明本主题没开 sitemap —— 删掉这条目标即可'];
        $rows[] = ['site_key' => $key, 'tier' => 'P2', 'kind' => 'other', 'sort' => 60, 'interval_s' => 3600,
                   'label' => $label . ' robots.txt', 'url' => $base . '/robots.txt',
                   'expect_codes' => '200', 'min_bytes' => 0, 'buster' => 1,
                   'enabled' => 0, 'note' => '默认停用：没有 robots.txt 也很正常，需要时手动启用'];
    }

    // ② 可选：用户自己的清单（或本仓库 status-fixtures/ 里的示例）
    $sf = trim((string)statx_opt('health.seed_file', ''));
    if ($sf !== '') {
        $sf = str_replace('{plugin}', __DIR__, $sf);
        if (is_file($sf)) {
            $extra = @include $sf;
            if (is_array($extra)) { $rows = array_merge($rows, $extra); }
        }
    }

    $added = 0; $kept = 0;
    foreach ($rows as $r) {
        if (empty($r['site_key']) || empty($r['url'])) { continue; }
        $T = statx_t('targets');
        $ex = Db::row("SELECT id FROM {$T} WHERE site_key=? AND url=? LIMIT 1",
            [(string)$r['site_key'], (string)$r['url']]);
        if ($ex) { $kept++; continue; }
        $r += [
            'expect_codes' => '200', 'require_re' => '', 'forbid_re' => '', 'follow' => 1,
            'max_redirects' => 5, 'interval_s' => 300, 'timeout_s' => 15, 'slow_ms' => 2500,
            'min_bytes' => 0, 'buster' => 0, 'tls_check' => 1, 'tls_interval_s' => 86400,
            'enabled' => 1, 'note' => '',
        ];
        Db::insert('statx_health_targets', $r);
        $added++;
    }
    return ['added' => $added, 'kept' => $kept];
}

/* ============================== 落库 ============================== */

function statx_store(int $targetId, array $res, ?string $ts = null): int
{
    return Db::insert('statx_health_checks', [
        'target_id'   => $targetId,
        'ts'          => $ts ?? date('Y-m-d H:i:s'),
        'ok'          => $res['ok'] ? 1 : 0,
        'is_fail'     => (int)$res['is_fail'],
        'is_probe'    => (int)$res['is_probe'],
        'is_429'      => (int)$res['is_429'],
        'slow'        => (int)$res['slow'],
        'err_class'   => (string)$res['err_class'],
        'http_code'   => (int)$res['http_code'],
        'redirects'   => (int)$res['redirects'],
        'hops'        => (string)$res['hops'],
        'final_url'   => (string)$res['final_url'],
        'dns_ms'      => (int)$res['dns_ms'],
        'connect_ms'  => (int)$res['connect_ms'],
        'tls_ms'      => (int)$res['tls_ms'],
        'ttfb_ms'     => (int)$res['ttfb_ms'],
        'total_ms'    => (int)$res['total_ms'],
        'size_bytes'  => (int)$res['size_bytes'],
        'tls_days_left' => $res['tls_days'],
        'marker_hit'  => (int)$res['marker_hit'],
        'forbid_hit'  => (string)$res['forbid_hit'],
        'attempts'    => (int)($res['attempts'] ?? 1),
        'note'        => (string)$res['note'],
    ]);
}

/** 近 7 天成功检测的页面体积中位数（用于"突降"判断） */
function statx_size_median(int $targetId): int
{
    $C = statx_t('checks');
    $since = statx_ago_days(7);   // ★ PHP 侧时间（不用 MySQL NOW()，见 statx_sync_tz 注释）
    $n = (int)Db::one("SELECT COUNT(*) FROM {$C} WHERE target_id=? AND ok=1 AND size_bytes>0 AND ts > ?", [$targetId, $since]);
    if ($n < 5) { return 0; }
    $off = max(0, (int)floor($n / 2));
    $v = Db::one("SELECT size_bytes FROM {$C} WHERE target_id=? AND ok=1 AND size_bytes>0 AND ts > ? ORDER BY size_bytes ASC LIMIT 1 OFFSET {$off}", [$targetId, $since]);
    return (int)$v;
}

/* ========================= 状态机 / 事件 ========================= */

function statx_open_event(int $targetId, string $kind): ?array
{
    $E = statx_t('events');
    return Db::row("SELECT * FROM {$E} WHERE target_id=? AND kind=? AND ended_at IS NULL ORDER BY id DESC LIMIT 1",
        [$targetId, $kind]);
}

function statx_create_event(int $targetId, string $kind, array $res, string $detail): int
{
    return Db::insert('statx_health_events', [
        'target_id'  => $targetId,
        'kind'       => $kind,
        'http_code'  => (int)($res['http_code'] ?? 0),
        'started_at' => date('Y-m-d H:i:s'),
        'fail_count' => 1,
        'detail'     => mb_substr($detail, 0, 500),
        'notify_state' => 'pending',
    ]);
}

function statx_close_event(int $eventId, string $detail = ''): void
{
    Db::update('statx_health_events',
        ['ended_at' => date('Y-m-d H:i:s'), 'detail' => mb_substr($detail, 0, 500)],
        'id=? AND ended_at IS NULL', [$eventId]);
}

/** 目标状态机：更新 streak、产生/关闭事件。返回本轮要通知的事件 id 列表 */
function statx_apply(int $targetId, array $t, array $res): array
{
    $now = date('Y-m-d H:i:s');
    $upd = [
        'last_ts'    => $now,
        'last_ok'    => $res['ok'] ? 1 : 0,
        'last_code'  => (int)$res['http_code'],
        'last_ms'    => (int)$res['total_ms'],
        'last_bytes' => (int)$res['size_bytes'],
        'last_is_probe' => (int)$res['is_probe'],
        'last_cls'   => (string)$res['err_class'],
    ];
    if ($res['tls_days'] !== null) { $upd['tls_days'] = (int)$res['tls_days']; $upd['tls_at'] = $now; }

    $notify = [];
    $failTh   = max(1, statx_cfg_int('fail_threshold'));
    $okTh     = max(1, statx_cfg_int('ok_threshold'));
    $slowTh   = max(1, statx_cfg_int('slow_threshold'));
    $tlsWarn  = statx_cfg_int('tls_warn_days');

    // ---- 被限频：不计失败、不重试、间隔翻倍 ----
    if ((int)$res['is_429'] === 1) {
        $iv = max(30, (int)$t['interval_s']);
        $upd['rl_until'] = date('Y-m-d H:i:s', time() + $iv);
        Db::update('statx_health_targets', $upd, 'id=?', [$targetId]);
        return [];
    }

    // ---- 探针配置故障（guard 拦了自家探针 / CF 挑战 / 裸 403）----
    if ((int)$res['is_probe'] === 1) {
        $upd['fail_streak'] = (int)$t['fail_streak'];   // 不动 streak：这不是站点故障
        Db::update('statx_health_targets', $upd, 'id=?', [$targetId]);
        $ev = statx_open_event($targetId, 'probe_blocked');
        $detail = '探针配置故障：' . $res['err_label'] . '（' . $res['err_class'] . '）· ' . $res['hops'];
        if ($ev === null) {
            $id = statx_create_event($targetId, 'probe_blocked', $res, $detail);
            $notify[] = $id;
        } else {
            Db::update('statx_health_events', ['fail_count' => (int)$ev['fail_count'] + 1, 'detail' => mb_substr($detail, 0, 500)], 'id=?', [(int)$ev['id']]);
        }
        return $notify;
    }

    if ((int)$res['is_fail'] === 1) {
        $streak = (int)$t['fail_streak'] + 1;
        $upd['fail_streak'] = $streak;
        $upd['ok_streak'] = 0;
        Db::update('statx_health_targets', $upd, 'id=?', [$targetId]);
        $ev = statx_open_event($targetId, 'down');
        $detail = $res['err_label'] . '｜' . $res['hops'] . ($res['note'] !== '' ? '｜' . $res['note'] : '');
        if ($ev === null) {
            if ($streak >= $failTh) {                       // ★ 连续 N 轮失败才告警
                $notify[] = statx_create_event($targetId, 'down', $res, $detail);
            }
        } else {
            Db::update('statx_health_events', ['fail_count' => $streak, 'http_code' => (int)$res['http_code'], 'detail' => mb_substr($detail, 0, 500)], 'id=?', [(int)$ev['id']]);
        }
        // 探针被拦的事件如果已经恢复，关闭它
        $pb = statx_open_event($targetId, 'probe_blocked');
        if ($pb !== null) { statx_close_event((int)$pb['id'], '后续检测恢复正常'); $notify[] = (int)$pb['id']; }
        return $notify;
    }

    // ---- 成功 ----
    $okStreak = (int)$t['ok_streak'] + 1;
    $upd['ok_streak'] = $okStreak;
    $upd['fail_streak'] = 0;
    Db::update('statx_health_targets', $upd, 'id=?', [$targetId]);

    foreach (['down', 'probe_blocked'] as $k) {
        $ev = statx_open_event($targetId, $k);
        if ($ev === null) { continue; }
        if ($okStreak < $okTh) { continue; }                 // ★ 连续 N 轮成功才报恢复
        statx_close_event((int)$ev['id'], '已恢复');
        $rid = Db::insert('statx_health_events', [
            'target_id' => $targetId, 'kind' => 'recovered',
            'http_code' => (int)$res['http_code'],
            'started_at' => $now, 'ended_at' => $now,
            'fail_count' => (int)$ev['fail_count'],
            'detail' => '从 ' . (int)$ev['fail_count'] . ' 次失败中恢复（事件 #' . (int)$ev['id'] . '，持续 '
                . max(0, time() - strtotime((string)$ev['started_at'])) . 's）',
            'notify_state' => 'pending',
        ]);
        $notify[] = $rid;
    }

    // ---- 慢（连续 N 轮才建事件；不算站点故障）----
    if ((int)$res['slow'] === 1) {
        $ss = (int)$t['slow_streak'] + 1;
        Db::update('statx_health_targets', ['slow_streak' => $ss], 'id=?', [$targetId]);
        if ($ss >= $slowTh && statx_open_event($targetId, 'slow') === null) {
            $notify[] = statx_create_event($targetId, 'slow', $res,
                '连续 ' . $ss . ' 轮慢：' . $res['total_ms'] . 'ms > ' . (int)$t['slow_ms'] . 'ms');
        }
    } else {
        Db::update('statx_health_targets', ['slow_streak' => 0], 'id=?', [$targetId]);
        $sl = statx_open_event($targetId, 'slow');
        if ($sl !== null) { statx_close_event((int)$sl['id'], '响应恢复正常'); $notify[] = (int)$sl['id']; }
    }

    // ---- TLS 到期 ----
    if ($res['tls_days'] !== null && $tlsWarn > 0 && (int)$res['tls_days'] <= $tlsWarn) {
        if (statx_open_event($targetId, 'tls_expiring') === null) {
            $notify[] = statx_create_event($targetId, 'tls_expiring', $res,
                '公网证书剩余 ' . (int)$res['tls_days'] . ' 天（阈值 ' . $tlsWarn . '）');
        }
    } elseif ($res['tls_days'] !== null && (int)$res['tls_days'] > $tlsWarn + 7) {
        $te = statx_open_event($targetId, 'tls_expiring');
        if ($te !== null) { statx_close_event((int)$te['id'], '证书已续期'); $notify[] = (int)$te['id']; }
    }

    return $notify;
}

/* ============================== 邮件 ============================== */

/**
 * 邮件配置解析（优先级）：
 *   ① 插件自有选项 statx.mail.*（推荐；密码 yc_opt_set 加密存储）
 *   ② 本站 mail.*（订阅/通知用的那套）
 *   ③ 空 → 优雅降级（后台红标「告警发不出去」）
 * 说明：跨站读配置在加密密钥（SECRET 派生）不同站不同的情况下无法解密，
 *       因此**不做运行时跨站读**，而是用 `cli.php --task=mailimport` 一次性导入。
 */
function statx_mail_conf(): array
{
    $c = [
        'host' => (string)statx_opt('mail.host', ''),
        'port' => (int)statx_opt('mail.port', ''),
        'ssl'  => (string)statx_opt('mail.ssl', ''),
        'user' => (string)statx_opt('mail.user', ''),
        'from' => (string)statx_opt('mail.from', ''),
        'pass' => '',
        'src'  => 'statx',
    ];
    if ($c['host'] === '') {                       // ② 回退本站 mail.*
        $c['host'] = (string)get_option('mail.host', '');
        $c['port'] = (int)get_option('mail.port', 465);
        $c['ssl']  = (string)get_option('mail.ssl', '1');
        $c['user'] = (string)get_option('mail.user', '');
        $c['from'] = (string)get_option('mail.from', '');
        $c['src']  = 'site';
    }
    if ($c['port'] <= 0) { $c['port'] = 465; }
    if ($c['ssl'] === '') { $c['ssl'] = '1'; }

    // 密码：先插件自己的（加密选项），再本站 mail.pass —— 一律走 yc_opt()，不自造解密
    $pass = function_exists('yc_opt') ? (string)yc_opt('statx.mail.pass', '') : '';
    if ($pass === '') { $pass = function_exists('yc_opt') ? (string)yc_opt('mail.pass', '') : ''; }
    $c['pass'] = $pass;
    return $c;
}

function statx_mail_ready(array $c): bool
{
    return $c['host'] !== '' && $c['from'] !== '';
}

/** 告警收件人：插件自己的 notify_to 优先，其次本站 mail.notify_to */
function statx_notify_to(): string
{
    $to = trim((string)statx_opt('notify_to', ''));
    if ($to !== '') { return $to; }
    return trim((string)get_option('mail.notify_to', ''));
}

/** 发送一封（成功 true；失败返回错误字符串，与 core/Mail.php 一致） */
function statx_mail_send(string $to, string $subject, string $body, ?array $conf = null): bool|string
{
    $c = $conf ?? statx_mail_conf();
    if (!statx_mail_ready($c)) {
        return 'SMTP 未配置：statx.mail.host / statx.mail.from 为空，且本站也没有 mail.* 配置';
    }
    if ($to === '') { return '未配置告警收件人（statx.notify_to / mail.notify_to）'; }
    return Mail::send(
        $c['host'], (int)$c['port'], $c['ssl'] === '1', (string)$c['user'], (string)$c['pass'],
        $c['from'], $to, $subject, $body, '站点健康'
    );
}

function statx_in_maint(): bool
{
    $u = trim((string)statx_opt('maint_until', ''));
    if ($u === '') { return false; }
    $t = strtotime($u);
    return $t !== false && time() < $t;
}

function statx_event_kind_label(string $k): string
{
    return match ($k) {
        'down'          => '掉线',
        'recovered'     => '已恢复',
        'slow'          => '变慢',
        'tls_expiring'  => '证书将到期',
        'marker_missing'=> '内容标记缺失',
        'probe_blocked' => '探针配置故障',
        default         => $k,
    };
}

/**
 * 通知：把本轮所有待通知事件**合并成一封邮件**（避免"一次掉线发 20 封"）
 * 失败也要记下来 → notify_state='failed'（后台红标："告警发不出去"本身就是故障）
 */
function statx_notify(array $eventIds, array $opt = []): array
{
    $E = statx_t('events');
    $T = statx_t('targets');
    $res = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'error' => ''];

    if (!$eventIds) { return $res; }
    if (statx_cfg('notify_enabled') !== '1') { $res['skipped'] = count($eventIds); return $res; }
    if (statx_in_maint()) {
        $res['skipped'] = count($eventIds);
        $res['error'] = '维护窗口内，已抑制告警（只记录）';
        foreach ($eventIds as $id) {
            Db::update('statx_health_events', ['notify_state' => 'muted', 'notify_err' => $res['error']], 'id=?', [(int)$id]);
        }
        return $res;
    }

    $rows = [];
    foreach ($eventIds as $id) {
        $r = Db::row("SELECT e.*, t.label, t.url, t.site_key, t.tier FROM {$E} e LEFT JOIN {$T} t ON t.id=e.target_id WHERE e.id=?", [(int)$id]);
        if ($r) { $rows[] = $r; }
    }
    if (!$rows) { return $res; }

    $to = statx_notify_to();
    $nDown = 0; $nOther = 0; $nRec = 0;
    foreach ($rows as $r) {
        if ($r['kind'] === 'down') { $nDown++; }
        elseif ($r['kind'] === 'recovered') { $nRec++; }
        else { $nOther++; }
    }
    $bits = [];
    if ($nDown)  { $bits[] = $nDown . ' 掉线'; }
    if ($nOther) { $bits[] = $nOther . ' 其它异常'; }
    if ($nRec)   { $bits[] = $nRec . ' 恢复'; }
    $subject = '[站点健康] ' . implode(' / ', $bits) . '（' . date('m-d H:i') . '）';

    $body = "StatX 站点健康告警 · " . date('Y-m-d H:i:s') . "\n"
          . str_repeat('-', 56) . "\n";
    foreach ($rows as $r) {
        $body .= '[' . statx_event_kind_label((string)$r['kind']) . '] '
               . (string)($r['label'] ?: ('#' . $r['target_id'])) . '  (' . (string)$r['tier'] . ")\n"
               . '  URL   : ' . (string)$r['url'] . "\n"
               . '  状态码: ' . (int)$r['http_code'] . '   失败轮数: ' . (int)$r['fail_count'] . "\n"
               . '  详情  : ' . (string)$r['detail'] . "\n\n";
    }
    $body .= str_repeat('-', 56) . "\n"
           . "查看：' . (function_exists('statx_admin_url') ? statx_admin_url() : '/admin/?s=plug_statx') . '\n"
           . "（本邮件由 YeCha 插件 StatX 自动发出；去抖规则：连续 " . statx_cfg_int('fail_threshold')
           . " 轮失败才告警 / 连续 " . statx_cfg_int('ok_threshold') . " 轮成功才报恢复）\n";

    $r2 = statx_mail_send($to, $subject, $body, $opt['mail_conf'] ?? null);

    $state = ($r2 === true) ? 'sent' : 'failed';
    $err   = ($r2 === true) ? '' : mb_substr((string)$r2, 0, 240);
    foreach ($rows as $r) {
        Db::update('statx_health_events', [
            'notify_state' => $state,
            'notify_to'    => $to,
            'notify_err'   => $err,
            'notify_tries' => (int)$r['notify_tries'] + 1,
            'notified_at'  => date('Y-m-d H:i:s'),
        ], 'id=?', [(int)$r['id']]);
    }
    if ($r2 === true) { $res['sent'] = count($rows); }
    else { $res['failed'] = count($rows); $res['error'] = $err; }
    return $res;
}

/** 取"该发但还没发出去"的事件（含失败重试，最多 3 次、间隔 ≥10 分钟） */
function statx_pending_notifications(int $limit = 50): array
{
    $E = statx_t('events');
    $rows = Db::rows("SELECT id FROM {$E}
        WHERE notify_state IN ('pending','failed') AND notify_tries < 3
          AND (notified_at IS NULL OR notified_at < ?)
        ORDER BY id ASC LIMIT " . max(1, $limit), [statx_ago(600)]);
    return array_map(static fn($r) => (int)$r['id'], $rows);
}

/* ============================ 保留 / 汇总 ============================ */

/**
 * 把「已完整过去的日子」的明细汇总进 daily（幂等：ON DUPLICATE KEY UPDATE 重算）
 * @param int $daysBack 只重算最近 N 天（默认 3；汇总当天会产生半天的假数据，故只到昨天）
 */
function statx_rollup(int $daysBack = 3): array
{
    $C = statx_t('checks');
    $D = statx_t('daily');
    $out = ['days' => 0, 'rows' => 0];

    $from = date('Y-m-d', strtotime('-' . max(1, $daysBack) . ' day'));
    $today = date('Y-m-d');   // ★ PHP 侧的今天（不用 MySQL CURDATE()，见 statx_sync_tz 注释）
    $groups = Db::rows("SELECT target_id, DATE(ts) d, COUNT(*) n FROM {$C}
        WHERE ts >= ? AND DATE(ts) < ? GROUP BY target_id, DATE(ts)", [$from . ' 00:00:00', $today]);
    foreach ($groups as $g) {
        $tid = (int)$g['target_id'];
        $d   = (string)$g['d'];
        $n   = (int)$g['n'];
        if ($n <= 0) { continue; }

        $agg = Db::row("SELECT COUNT(*) n,
                SUM(is_fail) fails,
                AVG(total_ms) avg_ms, AVG(ttfb_ms) avg_ttfb, AVG(size_bytes) avg_bytes,
                MAX(http_code) worst_code, MIN(tls_days_left) tls_min
            FROM {$C} WHERE target_id=? AND DATE(ts)=?", [$tid, $d]);
        $off = max(0, (int)floor($n * 0.95) - 1);
        $p95 = (int)Db::one("SELECT total_ms FROM {$C} WHERE target_id=? AND DATE(ts)=?
            ORDER BY total_ms ASC LIMIT 1 OFFSET {$off}", [$tid, $d]);

        $fails = (int)($agg['fails'] ?? 0);
        $up = $n > 0 ? round((($n - $fails) / $n) * 100, 2) : 100.0;

        Db::run("INSERT INTO {$D} (target_id, d, checks, fails, uptime_pct, avg_ms, p95_ms, avg_ttfb_ms, worst_code, tls_days_min, bytes_avg, built_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE checks=VALUES(checks), fails=VALUES(fails), uptime_pct=VALUES(uptime_pct),
                   avg_ms=VALUES(avg_ms), p95_ms=VALUES(p95_ms), avg_ttfb_ms=VALUES(avg_ttfb_ms),
                   worst_code=VALUES(worst_code), tls_days_min=VALUES(tls_days_min), bytes_avg=VALUES(bytes_avg),
                   built_at=NOW()",
            [$tid, $d, $n, $fails, $up,
             (int)round((float)($agg['avg_ms'] ?? 0)), $p95, (int)round((float)($agg['avg_ttfb'] ?? 0)),
             (int)($agg['worst_code'] ?? 0), $agg['tls_min'] === null ? null : (int)$agg['tls_min'],
             (int)round((float)($agg['avg_bytes'] ?? 0))]);
        $out['rows']++;
    }
    $out['days'] = count($groups);
    // ★ 能力① 用**同一套机制**汇总（hits/bots → ye_statx_daily / ye_statx_bots）
    $out['collect'] = statx_collect_rollup($daysBack);
    return $out;
}

/**
 * 保留清理：
 *   ① 先 rollup（保证被删的日子已经汇总过）
 *   ② 明细分批删除（每批 ≤5000 行，避免长事务锁表）
 *   ③ daily 只留 24 个月；events 永久保留
 */
function statx_prune(?int $keepDays = null, int $batch = 5000): array
{
    $keepDays = $keepDays ?? statx_cfg_int('keep_days');
    $keepDays = max(1, $keepDays);
    $months   = max(1, statx_cfg_int('keep_months'));
    $out = ['rollup' => null, 'deleted' => 0, 'daily_deleted' => 0, 'batches' => 0];

    // ① 汇总全部「今天以前」的日子（一次到位，避免删了才发现没汇总）
    $C = statx_t('checks');
    $D = statx_t('daily');
    $min = Db::one("SELECT MIN(DATE(ts)) FROM {$C}");
    if ($min) {
        $days = (int)floor((strtotime(date('Y-m-d')) - strtotime((string)$min)) / 86400);
        if ($days > 0) { statx_rollup(min(400, $days)); $out['rollup'] = 'ok'; }
    }

    // ② 分批删除过期明细
    $boundary = date('Y-m-d H:i:s', strtotime('-' . $keepDays . ' day'));
    for ($i = 0; $i < 200; $i++) {
        $st = Db::run("DELETE FROM {$C} WHERE ts < ? LIMIT " . $batch, [$boundary]);
        $n = $st->rowCount();
        $out['deleted'] += $n;
        $out['batches']++;
        if ($n < $batch) { break; }
    }

    // ③ daily 保留 24 个月（边界用 PHP 侧算，见 statx_sync_tz 注释）
    $cut = date('Y-m-d', strtotime('-' . $months . ' month'));
    $st = Db::run("DELETE FROM {$D} WHERE d < ?", [$cut]);
    $out['daily_deleted'] = $st->rowCount();
    // ★ 能力① 用**同一套机制**清理（明细 30 天分批删 → daily/bots 24 个月）
    $out['collect'] = statx_collect_prune($keepDays, $batch);
    return $out;
}

/* ======================= 手动「立即检测一次」（后台用） ======================= */

/**
 * 同步探测指定目标（不重试、有总预算），落库并走状态机。
 * 只读探测，绝不触碰被监控站的写操作。
 */
function statx_probe_now(array $ids, int $budgetS = 45): array
{
    $byKey = [];
    foreach ($ids as $id) {
        $t = statx_target((int)$id);
        if ($t !== null && (int)$t['enabled'] === 1) { $byKey[(string)$t['id']] = $t; }
    }
    $out = ['checks' => 0, 'fail' => 0, 'probe' => 0, 'ok' => 0, 'rl' => 0, 'rows' => []];
    if (!$byKey) { return $out; }

    $median = [];
    foreach ($byKey as $k => $t) { $median[$k] = statx_size_median((int)$t['id']); }

    $deadline = microtime(true) + max(5, $budgetS);
    $res = statx_probe_jobs_with_retry($byKey, ['median' => $median], $deadline, 0, STATX_MAX_CONCURRENCY);

    foreach ($byKey as $k => $t) {
        $r = $res[$k];
        if ((int)$r['attempts'] === 0) { continue; }
        statx_store((int)$t['id'], $r);
        statx_apply((int)$t['id'], $t, $r);
        $out['checks']++;
        if ($r['ok']) { $out['ok']++; }
        elseif ((int)$r['is_429'] === 1) { $out['rl']++; }
        elseif ((int)$r['is_probe'] === 1) { $out['probe']++; }
        else { $out['fail']++; }
        $out['rows'][] = ['id' => (int)$t['id'], 'url' => (string)$t['url'], 'code' => (int)$r['http_code'],
                          'cls' => (string)$r['err_class'], 'ttfb' => (int)$r['ttfb_ms'], 'hops' => (string)$r['hops']];
    }
    return $out;
}

/* ============================ 汇总查询（后台用） ============================ */
function statx_kpi(): array
{
    $T = statx_t('targets');
    $C = statx_t('checks');
    $E = statx_t('events');
    $targets = (int)Db::one("SELECT COUNT(*) FROM {$T} WHERE enabled=1");
    $open    = (int)Db::one("SELECT COUNT(*) FROM {$E} WHERE ended_at IS NULL AND kind NOT IN ('recovered')");
    $openDown = (int)Db::one("SELECT COUNT(*) FROM {$E} WHERE ended_at IS NULL AND kind='down'");
    $openProbe = (int)Db::one("SELECT COUNT(*) FROM {$E} WHERE ended_at IS NULL AND kind='probe_blocked'");
    $d1 = statx_ago(86400); $d7 = statx_ago_days(7);
    $tot24 = (int)Db::one("SELECT COUNT(*) FROM {$C} WHERE ts > ?", [$d1]);
    $fail24 = (int)Db::one("SELECT COUNT(*) FROM {$C} WHERE ts > ? AND is_fail=1", [$d1]);
    $up24 = $tot24 > 0 ? round((($tot24 - $fail24) / $tot24) * 100, 2) : null;
    $avg7 = (int)round((float)Db::one("SELECT AVG(total_ms) FROM {$C} WHERE ts > ? AND ok=1", [$d7]));
    $notifyFailed = (int)Db::one("SELECT COUNT(*) FROM {$E} WHERE notify_state='failed'");
    $lastRun = (string)Db::one("SELECT MAX(ts) FROM {$C}");
    return compact('targets', 'open', 'openDown', 'openProbe', 'tot24', 'fail24', 'up24', 'avg7', 'notifyFailed', 'lastRun');
}

/** 某目标最近 N 条检测 */
function statx_recent_checks(int $targetId, int $limit = 10): array
{
    return Db::rows('SELECT * FROM ' . statx_t('checks') . ' WHERE target_id=? ORDER BY id DESC LIMIT ' . max(1, $limit), [$targetId]);
}

function statx_open_events(int $limit = 30): array
{
    $E = statx_t('events');
    $T = statx_t('targets');
    return Db::rows("SELECT e.*, t.label, t.url, t.site_key, t.tier FROM {$E} e
        LEFT JOIN {$T} t ON t.id=e.target_id
        WHERE e.ended_at IS NULL ORDER BY FIELD(e.kind,'down','probe_blocked','slow','tls_expiring'), e.started_at DESC
        LIMIT " . max(1, $limit));
}

function statx_recent_events(int $limit = 40): array
{
    $E = statx_t('events');
    $T = statx_t('targets');
    return Db::rows("SELECT e.*, t.label, t.url, t.site_key, t.tier FROM {$E} e
        LEFT JOIN {$T} t ON t.id=e.target_id ORDER BY e.id DESC LIMIT " . max(1, $limit));
}

/** P95 趋势（近 N 天，跨目标平均） */
function statx_p95_trend(int $days = 14): array
{
    return Db::rows('SELECT d, ROUND(AVG(p95_ms)) p95, ROUND(AVG(uptime_pct),2) up, SUM(fails) fails
        FROM ' . statx_t('daily') . ' WHERE d >= ?
        GROUP BY d ORDER BY d ASC', [date('Y-m-d', strtotime('-' . max(1, $days) . ' day'))]);
}

/** TLS 倒计时（按剩余天数升序） */
function statx_tls_list(): array
{
    return Db::rows('SELECT id, label, site_key, tls_at, tls_days FROM ' . statx_t('targets')
        . ' WHERE enabled=1 AND tls_days IS NOT NULL ORDER BY tls_days ASC LIMIT 20');
}
