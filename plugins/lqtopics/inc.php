<?php
/* plugins/lqtopics/inc.php — 第24棒（ta24）：`/api/topics`（服务端聚合热榜）
 * =============================================================================
 * 参考项目：imsyy/DailyHotApi（MIT）—— 只读其**接口清单与返回结构/字段名**，按其结构实现：
 *   信封 = {code,name,title,type,description,link,total,updateTime,from,data[]}
 *   条目 = {id,title,desc,cover,author,timestamp,hot,url,mobileUrl}
 *   （来源：https://github.com/imsyy/DailyHotApi · README「接口总览」+ src/routes/*.ts 的 map 字段）
 *
 * ★★ 访客侧零外发（硬边界）：页面只请求**本站** `/api/topics`；对上游的 DNS/TCP/TLS/HTTP
 *    全部在本文件（服务端）完成。上游 URL 是**写死的常量**，没有任何用户可控 URL ⇒ 无 SSRF 面。
 * ★★ 缓存：每榜一个 JSON，TTL 20 分钟；上游失败 ⇒ 先用**过期缓存兜底**（≤6h）并标 stale，无缓存才报错。
 * ★★ 频控：每 IP 每分钟 40 次（CF-Connecting-IP → X-Real-IP → REMOTE_ADDR），超限 429。
 * ★★ 记录：每次真正打上游都在 var/lqtopics/fetch.log 追加一行（缓存生效与否可从行数证明）。
 * ========================================================================== */

if (!defined('LQTOPICS_TTL')) {
    define('LQTOPICS_TTL', 1200);        /* 新鲜期 20 分钟 */
    define('LQTOPICS_STALE', 21600);     /* 过期后仍可兜底 6 小时 */
    define('LQTOPICS_BODY_MAX', 524288); /* 上游正文上限 512 KB */
    define('LQTOPICS_TIMEOUT', 8);       /* 单榜超时 8s */
    define('LQTOPICS_RL_MAX', 40);       /* 每 IP 每分钟上限 */
}

function lqtopics_dir(): string
{
    return '/www/wwwroot/liulanqi-yecha/var/lqtopics';
}

/* ------------------------------------------------------------------ 榜单元信息 */
function lqtopics_boards(): array
{
    static $B = null;
    if ($B !== null) { return $B; }
    $UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    $B = [
        'baidu' => ['title' => '百度热搜', 'type' => '热搜榜', 'link' => 'https://top.baidu.com/board?tab=realtime',
            'description' => '百度热搜榜（公开接口）', 'url' => 'https://top.baidu.com/api/board?platform=wise&tab=realtime', 'hdr' => []],
        'weibo' => ['title' => '微博热搜', 'type' => '热搜榜', 'link' => 'https://s.weibo.com/top/summary',
            'description' => '微博热搜榜（公开接口）', 'url' => 'https://weibo.com/ajax/side/hotSearch', 'hdr' => ['Referer: https://weibo.com/']],
        'zhihu' => ['title' => '知乎热榜', 'type' => '热榜', 'link' => 'https://www.zhihu.com/hot',
            'description' => '知乎热榜（公开接口）', 'url' => 'https://api.zhihu.com/topstory/hot-lists/total?limit=50', 'hdr' => ['Referer: https://www.zhihu.com/hot']],
        'bilibili' => ['title' => '哔哩哔哩', 'type' => '排行榜 · 全站', 'link' => 'https://www.bilibili.com/v/popular/rank/all',
            'description' => '哔哩哔哩全站排行榜（公开接口）', 'url' => 'https://api.bilibili.com/x/web-interface/ranking/v2?rid=0&type=all', 'hdr' => ['Referer: https://www.bilibili.com/v/popular/rank/all']],
        'toutiao' => ['title' => '今日头条', 'type' => '热榜', 'link' => 'https://www.toutiao.com/hot-event/hot-board/',
            'description' => '今日头条热榜（公开接口）', 'url' => 'https://www.toutiao.com/hot-event/hot-board/?origin=toutiao_pc', 'hdr' => []],
        'juejin' => ['title' => '稀土掘金', 'type' => '热榜', 'link' => 'https://juejin.cn/hot/articles',
            'description' => '掘金热榜（公开接口）', 'url' => 'https://api.juejin.cn/content_api/v1/content/article_rank?category_id=1&type=hot', 'hdr' => []],
        'v2ex' => ['title' => 'V2EX', 'type' => '热议主题', 'link' => 'https://www.v2ex.com/?tab=hot',
            'description' => 'V2EX 热议主题（公开接口）', 'url' => 'https://www.v2ex.com/api/topics/hot.json', 'hdr' => []],
        'ithome' => ['title' => 'IT之家', 'type' => '热榜', 'link' => 'https://www.ithome.com/',
            'description' => 'IT之家热榜（公开接口）', 'url' => 'https://api.ithome.com/json/newslist/news', 'hdr' => []],
    ];
    foreach ($B as $k => $v) { if (!isset($v['hdr'])) { $B[$k]['hdr'] = []; } }
    unset($B['__ua']);
    return $B;
}

/* ------------------------------------------------------------------ 上游取数（写死 URL） */
function lqtopics_http(string $url, array $hdr = []): array
{
    $UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => LQTOPICS_TIMEOUT,
        CURLOPT_USERAGENT      => $UA,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json, text/plain, */*'], $hdr),
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if (is_string($body) && strlen($body) > LQTOPICS_BODY_MAX) { $body = substr($body, 0, LQTOPICS_BODY_MAX); }
    return [$code, (string)$body, $err];
}

/* ------------------------------------------------------------------ 解析（按参考项目的字段名） */
function lqtopics_items(string $board, string $body): array
{
    $j = json_decode($body, true);
    if (!is_array($j)) { return []; }
    $out = [];
    $ts = static function ($t): string { return is_numeric($t) ? date('c', (int)$t) : (string)$t; };
    if ($board === 'baidu') {
        $inner = $j['data']['cards'][0]['content'][0]['content'] ?? [];
        foreach ((array)$inner as $i => $it) {
            $out[] = ['id' => (string)($it['index'] ?? ($i + 1)), 'title' => (string)($it['word'] ?? ''),
                'desc' => (string)($it['desc'] ?? ''), 'cover' => (string)($it['img'] ?? ''),
                'author' => '', 'timestamp' => '', 'hot' => (int)($it['hotScore'] ?? 0),
                'url' => (string)($it['url'] ?? ''), 'mobileUrl' => (string)($it['url'] ?? '')];
        }
    } elseif ($board === 'weibo') {
        foreach ((array)($j['data']['realtime'] ?? []) as $it) {
            $w = (string)($it['word'] ?? '');
            if ($w === '') { continue; }
            $out[] = ['id' => (string)($it['rank'] ?? (count($out) + 1)), 'title' => $w,
                'desc' => (string)($it['note'] ?? ''), 'cover' => '', 'author' => (string)($it['label_name'] ?? ''),
                'timestamp' => '', 'hot' => (int)($it['num'] ?? 0),
                'url' => 'https://s.weibo.com/weibo?q=' . rawurlencode('#' . $w . '#'),
                'mobileUrl' => 'https://m.weibo.cn/search?containerid=100103type%3D1%26q%3D' . rawurlencode($w)];
        }
    } elseif ($board === 'zhihu') {
        foreach ((array)($j['data'] ?? []) as $i => $it) {
            $t = (array)($it['target'] ?? []);
            $h = (string)($it['detail_text'] ?? '');
            $hot = 0;
            if (preg_match('/([0-9.]+)\s*万/', $h, $m)) { $hot = (int)round((float)$m[1] * 10000); }
            elseif (preg_match('/([0-9]+)/', $h, $m)) { $hot = (int)$m[1]; }
            $u = (string)($t['url'] ?? '');
            if (preg_match('#api\.zhihu\.com/questions/(\d+)#', $u, $m)) { $u = 'https://www.zhihu.com/question/' . $m[1]; }
            $out[] = ['id' => (string)($t['id'] ?? ($i + 1)), 'title' => (string)($t['title'] ?? ''),
                'desc' => (string)($t['excerpt'] ?? ''), 'cover' => '', 'author' => (string)($t['author']['name'] ?? ''),
                'timestamp' => $ts($t['created'] ?? 0), 'hot' => $hot, 'hotText' => $h,
                'url' => $u, 'mobileUrl' => $u];
        }
    } elseif ($board === 'bilibili') {
        foreach ((array)($j['data']['list'] ?? []) as $i => $it) {
            $bv = (string)($it['bvid'] ?? '');
            $u  = (string)($it['short_link_v2'] ?? '');
            if ($u === '' && $bv !== '') { $u = 'https://www.bilibili.com/video/' . $bv; }
            $out[] = ['id' => (string)($bv !== '' ? $bv : ($it['aid'] ?? ($i + 1))), 'title' => (string)($it['title'] ?? ''),
                'desc' => (string)($it['desc'] ?? ''), 'cover' => str_replace('http://', 'https://', (string)($it['pic'] ?? '')),
                'author' => (string)($it['owner']['name'] ?? ''), 'timestamp' => $ts($it['pubdate'] ?? 0),
                'hot' => (int)($it['stat']['view'] ?? 0), 'url' => $u, 'mobileUrl' => $bv !== '' ? ('https://m.bilibili.com/video/' . $bv) : $u];
        }
    } elseif ($board === 'toutiao') {
        foreach ((array)($j['data'] ?? []) as $i => $it) {
            $out[] = ['id' => (string)($it['ClusterIdStr'] ?? ($i + 1)), 'title' => (string)($it['Title'] ?? ''),
                'desc' => (string)($it['LabelDesc'] ?? $it['Label'] ?? ''), 'cover' => (string)($it['Image'] ?? ''),
                'author' => '', 'timestamp' => '', 'hot' => (int)($it['HotValue'] ?? 0),
                'url' => (string)($it['Url'] ?? ''), 'mobileUrl' => (string)($it['Url'] ?? '')];
        }
    } elseif ($board === 'juejin') {
        foreach ((array)($j['data'] ?? []) as $i => $it) {
            $c = (array)($it['content'] ?? []);
            $cid = (string)($c['content_id'] ?? '');
            $cnt = (array)($it['content_counter'] ?? []);
            $hot = (int)($cnt['view_count'] ?? $cnt['hot_rank'] ?? $cnt['collect_count'] ?? 0);
            $out[] = ['id' => $cid !== '' ? $cid : (string)($i + 1), 'title' => (string)($c['title'] ?? ''),
                'desc' => (string)($c['brief'] ?? ''), 'cover' => '', 'author' => (string)($it['author']['name'] ?? ''),
                'timestamp' => $ts($c['ctime'] ?? 0), 'hot' => $hot,
                'url' => $cid !== '' ? ('https://juejin.cn/post/' . $cid) : '', 'mobileUrl' => ''];
        }
    } elseif ($board === 'v2ex') {
        foreach ((array)$j as $i => $it) {
            if (!is_array($it)) { continue; }
            $out[] = ['id' => (string)($it['id'] ?? ($i + 1)), 'title' => (string)($it['title'] ?? ''),
                'desc' => trim(mb_substr((string)($it['content'] ?? ''), 0, 120)), 'cover' => '',
                'author' => (string)($it['member']['username'] ?? ''), 'timestamp' => $ts($it['created'] ?? 0),
                'hot' => (int)($it['replies'] ?? 0), 'url' => (string)($it['url'] ?? ''), 'mobileUrl' => (string)($it['url'] ?? '')];
        }
    } elseif ($board === 'ithome') {
        $list = array_merge((array)($j['toplist'] ?? []), (array)($j['newslist'] ?? []));
        foreach ($list as $i => $it) {
            $u = (string)($it['url'] ?? '');
            if ($u !== '' && strpos($u, 'http') !== 0) { $u = 'https://www.ithome.com/' . ltrim($u, '/'); }
            $out[] = ['id' => (string)($it['newsid'] ?? ($i + 1)), 'title' => (string)($it['title'] ?? ''),
                'desc' => (string)($it['description'] ?? ''), 'cover' => (string)($it['image'] ?? ''),
                'author' => '', 'timestamp' => $ts(strtotime((string)($it['postdate'] ?? '')) ?: 0),
                'hot' => (int)($it['hitcount'] ?? 0), 'url' => $u, 'mobileUrl' => $u];
        }
    }
    $out = array_values(array_filter($out, static fn($x) => $x['title'] !== ''));
    return array_slice($out, 0, 50);
}

/* ------------------------------------------------------------------ 缓存 */
function lqtopics_cache_path(string $b): string { return lqtopics_dir() . '/cache/' . preg_replace('/[^a-z0-9_]/', '', $b) . '.json'; }

function lqtopics_cache_read(string $b): array
{
    $p = lqtopics_cache_path($b);
    if (!is_file($p)) { return []; }
    $j = json_decode((string)file_get_contents($p), true);
    return is_array($j) ? $j : [];
}

function lqtopics_cache_write(string $b, array $rec): bool
{
    $d = lqtopics_dir() . '/cache';
    if (!is_dir($d)) { @mkdir($d, 0777, true); }
    $tmp = lqtopics_cache_path($b) . '.tmp' . getmypid();
    if (file_put_contents($tmp, json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) { return false; }
    return @rename($tmp, lqtopics_cache_path($b));
}

function lqtopics_log(string $line): void
{
    $d = lqtopics_dir();
    if (!is_dir($d)) { @mkdir($d, 0777, true); }
    @file_put_contents($d . '/fetch.log', '[' . gmdate('c') . '] ' . $line . "\n", FILE_APPEND);
}

/* ------------------------------------------------------------------ 频控（每 IP 每分钟） */
function lqtopics_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        $v = (string)($_SERVER[$k] ?? '');
        if ($v === '') { continue; }
        if ($k === 'HTTP_X_FORWARDED_FOR') { $v = trim(explode(',', $v)[0]); }
        if (filter_var($v, FILTER_VALIDATE_IP)) { return $v; }
    }
    return '0.0.0.0';
}

/** 返回 [ok, used, resetInSec] */
function lqtopics_rate(string $ip): array
{
    $d = lqtopics_dir() . '/rl';
    if (!is_dir($d)) { @mkdir($d, 0777, true); }
    $f = $d . '/' . sha1($ip) . '.json';
    $now = time();
    $rec = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (!is_array($rec) || (int)($rec['win'] ?? 0) !== (int)floor($now / 60)) { $rec = ['win' => (int)floor($now / 60), 'n' => 0]; }
    $rec['n'] = (int)$rec['n'] + 1;
    @file_put_contents($f, json_encode($rec));
    if (random_int(1, 200) === 1) {                       /* 概率清理 10 分钟前的文件 */
        foreach ((array)@glob($d . '/*.json') as $g) { if (@filemtime($g) < $now - 600) { @unlink($g); } }
    }
    $reset = 60 - ($now % 60);
    return [$rec['n'] <= LQTOPICS_RL_MAX, $rec['n'], $reset];
}

/* ------------------------------------------------------------------ 单榜取数（缓存 → 上游 → 兜底） */
function lqtopics_board(string $b, bool $allowUpstream = true): array
{
    $B = lqtopics_boards();
    if (!isset($B[$b])) { return ['code' => 404, 'error' => 'unknown_board', 'msg' => '未知榜单：' . $b, 'board' => $b]; }
    $meta = $B[$b];
    $rec  = lqtopics_cache_read($b);
    $now  = time();
    $age  = $rec ? ($now - (int)($rec['ts'] ?? 0)) : PHP_INT_MAX;
    if ($rec && $age <= LQTOPICS_TTL) {
        return lqtopics_reply($b, $meta, (array)($rec['data'] ?? []), 'cache', (int)$rec['ts'], false, '', (int)($rec['upstreamAt'] ?? 0));
    }
    if (!$allowUpstream) {
        if ($rec && $age <= LQTOPICS_STALE) { return lqtopics_reply($b, $meta, (array)($rec['data'] ?? []), 'stale', (int)$rec['ts'], true, 'cache_only', (int)($rec['upstreamAt'] ?? 0)); }
        return ['code' => 503, 'error' => 'no_cache', 'msg' => '该榜暂无缓存（cache_only 模式不回源）', 'board' => $b, 'name' => $b, 'title' => (string)$meta['title']];
    }
    [$code, $body, $err] = lqtopics_http((string)$meta['url'], (array)$meta['hdr']);
    lqtopics_log('fetch board=' . $b . ' http=' . $code . ' bytes=' . strlen($body) . ($err !== '' ? (' err=' . $err) : ''));
    $items = ($code >= 200 && $code < 300) ? lqtopics_items($b, $body) : [];
    if ($items) {
        $upstreamAt = $now;
        lqtopics_cache_write($b, ['ts' => $now, 'upstreamAt' => $upstreamAt, 'data' => $items]);
        return lqtopics_reply($b, $meta, $items, 'upstream', $now, false, '', $upstreamAt);
    }
    /* 上游失败/解析为空 ⇒ 过期缓存兜底 */
    if ($rec && $age <= LQTOPICS_STALE) {
        return lqtopics_reply($b, $meta, (array)($rec['data'] ?? []), 'stale', (int)$rec['ts'], true,
            '上游不可用（http=' . $code . ($err !== '' ? ' ' . $err : '') . '），已回退到 ' . gmdate('c', (int)$rec['ts']) . ' 的缓存', (int)($rec['upstreamAt'] ?? 0));
    }
    return ['code' => 502, 'error' => 'upstream_fail', 'board' => $b, 'name' => $b, 'title' => (string)$meta['title'],
        'msg' => '上游不可用：http=' . $code . ($err !== '' ? (' / ' . $err) : '') . '，且本地无可用缓存', 'upstreamHttp' => $code];
}

function lqtopics_reply(string $b, array $meta, array $items, string $from, int $cachedAt, bool $stale, string $note, int $upstreamAt): array
{
    $r = ['code' => 200, 'name' => $b, 'title' => (string)$meta['title'], 'type' => (string)$meta['type'],
        'description' => (string)$meta['description'], 'link' => (string)$meta['link'],
        'total' => count($items), 'updateTime' => gmdate('c', $upstreamAt > 0 ? $upstreamAt : $cachedAt),
        'from' => $from, 'stale' => $stale, 'cachedAt' => $cachedAt, 'ttl' => LQTOPICS_TTL];
    if ($note !== '') { $r['note'] = $note; }
    $r['data'] = $items;
    return $r;
}

/* ------------------------------------------------------------------ 入口 */
function lqtopics_handle(array $q): array
{
    [$ok, $used, $reset] = lqtopics_rate(lqtopics_ip());
    if (!$ok) {
        return [429, ['code' => 429, 'error' => 'rate_limited', 'msg' => '请求过于频繁，请稍后再试（每分钟上限 ' . LQTOPICS_RL_MAX . ' 次）', 'retryAfter' => $reset]];
    }
    $B = lqtopics_boards();
    if (!empty($q['boards'])) {
        $list = [];
        foreach ($B as $k => $v) { $list[] = ['name' => $k, 'title' => $v['title'], 'type' => $v['type'], 'description' => $v['description'], 'link' => $v['link'], 'ttl' => LQTOPICS_TTL]; }
        return [200, ['code' => 200, 'total' => count($list), 'data' => $list, 'reference' => ['project' => 'imsyy/DailyHotApi', 'license' => 'MIT', 'url' => 'https://github.com/imsyy/DailyHotApi']]];
    }
    $b = strtolower(trim((string)($q['board'] ?? 'baidu')));
    if ($b === 'all') {
        $out = [];
        foreach (array_keys($B) as $k) { $out[$k] = lqtopics_board($k, false); }
        return [200, ['code' => 200, 'from' => 'cache', 'boards' => $out]];
    }
    return [200, lqtopics_board($b, true)];
}
