<?php
declare(strict_types=1);
/**
 * StatX · 链接可访问性体检 —— **只读接口**（批次 1 第 ④ 项，最小一步）
 *
 * ★ 设计前提（来自 tj 盘点 §I 的两条实测结论，必须照做）：
 *   ① **不要新写探针**：yecha.org 上「links 插件体检体系」**已经存在并在跑**
 *      （`plugins/links/data.php` + `tools/links-probe.php`，root crontab 每 6 小时一次，
 *        UA = `YeCha-LinksProbe/1.2 (+https://yecha.org)`，实测 8 条友链 `probe_state=ok`）。
 *      ⇒ 本文件**只读那份既有结果**，一个出站请求都不发、一个新表都不建、一行数据都不写。
 *   ② **URL 藏在 Markdown 正文里**：502 条「古籍導航」类目的网址是 `**網址**：<https://…>`
 *      （Markdown 自动链接），**不是 `<a href>`** —— 用 `href=` 抽取会一条都抽不到。
 *      该抽取属于**下一批**（内容是 content 型条目，需要跨库读 `ye_contents`），本批只把
 *      「解析函数」写好并留接口，避免下一批重踩这个坑。
 *
 * ★ 本批的产出：一个**能被后台区块直接调用的数据接口** + 一个 CLI 自检任务
 *   （`cli.php --task=links-health`）。后台挂区块放在下一批（本批不碰 admin.php 的布局，降低回归面）。
 *
 * ★ 状态口径（四态 + 一维活跃度）：
 *   ok       —— 体检可达
 *   err      —— 可达但异常（5xx / 内容标记不符）
 *   down     —— 不可达（超时/DNS/连接拒绝）
 *   blocked  —— **被 WAF/限频拦**（429/503/403 且带拦截图样）—— **绝不算死链**
 *   stale    —— **可达但长期停更**（`last_post_at` 超过阈值；实测「善用佳软」停更 472 天仍 `ok`）
 *   unknown  —— 没体检过
 *   另：**绝不自动删链**（探针被拦会产生假死链；删改必须留给人）。
 *
 * ★ 与 tj 的差异：tj **完全没有**出站链接巡检能力；本插件复用它同一台机上**已经跑着**的引擎。
 */

/** 体检数据来源：站点 key → 该站的 links 插件表（表名前缀由该站自己的 config.inc.php 解析） */
function statx_links_sources(): array
{
    return [
        'yecha.org' => ['label' => '夜叉（yecha.org）', 'table' => 'ye_links', 'groups' => 'ye_link_groups'],
    ];
}

/** stale 判定阈值（天）——「可达 ≠ 活着」：站点 HTTP 全绿但 472 天没更新的友链仍应被标出来 */
function statx_links_stale_days(): int
{
    return max(30, (int)statx_opt('links.stale_days', 180));
}

/** 从链接体检说明里识别「被拦」——**不能算死链**（§I.2 的实战警告：目标站多有同类 WAF） */
function statx_links_blocked_note(string $note): bool
{
    $n = mb_strtolower($note);
    foreach (['429', '503', '403', 'too many', 'rate limit', 'forbidden', 'waf', 'cloudflare', 'captcha', 'blocked'] as $k) {
        if (str_contains($n, $k)) { return true; }
    }
    return false;
}

/**
 * 读某站的 links 体检结果（**只读**）。
 * @return array{ok:bool,site:string,rows:array,summary:array,error:string}
 */
function statx_links_health(string $siteKey = 'yecha.org', array $opt = []): array
{
    $out = ['ok' => false, 'site' => $siteKey, 'rows' => [], 'summary' => [], 'error' => '',
            'stale_days' => statx_links_stale_days(), 'probe_source' => '', 'probed_at' => ''];
    $src = statx_links_sources()[$siteKey] ?? null;
    if ($src === null) { $out['error'] = '未登记的体检来源：' . $siteKey; return $out; }

    $root = statx_collect_local_root($siteKey);
    if ($root === null) { $out['error'] = '本机找不到该站的 YeCha 站点根目录'; return $out; }
    $pdo = statx_collect_local_pdo($root);
    if ($pdo === null) { $out['error'] = '读不到该站的 DB 凭据（config.inc.php）'; return $out; }

    $T = (string)$src['table'];
    try {
        $rows = $pdo->query("SELECT id,group_id,name,url,rss,engine,status,probe_state,probe_note,
                probe_at,last_post_at,last_ok_at FROM {$T} ORDER BY group_id, sort, id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $out['error'] = '读 ' . $T . ' 失败：' . $e->getMessage();
        return $out;
    }

    $stale = statx_links_stale_days();
    $now = time();
    $sum = ['total' => 0, 'ok' => 0, 'err' => 0, 'down' => 0, 'blocked' => 0, 'stale' => 0, 'unknown' => 0];
    $list = [];
    foreach ($rows as $r) {
        $state = (string)($r['probe_state'] ?? '');
        $note  = (string)($r['probe_note'] ?? '');
        $days  = 0;
        if (!empty($r['last_post_at'])) {
            $days = (int)floor(($now - (int)strtotime((string)$r['last_post_at'])) / 86400);
        }
        if ($state === 'ok' && $days >= $stale) { $state = 'stale'; }
        elseif ($state === '' && statx_links_blocked_note($note)) { $state = 'blocked'; }
        elseif ($state === 'err' && statx_links_blocked_note($note)) { $state = 'blocked'; }
        if (!isset($sum[$state])) { $state = 'unknown'; }
        $sum[$state]++;
        $sum['total']++;
        if ($days > 0 && (string)$r['last_post_at'] !== '') { $sum['max_idle_days'] = max((int)($sum['max_idle_days'] ?? 0), $days); }
        $r['state'] = $state;
        $r['idle_days'] = $days;
        $r['advice'] = '';
        if ($state === 'down') { $r['advice'] = '仅给建议清单：人工确认后再处置（绝不自动删链）'; }
        if ($state === 'blocked') { $r['advice'] = '被 WAF/限频拦，不算死链；建议不重试、冻结 7 天'; }
        if ($state === 'stale') { $r['advice'] = '可达但长期停更 —— “可达 ≠ 活着”，可考虑挪到不显眼分组'; }
        $list[] = $r;
        if (!$out['probed_at'] && !empty($r['probe_at'])) { $out['probed_at'] = (string)$r['probe_at']; }
    }
    $out['rows'] = $list;
    $out['summary'] = $sum;
    $out['probe_source'] = 'plugins/links/data.php + tools/links-probe.php（cron 每 6h，本插件只读）';
    $out['ok'] = ($out['error'] === '');
    if (!empty($opt['sort_by_idle'])) {
        usort($out['rows'], static fn($a, $b) => ($b['idle_days'] ?? 0) <=> ($a['idle_days'] ?? 0));
    }
    return $out;
}

/**
 * ★ 下一批要接进来的目标类别：**content 型导航条目**（yecha.org 上没有，gushuwu.com 上有 502 条）。
 * 本函数只做「从 Markdown 正文里抽外链」这一步 —— 这是 §I.1 里最容易踩的坑：
 * 正文是 Markdown，`**網址**：<https://…>`（自动链接）与 `[文字](https://…)`，
 * **`href="http…"` 在这个站上命中 0**（实测）。下一批直接调用本函数即可，不必重新踩一遍。
 *
 * @return string[] 去重后的 URL 列表（保持首次出现顺序）
 */
function statx_links_extract_md(string $body, int $max = 0): array
{
    $urls = [];
    // ① Markdown 自动链接：<https://…> ／ ② 行内链接：](https://…)
    if (preg_match_all('~<((?:https?)://[^\s<>"\']+)>~i', $body, $m)) {
        foreach ($m[1] as $u) { $urls[] = $u; }
    }
    if (preg_match_all('~\]\(\s*((?:https?)://[^\s)\'"]+)~i', $body, $m)) {
        foreach ($m[1] as $u) { $urls[] = $u; }
    }
    $out = [];
    foreach ($urls as $u) {
        $u = rtrim(str_replace(['&amp;'], ['&'], trim($u)), '.,;');
        if ($u === '' || isset($out[$u])) { continue; }
        $out[$u] = 1;
        if ($max > 0 && count($out) >= $max) { break; }
    }
    return array_keys($out);
}

/**
 * 域名去重（§I.2 的「礼貌策略」第一步：502 目标 → ≈200 域名 → 每域名只探 1 个代表 URL，请求量降 60%）。
 * 本批只提供函数；真正的探测**留给下一批**，且必须：每天 1 次 · 并发 ≤2 · 429/503 不重试 + 冻结 7 天 ·
 * 被拦判 blocked 态（不算死链）· 绝不自动删链。
 */
function statx_links_dedupe_host(array $urls): array
{
    $out = [];
    foreach ($urls as $u) {
        $h = mb_strtolower((string)parse_url((string)$u, PHP_URL_HOST));
        if ($h === '') { continue; }
        if (str_starts_with($h, 'www.')) { $h = substr($h, 4); }
        if (!isset($out[$h])) { $out[$h] = (string)$u; }
    }
    return $out;
}
