<?php
declare(strict_types=1);
/**
 * StatX · 内部自检（**无 HTTP 目标**）
 * ============================================================================
 * 背景（单点问题）：/root/tj-backup-watch/check.php 是「备份看守」——它每 30 分钟检查
 *   一次 tj 备份是否成功，失败了会自己发信。但它本身是**单点**：看守自己挂了
 *   （cron 没跑 / php 崩了 / 机器上这一坨被误删），就【再没有人报警】——
 *   而它负责的恰恰就是「备份失败要有人知道」。把「看守是否还活着」交给另一条
 *   链路（statx 的健康检测 + 它自己的 SMTP 通道）来看守，才是闭合的。
 *
 * 做法（刻意做小、复用既有机制）：
 *   ① 读看守的 state.json 的 **mtime** ⇒ 判定「多久没跑过」；
 *   ② 读看守 cron.log 里**最近一次的 rc=** ⇒ 判定「最近一次跑成功没有」；
 *   ③ 把结论伪装成一条普通的探测结果（$res），交给**既有的**状态机/去抖/告警：
 *        statx_store() → statx_apply() → statx_notify()
 *      —— 于是「连续 3 轮失败才告警、未恢复不重复发、连续 2 轮成功才报恢复」
 *         这些去抖规则**全部复用**，不需要另写一套。
 *
 * 阈值：默认「超过 2 小时没跑过」= 看守失联（看守 cron 每 30 分钟一次，2 小时 = 连续 4 次没跑）。
 *
 * ★ 局限（必须如实说明，不要假装解决了）：
 *   本自检与看守**同在 .106 这一台机器**上。因此：
 *     · 看守自身坏掉 / cron 没跑 / 该目录被误删 → 本自检能发现并告警 ✔
 *     · 【整机故障、网络中断、磁盘写满导致 statx 的 cron 也跑不起来】
 *       ⇒ 看守与 statx **一起变哑**，谁也发不出告警 ✘
 *   要覆盖后者，必须再有一个**第三视角**（例如 Cloudflare 的可用性告警、
 *   或另一台机器上的外部探针）。这已写进交付报告的「仍存在的问题」。
 *
 * 本文件是**新增文件**，不改动 inc.php / lib.php 里任何既有函数。
 * 回滚：删掉本文件 + 还原 cli.php（见 /root/ROLLBACK-statx-selfcheck.sh）
 */

/** 被看守的「备份看守」的标识（写进 statx_health_targets 的 site_key / url） */
const STATX_SELFCHECK_KEY = 'tj-backup-watch';
const STATX_SELFCHECK_URL = 'internal://tj-backup-watch/state.json';

/** 「多久没跑过就算失联」的默认阈值（秒）：2 小时 */
const STATX_SELFCHECK_STALE_SECS = 7200;

/**
 * 看守状态文件路径。
 * ★ 2026-09-14 可移植化：默认**空** —— 备份看守（/root/tj-backup-watch）是统计服务器自己
 *   机器上的一件外部组件，不是插件的一部分。老实现把它写死成默认值，导致**别人装完插件、
 *   一开 cron 就每轮报一次「探针配置故障」并可能发告警邮件**，说的还是他从没听说过的东西。
 *   现在：没显式配置 ⇒ statx_selfcheck_backup_watch() 直接返回「未配置」且**不建目标行、不发事件**。
 */
function statx_selfcheck_watch_state(): string
{
    $e = getenv('STATX_SELFCHECK_WATCH_STATE');
    if (is_string($e) && $e !== '') { return $e; }
    return trim((string)statx_opt('selfcheck.watch_state', ''));
}

/** 看守 cron 日志路径（cron 包装往里追加 `... rc=$?`）。同样支持环境变量覆盖。 */
function statx_selfcheck_watch_cronlog(): string
{
    $e = getenv('STATX_SELFCHECK_WATCH_CRONLOG');
    if (is_string($e) && $e !== '') { return $e; }
    return trim((string)statx_opt('selfcheck.watch_cronlog', ''));
}

/** 本自检是否被显式配置过（两个路径都在 / 至少状态文件在） */
function statx_selfcheck_configured(): bool
{
    return statx_selfcheck_watch_state() !== '';
}

/** 失联阈值（秒），可由后台选项 statx.selfcheck.stale_secs 调整 */
function statx_selfcheck_stale_secs(): int
{
    $v = (int)statx_opt('selfcheck.stale_secs', (string)STATX_SELFCHECK_STALE_SECS);
    return $v > 0 ? $v : STATX_SELFCHECK_STALE_SECS;
}

/** 只读文件尾部若干字节（避免把大日志整个读进内存） */
function statx_selfcheck_tail(string $path, int $bytes = 8192): string
{
    $fh = @fopen($path, 'rb');
    if (!$fh) { return ''; }
    $size = (int)@filesize($path);
    if ($size > $bytes) { @fseek($fh, $size - $bytes); }
    $buf = @stream_get_contents($fh);
    @fclose($fh);
    return is_string($buf) ? $buf : '';
}

/**
 * 取（必要时创建）这个内部自检用的目标行。
 *
 * ★ enabled=0 是刻意的，不是笔误：
 *   健康任务用 `SELECT * FROM ... WHERE enabled=1` 挑 HTTP 探测目标，
 *   enabled=0 保证本行**永远不会**被 HTTP 探针拿去请求（它的 url 是 internal://，
 *   真被探测只会得到 DNS 失败并误报）。本行只由 statx_selfcheck_round() 手动驱动。
 */
function statx_selfcheck_target(): ?array
{
    $T = statx_t('targets');
    $row = Db::row("SELECT * FROM {$T} WHERE site_key=? AND url=? LIMIT 1",
        [STATX_SELFCHECK_KEY, STATX_SELFCHECK_URL]);
    if ($row) { return $row; }

    $id = Db::insert('statx_health_targets', [
        'site_key'     => STATX_SELFCHECK_KEY,
        'label'        => '备份看守自身（tj-backup-watch）',
        'url'          => STATX_SELFCHECK_URL,
        'kind'         => 'internal',
        'expect_codes' => '200',
        'interval_s'   => 60,
        'timeout_s'    => 5,
        'slow_ms'      => 5000,
        'tls_check'    => 0,
        'enabled'      => 0,
        'sort'         => 999,
        'tier'         => 'P0',
        'note'         => '内部自检（读 state.json mtime + 最近一次 rc），非 HTTP。enabled=0 是刻意的：切勿开启 HTTP 探测。',
    ]);
    return Db::row("SELECT * FROM {$T} WHERE id=?", [$id]);
}

/**
 * 真正干活的检查：读 mtime + 最近一次 rc，拼成一条「探测结果」。
 *
 * @return array{r:array,line:string,ok:bool,fault:bool,rc:?int,age:?float,reasons:array}
 */
function statx_selfcheck_backup_watch(): array
{
    $stateFile = statx_selfcheck_watch_state();
    $cronLog   = statx_selfcheck_watch_cronlog();
    $staleSecs = statx_selfcheck_stale_secs();

    // ★ 未配置 ⇒ 完全不工作，也不产生任何事件/目标行（老实现会凭空报故障，见函数头说明）
    if ($stateFile === '' && $cronLog === '') {
        return ['r' => [], 'line' => '未配置：备份看守自检已跳过（设置 statx.selfcheck.watch_state 后启用）',
                'ok' => true, 'fault' => false, 'rc' => null, 'age' => null, 'reasons' => [],
                'skipped' => true];
    }

    $reasons = [];   // 真正的「看守异常」理由
    $fault   = '';   // 非空 = 自检自己跑不起来（配置/权限问题），不是看守故障
    $rc      = null;

    $stateReadable = is_readable($stateFile);
    $logReadable   = is_readable($cronLog);
    $stateMtime    = $stateReadable ? (int)@filemtime($stateFile) : 0;
    $age           = ($stateReadable && $stateMtime > 0) ? (time() - $stateMtime) : null;

    if (!$stateReadable && !$logReadable) {
        // 典型场景：本自检被非 root（例如后台 www 触发）跑到 —— 那是「读不到」，
        // 绝不能据此判定备份看守故障，否则会凭空误报一次备份事故。
        // 归为「探针配置故障」（statx_apply 里不会动 fail_streak、也不会报 down）。
        $fault = '读不到看守的状态文件与日志（' . $stateFile . ' / ' . $cronLog
               . '）—— 多半是非 root 身份运行；本自检应由 root 的 cron 触发';
    } else {
        // ① 多久没跑过
        if (!$stateReadable) {
            $reasons[] = '看守状态文件不存在/不可读：' . $stateFile;
        } elseif ($age !== null && $age > $staleSecs) {
            $reasons[] = sprintf('看守已 %.1f 小时没跑过（阈值 %.1f 小时）· state.json mtime=%s',
                $age / 3600, $staleSecs / 3600, date('Y-m-d H:i:s', $stateMtime));
        }

        // ② 最近一次运行的 rc（cron 包装写的 `rc=$?`；check.php 是 exit($bad?1:0)）
        if (!$logReadable) {
            $reasons[] = '看守日志不存在/不可读：' . $cronLog;
        } else {
            $tail = statx_selfcheck_tail($cronLog);
            if (preg_match_all('/(?:^|\s)rc=(-?\d+)/m', $tail, $mm) && !empty($mm[1])) {
                $rc = (int)$mm[1][count($mm[1]) - 1];
            }
            if ($rc === null) {
                $reasons[] = '看守日志里找不到最近一次的 rc（' . $cronLog . '）';
            } elseif ($rc !== 0) {
                $reasons[] = '看守最近一次运行 rc=' . $rc . '（非 0 ⇒ 备份链路有问题）';
            }
        }
    }

    $isFault = ($fault !== '');
    $ok      = (!$isFault && $reasons === []);

    $mtimeTxt = $stateMtime > 0 ? date('Y-m-d H:i:s', $stateMtime) : '未知';
    $ageTxt   = $age !== null ? sprintf('%.1f 小时前', $age / 3600) : '未知';
    $rcTxt    = $rc === null ? '未知' : (string)$rc;

    $detail = $ok
        ? sprintf('看守正常：最近一次更新 %s（%s），rc=%s', $mtimeTxt, $ageTxt, $rcTxt)
        : ($isFault ? $fault : implode('；', $reasons));

    $res = [
        'ok'         => $ok,
        // is_probe=1 → statx_apply 走「探针配置故障」分支：不动 fail_streak、不发 down
        'is_probe'   => $isFault ? 1 : 0,
        // is_fail=1 → 真正的掉线，走既有 down 事件 + 去抖告警
        'is_fail'    => (!$ok && !$isFault) ? 1 : 0,
        'is_429'     => 0,
        'slow'       => 0,
        'err_class'  => $ok ? '' : ($isFault ? 'probe_config' : 'watchdog_down'),
        'err_label'  => $ok ? '正常' : ($isFault ? '自检无法运行（探针配置故障）' : '备份看守自身异常'),
        'http_code'  => 0,
        'redirects'  => 0,
        'hops'       => $detail,
        'final_url'  => '',
        'dns_ms'     => 0,
        'connect_ms' => 0,
        'tls_ms'     => 0,
        'ttfb_ms'    => 0,
        'total_ms'   => 0,
        'size_bytes' => 0,
        'tls_days'   => null,
        'marker_hit' => $ok ? 1 : 0,
        'forbid_hit' => '',
        'attempts'   => 1,
        'note'       => '内部自检：state.json=' . $stateFile . ' · cron.log=' . $cronLog
                      . ' · 失联阈值=' . $staleSecs . 's',
    ];

    return ['r' => $res, 'line' => $detail, 'ok' => $ok, 'fault' => $isFault,
            'rc' => $rc, 'age' => $age, 'reasons' => $reasons];
}

/**
 * 一轮内部自检：落库 → 走既有状态机/去抖 → 走既有告警通道发信。
 * 返回一行给人看的摘要（会被写进 cron-statx-health.log）。
 */
function statx_selfcheck_round(bool $dry = false): string
{
    $c = statx_selfcheck_backup_watch();
    if (!empty($c['skipped'])) {
        // ★ 未配置 ⇒ **不建目标行、不落库、不发事件**（见 statx_selfcheck_backup_watch 的说明）
        return 'selfcheck[备份看守自身] 未配置，跳过（可选组件：设置 statx.selfcheck.watch_state 后启用）';
    }
    $t = statx_selfcheck_target();
    if (!$t) { return 'selfcheck: 目标行建立失败，本轮跳过'; }

    $r = $c['r'];

    $tag = $c['ok'] ? '正常' : ($c['fault'] ? '无法自检' : '异常');
    if ($dry) {
        return sprintf('selfcheck[备份看守自身] [dry] %s · %s', $tag, $c['line']);
    }

    // 落库（与普通探测同一张 checks 表，后台/趋势图一视同仁）
    statx_store((int)$t['id'], $r);
    // 重新取一次：保证 streak 是刚写进去的最新值
    $t2 = statx_target((int)$t['id']) ?: $t;
    $ids = statx_apply((int)$t['id'], $t2, $r);

    $out = sprintf('selfcheck[备份看守自身] %s · rc=%s · 上次更新=%s · fail_streak=%d ok_streak=%d · %s',
        $tag,
        $c['rc'] === null ? '未知' : (string)$c['rc'],
        $c['age'] === null ? '未知' : sprintf('%.1f 小时前', $c['age'] / 3600),
        (int)($t2['fail_streak'] ?? 0) + ($r['is_fail'] === 1 ? 1 : 0),
        (int)($t2['ok_streak'] ?? 0) + ($r['ok'] ? 1 : 0),
        $c['line']);

    if ($ids) {
        $n = statx_notify($ids);
        $out .= sprintf(' · ★ 产生事件 %d 个（sent=%d failed=%d%s）',
            count($ids), (int)$n['sent'], (int)$n['failed'],
            $n['error'] !== '' ? ' err=' . mb_substr((string)$n['error'], 0, 120) : '');
    } else {
        $out .= ' · 无新事件（去抖：未恢复不重复发）';
    }
    return $out;
}
