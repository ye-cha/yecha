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
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* 语言识别：/{en}/… 前缀 → en；其余为默认语言。界面语言包随当前语言切换（zh_CN/en_US）。
   多语插件关闭时不识别语言前缀、始终为默认语言（前台零多语痕迹）。 */
$pathLang = Poly::defaultLang();
$firstSeg = strtok($path, '/');
if (Poly::on() && in_array($firstSeg, Poly::enabled(), true) && $firstSeg !== Poly::defaultLang()) {
    $pathLang = (string)$firstSeg;
}
Lang::switch(Poly::pack($pathLang));

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

/* ---------- RSS / sitemap（XML 直出，多语 + hreflang） ---------- */
if (in_array($route['kind'], ['feed', 'sitemap'], true)) {
    header('Content-Type: application/xml; charset=utf-8');
    echo Feed::render($route['kind'], $pathLang);
    exit;
}

/* ---------- 全页缓存（未登录 GET 页面；X-Cache 观测） ---------- */
$cacheable = in_array($route['kind'], ['home', 'post', 'meta_list', 'archives_overview', 'archives_list'], true)
    && empty($_SERVER['HTTP_X_YC_NOCACHE'])
    && !Auth::check()
    && $method === 'GET';

if ($cacheable) {
    $qs = isset($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    $hostKey = strtolower((string)($_SERVER['HTTP_HOST'] ?? '')); // 缓存键含 Host，防 Host 头投毒串页
    $cacheKey = 'page:' . $hostKey . ':' . $path . $qs;
    $cached = Cache::get('pages', $cacheKey);
    if ($cached !== false) {
        header('X-Cache: HIT');
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
        $blocks = ['search', 'blogger', 'cats', 'latest', 'tags', 'archives'];
    } else {
        $show = ($cur['show'] ?? '1') !== '0';
        $blocks = is_array($cur['blocks'] ?? null) ? $cur['blocks'] : ['search', 'blogger', 'cats', 'latest', 'tags', 'archives'];
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
    return View::render('layout', ['title' => $title, 'main' => $mainHtml, 'side' => $side, 'tocSide' => $tocSide, 'sbSticky' => ((string)get_option('theme.sidebar_mode', 'flow') === 'sticky'), 'htmlLang' => ['zh' => 'zh-CN', 'tw' => 'zh-TW'][$pathLang] ?? $pathLang]);
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
        $page = $route['page'];
        if ($pathLang !== Poly::defaultLang()) {
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
        if ((string)get_option('home.landing', '0') === '1' && $page <= 1) {
            $postsRaw = Db::rows('SELECT title, slug, published_at, created_at FROM ' . Db::t('contents') . " WHERE type='post' AND status='publish' AND deleted_at IS NULL ORDER BY COALESCE(published_at, created_at) DESC LIMIT 4");
            $posts = [];
            foreach ($postsRaw as $p) { $posts[] = ['title' => $p['title'], 'slug' => $p['slug'], 'published_at' => (string)($p['published_at'] ?: $p['created_at'])]; }
            $cb = (int)Db::one("SELECT COUNT(*) FROM " . Db::t('links') . " WHERE status='show'");
            $cp = 0;
            if (Db::rows('SHOW TABLES LIKE "' . Db::t('circle_posts') . '"')) { $cp = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('circle_posts')); }
            $GLOBALS['YC_NO_SIDE'] = true;
            $GLOBALS['YC_SB_TYPE'] = 'page';
            $main = View::render('landing', ['posts' => $posts, 'circle_blogs' => $cb, 'circle_posts' => $cp]);
            $title = site_name() . ' · ' . (string)get_option('site.subtitle', '');
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
            $status = 404;
            $main = View::render('404');
            $title = '404 · ' . site_name();
        } else {
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
        $d = Front::metaIndex($route['kind'] === 'tags_index' ? 'tag' : 'category');
        $main = View::render('partials/meta-index', $d);
        $title = $d['title'];
        break;

    case 'meta_list':
        $d = Front::metaList($route['type'], $route['slug'], $route['page']);
        if ($d === null) {
            $status = 404;
            $main = View::render('404');
            $title = '404 · ' . site_name();
        } else {
            $slug = (string)$route['slug'];
            $base = ui_lp() . '/category/' . rawurlencode($slug) . '/page/{n}';
            if ($route['type'] === 'tag') { $base = ui_lp() . '/tag/' . rawurlencode($slug) . '/page/{n}'; }
            $main = View::render('partials/list', [
                'posts' => $d['posts'], 'page_title' => $d['page_title'],
                'sub' => sprintf(__('idx.meta_sub'), (int)$d['total_items'], e($d['name'])),
                'page' => $d['page'], 'total_pages' => $d['total_pages'], 'pager_base' => $base,
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
        $d = Front::archivesList($route['year'], $route['month']);
        $main = View::render('partials/list', $d + ['pager_base' => '/page/{n}', 'sub' => null]);
        $title = $d['title'];
        break;

    case 'search':
        $d = Front::search($route['q']);
        $main = View::render('partials/search', ['q' => $route['q'], 'posts' => $d['posts']]);
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
        $pg = Plugins::renderPage((string)$route['slug']);
        if ($pg === null) {
            $status = 404;
            $main = View::render('404');
            $title = '404 · ' . site_label();
        } else {
            $postTypePage = true; // 独立页布局
            $main = $pg['html'];
            $title = $pg['title'];
        }
        break;

    default:
        $status = 404;
        $main = View::render('404');
        $title = '404 · ' . site_label();
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

http_response_code($status);
$html = render_page($title, $main);
header('Content-Type: text/html; charset=utf-8');
header('X-Cache: MISS');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo $html;

if ($status === 200 && $cacheable && isset($cacheKey)) {
    Cache::set('pages', $cacheKey, $html);
}
