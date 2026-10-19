<?php
/** 前台单一入口（伪静态由 Nginx 转发到这里） */
declare(strict_types=1);

// 尚未安装 → 安装向导
if (!is_file(__DIR__ . '/config.inc.php')) {
    header('Location: /install/');
    exit;
}

require __DIR__ . '/config.inc.php';
require __DIR__ . '/core/bootstrap.php';
Plugins::boot(); // 第三方插件（Hooks / 侧栏块 / 独立页）

/* 定时文章到点自动上架 */
content_publish_due();

/* ---------- 解析请求路径（伪静态关闭时支持 ?r=…） ---------- */
function ye_request_path(): string
{
    if (isset($_GET['r'])) {
        return trim((string)$_GET['r'], '/');
    }
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') { $path = '/'; } // '//'、'///x' 等 parse_url 返回 false 的畸形路径兜底
    $path = rawurldecode($path);
    // 去掉入口文件名与多余斜杠
    if (preg_match('#/index\.php(/|$)#', $path, $m)) {
        $path = substr($path, strlen($m[0]) - (str_ends_with($m[0], '/') ? 1 : 0));
    }
    return trim($path, '/');
}

$path    = ye_request_path();
$query   = $_GET;
unset($query['r']);
$route   = Router::resolve($path, $query);
/* 根目录归属（内核合流 2026-09-26，来源：yecha-dev index.php）：
   企业官网（官网模式，插件 site 声明）优先接管站点首页；否则沿用「商城上根目录」（插件 mall）；
   都没有则回退博客首页。三态可逆，且**必须在补丁 15/16 的 /page/N/ 归一之前**判定 ——
   官网接管时 kind 已改写成 site，首页那两条 301 自然不再命中（官网自己有路由语义）。 */
if (($route['kind'] ?? '') === 'home') {
    if (Plugins::isOn('site') && class_exists('Site') && Site::isOfficial() && (int)($route['page'] ?? 1) <= 1) {
        $route = ['kind' => 'site', 'path' => []];
    }
    // [2026-09-27 官网恢复 · 2026-09-27 整版升级时保留] 商城（mall）不再接管站点首页（/），官网 landing 首页回归；
    // 商城自身路由（/mall、/list.html、/goods-<id>.html…）与商城数据表均不受影响（数据保留在库）。
    // ★★★ 此行影响官网首页归属：去掉下面 3 行注释会静默把首页换成商城 ★★★
    // 回滚：去掉下面 3 行注释即可恢复「商城上根目录」。
    // } elseif (Plugins::isOn('mall') && class_exists('Mall')) {
    //     $route = ['kind' => 'mall', 'path' => []];
    // }
}
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* 语言识别：/{en}/… 前缀 → en；其余为默认语言。界面语言包随当前语言切换（zh_CN/en_US）。
   多语插件关闭时不识别语言前缀、始终为默认语言（前台零多语痕迹）。 */
$pathLang = Poly::defaultLang();
$firstSeg = strtok($path, '/');
if (Poly::on() && in_array($firstSeg, Poly::enabled(), true) && $firstSeg !== Poly::defaultLang()) {
    $pathLang = (string)$firstSeg;
}
Lang::switch(Poly::pack($pathLang));

/* ---------- 补丁 15：`…/page/1/` → 301 到不带页码的规范 URL（**判据只有一份**）----------
   背景（2026-09-13 实测）：`/category/x/page/1/` 与 `/category/x/`、`/tag/x/page/1/` 与 `/tag/x/`、
   `/tag/<轴>/page/1/` 与 `/tag/<轴>/`、`/archives/2026/08/page/1/` 与 `/archives/2026/08/`、
   `/page/1/` 与 `/`（含 `/tw/` 前缀，共 7 组实测）**正文区 md5 逐字节相同** —— 同一内容的两种 URL；
   而除归档（补丁 11-C 已在主题侧折掉 page/1）外，主题自算 canonical 都是**自指** `…/page/1/`，
   等于把「第 1 页的另一种写法」当正式页提交给搜索引擎 → 站级重复入口。而分页器的
   「首頁 / 上一頁」确实会链到 `…/page/1`（实测 `/tw/archives/2026/08/page/2/` 页面源码），
   所以它真的会被抓到，不是理论风险。
   站长已定策略：page/1 没有独立价值 → **301 归一**（不选「只改 canonical」，更不选 404）。

   判据与第 2 批 `Router::pageBeyond()`（越界/非法 → 404）**同一处收口、同一份实现**：
   `Router::pageOne()` + `Router::pageOneTarget()`。两者互斥：`pageBeyond()` 为真时走 404 分支，
   不会再进这里。

   ⚠️ 为什么这个调用点在**全页缓存读取之前**：
   缓存命中分支是 `echo $cached; exit;`，把归一放在它之后，对**已缓存成 200 的** page/1 URL
   完全不生效。而实测这些 URL 极易被缓存 —— 本补丁改前的基线探测里 7 条 page/1 响应
   全是 `X-Cache: MISS`，**探测本身就把它们以 200 写进了全页缓存**（回测立刻变 HIT）。
   前置之后**不需要清缓存**：301 根本不读缓存，旧条目自然过期，不存在半新半旧。
   `/tag/<轴>/page/N/`（插件列表页）的页码记号只在 `$pm` 里（boot.php 把 `axp` 透传，
   Router 侧看不到 page 段），故在下面第 2 批的收口处再补一次调用；
   `plugin_page` 不在 `$cacheable` 名单里，那里不存在缓存短路问题。

   ⚠️ 边界：只处理**页码写在路径里**的形态；查询串形态 `?page=1` / `?p=1`、以及
   `/pages/page/1/` 这类混合形态本次不动（见 /root/gushuwu-EXTRA2-REPORT.md「未做项」）。 */
function yc_page1_redirect(): bool
{
    $rawPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
    $target  = Router::pageOneTarget($rawPath);
    if ($target === null) { return false; }          // 不以 /page/1 结尾 → 不跳转（如 /?page=1）
    $qs = (string)($_SERVER['QUERY_STRING'] ?? '');  // 查询串**原样保留**，不丢参数
    header('Location: ' . $target . ($qs !== '' ? '?' . $qs : ''), true, 301);
    return true;
}
if (($method === 'GET' || $method === 'HEAD')
    && Router::pageOne($route['page_tok'] ?? null, (int)($route['page'] ?? 1))) {
    if (yc_page1_redirect()) { exit; }
}

/* ---------- 补丁 16：首页 `/page/N/` → 301 到首页（**判据只有一份**）----------
   背景（2026-09-13 EXTRA3 实测）：`/`、`/page/2/`、`/page/3/`、`/page/0/`、`/?page=2`、`/tw/page/2/`
   的 `<main>` 正文区 md5 **逐字节相同**（`c0452d417696`），字节数同为 40,771 —— 首页「翻了等于没翻」；
   而 `/page/2/` 的 canonical 是**自指** `https://gushuwu.com/page/2/`，等于把这个无上界的
   重复 URL 群主动提交给搜索引擎。页面上**根本没有分页器**（实扫 `href="…page…"` 命中 0 条）。

   根因（读代码得出，见 core/Router.php::homePage() 的完整说明）：`option home.landing=1` 时
   首页是**门户落地页**，`$page <= 1` 与 `$page >= 2` 两条分支渲染的是同一个门户视图，
   而该视图**完全不读** `$posts` / `$page` / `$total_pages` / `$pager_base`。

   站长已定策略（任务原文）：首页若被设计成不翻页 → **`/page/N/` 一律 301 到首页**，
   不留「翻了没变」的重复内容页。本补丁照此实现，与补丁 15 同族（`page/1` 已经 301）。

   ⚠️ 为什么这个调用点必须**在全页缓存读取之前**（与补丁 15 完全相同的理由）：
   缓存命中的分支是 `echo $cached; exit;`，把归一放在它之后，对**已经缓存成 200 的**
   `/page/2/`、`/page/0/`、`/?page=2` 完全不生效 —— 而实测这三条响应头本来就是 `X-Cache: HIT`，
   即早被探测/抓取写进了全页缓存。前置之后**根本不需要清缓存**：301 不读缓存，
   旧条目到期自然消失，不存在半新半旧。

   ⚠️ 边界：只处理 **kind === 'home'** 的请求。分类 / 标签 / 归档 / `/pages` 的**真实分页**
   （`…/page/2/` 确有第 2 页内容）一律不受影响，仍由补丁 14 的 `pageBeyond()` 收口越界 404。
   `/page/`（`page/` 后无值）Router 判成 `kind=404`，不经过这里，行为不变。 */
function yc_home_page_redirect(): bool
{
    $rawPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
    $qs      = (string)($_SERVER['QUERY_STRING'] ?? '');   // 查询串**原样保留**，不丢参数
    $target  = Router::homePageTarget($rawPath);
    if ($target !== null) {                                // A. 路径形态：`/page/N/`、`/tw/page/N/`
        header('Location: ' . $target . ($qs !== '' ? '?' . $qs : ''), true, 301);
        return true;
    }
    if ($qs === '') { return false; }                      // B. 查询串形态：`/?page=N`
    parse_str($qs, $q);
    if (!isset($q['page'])) { return false; }              // 没带 page → 不归本站管
    unset($q['page']);                                     // 只摘掉 page，其余参数原样重建
    $rest = http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    $base = ($rawPath === '' ? '/' : $rawPath);
    header('Location: ' . $base . ($rest !== '' ? '?' . $rest : ''), true, 301);
    return true;
}
if (($method === 'GET' || $method === 'HEAD')
    && ($route['kind'] ?? '') === 'home'
    && Router::homePage($route['page_tok'] ?? null)) {
    if (yc_home_page_redirect()) { exit; }
}

/* ---------- 动作/认证端点（POST 优先处理，避免被缓存逻辑覆盖） ---------- */
if ($route['kind'] === 'logout') {
    Auth::logout();
    header('Location: /');
    exit;
}
if ($route['kind'] === 'user_action') {
    Uc::act($route['act']);
}

/* 阅读计数上报：POST /count body content_id=…；append 入 ye_views（前端按访客+文章+自然日去重）。
   同源轻校验即可（计数无敏感副作用，接受可刷，数值准确性交给 ye_views 聚合）。 */
if ($route['kind'] === 'count_hit') {
    header('Content-Type: application/json; charset=utf-8');
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'method']);
        exit;
    }
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($ref !== '' && $host !== '' && stripos($ref, $host) === false) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'origin']);
        exit;
    }
    $cid = (int)($_POST['content_id'] ?? 0);
    if ($cid <= 0) {
        echo json_encode(['ok' => false, 'error' => 'id']);
        exit;
    }
    $ok = Db::rows('SHOW TABLES LIKE "' . Db::t('views') . '"');
    if ($ok) {
        Db::run('INSERT INTO ' . Db::t('views') . ' (day, content_id, n) VALUES (CURDATE(), ?, 1) ON DUPLICATE KEY UPDATE n = n + 1', [$cid]);
    }
    // 返回最新总数（已验证总数 = contents.views + 尚未汇总的 ye_views 明细），供前端即时刷新
    $base = (int)Db::one('SELECT views FROM ' . Db::t('contents') . ' WHERE id=?', [$cid]);
    $pend = 0;
    if ($ok) {
        $pend = (int)Db::one('SELECT COALESCE(SUM(n),0) FROM ' . Db::t('views') . ' WHERE content_id=?', [$cid]);
    }
    echo json_encode(['ok' => true, 'views' => $base + $pend]);
    exit;
}
/* 图库详情页下载计数：POST /svg-dl slug=…（同源轻校验，纯计数，无副作用） */
if ($path === 'svg-dl' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $slug = trim((string)($_POST['slug'] ?? ''));
    if ($slug === '') {
        echo json_encode(['ok' => false, 'error' => 'slug']);
        exit;
    }
    if (is_file(__DIR__ . '/plugins/svg/plugin.php')) {
        $T = Db::t('svg_items');
        Db::run("UPDATE {$T} SET downloads=downloads+1 WHERE slug=? AND active=1", [$slug]);
    }
    echo json_encode(['ok' => true]);
    exit;
}
if ($route['kind'] === 'user_api') {
    if (!Auth::check()) { Uc::needLogin(); }
    header('Content-Type: application/json; charset=utf-8');
    if ($route['api'] === 'marks') {
        $uid = Auth::id();
        $cid = (int)($_GET['content_id'] ?? 0);
        $rows = Db::rows(
            'SELECT m.anchor_ref, m.quote, m.color, m.id FROM ' . Db::t('marks') . ' m WHERE m.user_id=? AND m.content_id=? ORDER BY m.id ASC',
            [$uid, $cid]
        );
        $ann = Db::rows('SELECT anchor_ref, body, mark_id, quote FROM ' . Db::t('annotations') . ' WHERE user_id=? AND content_id=? ORDER BY id ASC', [$uid, $cid]);
        echo json_encode(['marks' => $rows, 'annotations' => $ann], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => false, 'error' => 'unknown api'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
if (in_array($route['kind'], ['login', 'register'], true) && $method === 'POST') {
    Uc::authPost($route['kind']);
}

/* ---------- 博客关闭模式：博客专属路由不再提供（图库/纯插件站） ---------- */
if ((string)get_option('blog.disabled', '0') === '1' && in_array($route['kind'], ['meta_list', 'tags_index', 'cats_index', 'archives_overview', 'archives_list', 'search', 'pages_list', 'feed'], true)) {
    $lp = ($pathLang !== Poly::defaultLang() ? '/' . $pathLang : '');
    header('Location: ' . ($lp !== '' ? $lp : '/'), true, 301);
    exit;
}

/* ---------- RSS / sitemap（XML 直出，多语 + hreflang） ---------- */
if (in_array($route['kind'], ['feed', 'sitemap'], true)) {
    header('Content-Type: application/xml; charset=utf-8');
    echo Feed::render($route['kind'], $pathLang);
    exit;
}

/* ---------- 全页缓存（未登录 GET 页面；X-Cache 观测） ---------- */
$cacheable = in_array($route['kind'], ['home', 'post', 'meta_list', 'archives_overview', 'archives_list', 'search', 'cats_index', 'tags_index', 'pages_list'], true)
    && empty($_SERVER['HTTP_X_YC_NOCACHE'])
    && !Auth::check()
    && $method === 'GET';

/* 皮肤 cookie：主题（如 gushu）按 cookie 输出 <html data-skin>，首屏 HTML 随 cookie 变化，
   缓存键必须分桶，否则先到的访客会把默认皮肤钉给所有人。见 themes/gushu/CORE-PATCH.md */
$skinKey = '';
if (isset($_COOKIE['gushu_skin'])) {
    $skinKey = (string)preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$_COOKIE['gushu_skin']);
    if (strlen($skinKey) > 24) { $skinKey = substr($skinKey, 0, 24); }
}

/* 同上：站点级明暗（cookie gushu_theme）也改变首屏 HTML，缓存键一并分桶 */
$themeKey = '';
if (isset($_COOKIE['gushu_theme'])) {
    $themeKey = (string)preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$_COOKIE['gushu_theme']);
    if (strlen($themeKey) > 24) { $themeKey = substr($themeKey, 0, 24); }
}

/* ---------- 全页缓存 TTL：**必须有界**（体检 P0-3，2026-09-13） ----------
   pages 命名空间的失效原本**完全**依赖事件钩子 Cache::flush('pages')（后台 / Poly / helpers），
   而繁译、批量导入等 out-of-band 脚本（如 /root/gushu-tw-fill/）不经过这些钩子：
   它们直连 DB 写 ye_poly_contents 后从不 flush。由于命中的分支是 echo 后直接 exit，
   页面 HTML 会连同内嵌的统计数字**永久定格** —— 2026-09-13 实测：简体首页第五格
   「繁體可讀」长期停在 871,227（真值 910,727），而 /tw/ 首页因缓存键是繁译之后才新建的
   所以显示正确值 → **同一指标、简体页与繁体页给出不同数字**。
   这里给出上界：任何漏掉的失效钩子最多陈旧 $pageTtl 秒；要立刻生效则由收尾脚本调用
   tools/cache-invalidate.php 显式失效（配合使用，见 themes/gushu/CORE-PATCH.md 补丁 13）。
   ⚠️ Cache::set() 的 ttl<=0 语义是「永不过期」，故下限强制 60s。
   想调：后台选项 cache.pages_ttl（秒，60–86400），默认 3600 —— 与 gsh_home_stats()
   的 misc 统计缓存 TTL 对齐，使统计条最坏陈旧度是一个干净的 1 小时。 */
$pageTtl = 3600;
if (function_exists('get_option')) {
    $__pt = (int)get_option('cache.pages_ttl', '');
    if ($__pt > 0) { $pageTtl = max(60, min(86400, $__pt)); }
}

if ($cacheable) {
    $qs = isset($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    $hostKey = strtolower((string)($_SERVER['HTTP_HOST'] ?? '')); // 缓存键含 Host，防 Host 头投毒串页
    $cacheKey = 'page:' . $hostKey . ':' . $path . $qs . ':skin=' . $skinKey . ':theme=' . $themeKey;
    $cached = Cache::get('pages', $cacheKey);
    if ($cached !== false) {
        header('X-Cache: HIT');
        header('Vary: Cookie, Host');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $cached;
        exit;
    }
}

/* ---------- 渲染入口（按页面类型读取右栏布局配置，分功能块组装） ---------- */
function yc_side_box(string $title, string $inner, string $headLink = ''): string
{
    $h = $headLink !== '' ? '<a href="' . e($headLink) . '">' . e($title) . '</a>' : e($title);
    return '<div class="side-box"><h4>' . $h . '</h4>' . $inner . '</div>';
}

/* ---------- 门户落地页（option home.landing=1）：主题没有落地页模板则不接管 ----------
 * ● 2026-09-27 landing 守卫
 * 起因：`home.landing=1` 时内核**无条件**渲染 `landing`，而 `View::render()` 找不到模板就抛
 * `RuntimeException('模板不存在: …')` ⇒ **少一个模板文件就把首页打死**（演示主题 `zhiku` 正缺
 * `views/landing.php`，26 套里唯一一套）。
 * 修法：主题自带 `views/landing.php` 时才走落地页；没有则**不进入落地页分支**，
 * 首页自然回落到下面的普通博客首页（文章流）—— 这才是"没有落地页"的正确语义。
 * 说明：`core/View.php` 另有通用回落（缺模板即回落到默认主题 yecha）作为兜底；两层不冲突：
 * 这一层决定"**要不要用落地页这个形态**"，那一层决定"**缺部件时去哪儿找**"。
 * 未改基线主题 themes/yecha 一个字节。 */
function yc_landing_html(string $theme): ?string
{
    if (!is_file(dirname(__FILE__) . '/themes/' . $theme . '/views/landing.php')) {
        @error_log('[home] 主题 "' . $theme . '" 无 views/landing.php，首页不启用落地页模式（回落为文章流）');
        return null;
    }
    if (!class_exists('Db')) { return null; }
    // 排序键必须走有序索引：COALESCE(published_at, created_at) 会让索引失效（1.0M+ 行时实测 3060ms
    // 并撞 max_execution_time → 首页 500）。用 published_at DESC, id DESC 对齐既有索引。
    // NULL 取舍：DESC 把 NULL 排最后 = 视为「最早」，正是期望语义（实测全表 NULL = 0 行）。
    $rows = Db::rows('SELECT title, slug, published_at, created_at FROM ' . Db::t('contents')
        . " WHERE type='post' AND status='publish' AND deleted_at IS NULL ORDER BY published_at DESC, id DESC LIMIT 4");
    $posts = [];
    foreach ($rows as $p) {
        $posts[] = ['title' => $p['title'], 'slug' => $p['slug'],
                    'published_at' => (string)($p['published_at'] ?: $p['created_at'])];
    }
    $cb = 0;
    if (Db::rows('SHOW TABLES LIKE "' . Db::t('links') . '"')) {
        $cb = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('links') . " WHERE status='show'");
    }
    $cp = 0;
    if (Db::rows('SHOW TABLES LIKE "' . Db::t('circle_posts') . '"')) {
        $cp = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('circle_posts'));
    }
    $GLOBALS['YC_NO_SIDE'] = true;
    $GLOBALS['YC_SB_TYPE'] = 'page';
    $tpl = 'landing';
    $lg = Lang::short();
    if (Poly::on() && $lg !== Poly::defaultLang()
        && is_file(dirname(__FILE__) . '/themes/' . $theme . '/views/landing-' . $lg . '.php')) {
        $tpl = 'landing-' . $lg; // t38：非默认语言用同目录 landing-<lang>.php（没有则回落 landing.php）
    }
    return View::render($tpl, ['posts' => $posts, 'circle_blogs' => $cb, 'circle_posts' => $cp]);
}

function render_page(string $title, string $mainHtml): string
{
    global $pathLang;
    $tocSide = false;
    $side = '';
    $isDefLang = !(Poly::on() && Lang::short() !== Poly::defaultLang());
    // 插件可声明“门户页”：无右栏、通栏内容（如博客收录圈）
    $noSide = !empty($GLOBALS['YC_NO_SIDE']);
    $tp = $GLOBALS['YC_SB_TYPE'] ?? 'post';
    $raw = json_decode((string)get_option('theme.sidebar', ''), true);
    $cfgAll = is_array($raw) ? $raw : [];
    $cur = $cfgAll[$tp] ?? null;
    if ($noSide) {
        $show = false;
        $blocks = [];
    } elseif ($cur === null) {
        $show = theme_opt('show_sidebar', '1') !== '0';
        $blocks = ['search', 'cats', 'latest', 'tags'];
    } else {
        $show = ($cur['show'] ?? '1') !== '0';
        $blocks = is_array($cur['blocks'] ?? null) ? $cur['blocks'] : ['search', 'cats', 'latest', 'tags'];
    }

    if ($show) {
        $pre = '';
        if (!empty($GLOBALS['YC_TOC'])) {
            $pre = View::render('partials/toc-side', ['items' => $GLOBALS['YC_TOC']]);
            $tocSide = true;
        }
        if ($isDefLang) {
            $sb = Front::sidebar();
            $html = $pre;
            foreach ($blocks as $b) {
                if ($b === 'search') {
                    $html .= yc_side_box(__('sb.search'),
                        '<form class="side-search" method="get" action="/search" role="search">'
                        . '<input type="search" name="q" placeholder="' . e(__('sb.search_ph')) . '" aria-label="' . e(__('sb.search_aria')) . '">'
                        . '<button type="submit">' . e(__('sb.go')) . '</button></form>');
                } elseif ($b === 'blogger') {
                    $nick = trim((string)get_option('blogger.nick', ''));
                    $bio = (string)get_option('blogger.bio', '');
                    $inner = ($nick !== '' ? '<div class="side-nick" style="font-weight:700;margin-bottom:6px;">' . e($nick) . '</div>' : '')
                        . ($bio !== '' ? '<div class="side-bio">' . e(Str::excerpt($bio, 60)) . '</div>' : '');
                    if ($nick !== '' || $bio !== '') { $html .= yc_side_box(__('sb.blog'), $inner); }
                } elseif ($b === 'cats') {
                    $li = '';
                    foreach ($sb['cats'] as $c) {
                        $li .= '<li><a class="side-cat" href="/category/' . e((string)$c['slug']) . '/"><span>' . e($c['name']) . '</span><span>' . (int)$c['count'] . '</span></a></li>';
                    }
                    if ($li !== '') { $html .= yc_side_box(__('sb.cats'), '<ul class="side-list">' . $li . '</ul>', '/category/'); }
                } elseif ($b === 'latest') {
                    $li = '';
                    foreach ($sb['latest'] as $p) {
                        $li .= '<li><a href="/' . e((string)$p['slug']) . '.html">' . e(Str::excerpt((string)$p['title'], 30))
                            . '<span class="d">' . e(lang_date(strtotime((string)$p['dt']))) . '</span></a></li>';
                    }
                    if ($li !== '') { $html .= yc_side_box(__('sb.latest'), '<ul class="side-list">' . $li . '</ul>'); }
                } elseif ($b === 'tags') {
                    $li = '';
                    foreach ($sb['tags'] as $t) {
                        $w = (int)$t['count'] >= 12 ? 5 : ((int)$t['count'] >= 6 ? 4 : ((int)$t['count'] >= 3 ? 3 : ((int)$t['count'] >= 2 ? 2 : 1)));
                        $li .= '<li><a class="tw tw' . $w . '" href="/tag/' . e((string)$t['slug']) . '/">' . e($t['name']) . '</a></li>';
                    }
                    if ($li !== '') { $html .= yc_side_box(__('sb.tags'), '<ul class="side-list side-tags">' . $li . '</ul>', '/tag/'); }
                } elseif ($b === 'archives') {
                    $li = '';
                    foreach ($sb['months'] as $mm) {
                        $li .= '<li><a class="side-cat" href="/archives/' . (int)$mm['y'] . '/' . (int)$mm['m'] . '/"><span>' . e(lang_month((int)$mm['y'], (int)$mm['m'])) . '</span><span>' . (int)$mm['n'] . '</span></a></li>';
                    }
                    if ($li !== '') { $html .= yc_side_box(__('sb.arch'), '<ul class="side-list">' . $li . '</ul>'); }
                } else {
                    // 第三方插件块（页面布局勾选 id=plug:<插件>:<块>）
                    $ph = Plugins::renderSideBlock($b);
                    if ($ph !== '') { $html .= $ph; }
                }
            }
            $side = $html;
        } else {
            // 译文界面：轻量侧栏（搜索 / 博主 / 该语言最新译文），目录置顶
            $side = $pre . View::render('partials/sidebar-lang', ['posts' => Poly::publishedList(Lang::short(), (int)theme_opt('latest_n', 8))]);
        }
    }
    /* YC_HTML_LANG：允许插件（如企业官网多语言）覆盖 <html lang>，未设置时沿用原逻辑。
       （内核合流 2026-09-26，来源：yecha-dev index.php；主题 layout 本就有 $htmlLang 位） */
    $htmlLang = (string)($GLOBALS['YC_HTML_LANG'] ?? '');
    if ($htmlLang === '') { $htmlLang = ['zh' => 'zh-CN', 'tw' => 'zh-TW'][$pathLang] ?? $pathLang; }
    return View::render('layout', ['title' => $title, 'main' => $mainHtml, 'side' => $side, 'tocSide' => $tocSide, 'sbSticky' => ((string)get_option('theme.sidebar_mode', 'flow') === 'sticky'), 'htmlLang' => $htmlLang]);
}

$main   = '';
$title  = site_name();
$status = 200;
$postTypePage = false; // route=post 且正文类型为 page 时置位（页面布局按“独立页面”处理）

switch ($route['kind']) {
    case 'health':
        header('Content-Type: text/plain; charset=utf-8');
        echo 'ok';
        exit;

    case 'home':
        // 图库站模式（option svg.home=1）：根路径即图库，不再渲染博客文章列表
        if ((string)get_option('svg.home', '0') === '1') {
            $svgFile = __DIR__ . '/plugins/svg/page.php';
            if (is_file($svgFile)) {
                require_once $svgFile;
                $svgPg = function_exists('yc_svg_page') ? yc_svg_page('svg') : null;
                if (is_array($svgPg)) {
                    $postTypePage = true;
                    $main = (string)($svgPg['html'] ?? '');
                    $title = (string)($svgPg['title'] ?? site_name());
                    $GLOBALS['YC_SB_TYPE'] = 'page';
                    $GLOBALS['YC_NO_SIDE'] = true;
                    if (!empty($svgPg['standalone'])) { $GLOBALS['YC_STANDALONE'] = true; }
                    break;
                }
            }
        }
        $page = $route['page'];
        if ($pathLang !== Poly::defaultLang()) {
            // CNTales 门户模式：英文等非默认语言首页同样渲染 landing（品牌 Hero/栏目卡/订阅）
            $landing = ((string)get_option('home.landing', '0') === '1' && $page <= 1)
                ? yc_landing_html((string)get_option('theme.default', 'yecha')) : null;
            if ($landing !== null) {
                $main = $landing;
                $title = site_label() . ' · ' . site_tagline();
                break;
            }
            // 其它语言首页：该语言已发布译文列表（图文/文字随首页版式开关，链接带语言前缀）
            $d = Front::polyHome($pathLang, $page);
            $lp = '/' . $pathLang;
            $view = (string)get_option('home.style', 'text') === 'img' ? 'partials/list-media' : 'partials/list';
            $main = View::render($view, [
                'posts' => $d['posts'], 'page_title' => $d['page_title'],
                'sub' => $d['sub'], 'page' => $d['page'], 'total_pages' => $d['total_pages'],
                'pager_base' => $lp . '/page/{n}',
                'link_prefix' => $lp,
            ]);
            $title = $d['title'];
            break;
        }
        // 官网首页·门户落地页模式（option home.landing=1 的站启用；其它站/语言不受影响）
        $landing = ((string)get_option('home.landing', '0') === '1' && $page <= 1)
            ? yc_landing_html((string)get_option('theme.default', 'yecha')) : null;
        if ($landing !== null) {
            $main = $landing;
            $title = site_label() . ' · ' . site_tagline();
            break;
        }
        $d = Front::home($page);
        $view = (string)get_option('home.style', 'text') === 'img' ? 'partials/list-media' : 'partials/list';
        $main = View::render($view, [
            'posts' => $d['posts'], 'page_title' => $d['page_title'],
            'sub' => (string)get_option('site.subtitle', ''), 'page' => $page,
            'total_pages' => $d['total_pages'], 'pager_base' => '/page/{n}',
        ]);
        $title = $d['title'];
        break;

    case 'post':
        $d = Front::post($route['slug'], $pathLang, max(1, (int)($query['cpage'] ?? 1)));
        if ($d === null) {
            // 插件文档解析钩子：动态目录（如 svg_items 数十万条目）命中时渲染
            $pdoc = Plugins::resolveDoc((string)$route['slug']);
            if ($pdoc !== null) {
                $postTypePage = (bool)($pdoc['page'] ?? false);
                $main = (string)$pdoc['html'];
                $title = (string)($pdoc['title'] ?? ($route['slug'] . ' · ' . site_name()));
                $desc = trim((string)($pdoc['description'] ?? ''));
                // 插件文档声明独立整页（已含完整 <!DOCTYPE>）→ 不走博客主题 layout
                if (!empty($pdoc['standalone'])) { $GLOBALS['YC_STANDALONE'] = true; }
                if ($desc !== '') {
                    $GLOBALS['YC_META'] = ['description' => mb_substr($desc, 0, 300), 'keywords' => ''];
                }
                break;
            }
            $status = 404;
            $main = View::render('404');
            $title = '404 · ' . site_label();
        } else {
            // 博客关闭模式：真实博客文章不再公开（301 回首页；插件详情/文档不受影响——上面 resolveDoc 已接管）
            if ((string)get_option('blog.disabled', '0') === '1') {
                $lp = ($pathLang !== Poly::defaultLang() ? '/' . $pathLang : '');
                header('Location: ' . ($lp !== '' ? $lp : '/'), true, 301);
                exit;
            }
            $tpl = (($d['post']['type'] ?? 'post') === 'page') ? 'partials/page' : 'partials/post';
            if ($tpl === 'partials/page') { $postTypePage = true; }
            $main = View::render($tpl, $d);
            $title = $d['title'];
            // 当前语言独立 description / keywords（多语 SEO）
            $pMeta = $d['post'];
            $desc = trim(preg_replace('/\s+/u', ' ', (string)($pMeta['description'] ?? '')) ?? '');
            if ($desc === '') {
                $raw = (string)($pMeta['excerpt'] !== '' ? $pMeta['excerpt'] : $pMeta['content']);
                $raw = Str::cleanMd($raw); // 统一清洗 markdown（标题符/链接/图片/强调等）
                $desc = Str::excerpt($raw, 150);
            }
            $GLOBALS['YC_META'] = [
                'description' => $desc,
                'keywords' => trim((string)($pMeta['keywords'] ?? '')),
            ];
            // 语言 alternate：只有“该内容在该语言确有 publish 译文”才声明（避免 zh-only 页挂 5 条假 hreflang）
            if (Poly::on()) {
                $pid = (int)$pMeta['id'];
                $baseSlug = (string)Db::one('SELECT slug FROM ' . Db::t('contents') . ' WHERE id=?', [$pid]) ?: (string)$pMeta['slug'];
                $urlByLang = [Poly::defaultLang() => '/' . $baseSlug . '.html'];
                foreach (Db::rows('SELECT lang, slug FROM ' . Poly::table() . " WHERE group_id=? AND status='publish'", [$pid]) as $row2) {
                    $lc = (string)$row2['lang'];
                    $ls = trim((string)$row2['slug']);
                    if ($lc !== Poly::defaultLang() && $ls !== '' && !isset($urlByLang[$lc])) {
                        $urlByLang[$lc] = '/' . $lc . '/' . $ls . '.html';
                    }
                }
                $GLOBALS['YC_HREFLANG'] = $urlByLang; // lang → 相对路径（输出 absolute 由 layout 拼 host）
                // canonical：当前语言有真译文 → 当前语言版本；否则指回默认语言
                $canonRel = $urlByLang[$pathLang] ?? $urlByLang[Poly::defaultLang()];
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8082';
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $GLOBALS['YC_CANONICAL'] = $scheme . '://' . $host . $canonRel;
            }
            // 服务端目录：≥3 个 h2/h3 时注入右栏顶部（仅文章）
            if (count($d['toc'] ?? []) >= 3 && ($pMeta['type'] ?? 'post') === 'post') {
                $GLOBALS['YC_TOC'] = $d['toc'];
            }
        }
        break;

    case 'pages_list':
        $d = Front::pagesList();
        $main = View::render('partials/pages-list', $d);
        $title = $d['title'];
        break;

    case 'tags_index':
    case 'cats_index':
        /* 补丁 12（体检 P1-5）：`/tag/` 索引分页走 `?p=N`（与 `/pages` 同一做法，不新增路由）。
           改前 `Front::metaIndex()` 写死 `LIMIT 500`，而站内标签共 39,447 个 → 38,947 个标签页无站内入口。 */
        $d = Front::metaIndex($route['kind'] === 'tags_index' ? 'tag' : 'category', max(1, (int)($_GET['p'] ?? 1)));
        $main = View::render('partials/meta-index', $d);
        $title = $d['title'];
        break;

    case 'meta_list':
        $d = Front::metaList($route['type'], $route['slug'], $route['page']);
        if ($d === null) {
            $status = 404;
            $main = View::render('404');
            $title = '404 · ' . site_label();
        } else {
            $slug = (string)$route['slug'];
            $base = ui_lp() . '/category/' . rawurlencode($slug) . '/page/{n}';
            if ($route['type'] === 'tag') { $base = ui_lp() . '/tag/' . rawurlencode($slug) . '/page/{n}'; }
            $main = View::render('partials/list', [
                'posts' => $d['posts'], 'page_title' => $d['page_title'],
                'sub' => sprintf(__('idx.meta_sub'), (int)$d['total_items'], e($d['name'])),
                'page' => $d['page'], 'total_pages' => $d['total_pages'], 'pager_base' => $base,
                /* 补丁 11：把内核算好的**真实总数**交给视图。改前视图拿不到它，只能拿
                   count($posts) 顶上 → 标签页「共 N」显示的是「本页条数」（P0-1）。 */
                'total_items' => (int)$d['total_items'],
            ]);
            $title = $d['title'];
        }
        break;

    case 'archives_overview':
        $d = Front::archivesOverview();
        $main = View::render('partials/archives', $d);
        $title = $d['title'];
        break;

    case 'archives_list':
        /* 补丁 11：归档分页。
           改前 `pager_base` 是裸的 '/page/{n}'（缺年月路径）、`$d` 里也没有 page/total_pages/total_items，
           所以即使视图想渲染分页器也无从下手 —— 而 Router 那时也不解析 page 段，
           `/archives/YYYY/MM/page/N/` 一律返回第 1 页内容。三处一起修才有意义。 */
        $d = Front::archivesList((int)$route['year'], (int)$route['month'], (int)($route['page'] ?? 1));
        /* 月份必须**补零**（`/archives/2026/08/`）—— 首版写成 (int)$month 生成了
           `/archives/2026/8/page/2`，与页面自身 canonical 的 `/archives/2026/08/` 不是同一个 URL，
          等于自己制造了一批重复页（2026-09-13 验收时发现并修）。 */
        $arcBase = ui_lp() . '/archives/' . (int)$route['year'] . '/'
            . ((int)$route['month'] > 0 ? sprintf('%02d', (int)$route['month']) . '/' : '') . 'page/{n}';
        $main = View::render('partials/list', $d + ['pager_base' => $arcBase, 'sub' => null]);
        $title = $d['title'];
        break;

    case 'search':
        $d = Front::search($route['q']);
        // 把检索统计（耗时/扫描行数/候选数/是否超时降级）一并交给视图，页面上要显式告知用户
        $main = View::render('partials/search', ['q' => $route['q'], 'posts' => $d['posts'], 'stats' => $d['stats'] ?? []]);
        $title = $d['title'];
        break;

    /* ---------- E2：登录 / 注册 / 用户中心 ---------- */
    case 'login':
        if (Auth::check()) { header('Location: ' . ui_user()); exit; }
        $main = Uc::loginPage();
        $title = __('tt.login') . ' · ' . site_label();
        break;

    case 'register':
        if (Auth::check()) { header('Location: ' . ui_user()); exit; }
        $main = Uc::registerPage();
        $title = __('tt.register') . ' · ' . site_label();
        break;

    case 'user_profile':
    case 'user_favorites':
    case 'user_notes':
    case 'user_notifications':
        if (!Auth::check()) { Uc::needLogin(); }
        $labels = ['user_profile' => __('tt.user_profile'), 'user_favorites' => __('tt.user_favorites'), 'user_notes' => __('tt.user_notes'), 'user_notifications' => __('tt.user_notifications')];
        $pageFn = ['user_profile' => 'profilePage', 'user_favorites' => 'favoritesPage', 'user_notes' => 'notesPage', 'user_notifications' => 'notificationsPage'];
        $fn = $pageFn[$route['kind']];
        $main = Uc::$fn();
        $title = $labels[$route['kind']] . ' · ' . site_label();
        break;

    case 'plugin_page':
        // t38：插件页二级路径透传（如 /theme/astra/、/plugin/statx/），插件内自行解析
        $GLOBALS['YC_PLUGIN_PATH'] = array_values((array)($route['path'] ?? []));
        // 图库站模式：/svg 与 /en/svg 合并到站点根（/ 与 /en/），301 防重复内容
        if ((string)get_option('svg.home', '0') === '1' && ($route['slug'] === 'svg' || $route['slug'] === 'gallery')) {
            $dest = ($pathLang !== Poly::defaultLang() ? '/' . $pathLang : '') . '/';
            header('Location: ' . $dest, true, 301);
            exit;
        }
        $pg = Plugins::renderPage((string)$route['slug']);
        if ($pg === null) {
            $status = 404;
            $main = View::render('404');
            $title = '404 · ' . site_label();
        } else {
            $postTypePage = true; // 独立页布局
            $main = $pg['html'];
            $title = $pg['title'];
            // 插件声明独立整页（已含完整 <!DOCTYPE> 外壳）→ 不走博客主题 layout
            if (!empty($pg['standalone'])) { $GLOBALS['YC_STANDALONE'] = true; }
            // t38：插件页可声明 HTTP 状态（如详情页未知 id → 真 404，避免软 404）
            if (!empty($pg['status'])) { $status = (int)$pg['status']; }
        }
        break;

    /* ---------- 插件多级路由（内核合流 2026-09-26，来源：yecha-dev index.php） ----------
       三个 case 与 core/Router.php 的 mall/site/ai 分支一一对应；
       插件停用/未安装时各自回落 404，不影响任何既有站点。 */
    case 'mall':
        // 商城插件：自包含头尾（YC_NO_SIDE），分发在插件内
        if (!Plugins::isOn('mall') || !class_exists('Mall')) {
            $status = 404;
            $main = View::render('404');
            $title = '404 · ' . site_label();
            break;
        }
        $pg = Mall::dispatch($route['path'] ?? [], $query);
        $postTypePage = true;          // 独立页布局（通栏无侧栏由 mall 自身 YC_NO_SIDE 语义 + .mall-root 控制）
        $GLOBALS['YC_NO_SIDE'] = true; // 商城版式自带头尾，不出博客右栏
        $main = $pg['html'];
        $title = $pg['title'];
        if (!empty($pg['status'])) { $status = (int)$pg['status']; }
        break;

    case 'site':
        // 企业官网插件多级路由（Router RESERVED 'site' 分支进来）；停用/未安装 → 404
        if (!Plugins::isOn('site') || !class_exists('Site')) {
            $status = 404;
            $main = View::render('404');
            $title = '404 · ' . site_label();
            break;
        }
        $pg = Site::dispatch($route['path'] ?? [], $query);
        $postTypePage = true;
        $GLOBALS['YC_NO_SIDE'] = true;   // 官网自包含头尾（sc-root），不出博客右栏
        $main = $pg['html'];
        $title = $pg['title'];
        if (!empty($pg['status'])) { $status = (int)$pg['status']; }  // 插件可指定状态（如官网 404）
        break;

    case 'ai':
        // AI 能力插件：/ai/api/chat 等 JSON 接口（dispatch 内自行输出并终止）
        if (Plugins::isOn('ai') && class_exists('Ai')) {
            Ai::dispatch($route['path'] ?? [], $query);
        } else {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'err' => 'not found']);
        }
        exit; // JSON 接口：不再套页面渲染

    default:
        // 站点级开关：seo.404_home=1 → 未知路由直接跳首页（默认关闭）
        if ((string)get_option('seo.404_home', '0') === '1') {
            header('Location: /', true, 302);
            exit;
        }
        $status = 404;
        $main = View::render('404');
        $title = '404 · ' . site_label();
}

/* ---------- 补丁 14：越界 / 非法页码 → 404（**唯一一处收口**，core 侧列表页通用）----------
   越界页此前一律 200 + **自指 canonical**（等于把空页当独立页提交给搜索引擎）；
   `page/0`、`page/-1`、`page/abc`、`?p=0/-1/abc` 则被 Router 静默折成第 1 页，
   于是同一份内容挂上无数个 URL。站长已定策略：**越界与非法页码都返回 404**。
   判据只用内核**已经算好的** `$d['page']` / `$d['total_pages']` —— 与页面上分页器**同一个数**，
   绝不另算一遍，杜绝两处口径漂移（这是本补丁最关键的正确性约束）。
   插件列表页（`/pages`、`/tag/<轴>/page/N/`）由 plugins/gushuauthors/page.php 调**同一个**
   Router::pageBeyond()，命中后返回 null，走上面 plugin_page 的既有 404 分支。 */
if ($status === 200) {
    /* 分页元数据来源：core 列表页在 `$d`；插件列表页（`/pages`、`/tag/<轴>/page/N/`）在 `$pg`
       —— plugins/gushuauthors/page.php 把 page / total_pages / page_tok 一并放进返回值。
       ⚠️ 插件**不能**用 `return null` 表达 404：`Plugins::renderPage()` 的
       `if (!is_array($res)) { $res = ['html' => (string)$res, ...]; }` 会把 null 折成
       **空 200 页**，于是 index.php 里 `if ($pg === null)` 那条分支永远不会命中（实测踩过）。 */
    $pm = null;
    if (isset($d) && is_array($d) && array_key_exists('total_pages', $d)) { $pm = $d; }
    elseif (isset($pg) && is_array($pg) && array_key_exists('total_pages', $pg)) { $pm = $pg; }
    if ($pm !== null) {
        $ptok = $route['page_tok'] ?? null;
        if ($ptok === null && isset($pm['page_tok'])) { $ptok = (string)$pm['page_tok']; }
        /* 查询串分页页型（/tag/、/category/ 索引）的页码记号在 `?p=` */
        if ($ptok === null
            && in_array($route['kind'], ['tags_index', 'cats_index', 'pages_list'], true)
            && isset($_GET['p'])) {
            $ptok = (string)$_GET['p'];
        }
        if (Router::pageBeyond($ptok, (int)($pm['page'] ?? 1), (int)$pm['total_pages'])) {
            $status = 404;
            $main = View::render('404');
            $title = '404 · ' . site_label();
            /* 该 URL 很可能已按 200 落进全页缓存（补丁 13 起 TTL≤1h，但不能干等）→ 主动删掉：
               上面缓存读取那一段已经 HIT 过了，不清掉这一条就会继续吐 200。 */
            if (isset($cacheKey)) { Cache::forget('pages', $cacheKey); }
        } elseif (Router::pageOne($ptok, (int)($pm['page'] ?? 1))) {
            /* 补丁 15：插件列表页的 page/1 归一（调用点 B）。
               第 2 批已经把「越界 / 非法 → 404」收到这里，page/1 用**同一个** Router::pageOne()
               在**同一个** elseif 里互补收掉；`$ptok` 也复用上面已算好的那一份（插件在
               `$pm['page_tok']` 里把 `axp` 原样透传）。plugin_page 不进全页缓存，故此处安全。 */
            if (yc_page1_redirect()) { exit; }
        }
    }
}

/* 仅用户中心模式：登录/注册/用户中心若插件提供独立外壳则接管（博客内容关闭的图库站通用做法） */
if (in_array($route['kind'], ['login', 'register', 'user_profile', 'user_favorites', 'user_notes', 'user_notifications'], true)) {
    if (!function_exists('svg_account_shell_page')) {
        $__svgLib = __DIR__ . '/plugins/svg/lib.php';
        if (is_file($__svgLib)) { require_once $__svgLib; }
    }
    if (function_exists('svg_account_shell_page')) {
        $__acc = svg_account_shell_page(['kind' => $route['kind'], 'title' => $title ?? '', 'main' => $main ?? '']);
        if (is_array($__acc) && !empty($__acc['html'])) {
            http_response_code($status ?? 200);
            header('Content-Type: text/html; charset=utf-8');
            header('X-Cache: MISS');
            header('Vary: Cookie, Host');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            echo $__acc['html'];
            exit;
        }
    }
}

/* 页面类型 → 右侧栏布局配置（后台「页面布局」分别控制） */
$sbType = 'post';
switch ($route['kind']) {
    case 'home':            $sbType = 'home'; break;
    case 'meta_list':       $sbType = ($route['type'] ?? '') === 'tag' ? 'tag' : 'category'; break;
    case 'tags_index':      $sbType = 'tag'; break;
    case 'cats_index':      $sbType = 'category'; break;
    case 'archives_overview':
    case 'archives_list':   $sbType = 'archives'; break;
    case 'search':          $sbType = 'search'; break;
    case 'pages_list':      $sbType = 'page'; break;
    default:                $sbType = $postTypePage ? 'page' : 'post';
}
$GLOBALS['YC_SB_TYPE'] = $sbType;

/* 插件外壳接管（front.shell）：插件可返回完整页面外壳（主题令牌 + 头尾），一次性覆盖任意内核页面的皮肤。
   （内核合流 2026-09-26，来源：yecha-dev index.php）
   载荷：kind/route/main/title/query/status；返回：
     ['shell' => '<完整 HTML 外壳>', 'title' => '覆盖标题', 'canonical' => '…', 'meta' => [...]]（不返回 shell 则不改动）
   接管后关闭博客主题外壳与右栏（走本文件既有的 YC_STANDALONE 直出分支），并**禁用整页缓存**
   （外壳含登录态/语言差异，缓存会串号）。 */
$shell = Hooks::run('front.shell', [
    'kind' => (string)($route['kind'] ?? ''), 'route' => $route,
    'main' => $main, 'title' => $title, 'query' => $query, 'status' => $status,
]);
if (!empty($shell['shell'])) {
    $main = (string)$shell['shell'];
    if (!empty($shell['title'])) { $title = (string)$shell['title']; }
    $GLOBALS['YC_NO_SIDE'] = true;
    $GLOBALS['YC_STANDALONE'] = true;
    $cacheable = false;
    if (!empty($shell['canonical'])) { $GLOBALS['YC_CANONICAL'] = (string)$shell['canonical']; }
    if (!empty($shell['brand'])) { $GLOBALS['YC_OG']['site_name'] = (string)$shell['brand']; }
    if (!empty($shell['bg'])) { $GLOBALS['YC_SHELL_BG'] = (string)$shell['bg']; }
    // 允许插件改写 HTTP 状态（如官网模式下把被排除的内容按 404 处理，避免软 404 影响 SEO）
    if (!empty($shell['status'])) { $status = (int)$shell['status']; }
    if (!empty($shell['meta']) && is_array($shell['meta'])) {
        $GLOBALS['YC_META'] = array_merge((array)($GLOBALS['YC_META'] ?? []), $shell['meta']);
    }
    // 允许插件改写 <html lang> / dir（官网多语言：?lang=xx 时让文档语言与内容一致，利于 SEO 与 RTL 排版）
    if (!empty($shell['htmlLang'])) { $GLOBALS['YC_HTML_LANG'] = (string)$shell['htmlLang']; }
    if (!empty($shell['htmlDir'])) { $GLOBALS['YC_HTML_DIR'] = (string)$shell['htmlDir']; }
}

http_response_code($status);
if (!empty($GLOBALS['YC_STANDALONE'])) {
    // 独立整页：插件已输出完整 HTML 文档，直接返回（不含主题外壳/统计注入）
    header('Content-Type: text/html; charset=utf-8');
    header('X-Cache: MISS');
    header('Vary: Cookie, Host');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $main;
    if ($status === 200 && $cacheable && isset($cacheKey)) {
        Cache::set('pages', $cacheKey, $main, $pageTtl);
    }
    exit;
}
$html = render_page($title, $main);
header('Content-Type: text/html; charset=utf-8');
header('X-Cache: MISS');
header('Vary: Cookie, Host');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo $html;

if ($status === 200 && $cacheable && isset($cacheKey)) {
    Cache::set('pages', $cacheKey, $html, $pageTtl);
}
