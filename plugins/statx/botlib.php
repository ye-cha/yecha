<?php
declare(strict_types=1);
/**
 * StatX · 爬虫识别（三级特征库）—— 能力① 的「真人 PV 不被爬虫淹没」防线
 *
 * ★ 复用策略（站长要求：必须复用 guardweb 的三级特征库）
 *   ① 本机装了 plugins/guardweb 且已启用时，**直接调用它的三个判定函数**：
 *        yc_guardweb_is_se($ua)      —— 合法搜索引擎蜘蛛（含社交预览 / 监控服务商）
 *        yc_guardweb_is_ai($ua)      —— AI / 大模型训练与检索爬虫
 *        yc_guardweb_is_scraper($ua) —— 通用采集器 / 脚本客户端
 *      这是**首选路径**（名单以 guardweb 为准，它更新时本插件自动跟着更新）。
 *   ② guardweb 未装 / 未启用时，退化为本文件内嵌的**同源副本**
 *      （与 guardweb `plugins/guardweb/boot.php` 的三个数组逐条对齐；
 *        改动请以 guardweb 为准并两边同步）。
 *
 * ⚠️ 本文件被 boot.php 在每个前台请求 require —— **只允许定义函数，不得有任何副作用、不得碰 DB**。
 *
 * 返回结构（statx_bot_classify）：
 *   ['is_bot'=>0|1, 'cls'=>'human|se|ai|scraper|empty|other', 'name'=>'gptbot'|…]
 */

/** 内嵌三级名单（同源副本；guardweb 缺失时的兜底） */
function statx_bot_lists(): array
{
    static $L = null;
    if ($L !== null) { return $L; }
    return $L = [
        // ① 合法搜索引擎 / 社交预览 / 监控服务商
        'se' => [
            'googlebot', 'bingbot', 'baiduspider', 'sogou', '360spider', 'yandex',
            'duckduckbot', 'applebot', 'naverbot', 'slurp', 'yisouspider',
            'facebookexternalhit', 'linkedinbot', 'twitterbot',
            'semrushbot', 'ahrefsbot', 'mj12bot', 'dotbot', 'pingdom', 'uptimerobot',
            // ★★ r12 新增（依据 = **本站真实 access log + 真实 /st 上报**，见 r12 报告「蜘蛛数字对照表」）——
            //   这些是旧名单识别不出、会被静默算成真人 PV 的爬虫。
            //   ⚠️ 'yisouspider' 上面第 10 行**已有**：r12 初版在这里重复列了一次（同一 token 两遍，
            //      对识别结果无影响，但会让人误判名单长度）⇒ r12x 去重。
            'yeti',             // 韩国 Naver（`Yeti/1.1; +http://naver.me/spd`）
            'qwantify',         // 法国 Qwant
            'startpage.com',    // Startpage（带 .com，比裸 'startpage' 精确，不会误伤域名）
            'googleother',      // Google 多功能爬虫（含 'google' ⇒ 旧名单反而匹配不到）
            'google-inspectiontool',  // Search Console「网址检查」（旧名单匹配不到）
        ],
        // ② AI / 大模型爬虫（与①严格互斥）
        'ai' => [
            'gptbot', 'chatgpt-user', 'oai-searchbot', 'anthropic', 'claudebot',
            'claude-web', 'claude-searchbot', 'perplexitybot', 'ccbot', 'amazonbot',
            'cohere', 'cohere-ai', 'google-extended', 'meta-externalagent',
            'imagesiftbot', 'timpibot', 'applebot-extended', 'diffbot', 'ai2bot',
            'omgili', 'omgilibot', 'bytespider', 'petalbot', 'seekr', 'dataforseo',
            // ⚠️ 'applebot-extended' 上面第 18 行**已有**（r12 初版重复列了一次）⇒ r12x 去重。
            //   它与 'applebot' 的区分**不靠名单顺序**，靠 statx_bot_has_longer_match()（见下），
            //   所以把它挪到短名单之后也不会把 AI 爬虫误判成搜索引擎。
            'exasearchbot',        // ★ r12x：Exa（AI 检索，`crawler.exa.ai`）。实测会执行 JS 并上报 /st，
                                   //   旧逻辑把它算成真人 —— 近 7 天上报流里占 12 条。
            'reflectionbot',       // ★ r12x：`(compatible; Reflectionbot/1.0; +https://reflection.ai/bot)`，
                                   //   同上，近 7 天上报流里占 11 条。
            'firecrawl', 'crawl4ai', 'sidetrade', 'magpie-crawler', 'netsprint',
        ],
        // ③ 通用采集 / 脚本客户端
        'scraper' => [
            'python-requests', 'python-urllib', 'aiohttp', 'httpx', 'scrapy', 'pycurl',
            'wget', 'curl/', 'go-http-client', 'okhttp', 'httpclient', 'apache-httpclient',
            'java/', 'libwww-perl', 'lwp', 'ruby', 'mechanize', 'phantomjs',
            'headlesschrome', 'selenium', 'playwright', 'puppeteer',
            'axios', 'node-fetch', 'postman', 'insomnia', 'httpie',
            'masscan', 'zgrab', 'nmap', 'nikto', 'sqlmap',
            // ★★ r12x：从**本站真实数据**里抓到的、名单没收录的爬虫（详见报告 §② 对照表）：
            //   · 'shapbot'  —— gushuwu access log 里 3,945 次；且**会执行 JS**，/st 上报 70 条，
            //                   旧逻辑 100% 算成真人 PV（这是本次实测影响最大的一条）。
            //   · 'aionbot'  —— access log 78 次（`(compatible; AionBot/1.0)`）。
            'shapbot', 'aionbot',
        ],
    ];
}

/** 把 UA 归一化成小写（与 guardweb 的 yc_guardweb_ua() 同口径） */
function statx_bot_ua(): string
{
    return mb_strtolower(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
}

/** 二级判定：优先 guardweb，缺失时用内嵌副本 */
function statx_bot_is(string $cls, string $ua): bool
{
    $fn = 'yc_guardweb_is_' . $cls;
    if (function_exists($fn)) {
        try { return (bool)$fn($ua); } catch (Throwable $e) { /* 落到内嵌副本 */ }
    }
    $L = statx_bot_lists();
    foreach (($L[$cls] ?? []) as $k) {
        if ($k !== '' && strpos($ua, $k) !== false) { return true; }
    }
    return false;
}

/** 命中项名称（用于「被谁刷」统计）：优先用 guardweb 的 ai_list()，其余用内嵌名单 */
function statx_bot_name(string $cls, string $ua): string
{
    if ($cls === 'ai' && function_exists('yc_guardweb_ai_list')) {
        try {
            foreach ((array)yc_guardweb_ai_list() as $n) {
                if (strpos($ua, mb_strtolower((string)$n)) !== false) { return mb_strtolower((string)$n); }
            }
        } catch (Throwable $e) { /* 忽略 */ }
    }
    $L = statx_bot_lists();
    foreach (($L[$cls] ?? []) as $k) {
        if ($k !== '' && strpos($ua, $k) !== false) { return $k; }
    }
    return $cls === '' ? '' : ($cls . '-other');
}

/**
 * r12 新增：某类名单里**最长命中**的特征名（没有命中返回 ''）。
 * 用途只有一个：区分 `applebot` 与 `applebot-extended` 这类「短特征是长特征的子串」的情况。
 * 优先走 guardweb 的具名清单（与 statx_bot_name 同源），缺失时用内嵌副本。
 */
function statx_bot_longest(string $cls, string $ua): string
{
    $best = '';
    $cand = null;
    if ($cls === 'ai' && function_exists('yc_guardweb_ai_list')) {
        try { $cand = (array)yc_guardweb_ai_list(); } catch (Throwable $e) { $cand = null; }
    }
    if ($cand === null) { $L = statx_bot_lists(); $cand = $L[$cls] ?? []; }
    foreach ($cand as $k) {
        $k = mb_strtolower((string)$k);
        if ($k !== '' && strpos($ua, $k) !== false && mb_strlen($k) > mb_strlen($best)) { $best = $k; }
    }
    return $best;
}

/**
 * r12 新增：该类名单的命中特征里，**存在比"更长名单"的命中更专有**的一条吗？
 * 判据（可复现）：本类的命中长度 > 比它短的那个类（$shorterCls）的命中长度。
 * 例：UA 含 `applebot-extended` ⇒ ai 命中 17 字符，se 只命中 `applebot` 8 字符 ⇒ 归 **AI**。
 *     UA 只是 `applebot` ⇒ ai 不命中（0），se 命中 8 ⇒ 归**搜索引擎**（与 r11 一致）。
 */
function statx_bot_has_longer_match(string $cls, string $ua, string $shorterCls = 'se'): bool
{
    return mb_strlen(statx_bot_longest($cls, $ua)) > mb_strlen(statx_bot_longest($shorterCls, $ua));
}

/**
 * ★★ r12x 新增：兜底「自称爬虫」识别 —— 名单没收录、但 UA 里**自证**是爬虫的那一类。
 *
 * 判据（可复现、极窄）：形如 `<名字>/<数字版本>`，且名字以 `bot` / `spider` / `crawler` 结尾。
 *   例：`Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ShapBot/0.1.0`
 *   ⇒ 取到 `shapbot`。
 *
 * ★ 为什么必须有它（而不是继续往名单里加名字）：**名单永远是滞后的**。实测 ShapBot /
 *   ExaSearchBot / Reflectionbot / AionBot 全都是这个形态，而且**都会执行 JS 并上报 /st** ——
 *   旧逻辑把这一整类算成真人，直接污染 PV/UV。加名字只能修今天看到的 4 个；这条规则修的是"这一形态"。
 *
 * ★ 为什么不误伤真人（回归证据见报告 §②）：真人浏览器 UA **从不**出现"名字+斜杠+数字版本"
 *   且名字以 bot/spider/crawler 结尾的形态。已对**全部真实样本**做过全量回归：
 *   · 168 条真实 /st 上报 UA（6,756 行加权）
 *   · 15,455 行三站 access log 的**去重 UA 全量**
 *   命中项 100% 是真爬虫；被判为真人的 UA 命中 0 条。
 *
 * ⚠️ 本函数只做**形态识别**，不做"官方 IP 段核验" ⇒ 它识别的是"自称是谁"，
 *    不等于"真的是谁"。站长要"已验证爬虫"需要另开一条出站拉取官方 IP 段的链路（本轮未做，见报告）。
 */
function statx_bot_selfclaimed(string $ua): string
{
    if (preg_match('~(?<![a-z0-9])([a-z0-9][a-z0-9._-]{0,30}?(?:bot|spider|crawler))/\d~i', $ua, $m)) {
        return mb_substr(mb_strtolower($m[1]), 0, 32);
    }
    return '';
}

/**
 * 三级分类。空 UA 单独处理；无任何浏览器内核标记的非空 UA 也按 other 计为机器人
 * （guardweb 的 is_scraper 有意保守，这里补上「连 Mozilla 都没有」这一档）。
 */
function statx_bot_classify(?string $ua = null): array
{
    $ua = $ua === null ? statx_bot_ua() : mb_strtolower(trim($ua));

    if ($ua === '') { return ['is_bot' => 1, 'cls' => 'empty', 'name' => '(empty-ua)']; }

    /* ★ r12：AI 里**更长、更专用**的特征要先判 —— 'applebot-extended' 里含 'applebot'，
       它属于 AI 训练爬虫，若先跑①的 se 名单就会把一条 AI 爬虫记成'搜索引擎'。
       只对 **ai 名单** 做这次前置；se / scraper 的顺序与 r11 **完全一致**。 */
    if (statx_bot_is('ai', $ua) && statx_bot_has_longer_match('ai', $ua)) {
        return ['is_bot' => 1, 'cls' => 'ai', 'name' => statx_bot_name('ai', $ua)];
    }
    foreach (['se', 'ai', 'scraper'] as $cls) {
        if (statx_bot_is($cls, $ua)) {
            return ['is_bot' => 1, 'cls' => $cls, 'name' => statx_bot_name($cls, $ua)];
        }
    }
    /* ★★ r12x：三张名单都没收录 —— 但 UA 自证是爬虫（`XxxBot/1.0` 形态）时归「采集器」，
       而不是继续算成真人。**刻意放在三张名单之后**：已收录的名字仍走上面的精确分支，
       因此既有爬虫的 bot_name / bot_cls 与 r11 **逐字节一致**（不会因为这条规则而漂移）。 */
    $self = statx_bot_selfclaimed($ua);
    if ($self !== '') { return ['is_bot' => 1, 'cls' => 'scraper', 'name' => $self]; }
    // 无浏览器内核标记 → 疑似脚本
    if (!preg_match('/(mozilla|applewebkit|gecko|chrome|safari|firefox|opera|edg)/', $ua)) {
        return ['is_bot' => 1, 'cls' => 'other', 'name' => mb_substr(preg_replace('/[^a-z0-9._\/-]/', '', $ua), 0, 28)];
    }
    return ['is_bot' => 0, 'cls' => 'human', 'name' => ''];
}

/** UA 粗分设备类型：desktop / mobile / tablet / bot */
function statx_device(?string $ua = null, ?bool $isBot = null): string
{
    $ua = $ua === null ? statx_bot_ua() : mb_strtolower(trim($ua));
    if ($isBot === null) { $isBot = (bool)statx_bot_classify($ua)['is_bot']; }
    if ($isBot) { return 'bot'; }
    if (preg_match('/(ipad|tablet|kindle|silk|playbook)/', $ua)
        || (strpos($ua, 'android') !== false && strpos($ua, 'mobile') === false)) {
        return 'tablet';
    }
    if (preg_match('/(iphone|ipod|android|windows phone|blackberry|opera mini|iemobile|mobile|fennec)/', $ua)) {
        return 'mobile';
    }
    return 'desktop';
}

/**
 * 来源归类：none（直达）/ in（站内）/ out（站外）。**本函数只产出「域名级」三元组。**
 *
 * ⚠️ r12x 澄清（旧注释写的是"只保留域名，**绝不存完整 Referer**" —— 与现行实现不符，极易误导
 *    后来的人以为"库里没有完整来路"，r12 排查时就被它带偏过一次）：
 *   · 完整 referrer **由 `endpoint.php::statx_ep_ref()` 单独清洗**（只接受 http/https、去控制字符、
 *     截断 500、r12x 起再剥敏感参数），写进 spool 第 9 列 `ref`，入库落 `hits.ref`；
 *   · 本函数只管 `ref_kind` / `ref_domain` 两列，**不参与** `ref` 列的写入。
 */
function statx_ref_split(string $referer, string $host): array
{
    $referer = trim($referer);
    if ($referer === '') { return ['kind' => 'none', 'domain' => '']; }
    $h = (string)parse_url($referer, PHP_URL_HOST);
    if ($h === '') { return ['kind' => 'none', 'domain' => '']; }
    $h = mb_strtolower($h);
    if (str_starts_with($h, 'www.')) { $h = substr($h, 4); }
    $self = mb_strtolower($host);
    if (str_starts_with($self, 'www.')) { $self = substr($self, 4); }
    if ($h === $self) { return ['kind' => 'in', 'domain' => $h]; }
    return ['kind' => 'out', 'domain' => mb_substr($h, 0, 128)];
}
