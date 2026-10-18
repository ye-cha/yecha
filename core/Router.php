<?php
declare(strict_types=1);

/**
 * Router：统一路由器（设计文档第 5.4 节顺序）
 * 解析顺序：固定路由 → 语言段(预留) → category/tag → 归档 → {slug}.html → 404
 * 伪静态关闭时路径为空、参数在 ?r=… 中，由 index.php 转译为 $path 后再进本类。
 */
final class Router
{
    /** 预留固定功能路径（内容 slug 不得占用，建 slug 时校验） */
    public const RESERVED = ['login', 'register', 'user', 'admin', 'feed', 'sitemap.xml', 'search', 'healthz', 'page', 'category', 'tag', 'archives', 'themes', 'plugins', 'uploads', 'install', 'mall', 'site', 'ai'];

    public static function resolve(string $path, array $query): array
    {
        $path = trim($path, '/');
        $seg = $path === '' ? [] : array_values(array_filter(explode('/', $path), fn($s) => $s !== ''));
        if (count($seg) === 0) {
            /* 补丁 14：把**原始**页码记号一并带出（策略判定统一在 index.php，见 Router::pageBeyond）。
               `?page=…` 是内核自带的「伪静态关闭」通道，同样要参与越界/非法判定。 */
            $qt = isset($query['page']) ? (string)$query['page'] : null;
            return ['kind' => 'home', 'page' => self::page($query), 'page_tok' => $qt];
        }
        $head = strtolower($seg[0]);

        // 固定路由
        if ($head === 'healthz') {
            return ['kind' => 'health'];
        }

        // 语言段（多语插件启用后才解析；关闭时语言前缀不生效、直接落到 404/普通路由）
        $langs = Poly::on() ? Poly::enabled() : [];
        if (in_array($head, $langs, true)) {
            return self::resolve(implode('/', array_slice($seg, 1)), $query + ['__lang' => $head]);
        }

        if ($head === 'category' || $head === 'tag') {
            if (!isset($seg[1])) {
                // 索引页：/tag/ 全标签、/category/ 全分类（导航 & SEO）
                return ['kind' => $head === 'tag' ? 'tags_index' : 'cats_index'];
            }
            /* 补丁 14：`page` 段存在就记录其**原始**记号（`/page/` 后无值记为空串 → 判非法），
               数值只在是**纯十进制正整数**时才采信。改前 `max(1,(int)…)` 把 page/0 与 page/-1
               静默折成第 1 页 → 同一份内容挂上无数个自指 canonical 的 URL。 */
            $page = 1;
            $ptok = null;
            if (($seg[2] ?? '') === 'page') {
                $ptok = isset($seg[3]) ? (string)$seg[3] : '';
                if ($ptok !== '' && ctype_digit($ptok)) { $page = max(1, (int)$ptok); }
            }
            return ['kind' => 'meta_list', 'type' => $head === 'category' ? 'category' : 'tag',
                    'slug' => $seg[1], 'page' => $page, 'page_tok' => $ptok];
        }

        if ($head === 'archives') {
            if (!isset($seg[1])) {
                return ['kind' => 'archives_overview'];
            }
            $y = (int)$seg[1];
            $m = isset($seg[2]) && is_numeric($seg[2]) ? (int)$seg[2] : 0;
            if ($y < 1970 || $y > 2200 || $m < 0 || $m > 12) {
                return ['kind' => '404']; // 非法年份/月份
            }
            /* 归档分页段（CORE-PATCH 补丁 11）：`/archives/YYYY/page/N/` 与 `/archives/YYYY/MM/page/N/`。
               月份段可选，故 page 段的下标随「有没有月份」而变（带月份 → seg[3]，不带 → seg[2]）。
               没有 page 段时 $page 恒为 1 —— 与补丁 11 之前**行为完全一致**
               （此前第 3/4 段一律被静默忽略，`/archives/2026/08/page/2/` 返回的是第 1 页内容，
               是该 URL 既是重复内容、又让「归档只能看前 200 条」的真因）。 */
            $page = 1;
            $ptok = null;
            $pi = isset($seg[2]) && is_numeric($seg[2]) ? 3 : 2;
            if (($seg[$pi] ?? '') === 'page') {
                $ptok = isset($seg[$pi + 1]) ? (string)$seg[$pi + 1] : '';
                if ($ptok !== '' && ctype_digit($ptok)) { $page = max(1, (int)$ptok); }
            }
            return ['kind' => 'archives_list', 'year' => $y, 'month' => $m, 'page' => $page, 'page_tok' => $ptok];
        }

        if ($head === 'search') {
            return ['kind' => 'search', 'q' => trim((string)($query['q'] ?? ''))];
        }

        // 前台登录/注册/退出
        if ($head === 'login') { return ['kind' => 'login']; }
        if ($head === 'register') { return ['kind' => 'register']; }
        if ($head === 'logout') { return ['kind' => 'logout']; }
        // 阅读计数上报（POST，前端按 访客+文章+自然日 去重）
        if ($head === 'count') { return ['kind' => 'count_hit']; }

        // RSS / sitemap（多语：/{lang}/feed 由语言段递归后落到 feed）
        if ($head === 'feed') { return ['kind' => 'feed']; }
        if ($head === 'sitemap' || $head === 'sitemap.xml') { return ['kind' => 'sitemap']; }
        if ($head === 'pages') { return ['kind' => 'pages_list']; }

        // 用户中心 / 动作端点（E2）
        if ($head === 'user') {
            $sub = $seg[1] ?? 'profile';
            if ($sub === 'api') {
                return ['kind' => 'user_api', 'api' => (string)($seg[2] ?? '')];
            }
            if (in_array($sub, ['mark', 'annotation', 'mark-del', 'annotation-del', 'favorite', 'comment', 'notify-read', 'logout', 'password'], true)) {
                return ['kind' => 'user_action', 'act' => $sub];
            }
            if ($sub === 'notifications') { return ['kind' => 'user_notifications']; }
            if ($sub === 'favorites') { return ['kind' => 'user_favorites']; }
            if ($sub === 'notes') { return ['kind' => 'user_notes']; }
            return ['kind' => 'user_profile'];
        }

        if ($head === 'page' && isset($seg[1])) {
            /* 补丁 14：只采集 `/page/N/` 的 N（含 abc / 0 / -1 等非法形态），判定交给 Router::pageBeyond。
               改前 `is_numeric()` 为假时直接落到 404（行为保留），为真时 `max(1,…)` 把 0/-1 折成第 1 页。 */
            $pt = (string)$seg[1];
            return ['kind' => 'home', 'page' => ctype_digit($pt) ? max(1, (int)$pt) : 1, 'page_tok' => $pt];
        }

        /* ---------- 插件多级路由前缀（内核合流 2026-09-26，来源：yecha-dev） ----------
           三个前缀在此收口为 kind=mall / site / ai，各自的分发实现在 index.php 对应 case 里；
           插件停用或未安装时，对应 case 会回落成 404（见 index.php）。 */
        // 商城插件（mall）：多级路径 /mall[/list/{cat}/|/goods/{id}.html|/cart|...]，分发见 index.php case 'mall'
        if ($head === 'mall') {
            return ['kind' => 'mall', 'path' => array_values(array_slice($seg, 1))];
        }

        // 企业官网插件（site）：多级路径 /site[/products|/product/{id}.html|/cases|...]，分发见 index.php case 'site'
        if ($head === 'site') {
            return ['kind' => 'site', 'path' => array_values(array_slice($seg, 1))];
        }

        // AI 能力插件（ai）：/ai/api/chat 客服 JSON 接口，分发见 index.php case 'ai'
        if ($head === 'ai') {
            return ['kind' => 'ai', 'path' => array_values(array_slice($seg, 1))];
        }

        // 站内文档 / 更新日志：干净 URL 直达同名内容页（既有 /docs.html、/changelog.html 不变）
        // t38：菜单用 /docs、/changelog；内容仍是普通内容页（page），沿用内容/译文的既有机制。
        if (in_array($head, ['docs', 'changelog'], true) && count($seg) === 1) {
            return ['kind' => 'post', 'slug' => $head];
        }

        // {slug}.html（文章/页面统一）
        $last = end($seg);
        if (is_string($last) && str_ends_with(strtolower($last), '.html') && count($seg) === 1) {
            $slug = substr($last, 0, -5);
            if ($slug === '' || in_array(strtolower($slug), self::RESERVED, true)) {
                return ['kind' => '404'];
            }
            return ['kind' => 'post', 'slug' => $slug];
        }

        // 第三方插件独立页：/{slug}[/...]（t38：允许二级路径，插件内自行解析子路径；首段命中即分发）
        if ($path !== '' && count($seg) >= 1) {
            $plugPages = class_exists('Plugins', false) ? Plugins::pages() : [];
            if (isset($plugPages[$seg[0]])) {
                return ['kind' => 'plugin_page', 'slug' => $seg[0], 'path' => array_values(array_slice($seg, 1))];
            }
        }

        return ['kind' => '404'];
    }

    /**
     * 补丁 14：越界 / 非法页码的统一判据 —— **策略只在这一处实现**。
     *
     * 背景：改前越界页一律 200 + **自指 canonical**（空页被当成独立页提交给搜索引擎），
     * 而 `page/0`、`page/-1`、`page/abc` 又被 `max(1,(int)…)` 静默折成第 1 页 →
     * 同一份内容挂上无数个 URL。站长已决定策略：**越界与非法页码都应答 404**。
     *
     * @param string|null $tok        URL 里出现的**原始**页码记号（null = 该请求根本没带页码 → 合法）
     * @param int         $page       内核解析出的有效页码
     * @param int         $totalPages 该列表的真实总页数（**必须与页面上分页器同源**）
     * @return bool true = 该页码请求不成立
     *
     * 调用点：index.php 的统一收口（core 列表页）与 plugins/gushuauthors/page.php（插件列表页，
     * 命中后返回 null 走内核既有 plugin_page 404 分支）。判据只有这一份，不在别处重写。
     */
    public static function pageBeyond(?string $tok, int $page, int $totalPages): bool
    {
        if ($tok !== null) {
            /* 带了页码记号就必须是**纯十进制正整数**：拒 0 / -1 / +1 / 1.5 / 1e3 / abc / 空串 / 带空白。
               （超长数字串走 ctype_digit 为真、(int) 饱和到 PHP_INT_MAX → 由下面的范围判定收掉。） */
            if ($tok === '' || ctype_digit($tok) !== true || (int)$tok < 1) { return true; }
        }
        return $page > $totalPages;
    }

    /**
     * 补丁 15：`…/page/1/` 是「不带页码的同一 URL」的另一种写法 —— **判据只在这一处**。
     *
     * 与 `Router::pageBeyond()` 互补、互斥、不重叠：
     *   · `pageBeyond()` 收「页码**不成立**」（非法记号 / 越界）→ 让调用方答 404；
     *   · 本函数收「页码成立**且等于 1**」→ 让调用方 301 到规范形态（第 1 页没有独立价值）。
     * 实测（2026-09-13）7 组 `X/page/1/` 与 `X/` 的**正文区 md5 逐字节相同**，
     * 而除归档（补丁 11-C 已在主题侧折掉 page/1）外主题自算 canonical 都是**自指**
     * `…/page/1/` → 站级重复入口；分页器的「首頁 / 上一頁」也确实链到 `…/page/1`。
     *
     * @param string|null $tok  URL 里出现的**原始**页码记号（null = 根本没带页码 → 无重复）
     * @param int         $page 内核解析出的有效页码
     * @return bool true = 这是「第 1 页的另一种写法」，应 301 归一
     */
    public static function pageOne(?string $tok, int $page): bool
    {
        if ($tok === null || ctype_digit($tok) !== true) { return false; }
        return (int)$tok === 1 && $page === 1;
    }

    /**
     * 补丁 15：把「页码写在路径里」的请求折到**不带页码**的规范形态。
     *
     * 只对**尾部**做手术：语言前缀（`/tw/`）与路径里的百分号编码**原样保留**，
     * 不 decode、不 re-encode —— 这样 301 的 Location 与主题自算的 canonical
     * **逐字节一致**（实测 canonical 就是 `/category/%E8%AF%97/` 这种已编码形态）。
     * 大小写敏感，与 Router 的路由判定一致（Router 只认小写 `page` 段）。
     *
     * @param string $rawPath 原始请求路径（`parse_url(REQUEST_URI, PHP_URL_PATH)`，**未解码**）
     * @return string|null null = 该路径**不以 `/page/1` 结尾**（例如 `/?page=1`、
     *                     `/x/page/1/extra/`）→ 无法安全构造目标，调用方**不跳转**
     */
    public static function pageOneTarget(string $rawPath): ?string
    {
        $t = rtrim($rawPath, '/');
        if ($t === '' || !str_ends_with($t, '/page/1')) { return null; }
        $base = substr($t, 0, -7);                    // 去掉尾部 '/page/1'
        return $base === '' ? '/' : rtrim($base, '/') . '/';
    }

    public static function page(array $query): int
    {
        return max(1, (int)($query['page'] ?? 1));
    }

    /**
     * 补丁 16：**首页没有「页」** —— `/page/N/`（含 `?page=N`）在首页上恒定是同一份内容。
     *
     * 背景（2026-09-13 EXTRA3 实测）：`/`、`/page/2/`、`/page/3/`、`/page/4/`、`/tw/page/2/`
     * 的 `<main>` 正文区 md5 **逐字节相同**（`c0452d417696`，40,771 B），而它们的 canonical
     * 全是**自指**（`https://gushuwu.com/page/2/` …）→ 一个无上界的重复内容 URL 群。
     *
     * 为什么相同（读代码得到的根因，非猜测）：
     *   · `option home.landing = 1` → index.php 的 `home` 分支对 `$page <= 1` **直接**渲染
     *     `views/landing.php`（门户落地页），**根本不调用** `Front::home()`；
     *   · `$page >= 2` 才走 `Front::home($page)` → `partials/list` → `partials/list.php` 判
     *     `^page/\d+$` 为真 → 仍然渲染**同一个门户视图**；
     *   · 而该门户视图通篇**不读** `$posts` / `$page` / `$total_pages` / `$pager_base`
     *     （实扫 grep 命中 0 行），它自己调 `gsh_home_recent()` 等固定查询。
     *   → 首页被**设计成不翻页的门户落地页**，页码参数从头到尾被丢弃，页面上也不渲染分页器。
     *     两条分支只是**碰巧**吐出同一份 HTML，所以「翻了等于没翻」。
     *
     * 策略（按实测证据选，与补丁 15 同族）：**301 归一**，不做「真翻页」。
     *   门户落地页没有文章流可分页（要造列表等于重做首页版式，超出「首页分页逻辑」的边界）；
     *   补丁 15 已为 `/page/1/` 立下同一先例。归一后每个 `/page/N/` **一跳**直达首页，
     *   不产生链式跳转，也不留「翻了没变」的重复内容页。
     *
     * 与补丁 14 `pageBeyond()` 的关系：`pageBeyond()` 判的是「**真实分页列表**的第 N 页不存在」
     *   （分类 / 标签 / 归档 / `/pages`），首页**根本没有列表**，故本函数不做页数范围判定 ——
     *   只要带了页码记号就归一。`/page/<非数字>` 由 Router 判成 `kind=home` + `page_tok=<非数字>`，
     *   同样落进本函数 → 也是 301（此前这些形态因 `home.landing` 分支不设置 `$d` 而**逃过**
     *   `pageBeyond()`、以 200 + 自指 canonical 直出，见 EXTRA3 报告）。`/page/`（`page/` 后无值）
     *   Router 判成 `kind=404`，不经过本函数，行为不变。
     *
     * @param string|null $tok URL 里出现的**原始**页码记号（null = 该请求没带页码 → 不归一）
     * @return bool true = 这是「首页的另一种带页码写法」，应 301 归一
     */
    public static function homePage(?string $tok): bool
    {
        return $tok !== null;
    }

    /**
     * 补丁 16：把「首页 + 页码写在路径里」的请求折到**不带页码**的规范形态。
     *
     * 只认两种形状：`/page/N` 与 `/<lang>/page/N`（N 原样保留、不校验是否为数字 —— 是不是
     * 数字都不改变「首页没有页」这个结论）。与补丁 15 `pageOneTarget()` 一样**只做尾部手术**：
     * 语言前缀原样保留、百分号编码既不 decode 也不 re-encode，使 301 的 Location 与主题
     * 自算的 canonical **逐字节一致**。大小写敏感（Router 只认小写 `page` 段）。
     *
     * @param string $rawPath 原始请求路径（`parse_url(REQUEST_URI, PHP_URL_PATH)`，**未解码**）
     * @return string|null null = 该路径不是「首页 + /page/N」形状 → 调用方**不跳转**
     */
    public static function homePageTarget(string $rawPath): ?string
    {
        $t = rtrim($rawPath, '/');
        if (preg_match('#^(/[A-Za-z][A-Za-z0-9_-]{0,7})?/page/[^/]+$#', $t, $m) !== 1) { return null; }
        $base = (string)($m[1] ?? '');
        return $base === '' ? '/' : $base . '/';
    }
}
