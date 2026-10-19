<?php
declare(strict_types=1);
/**
 * StatX · 派生字段（批次 2 新增）—— 纯函数，零依赖，被端点与入库侧共用
 *
 * ★ 为什么要有这几个函数（决定"界面能不能只靠一条 SQL 出结果"）：
 *   看板要的「来源类型四分类（直达/站内/搜索/社交/外链）」「关键词（外部引擎 + 站内搜索词）」
 *   「浏览器 / 操作系统」全部需要**解析 URL 或 UA**。SQLite 没有正则、也没有 URL 解析，
 *   若放进看板再算，就只能"把区间内所有明细全量取回 PHP 里聚合" ——
 *   这正是 tj 两处**22 万行就明显变慢**的写法（tj-feature-inventory §F.5 结论 2）。
 *   ⇒ 本插件的做法：**在数据入口处（端点 / 入库）算一次、落成列**，看板只做 `GROUP BY + LIMIT`。
 *   与 tj 的 `hits.ref_type` / `hits.keyword` 两列是同一思路（tj 由 Python 侧写入）。
 *
 * ★ 两个函数的调用点：
 *   · `statx_derive_ua()`   —— 端点（`endpoint.php`），UA 只在那一次请求里存在
 *   · `statx_derive_ref()`  —— 入库侧（`collect.php` 的 spool flush），`ref` 在库里就是完整 URL
 *     （放入库侧而不是端点，是为了让**端点承担的工作尽量少**：端点每多一微秒，
 *       全站每个访客都要付一次；入库侧是 cron，每分钟一次）
 */

/** 搜索引擎（主机名后缀匹配；含常见国家/地区变体与移动域名） */
function statx_se_engines(): array
{
    return [
        'google' => ['google.com', 'google.com.hk', 'google.co.jp', 'google.co.uk', 'google.de',
                     'google.fr', 'google.ca', 'google.com.au', 'google.co.kr', 'google.com.tw',
                     'googleusercontent.com', 'googleapis.com'],
        'bing'   => ['bing.com', 'cn.bing.com', 'bing.net'],
        'baidu'  => ['baidu.com', 'm.baidu.com'],
        'sogou'  => ['sogou.com', 'm.sogou.com', 'sogoucdn.com'],
        '360'    => ['so.com', '360.cn', 'haosou.com', 'so.360.cn'],
        'shenma' => ['sm.cn', 'm.sm.cn', 'quark.sm.cn'],
        'yahoo'  => ['yahoo.com', 'search.yahoo.com', 'yahoo.co.jp', 'tw.yahoo.com'],
        'yandex' => ['yandex.ru', 'yandex.com', 'ya.ru'],
        'ddg'    => ['duckduckgo.com'],
        'ecosia' => ['ecosia.org'],
        'brave'  => ['search.brave.com'],
        'startpage' => ['startpage.com'],
        'qwant'  => ['qwant.com'],
        'naver'  => ['naver.com', 'search.naver.com'],
        'daum'   => ['daum.net'],
        'ask'    => ['ask.com'],
        'aol'    => ['search.aol.com'],
        'github' => ['github.com'],
        'zhihu'  => ['zhihu.com', 'zhihu.com/search'],
    ];
}

/** 社交 / 内容平台（这些来源**不透出关键词**，单列一类而不是混进"外链"） */
function statx_social_sites(): array
{
    return ['weixin.qq.com', 'mp.weixin.qq.com', 'weibo.com', 't.cn', 'zhihu.com', 'douban.com',
            'xiaohongshu.com', 'xhslink.com', 'bilibili.com', 'b23.tv', 'tieba.baidu.com',
            'facebook.com', 'm.facebook.com', 'l.facebook.com', 'instagram.com', 'twitter.com',
            'x.com', 't.co', 'linkedin.com', 'lnkd.in', 'reddit.com', 'pinterest.com',
            't.me', 'telegram.me', 'whatsapp.com', 'youtube.com', 'youtu.be', 'vk.com', 'wechat.com'];
}

/** 关键词参数名（外部引擎用的；站内搜索也用同一张表 —— 站点自己写 `q`/`wd`/`s` 都认） */
function statx_kw_params(): array
{
    // ⚠️ r12x：本表里的 `key` 与下面 statx_ref_sensitive_params() 的 `key` 冲突 ——
    //   隐私优先 ⇒ `key=` 一律按**凭据**剥掉，故关键词侧实际只剩其余 11 个（q/query/wd/word/kw/k/w/
    //   keyword/search/text/s）。留在这里是为了不改动既有取词顺序的语义。
    return ['q', 'query', 'wd', 'word', 'kw', 'k', 'w', 'key', 'keyword', 'search', 'text', 's'];
}

/* ============================== r12x：referrer 隐私加固 ============================== */

/**
 * ★★ r12x 新增：referrer 里**必须剥离**的参数名（隐私红线）。
 * 判据是"参数名指示凭据/会话/身份"，**不看值**：规则短、可审计、无正则、不会误伤关键词参数。
 * ⚠️ 刻意**不含**关键词参数（q/query/wd/word/kw/k/w/keyword/search/text/s）——
 *    剥了就等于把站长要的「搜索词」亲手删掉。唯一的交集 `key` 按凭据处理（见上）。
 * ⚠️ 只影响**以后新写入**的行；既有历史行**一行都不动**（红线：不许改统计数据行）。
 */
function statx_ref_sensitive_params(): array
{
    return [
        // 令牌 / 密钥
        'token', 'access_token', 'refresh_token', 'id_token', 'api_key', 'apikey',
        'access_key', 'secret', 'client_secret', 'key', 'jwt', 'bearer', 'sso',
        // 认证 / 会话
        'auth', 'authorization', 'password', 'passwd', 'pwd', 'pass', 'session', 'sessionid',
        'session_id', 'sess', 'sid', 'phpsessid', 'jsessionid', 'csrf', 'csrf_token', 'xsrf',
        'nonce', 'signature', 'sign', 'sig', 'hmac', 'otp', 'ticket', 'code',
        // 身份 / 联系方式
        'email', 'mail', 'phone', 'mobile', 'tel', 'username', 'user', 'account', 'uid', 'login',
        'credit', 'card', 'cvv', 'iban', 'ssn', 'id_card',
    ];
}

/**
 * ★★ r12x 新增：剥离 referrer 查询串 / 片段里的敏感参数。**只删参数，别的一律不动**：
 *   · 路径、其余参数、参数顺序、编码形态**原样保留** ⇒ 引擎关键词（`?q=…`）与站长要看的
 *     "带参数的页面地址"完好无损；
 *   · 没有查询串、或**查询串与片段两处都没命中**时**逐字节原样返回**（`return $url;`）
 *     ⇒ 绝不因为"清洗"把一条本来能用的来路整条丢掉，也不会平白改写 URL；
 *   · **幂等**：重复调用结果一致（入库侧会对 spool 行再跑一次，见 collect.php 的 v2/v3 分支）；
 *   · 大小写/分隔符归一：`API-KEY` / `api.key` / `api_key` 都归一到 `api_key` 再比对。
 */
function statx_ref_strip_sensitive(string $url): string
{
    $qp = strpos($url, '?');
    if ($qp === false) {
        // ★ 没有查询串时**仍要单独处理片段**：OAuth 隐式流把 token 放在 `#access_token=…` 里，
        //   而这种 URL 一个 `?` 都没有。第一版在这里直接 `return statx_ref_strip_sensitive_frag($url)`
        //   —— 把**整条 URL** 当片段传了进去，等于什么都没剥（单元探针第 11 条抓到的就是这个）。
        $hp = strpos($url, '#');
        if ($hp === false) { return $url; }
        return substr($url, 0, $hp) . statx_ref_strip_sensitive_frag(substr($url, $hp));
    }
    $head = substr($url, 0, $qp);
    $tail = substr($url, $qp + 1);
    $frag = '';
    $fp = strpos($tail, '#');
    if ($fp !== false) { $frag = substr($tail, $fp); $tail = substr($tail, 0, $fp); }
    // ★ 查询串与片段**分别**判空：只看查询串就提前 return，会把片段里的 token 漏掉
    //   （OAuth 隐式流 `#access_token=…` 正是这种形态 —— 第一版就是这么写错的，已修）。
    $qkeep = ($tail === '') ? null : statx_ref_filter_pairs($tail);
    $fclean = statx_ref_strip_sensitive_frag($frag);
    if ($qkeep === null && $fclean === $frag) { return $url; }   // 两处都没改 ⇒ 原样
    $q = ($qkeep === null) ? $tail : $qkeep;
    return $head . ($q === '' ? '' : ('?' . $q)) . $fclean;
}

/** 片段（`#…`）按同样的规则过一遍：OAuth 隐式流会把 token 放在片段里而不是查询串里 */
function statx_ref_strip_sensitive_frag(string $frag): string
{
    if ($frag === '' || strpos($frag, '=') === false) { return $frag; }
    $keep = statx_ref_filter_pairs(substr($frag, 1));
    if ($keep === null) { return $frag; }
    return $keep === '' ? '' : ('#' . $keep);
}

/** 过滤 `k=v&k=v` 串。**返回 null 表示"一个都没删"**（调用方据此原样返回，保证无损与幂等） */
function statx_ref_filter_pairs(string $qs): ?string
{
    $deny = statx_ref_sensitive_params();
    $out = [];
    $dropped = 0;
    foreach (explode('&', $qs) as $pair) {
        if ($pair === '') { continue; }
        $kv = explode('=', $pair, 2);
        $k  = mb_strtolower(urldecode($kv[0]));
        $k  = str_replace(['-', '.'], '_', $k);
        if ($k !== '' && in_array($k, $deny, true)) { $dropped++; continue; }
        $out[] = $pair;
    }
    return $dropped === 0 ? null : implode('&', $out);
}

/** 主机名归一（小写、去 www.、去端口） */
function statx_derive_host(string $url): string
{
    $h = (string)parse_url(trim($url), PHP_URL_HOST);
    if ($h === '') { return ''; }
    $h = mb_strtolower($h);
    if (str_starts_with($h, 'www.')) { $h = substr($h, 4); }
    return $h;
}

/** 主机是否属于某列表（相等或为其子域） */
function statx_derive_host_in(string $host, array $list): bool
{
    foreach ($list as $d) {
        if ($host === $d || str_ends_with($host, '.' . $d)) { return true; }
    }
    return false;
}

/** 从 URL 的查询串里取关键词（白名单参数名；最长 100 字符；空串表示没有） */
function statx_derive_kw(string $url): string
{
    $q = (string)parse_url(trim($url), PHP_URL_QUERY);
    if ($q === '') { return ''; }
    $allow = statx_kw_params();
    foreach (explode('&', $q) as $pair) {
        if ($pair === '') { continue; }
        $kv = explode('=', $pair, 2);
        $k = mb_strtolower(urldecode($kv[0]));
        if (!in_array($k, $allow, true)) { continue; }
        $v = isset($kv[1]) ? urldecode($kv[1]) : '';
        $v = trim(preg_replace('~[\x00-\x1f\x7f]+~', '', $v) ?? '');
        // 去掉引擎自己塞的空搜索（如 `wd=`）与纯符号
        if ($v === '' || !preg_match('~\p{L}|\p{N}~u', $v)) { continue; }
        return mb_substr($v, 0, 100);
    }
    return '';
}

/**
 * 来源类型 + 关键词。**顺序即优先级**：直达 → 站内 → 社交 → 搜索引擎 → 外链。
 * （社交里也有带 `q=` 的，但社交来源的关键词没有意义，故社交排在搜索之前。）
 *
 * @return array{type:string,kw:string,host:string}
 *         type ∈ direct | internal | social | search | external
 */
function statx_derive_ref(string $ref, string $siteHost): array
{
    $ref = trim($ref);
    if ($ref === '') { return ['type' => 'direct', 'kw' => '', 'host' => '']; }
    $h = statx_derive_host($ref);
    if ($h === '') { return ['type' => 'direct', 'kw' => '', 'host' => '']; }

    $self = mb_strtolower(trim($siteHost));
    if (str_starts_with($self, 'www.')) { $self = substr($self, 4); }

    if ($h === $self) {
        // 站内来源：若来源页是一个"搜索结果页"，这里就能拿到**站内搜索词**（100% 可靠、零站点开销）
        return ['type' => 'internal', 'kw' => statx_derive_kw($ref), 'host' => $h];
    }
    if (statx_derive_host_in($h, statx_social_sites())) {
        return ['type' => 'social', 'kw' => '', 'host' => $h];
    }
    foreach (statx_se_engines() as $name => $list) {
        if (statx_derive_host_in($h, $list)) {
            return ['type' => 'search', 'kw' => statx_derive_kw($ref), 'host' => $h];
        }
    }
    return ['type' => 'external', 'kw' => '', 'host' => $h];
}

/** 来源类型的显示名（看板与 CSV 共用一套口径，避免两处写死不一致） */
function statx_derive_ref_label(string $type): string
{
    return [
        'direct'   => '直达（无来路）',
        'internal' => '站内来源',
        'search'   => '搜索引擎',
        'social'   => '社交/内容平台',
        'external' => '外链',
    ][$type] ?? '其它';
}

/* ============================== 浏览器 / 操作系统 ============================== */

/**
 * UA → [browser, os]。**顺序敏感**（Chromium 系互相包含：`Edg/` 里也有 `Chrome/` 字样）。
 * 只取到"族"这一级（不存版本号）：看板要的是分布，不是指纹，也顺带少存一点隐私信息。
 *
 * ★★ 踩过的坑（必读）：调用方（`endpoint.php`）用的是 `statx_bot_ua()`，它返回的是
 *    **已经 `mb_strtolower` 过的 UA** —— 所以本函数里**必须全部用小写匹配**，
 *    否则真实访客的 `Chrome/152.0.0.0` 会因为大小写不匹配而全部落进 `Other`
 *    （实测：第一次上线后 E2E 落库里 `browser=Other os=Other`，正是这个原因）。
 *    这里再主动小写一次，让本函数对"传进来的 UA 是否已小写"不敏感。
 */
function statx_derive_ua(string $ua): array
{
    $ua = mb_strtolower($ua);
    $b = 'Other';
    if (preg_match('~edg[a-z/]~', $ua))                     { $b = 'Edge'; }
    elseif (preg_match('~opr/|opera[ /]~', $ua))            { $b = 'Opera'; }
    elseif (str_contains($ua, 'samsungbrowser'))            { $b = 'Samsung'; }
    elseif (str_contains($ua, 'ucbrowser'))                 { $b = 'UCBrowser'; }
    elseif (str_contains($ua, 'qqbrowser'))                 { $b = 'QQBrowser'; }
    elseif (str_contains($ua, 'micromessenger'))            { $b = 'WeChat'; }
    elseif (str_contains($ua, 'firefox/') || str_contains($ua, 'fxios')) { $b = 'Firefox'; }
    elseif (str_contains($ua, 'crios'))                     { $b = 'Chrome'; }
    elseif (str_contains($ua, 'chrome/'))                   { $b = 'Chrome'; }
    elseif (str_contains($ua, 'safari/'))                   { $b = 'Safari'; }
    elseif (str_contains($ua, 'msie ') || str_contains($ua, 'trident/')) { $b = 'IE'; }
    elseif (preg_match('~curl/|wget/|python-requests|go-http-client|java/|okhttp~', $ua)) { $b = 'Tool'; }

    $o = 'Other';
    if (str_contains($ua, 'windows nt'))                    { $o = 'Windows'; }
    elseif (str_contains($ua, 'harmonyos') || str_contains($ua, 'openharmony')) { $o = 'HarmonyOS'; }
    elseif (preg_match('~iphone|ipad|ipod~', $ua))          { $o = 'iOS'; }
    elseif (str_contains($ua, 'android'))                   { $o = 'Android'; }
    elseif (str_contains($ua, 'mac os x') || str_contains($ua, 'macintosh')) { $o = 'macOS'; }
    elseif (str_contains($ua, 'cros'))                      { $o = 'ChromeOS'; }
    elseif (preg_match('~linux|x11~', $ua))                 { $o = 'Linux'; }
    elseif (preg_match('~bot|spider|crawl~', $ua))          { $o = 'Bot'; }

    return [$b, $o];
}

/** IP 版本号（0=未知 / 4 / 6）—— 看板要能证明「IPv6 不再是一个静默的空桶」 */
function statx_derive_ipver(string $ip): int
{
    $b = @inet_pton(trim($ip));
    if (!is_string($b)) { return 0; }
    return strlen($b) === 16 ? 6 : 4;
}
