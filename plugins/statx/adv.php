<?php
declare(strict_types=1);
/**
 * StatX · 批次 3 入口聚合 + 页面路由 + 会话内写操作（全部 POST + CSRF）
 *
 * ★ 为什么把"写操作"集中在这里：本批新增的可写动作只有两个 ——
 *   「分析结论保存」与「数据保留执行」。两者都**必须 POST**，并且都在这里统一做 CSRF 校验。
 *   其余所有 POST 动作（探针 / 采集开关 / 站点注入 / 邮件 / 告警重发 …）由 `admin.php` 处理。
 *   ★ 特别地：**`statx-retention-run` 没有 `confirm=yes` 就什么都不做**（二次确认在服务端强制，
 *     不能只靠前端 `required` 属性）。
 */

require_once __DIR__ . '/pages5.php';

/* ============================== 事件流水的 CSV 包装 ============================== */

function statx_c_events_recent2(array $ctx): array
{
    return statx_c_events_recent($ctx);
}

/* ============================== 路由 ============================== */

/**
 * 渲染一个页面（完整 HTML，含外壳）。
 * @param array $ctx 由 statx_ctx() 给出
 */
function statx_render_page(array $ctx): string
{
    $b = statx_bounds();
    $sites = statx_ui_sites_ordered($ctx['from'], $ctx['to']);
    $fn = statx_page_builder($ctx['p']);
    $t0 = microtime(true);

    if ($fn === null) {
        $body = stx_empty('页面不存在：' . e($ctx['p']));
        return stx_shell($ctx, $sites, $body, ['filters' => stx_filters($ctx, $b)]);
    }

    try {
        $r = $fn($ctx);
    } catch (Throwable $e) {
        // ★ 服务端异常只显示一句话（批次 2 的规矩延续），细节进错误日志
        error_log('[statx] page ' . $ctx['p'] . ' failed: ' . $e->getMessage());
        $body = stx_error('这个统计页面暂时不可用（统计数据读取失败，详细信息见服务器 PHP 错误日志）。'
            . '采集与站点本身不受影响。');
        return stx_shell($ctx, $sites, $body, ['filters' => stx_filters($ctx, $b)]);
    }

    // 支持 builder 直接返回表格结构（省掉一层包装）
    if (!isset($r['kind'])) { $r = ['kind' => 'table', 'table' => $r]; }

    if (($r['kind'] ?? '') === 'table') {
        $r = ['kind' => 'page', 'body' => stx_table($r['table'], $ctx)];
    }
    if (($r['kind'] ?? '') === 'page') {
        $opt = [
            'filters' => stx_filters($ctx, $b),
            'notice'  => stx_range_notice($ctx, $b),
            'foot'    => statx_footer($t0, $ctx, $b),
        ];
        if ($ctx['p'] === 'live') {
            // ★ 照老版：实时页整页 15 秒自动刷新（`<meta http-equiv="refresh">`，不是 JS）
            $opt['head'] = '<meta http-equiv="refresh" content="15">';
        }
        return stx_shell($ctx, $sites, (string)$r['body'], $opt);
    }
    return stx_shell($ctx, $sites, stx_empty('页面渲染失败。'));
}

/**
 * 会话内写操作（只处理本批新增的两个）。必须在任何 HTML 输出之前调用。
 * 返回 true 表示"已经处理并写过 flash / 已重定向"（实际上都会 exit）。
 */
function statx_handle_post(array $ctx): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return; }
    $do = (string)($_POST['do'] ?? '');
    if ($do !== 'statx-insight-save' && $do !== 'statx-retention-run') { return; }

    Csrf::check();   // 两个动作都必须带 CSRF token（缺失 → 403 并停止）

    if ($do === 'statx-insight-save') {
        $sk = (string)preg_replace('~[^A-Za-z0-9._:@\-]~', '', (string)($_POST['site'] ?? ''));
        $key = 'insight.' . ($sk !== '' ? $sk : '_global');
        $txt = (string)($_POST['insight'] ?? '');
        if (mb_strlen($txt) > 20000) { $txt = mb_substr($txt, 0, 20000); }
        statx_opt_set($key, $txt);
        statx_opt_set($key . '.at', date('Y-m-d H:i:s'));
        $_SESSION['flash'] = '分析结论已保存（站点：' . ($sk !== '' ? $sk : '全局') . '，'
            . mb_strlen($txt) . ' 字，保存于 ' . date('Y-m-d H:i:s') . '）';
        header('Location: ' . statx_ui_url($ctx, ['p' => 'insights', 'site' => $sk])); exit;
    }

    if ($do === 'statx-retention-run') {
        // ★★ 二次确认**在服务端强制**：没有 confirm=yes 就什么都不做（不能只靠前端 required）
        $confirm = (string)($_POST['confirm'] ?? '');
        if ($confirm !== 'yes') {
            $_SESSION['flash'] = '⚠ 未执行：缺少二次确认（要删数据必须在表单里勾选确认框）。'
                . '**本次没有删除任何数据。**';
            header('Location: ' . statx_ui_url($ctx, ['p' => 'retention'])); exit;
        }
        $keepDays = (int)statx_cfg('collect.keep_days');
        $r = statx_prune($keepDays, 5000);       // 分批删除，内部含汇总 + 增量回收（**不做整库 VACUUM**）
        $c = $r['collect'] ?? [];
        $_SESSION['flash'] = '保留清理已执行（保留 ' . $keepDays . ' 天）：'
            . '统计明细删除 ' . (int)($c['deleted'] ?? 0) . ' 行（' . (int)($c['batches'] ?? 0) . ' 批）'
            . ' · 统计汇总 ' . (int)($c['daily_deleted'] ?? 0) . ' 行 · 爬虫表 ' . (int)($c['bots_deleted'] ?? 0) . ' 行'
            . ' ｜ 健康明细 ' . (int)$r['deleted'] . ' 行（' . (int)$r['batches'] . ' 批）'
            . ' · 健康汇总 ' . (int)$r['daily_deleted'] . ' 行'
            . '。整库 VACUUM **未执行**（只跑 PRAGMA incremental_vacuum）。';
        header('Location: ' . statx_ui_url($ctx, ['p' => 'retention'])); exit;
    }
}

/* ============================== 诊断（隐藏入口，非导航项） ============================== */

/**
 * `?p=admin`：把原有的能力②管理界面（目标管理 / 事件流水 / 配置手册）收在这一页，
 * **不是导航项**（老版也没有"路由清单"页），只作为诊断入口保留，功能一字未减。
 */
function statx_pg_admin(array $ctx): array
{
    $targets = statx_targets([], 'sort, id');
    $rows = [];
    foreach ($targets as $t) {
        $rows[] = ['id' => (int)$t['id'], 'site' => e((string)$t['site_key']), 'tier' => e((string)$t['tier']),
                   'url' => e((string)$t['url']) . ((string)$t['note'] !== ''
                       ? '<div class="stx-muted-inline">' . e((string)$t['note']) . '</div>' : ''),
                   'exp' => e((string)$t['expect_codes']),
                   're' => '<code style="font-size:11px">' . e(mb_substr((string)$t['require_re'], 0, 60)) . '</code>',
                   'iv' => (int)$t['interval_s'] . 's',
                   'en' => (int)$t['enabled'] === 1 ? stx_tag('开', 'g') : stx_tag('关', ''),
                   'act' => '<form method="post" action="/admin/?s=plug_statx" style="display:inline">' . Csrf::field()
                       . '<input type="hidden" name="do" value="statx-probe"><input type="hidden" name="id" value="' . (int)$t['id'] . '">'
                       . '<button class="stx-btn ghost sm" type="submit">检测</button></form>'];
    }
    $h = stx_table(['cols' => [['ID', 'id', 'r'], ['站点', 'site'], ['Tier', 'tier'], ['URL', 'url'],
                               ['期望', 'exp'], ['必需标记', 're'], ['间隔', 'iv', 'r'], ['启用', 'en'], ['操作', 'act']],
                    'rows' => $rows, 'bar' => '', 'title' => '目标管理（能力②，共 ' . count($targets) . ' 个）',
                    'note' => '⚠️ 不要添加会写数据 / 需要凭据的路径（例如 `/p.gif`、`/admin`、任何 `?do=` 动作）。'], $ctx);

    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>添加目标</h2></div>'
        . '<form method="post" action="/admin/?s=plug_statx" class="stx-form">' . Csrf::field()
        . '<input type="hidden" name="do" value="statx-add">'
        . '<div class="stx-fld"><label>站点标识</label><input name="site_key" placeholder="example.com" style="width:150px"></div>'
        . '<div class="stx-fld"><label>名称</label><input name="label" placeholder="示例站 首页" style="width:150px"></div>'
        . '<div class="stx-fld"><label>URL</label><input name="url" placeholder="https://example.com/" style="width:280px"></div>'
        . '<div class="stx-fld"><label>Tier</label><input name="tier" value="P2" style="width:60px"></div>'
        . '<div class="stx-fld"><label>期望码</label><input name="expect_codes" value="200" style="width:80px"></div>'
        . '<div class="stx-fld"><label>必需正则</label><input name="require_re" style="width:200px"></div>'
        . '<div class="stx-fld"><label>间隔(s)</label><input name="interval_s" value="300" style="width:80px"></div>'
        . '<button class="stx-btn" type="submit">添加目标</button></form></div>';

    $ev = statx_recent_events(40);
    $rows = [];
    foreach ($ev as $e2) {
        $rows[] = ['kind' => e(statx_event_kind_label((string)$e2['kind'])),
                   'label' => e((string)($e2['label'] ?? ('#' . $e2['target_id']))),
                   'at' => e((string)$e2['started_at']),
                   'end' => e((string)($e2['ended_at'] ?? '—')),
                   'ns' => e((string)$e2['notify_state']),
                   'detail' => e(mb_substr((string)$e2['detail'], 0, 160))];
    }
    $h .= stx_table(['cols' => [['类型', 'kind'], ['目标', 'label'], ['开始', 'at'], ['结束', 'end'],
                                ['通知', 'ns'], ['详情', 'detail']],
                     'rows' => $rows, 'bar' => '', 'title' => '最近事件流水（最多 40 条）'], $ctx);
    $h .= stx_muted('这一页是**诊断入口**（不在左栏导航里，老版也没有"路由清单"这种页）。'
        . '能力② 的日常使用在「站点健康」页：矩阵 / 未恢复事件 / P95 / TLS 都在那里。');
    return ['kind' => 'page', 'body' => $h];
}

/* ★ 2026-09-15 新增两页（站点管理 / 账号）。放在文件末尾 require：
   statx_page_builder() 是「先 require adv.php，再 function_exists」⇒ 在这里加载即可被认到。 */
require_once __DIR__ . '/adminops.php';
