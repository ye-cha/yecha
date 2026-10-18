<?php
declare(strict_types=1);
/**
 * lq-www —— 对齐 `www.liulanqi.com` 形态的共享件（lqalign2 · 2026-10-05）
 * ===========================================================================
 * 参照物 = 线上 `www.liulanqi.com`（预渲染 React + Tailwind 的静态站）。
 *   它的样式产物 `/assets/index-cAFfNupP.css` 被**逐字节复制**进
 *   `themes/bl/lqwww/www.css`（站点自己的 CSS，非"仿制"），本文件只产出
 *   **www 的 markup 形状**（同一批 Tailwind 工具类 + 同一套 DOM 骨架）。
 *
 * 本文件**不**产出任何 DedeCMS 老站类名（g-box-1200 / m-listdiv / softli / tspage…）。
 *
 * 让位规则（与 parts/lq-mech.php 的机制层共存）：
 *   · 可见面包屑由各页 view 自己发（带 aria-label="breadcrumb"）⇒ lq-mech 自动让位，
 *     JSON-LD 的 BreadcrumbList 仍由 lq-mech 出（每页恰 1 条）。
 *   · canonical / description / keywords / OGP / Twitter 全部**只在本层**发一次
 *     （layout.php 的 head 里 lqwww_head()），各页 view 不得再发同类 meta。
 */

/* ============================ 1. origin / canonical ============================ */

/** 站点公开 origin（canonical / OGP 用）：优先 site.url，**强制 https**（参照 www 用 https） */
function lqwww_origin(): string
{
    $u = trim((string)get_option('site.url', ''));
    if ($u === '' || stripos($u, 'http') !== 0) {
        $h = (string)($_SERVER['HTTP_HOST'] ?? 'liulanqi.com');
        $u = 'https://' . $h;
    }
    $u = (string)preg_replace('#^http://#i', 'https://', $u);
    return rtrim($u, '/');
}

/** 当前请求路径（剥掉 /index.php 前缀；查询串不进 canonical） */
function lqwww_path(): string
{
    $p = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $p = (string)preg_replace('#^/index\.php#', '', $p);
    if ($p === '') { $p = '/'; }
    return $p;
}

/** canonical = origin + 当前路径（**自指**；目录型路径保留结尾斜杠） */
function lqwww_canonical(): string
{
    $p = lqwww_path();
    if ($p !== '/' && substr($p, -1) !== '/') { $p = rtrim($p, '/'); }
    return lqwww_origin() . $p;
}

/** 站点级默认描述（只取站点自己的真值；取不到返回空串，不编） */
function lqwww_default_desc(): string
{
    $d = trim((string)get_option('site.subtitle', ''));
    if ($d === '') { $d = trim((string)get_option('site.description', '')); }
    return $d;
}

/** 站点级默认图（真值：site.og_image 优先，其次站标 512；都没有 ⇒ 空串 ⇒ 不输出 og:image） */
function lqwww_default_image(): string
{
    $o = trim((string)get_option('site.og_image', ''));
    if ($o !== '') {
        return stripos($o, 'http') === 0 ? $o : lqwww_origin() . '/' . ltrim($o, '/');
    }
    $rel = View::asset('logo-512.png');
    $abs = dirname(__DIR__, 3) . '/' . ltrim(preg_replace('/\?.*$/', '', $rel), '/');
    return is_file($abs) ? lqwww_origin() . preg_replace('/\?.*$/', '', $rel) : '';
}

/**
 * head 里**唯一**的 SEO 段：canonical（恰 1、自指）· hreflang（真译文才出）·
 * description / keywords · OGP · Twitter Card。
 * 每类节点**只发一次**；无真值不发（不编 og:image）。
 */
function lqwww_head(string $title = '', string $kind = 'website'): string
{
    $o = '';
    $o .= "\n" . '<link rel="canonical" href="' . e(lqwww_canonical()) . '" />';

    /* hreflang：只有"该内容确有该语言 publish 译文"才声明（index.php 已判明）；索引页无译文表 ⇒ 不发 */
    $hl = $GLOBALS['YC_HREFLANG'] ?? null;
    if (is_array($hl) && $hl !== []) {
        $origin = lqwww_origin();
        foreach (Poly::enabled() as $lc) {
            $rel = $hl[$lc] ?? null;
            if ($rel === null) { continue; }
            $o .= "\n" . '<link rel="alternate" hreflang="' . e($lc) . '" href="' . e($origin . $rel) . '" />';
        }
        $xdef = $hl[Poly::defaultLang()] ?? null;
        if ($xdef !== null) {
            $o .= "\n" . '<link rel="alternate" hreflang="x-default" href="' . e($origin . $xdef) . '" />';
        }
    }

    $meta = (array)($GLOBALS['YC_META'] ?? []);
    $desc = trim((string)($meta['description'] ?? ''));
    if ($desc === '') { $desc = lqwww_default_desc(); }
    $desc = (string)preg_replace('/\s+/u', ' ', $desc);
    if ($desc !== '') {
        $o .= "\n" . '<meta name="description" content="' . e(Str::excerpt($desc, 160)) . '">';
    }
    $kw = trim((string)($meta['keywords'] ?? ''));
    if ($kw === '') {
        /* ★ 索引页 DB 里没有关键词 ⇒ 用**页面自身已有的真值**拼（标题首段 + 站点名 + 站点英文名），
           不编造营销词；详情页仍以 DB 的 keywords 为准（有就用，没有才兜底）。 */
        $parts = [];
        $t0 = trim((string)(preg_split('/[·|\-—–]/u', trim($title))[0] ?? ''));
        if ($t0 !== '' && mb_strlen($t0) <= 24) { $parts[] = $t0; }
        $parts[] = site_label();
        $en = trim((string)get_option('site.name_en', ''));
        if ($en !== '') { $parts[] = $en; }
        $kw = implode(',', array_values(array_unique(array_filter($parts, static fn($v) => $v !== ''))));
    }
    if ($kw !== '') { $o .= "\n" . '<meta name="keywords" content="' . e($kw) . '">'; }

    /* ---- OGP ---- */
    $t = trim($title);
    if ($t === '') { $t = site_name(); }
    $url = lqwww_canonical();
    $img = lqwww_default_image();
    $locale = 'zh_CN';
    $lshort = Lang::short();
    if ($lshort === 'tw') { $locale = 'zh_TW'; }
    elseif ($lshort !== 'zh' && $lshort !== 'zh-CN') { $locale = 'en_US'; }
    $o .= "\n" . '<meta property="og:type" content="' . e($kind === 'article' ? 'article' : 'website') . '" />';
    $o .= "\n" . '<meta property="og:site_name" content="' . e(site_name()) . '" />';
    $o .= "\n" . '<meta property="og:title" content="' . e($t) . '" />';
    if ($desc !== '') { $o .= "\n" . '<meta property="og:description" content="' . e(Str::excerpt($desc, 160)) . '" />'; }
    $o .= "\n" . '<meta property="og:url" content="' . e($url) . '" />';
    if ($img !== '') { $o .= "\n" . '<meta property="og:image" content="' . e($img) . '" />'; }
    $o .= "\n" . '<meta property="og:locale" content="' . e($locale) . '" />';

    /* ---- Twitter Card（card 恒有；图只在有真值时给） ---- */
    $o .= "\n" . '<meta name="twitter:card" content="' . ($img !== '' ? 'summary_large_image' : 'summary') . '" />';
    $o .= "\n" . '<meta name="twitter:title" content="' . e($t) . '" />';
    if ($desc !== '') { $o .= "\n" . '<meta name="twitter:description" content="' . e(Str::excerpt($desc, 160)) . '" />'; }
    if ($img !== '') { $o .= "\n" . '<meta name="twitter:image" content="' . e($img) . '" />'; }
    return $o;
}

/* ============================ 2. 图标（lucide，与 www 同一批） ============================ */

function lqwww_ico(string $name, string $cls = 'h-4 w-4'): string
{
    $P = [
        'search'      => '<circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path>',
        'file-text'   => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"></path><path d="M14 2v4a2 2 0 0 0 2 2h4"></path><path d="M10 9H8"></path><path d="M16 13H8"></path><path d="M16 17H8"></path>',
        'arrow-right' => '<path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path>',
        'arrow-left'  => '<path d="M19 12H5"></path><path d="m12 19-7-7 7-7"></path>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"></path>',
        'log-in'      => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" x2="3" y1="12" y2="12"></line>',
        'menu'        => '<line x1="4" x2="20" y1="12" y2="12"></line><line x1="4" x2="20" y1="6" y2="6"></line><line x1="4" x2="20" y1="18" y2="18"></line>',
        'compass'     => '<circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon>',
        'sparkles'    => '<path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"></path>',
        'list-ordered' => '<line x1="10" x2="21" y1="6" y2="6"></line><line x1="10" x2="21" y1="12" y2="12"></line><line x1="10" x2="21" y1="18" y2="18"></line><path d="M4 6h1v4"></path><path d="M4 10h2"></path><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"></path>',
        'book-open'   => '<path d="M12 7v14"></path><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"></path>',
        'graduation-cap' => '<path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"></path><path d="M22 10v6"></path><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"></path>',
        'external-link' => '<path d="M15 3h6v6"></path><path d="M10 14 21 3"></path><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>',
        'notebook-pen' => '<path d="M13.4 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7.4"></path><path d="M2 6h4"></path><path d="M2 10h4"></path><path d="M2 14h4"></path><path d="M2 18h4"></path><path d="M21.378 5.626a1 1 0 1 0-3.004-3.004l-5.01 5.012a2 2 0 0 0-.506.854l-.837 2.87a.5.5 0 0 0 .62.62l2.87-.837a2 2 0 0 0 .854-.506z"></path>',
        'wrench'      => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>',
        'moon'        => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"></path>',
        'languages'   => '<path d="m5 8 6 6"></path><path d="m4 14 6-6 2-3"></path><path d="M2 5h12"></path><path d="M7 2h1"></path><path d="m22 22-5-10-5 10"></path><path d="M14 18h6"></path>',
        'users'       => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
        'rss'         => '<path d="M4 11a9 9 0 0 1 9 9"></path><path d="M4 4a16 16 0 0 1 16 16"></path><circle cx="5" cy="19" r="1"></circle>',
        'code'        => '<polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline>',
        'shuffle'     => '<path d="m18 14 4 4-4 4"></path><path d="m18 2 4 4-4 4"></path><path d="M2 18h1.4c1.3 0 2.5-.6 3.3-1.7l6.1-8.6c.7-1.1 2-1.7 3.3-1.7H22"></path><path d="M2 6h1.9c1.5 0 2.9.9 3.6 2.2"></path><path d="M22 18h-5.9c-1.3 0-2.6-.7-3.3-1.8l-.5-.8"></path>',
        'image'       => '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"></rect><circle cx="9" cy="9" r="2"></circle><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"></path>',
        'play'        => '<polygon points="6 3 20 12 6 21 6 3"></polygon>',
        'calculator'  => '<rect width="16" height="20" x="4" y="2" rx="2"></rect><line x1="8" x2="16" y1="6" y2="6"></line><line x1="16" x2="16" y1="14" y2="18"></line><path d="M16 10h.01"></path><path d="M12 10h.01"></path><path d="M8 10h.01"></path><path d="M12 14h.01"></path><path d="M8 14h.01"></path><path d="M12 18h.01"></path><path d="M8 18h.01"></path>',
        'clock'       => '<circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline>',
        'palette'     => '<circle cx="13.5" cy="6.5" r=".5" fill="currentColor"></circle><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"></circle><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"></circle><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"></circle><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"></path>',
        'tag'         => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"></path><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"></circle>',
        'heart'       => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"></path>',
        'shield'      => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"></path>',
        'file'        => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"></path><path d="M14 2v4a2 2 0 0 0 2 2h4"></path>',
    ];
    $p = $P[$name] ?? $P['file-text'];
    return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"'
        . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
        . ' class="lucide lucide-' . e($name) . ' ' . e($cls) . '">' . $p . '</svg>';
}

/* ============================ 3. 站头 / 页脚 ============================ */

/** 主导航（= www 那 9 项，全部指向本实例真实存在的页面） */
function lqwww_topnav(): array
{
    $lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
    $cur = rtrim(lqwww_path(), '/');
    if ($cur === '') { $cur = '/'; }
    /* ★ 2026-10-05（精仿第 3 批 · 像素轮）：第 3 列曾是「首页」的 true 标记，而它的判据
       `$cur === rtrim($href,'/')` 在**非首页**上会落到 `$cur === ''`（rtrim('/','/') === ''）
       ⇒ 任何页型都把「首页」画成 active（`text-foreground bg-accent` + aria-current="page"）
       ⇒ 站头那枚灰底块在 /tips /tool /gallery /tips/* /tool/* /404 上全亮着。
       实测代价：站头 nav 的 color/background-color 两项样式差 + 首屏热格 5.28%。
       修法：不再用第 3 列做特判（下面的通用判据对 '/' 已经正确：$base='' 会被跳过），
       另把「首页」的 active 明确限定在 `/` 与 `/page/N`（此时 $cur 就是 `/page/N`）。 */
    $items = [
        ['/',            '首页',        false],
        ['/bookmark',    '书签',        false],
        ['/notes',       '云笔记',      false],
        ['/learn',       '浏览器学堂',  false],
        ['/mobile',      '手机浏览器',  false],
        ['/download',    '浏览器下载',  false],
        ['/plugins',     '浏览器插件',  false],
        ['/tool',        '工具箱',      false],
        ['/jizhang',     '记账',        false],
    ];
    $out = [];
    foreach ($items as $it) {
        $href = $it[0] === '/' ? ($lp === '' ? '/' : $lp . '/') : $lp . $it[0];
        $base = rtrim($it[0], '/');
        $act = $it[2]
            ? ($cur === rtrim($href, '/') || ($lp === '' && ($cur === '/' || preg_match('#^/page/\d+$#', $cur) === 1)))
            : ($base !== ''
                ? ($cur === $base || str_starts_with($cur, $base . '/'))
                /* 首页（$base === ''）：只在真正首页与首页分页上 active */
                : ($cur === '/' || preg_match('#^/page/\d+$#', $cur) === 1));
        $out[] = ['label' => $it[1], 'href' => $href, 'active' => $act];
    }
    return $out;
}

/** 站头：逐块对齐 www 的 `<header class="sticky …">` DOM；登录/语言/深色/搜索都是真功能 */
/** ★★ 2026-10-11 站主裁定（站级 Shell 逐页型对齐 S1/S3/S4）：
 *  老站这四类站级控件的形态【按页型不同】——
 *    S1 菜单开关：/download/* 老站是 `#navToggle[aria-label="菜单"] aria-expanded`；/tool/* 两侧都叫「打开菜单」⇒ 只改 /download/*
 *    S3 快速记录：老站 /download/*【没有】、/tool/*【有】⇒ 只在 /download/* 不输出
 *    S4 页头搜索：老站 /tool/* 页头【没有】、/download/* 两边都没有 ⇒ 只在 /tool/* 摘除
 *  ★★ 页型判定【必须先剥语言前缀 /en】—— 否则 EN 页走"都不是"分支 ⇒ 同页型两种外壳（实测 /en/tool/affine 等 5 个 URL 均 200）。
 *  ★ 依赖自由：只用 parse_url($_SERVER['REQUEST_URI']) + Poly/Lang 的存在性判断。 */
if (!function_exists('lqwww_shell_path')) {
    function lqwww_shell_path(): string
    {
        $p = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($p === '') { $p = '/'; }
        if (class_exists('Poly') && Poly::on() && class_exists('Lang') && Lang::short() !== Poly::defaultLang()) {
            $lp = '/' . Lang::short();
            $n = strlen($lp);
            if (strncmp($p, $lp . '/', $n + 1) === 0) { $p = substr($p, $n); }
            elseif ($p === $lp) { $p = '/'; }
        }
        return $p === '' ? '/' : $p;
    }
}
if (!function_exists('lqwww_is_tool')) {
    function lqwww_is_tool(): bool { $p = lqwww_shell_path(); return $p === '/tool' || strncmp($p, '/tool/', 6) === 0; }
}
if (!function_exists('lqwww_is_download')) {
    function lqwww_is_download(): bool { $p = lqwww_shell_path(); return strncmp($p, '/download/', 10) === 0; }
}
/** 站头：**以 www 的真实 HTML 骨架为模板**（骨架 = lqwww/skel/home_header.html，
 *  逐字节切自参照页 seo/index.html）；本函数只取数 + 填占位符，不画任何 UI。
 *  ★ 与上一轮的区别：参照站头**没有**语言切换与深色开关 ⇒ 为了让站头几何/像素与参照一致，
 *    这两个真功能**移到页脚底行**（`{{foot_extra}}`），功能一个不丢（见报告 §⑤-13）。 */
function lqwww_header(): string
{
    require_once __DIR__ . '/lq-skel.php';
    $lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
    $q  = (string)($_GET['q'] ?? '');
    $on  = 'whitespace-nowrap rounded-md px-2 py-2 text-[13px] font-medium transition-colors hover:text-foreground xl:text-sm text-foreground bg-accent';
    $off = 'whitespace-nowrap rounded-md px-2 py-2 text-[13px] font-medium text-muted-foreground transition-colors hover:text-foreground xl:text-sm';

    $nav = '';
    foreach (lqwww_topnav() as $n) {
        $nav .= $n['active']
            ? '<a aria-current="page" class="' . $on . '" href="' . e($n['href']) . '">' . e($n['label']) . '</a>'
            : '<a class="' . $off . '" href="' . e($n['href']) . '">' . e($n['label']) . '</a>';
    }
    $panel = '<nav id="lqMenuPanel" class="border-t bg-background" hidden aria-label="移动导航">'
        . '<div class="container grid gap-0.5 py-3">';
    foreach (lqwww_topnav() as $n) {
        $panel .= '<a class="rounded-md px-2 py-2 text-sm font-medium '
            . ($n['active'] ? 'bg-accent text-foreground' : 'text-muted-foreground hover:text-foreground')
            . '" href="' . e($n['href']) . '">' . e($n['label']) . '</a>';
    }
    $panel .= '</div></nav>';

    if (\Auth::check()) {
        $uHref = ui_user(); $uLabel = e(__('ui.my')); $uIcon = lqwww_ico('users', 'h-4 w-4');
    } else {
        $uHref = ui_login(); $uLabel = e(__('ui.login')); $uIcon = lqwww_ico('log-in', 'h-4 w-4');
    }

    $h = lqskel_fill(lqskel('home_header'), [
        'brand'         => e(site_label()),
        'home_href'     => e($lp === '' ? '/' : $lp . '/'),
        'nav'           => $nav,
        'search_action' => e($lp . '/search'),
        'q'             => e($q),
        'search_btn'    => e(__('sr.btn')),
        'user_href'     => e($uHref),
        'user_label'    => $uLabel,
        'user_icon'     => $uIcon,
        'mobile_panel'  => $panel,
    ], 'header');

    /* ★★ S1（仅 /download/*）：菜单开关与老站一致 —— type/id/aria-label/aria-expanded 对齐；
       ★ class 保持我方（站主 Z4：命名/属性对齐、class 体系不同 ⇒ 登记）。★ 锚点须恰 1 才替换。 */
    if (lqwww_is_download()) {
        $__a = '<button aria-label="打开菜单" class="';
        $__b = '<button type="button" id="navToggle" aria-label="菜单" aria-expanded="false" class="';
        if (substr_count($h, $__a) === 1) { $h = str_replace($__a, $__b, $h); }
        else { error_log('[lqta] S1 anchor not unique: ' . substr_count($h, $__a)); }
    }

    /* ★★ S4（仅 /download/*）—— ★★ 2026-10-11 AE1【页型翻正】：
       ★ DOM 实测（不用静态 grep）：老站 `/tool/affine` 页头 input = 【1】（★ 有搜索框）·
         老站 `/download/{chrome,firefox}` 页头 = 【0】· 而我方 `/download/*` = 1 ⇒ ★ **该摘的是 /download/***，`/tool/*` 不动。
       ★ 初版曾把页型写成 `/tool/*` ⇒ 装反。根因：早前在【`main` 内】枚举控件来判"老站 `/tool/*` 页头无搜索"，
         而**页头不在 `main` 内** ⇒ 数到 0 ≠ 没有（教训：数某区域内的元素前，先证明该区域【包含】那个东西）。
       ★ 按【元素边界 + div 配平扫描】摘（不用正则 —— 防嵌套 div 与 JS 串里的 '<div>'）；
       ★ 双前置断言：摘除块含「全站搜索」恰 1 · 全页「全站搜索」恰 1 ⇒ 否则只记日志、不动。 */
    if (lqwww_is_download()) {
        $__needle = '<div class="hidden w-56 lg:block">';
        $__at = strpos($h, $__needle);
        if ($__at !== false && substr_count($h, '全站搜索') === 1) {
            $__i = $__at; $__depth = 0; $__end = false; $__len = strlen($h);
            while ($__i < $__len) {
                $__no = strpos($h, '<div', $__i); $__nc = strpos($h, '</div>', $__i);
                if ($__nc === false) { break; }
                if ($__no !== false && $__no < $__nc) { $__depth++; $__i = $__no + 4; }
                else { $__depth--; $__i = $__nc + 6; if ($__depth === 0) { $__end = $__i; break; } }
            }
            $__blk = ($__end === false) ? '' : substr($h, $__at, $__end - $__at);
            if ($__end !== false && substr_count($__blk, '全站搜索') === 1) {
                $h = substr($h, 0, $__at) . '<!-- S4: /download/* 页头无搜索框（按老站 · DOM 实测） -->' . substr($h, $__end);
            } else { error_log('[lqta] S4 block assertion failed'); }
        } elseif ($__at !== false) {
            error_log('[lqta] S4 precondition failed: 全站搜索 count=' . substr_count($h, '全站搜索'));
        }
    }
    return $h;
}

/** 页脚：逐块对齐 www 的 `<footer class="mt-16 border-t bg-muted/40">`；栏目换成实例真实入口 */
/** 页脚：**以 www 的真实 HTML 骨架为模板**（骨架 = lqwww/skel/home_footer.html）。
 *  参照页脚的 4 个栏目路径（/learn /mobile /tips /download /plugins /tool /notes /share
 *  /markdown* /tool/md2* /bookmark /gallery /wallpaper /about）实例**全都在** ⇒ 连文案一起
 *  逐字节照抄，只有品牌名 "liulanqi" 换成实例自己的 `site_label()`。
 *  ★ 从站头**移入**的语言切换 + 深色开关挂在底行内链簇尾部（`{{foot_extra}}`）：功能不丢。 */
function lqwww_footer(): string
{
    require_once __DIR__ . '/lq-skel.php';
    $lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
    $lnk = static function (string $href, string $label) use ($lp): string {
        return '<li><a class="hover:text-foreground" href="' . e($lp . $href) . '">' . e($label) . '</a></li>';
    };
    /* ① 栏目 1「站点」—— 与参照同 5 条 */
    $c1 = $lnk('/learn', '浏览器学堂') . $lnk('/mobile', '手机浏览器') . $lnk('/tips', '浏览器技巧')
        . $lnk('/download', '浏览器下载') . $lnk('/plugins', '浏览器插件');
    /* ② 栏目 2「工具箱」—— 与参照同 9 条（Markdown 那 6 条实例都是真页面） */
    $c2 = $lnk('/tool', '工具箱首页') . $lnk('/notes', 'Markdown 云笔记') . $lnk('/share', '笔记分享')
        . $lnk('/markdown', 'Markdown 工具精选') . $lnk('/markdown/tools', 'Markdown 站内工具')
        . $lnk('/markdown/syntax', 'Markdown 语法速查') . $lnk('/tool/md2html', 'Markdown 转 HTML')
        . $lnk('/tool/md2pdf', 'Markdown 转 PDF') . $lnk('/tool/md2word', 'Markdown 转 Word');
    /* ③ 栏目 3「更多」—— 与参照同 4 条 */
    $c3 = $lnk('/bookmark', '书签导航') . $lnk('/about', '关于 ' . site_label());

    if (\Auth::check()) {
        $ctaHref = ui_user(); $cta = lqwww_ico('users', 'h-4 w-4') . e(__('ui.my'));
    } else {
        $ctaHref = ui_login(); $cta = lqwww_ico('log-in', 'h-4 w-4') . e(__('ui.login'));
    }

    /* 底行内链簇尾部：语言切换 + 深色开关（自站头移入，见本函数头注释） */
    $extra = '';
    if (Poly::on()) {
        foreach (Poly::enabled() as $lc) {
            $onL = $lc === Lang::short();
            $extra .= '<a class="hover:text-foreground' . ($onL ? ' text-foreground' : '') . '" href="' . e(ui_lang_url($lc)) . '"'
                . ' hreflang="' . e($lc) . '"' . ($onL ? ' aria-current="true"' : '') . '>' . e(Poly::label($lc)) . '</a>';
        }
    }
    $extra .= '<a class="hover:text-foreground js-dark-toggle" href="#" role="button">' . e(__('ui.dark_title')) . '</a>';

    return lqskel_fill(lqskel('home_footer'), [
        'brand'         => e(site_label()),
        'foot_desc'     => e((string)get_option('site.subtitle', '')),
        'search_action' => e($lp . '/search'),
        'q'             => e((string)($_GET['q'] ?? '')),
        'search_btn'    => e(__('sr.btn')),
        'foot_note'     => e('记录与同步，登录后可用'),
        'foot_cta_href' => e($ctaHref),
        'foot_cta'      => $cta,
        'foot_col1'     => $c1,
        'foot_col2'     => $c2,
        'foot_col3'     => $c3,
        'foot_privacy'  => '<a class="hover:text-foreground" href="' . e($lp . '/privacy') . '">隐私政策</a>',
        'foot_extra'    => $extra,
        'foot_copy'     => '© <!-- -->' . date('Y') . '<!-- --> ' . e(site_label()) . ' · 上上网，更清爽。保留所有权利。',
    ], 'footer');
}

/* ============================ 4. 面包屑 / 分页 / 卡片 ============================ */

/** www 形状的面包屑（带 aria-label="breadcrumb" ⇒ lq-mech 自动让位，不重复渲染） */
function lqwww_crumbs(array $items): string
{
    $n = count($items);
    if ($n < 2) { return ''; }
    $h = '<nav class="mb-6 flex items-center gap-1 text-sm text-muted-foreground" aria-label="breadcrumb">';
    foreach ($items as $i => $c) {
        $last = ($i === $n - 1);
        $h .= '<span class="flex items-center gap-1">';
        if (!$last && (string)$c[1] !== '') {
            $h .= '<a class="hover:text-foreground" href="' . e((string)$c[1]) . '">' . e((string)$c[0]) . '</a>';
        } else {
            $h .= '<span class="text-foreground"' . ($last ? ' aria-current="page"' : '') . '>' . e((string)$c[0]) . '</span>';
        }
        if (!$last) { $h .= lqwww_ico('chevron-right', 'h-4 w-4'); }
        $h .= '</span>';
    }
    return $h . '</nav>';
}

/** 分页（保留实例的可用分页；www 形状的按钮条） */
function lqwww_pager(int $page, int $totalPages, string $base, int $totalItems, int $per): string
{
    if ($totalItems <= 0) { return ''; }
    $totalPages = max(1, $totalPages);
    $u = static function (int $n) use ($base): string { return str_replace('{n}', (string)$n, $base); };
    $btn = 'inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-colors';
    $h  = '<nav class="lq-pager mt-8 flex flex-wrap items-center justify-between gap-3" aria-label="分页">';
    $h .= '<span class="text-sm text-muted-foreground">共 ' . (int)$totalItems . ' 条 · 第 ' . (int)$page . ' / ' . $totalPages . ' 页</span>';
    $h .= '<span class="flex flex-wrap items-center gap-1.5">';
    if ($page > 1) {
        $h .= '<a class="' . $btn . ' hover:bg-accent" rel="prev" href="' . e($u($page - 1)) . '">上一页</a>';
    }
    $from = max(1, $page - 2);
    $to   = min($totalPages, max($page + 2, min($totalPages, 5)));
    for ($i = $from; $i <= $to; $i++) {
        $h .= $i === $page
            ? '<span class="' . $btn . ' bg-primary text-primary-foreground" aria-current="page">' . $i . '</span>'
            : '<a class="' . $btn . ' hover:bg-accent" href="' . e($u($i)) . '">' . $i . '</a>';
    }
    if ($page < $totalPages) {
        $h .= '<a class="' . $btn . ' hover:bg-accent" rel="next" href="' . e($u($page + 1)) . '">下一页</a>';
        $h .= '<a class="' . $btn . ' hover:bg-accent" href="' . e($u($totalPages)) . '">尾页</a>';
    }
    return $h . '</span></nav>';
}

/** 列表卡片（markup 取自参照 /tips 索引的卡片，逐类同名） */
function lqwww_card(array $r, string $prefix = ''): string
{
    $url  = $prefix . (string)($r['url'] ?? '');
    $t    = (string)($r['title'] ?? '');
    $lead = (string)($r['lead'] ?? '');
    $date = (string)($r['date'] ?? '');
    $pop  = (int)($r['pop'] ?? 0);
    $h  = '<a class="group block rounded-xl border bg-card p-5 shadow-sm transition hover:shadow-md hover:-translate-y-0.5" href="' . e($url) . '">';
    $h .= '<div class="flex items-start gap-3">';
    $h .= '<div class="rounded-lg bg-primary/10 p-2 text-primary">' . lqwww_ico('file-text', 'h-5 w-5') . '</div>';
    $h .= '<div class="min-w-0 flex-1">';
    $h .= '<h2 class="text-base font-semibold leading-snug text-card-foreground group-hover:text-primary">' . e($t) . '</h2>';
    if ($lead !== '') { $h .= '<p class="mt-2 line-clamp-3 text-sm text-muted-foreground">' . e($lead) . '</p>'; }
    $m = [];
    if ($date !== '') { $m[] = '更新于 ' . $date; }
    if ($pop > 0) { $m[] = '阅读 ' . $pop; }
    if ($m !== []) { $h .= '<p class="mt-3 text-xs text-muted-foreground">' . e(implode(' · ', $m)) . '</p>'; }
    $h .= '</div></div></a>';
    return $h;
}

/** 工具卡（/tool 与 /tool/c/*）：标题 + 描述 + 人气，同一套 www 卡片形状 */
function lqwww_tool_card(array $r, string $prefix = ''): string
{
    $url  = $prefix . (string)($r['url'] ?? '');
    $t    = (string)($r['title'] ?? '');
    $lead = (string)($r['lead'] ?? '');
    $pop  = (int)($r['pop'] ?? 0);
    $h  = '<a class="group block rounded-xl border bg-card p-5 shadow-sm transition hover:shadow-md hover:-translate-y-0.5" href="' . e($url) . '">';
    $h .= '<div class="flex items-start gap-3">';
    $h .= '<div class="rounded-lg bg-primary/10 p-2 text-primary">' . lqwww_ico('wrench', 'h-5 w-5') . '</div>';
    $h .= '<div class="min-w-0 flex-1">';
    $h .= '<h2 class="text-base font-semibold leading-snug text-card-foreground group-hover:text-primary">' . e($t) . '</h2>';
    if ($lead !== '') { $h .= '<p class="mt-2 line-clamp-3 text-sm text-muted-foreground">' . e($lead) . '</p>'; }
    if ($pop > 0) { $h .= '<p class="mt-3 text-xs text-muted-foreground">使用 ' . $pop . ' 次</p>'; }
    $h .= '</div></div></a>';
    return $h;
}

/* ============================ 5. 取数（只读 DB；不编造） ============================ */

function lqwww_store_slug(string $s): string
{
    return str_contains($s, '/') ? (string)preg_replace('#/#', '__', $s, 1) : $s;
}
function lqwww_url_slug(string $s): string
{
    return str_contains($s, '__') ? (string)preg_replace('#__#', '/', $s, 1) : $s;
}

/** 呈现用标题：去掉站点后缀（参照 www 的**卡片/列表**标题都不带 " - liulanqi"，而它自己的
 *  `<title>` 带后缀 ⇒ 这里只处理"卡片显示"，不动 SEO 标题）。
 *  只改呈现，不动任何内容行。 */
function lqwww_tool_title(string $slug, string $title): string
{
    $t = (string)preg_replace('/\s*[-–—·|]\s*liulanqi.*$/u', '', $title);
    $t = (string)preg_replace('/\s*[-–—·|]\s*浏览工坊.*$/u', '', $t);
    return trim($t) === '' ? $title : trim($t);
}

/** 摘要：description 优先，其次 excerpt，再其次正文纯文本；都没有 ⇒ 空（不编） */
function lqwww_lead(array $row, int $n = 120): string
{
    foreach (['description', 'excerpt'] as $k) {
        $v = trim((string)($row[$k] ?? ''));
        if ($v !== '') { return Str::excerpt($v, $n); }
    }
    $c = trim((string)($row['plain'] ?? ''));
    return $c === '' ? '' : Str::excerpt($c, $n);
}

/* ★★ lqlead 2026-10-06 —— **工具页专用**的 hero lead 口径（只在呈现时剥，DB 数据一个字节不动）
 * ---------------------------------------------------------------------------
 * 参照 `/tool/<slug>` 的 hero 副文案 = **一句纯描述**（无前缀、无后缀）：
 *   https://liulanqi.com/tool/text →
 *   「大小写转换、去重行、去空行、去除首尾空格、反转、统计字数/词数/行数，文本整理一站式。」
 * 而实例 DB 的 description 列把三段烘焙在一起：
 *   「<工具名>：<纯描述> 在线免费使用，内容本地处理、注重隐私、无需注册，属于 liulanqi「<分类>」分类下的实用工具。」
 * ⇒ 多出的前缀与后缀把 1440px 下的 hero 从 1 行顶成 2–3 行，整张工具卡随之下移 24–48px。
 * 本函数**只在呈现时**剥掉这两段：前缀只认"与本行标题逐字相同的那一段 + 全角/半角冒号"，
 * 后缀只认"在线免费使用…属于 liulanqi「…」分类下的实用工具。"这一条固定尾巴；剥不出就原样返回。
 * ★ 137 行逐条实测（见 _lqlead 报告）：135 行剥完与参照 hero **逐字相同**；
 *   `tool__markdown` 本来就没有这两段（原样返回 ⇒ 单页特殊项保持现状）；
 *   `tool__htmlentity` 的 description 在库里就是半截（数据缺陷，按纪律不动数据）。
 * ★ 调用点只有一处：`views/partials/tool.php`（工具页）。/tips、/learn 与各列表卡仍走 `lqwww_lead()`。
 */
function lqwww_tool_lead(array $row, int $n = 200): string
{
    $raw = '';
    foreach (['description', 'excerpt'] as $k) {
        $v = trim((string)($row[$k] ?? ''));
        if ($v !== '') { $raw = $v; break; }
    }
    if ($raw === '') { $raw = trim((string)($row['plain'] ?? '')); }
    if ($raw === '') { return ''; }

    /* ① 前缀「<呈现标题>：」—— 标题口径与 h1 同一份（lqwww_tool_title，去站点后缀） */
    $t = lqwww_tool_title((string)($row['slug'] ?? ''), (string)($row['title'] ?? ''));
    if ($t !== '') {
        foreach ([$t . '：', $t . ':'] as $pre) {
            if (str_starts_with($raw, $pre)) { $raw = substr($raw, strlen($pre)); break; }
        }
    }
    /* ② 后缀「 在线免费使用，……属于 liulanqi「…」分类下的实用工具。」 */
    $raw = (string)preg_replace('/\s*在线免费使用[，,].*?分类下的实用工具。\s*$/u', '', $raw);

    $raw = trim($raw);
    return $raw === '' ? '' : Str::excerpt($raw, $n);
}

/** 人气：优先 nginx 日志真实 PV；没有就回落 views 字段（可能为 0，不编造） */
function lqwww_pop(string $slug, int $views = 0): int
{
    static $pv = null;
    if ($pv === null) { $pv = lqwww_pv(); }
    $k = lqwww_url_slug($slug);
    $n = (int)($pv[$k] ?? 0);
    return $n > 0 ? $n : $views;
}

/** 真实 PV：只读 nginx 日志尾部 4MB×3，过滤爬虫，1 小时缓存（沿用本项目既有口径） */
function lqwww_pv(): array
{
    static $map = null;
    if ($map !== null) { return $map; }
    $ns = 'misc';
    $key = 'lqwww_pv_v1';
    if (class_exists('Cache')) {
        $raw = Cache::get($ns, $key);
        if (is_string($raw) && $raw !== '') {
            $d = json_decode($raw, true);
            if (is_array($d)) { return $map = $d; }
        }
    }
    $counts = [];
    $logs = ['/www/wwwlogs/liulanqi.com.log', '/www/wwwlogs/liulanqi.com.log.1',
             '/www/wwwlogs/test.liulanqi.com.log'];
    $tail = 4 * 1024 * 1024;
    foreach ($logs as $log) {
        if (!is_readable($log)) { continue; }
        $sz = (int)@filesize($log);
        $fh = @fopen($log, 'rb');
        if (!$fh) { continue; }
        if ($sz > $tail) { fseek($fh, $sz - $tail); fgets($fh); }
        while (($line = fgets($fh)) !== false) {
            if (stripos($line, 'bot') !== false || stripos($line, 'spider') !== false
                || stripos($line, 'crawl') !== false || stripos($line, 'ahrefs') !== false
                || stripos($line, 'semrush') !== false || stripos($line, 'curl') !== false
                || stripos($line, 'python') !== false || stripos($line, 'go-http') !== false) { continue; }
            if (preg_match('#GET /(tips|learn|tool|download|gallery)/([a-z0-9\-]+)#', $line, $m)) {
                $k = $m[1] . '/' . $m[2];
                $counts[$k] = ($counts[$k] ?? 0) + 1;
            }
        }
        fclose($fh);
    }
    if (class_exists('Cache') && $counts !== []) {
        Cache::set($ns, $key, json_encode($counts), 3600);
    }
    return $map = $counts;
}

/** 同栏目/全站最新取数（一次查询 + 请求内缓存） */
function lqwww_rows(string $section, int $limit = 30): array
{
    static $cache = [];
    $ck = $section . ':' . $limit;
    if (isset($cache[$ck])) { return $cache[$ck]; }
    $section = (string)preg_replace('/[^a-z0-9-]/', '', strtolower($section));
    $params = [];
    $where = "type='post' AND status='publish' AND deleted_at IS NULL";
    if ($section !== '') {
        $where .= ' AND slug LIKE ?';
        $params[] = $section . '\_\_%';
    }
    $rows = Db::rows(
        'SELECT slug, title, description, excerpt, views, published_at, updated_at, created_at FROM '
        . Db::t('contents') . ' WHERE ' . $where
        . ' ORDER BY published_at DESC, id DESC LIMIT ' . (int)$limit,
        $params
    );
    foreach ($rows as &$r) {
        $u = lqwww_url_slug((string)$r['slug']);
        $r['slk']   = lqwww_store_slug((string)$r['slug']);
        $r['url']   = yc_entry_url($u);
        $r['title'] = lqwww_tool_title((string)$r['slk'], (string)$r['title']);
        $r['lead']  = lqwww_lead($r, 120);
        $ts = strtotime((string)($r['updated_at'] ?: ($r['published_at'] ?: $r['created_at'])));
        $r['date']  = $ts ? date('Y-m-d', $ts) : '';
        $r['pop']   = lqwww_pop((string)$r['slk'], (int)$r['views']);
    }
    unset($r);
    return $cache[$ck] = $rows;
}

/** 由分页基底反推 section / 分类（与 index.php 的 pager_base 形态同源） */
function lqwww_section_of(string $pagerBase): array
{
    if (preg_match('#/tool/c/([a-z0-9-]+)#', $pagerBase, $m)) { return ['tool', $m[1]]; }
    if (preg_match('#/(tips|tool|learn|gallery|download)/#', $pagerBase, $m)) { return [$m[1], '']; }
    return ['', ''];
}

/** section 人类可读名（与 /tool 分类页、面包屑共用同一张表） */
function lqwww_label(string $section): string
{
    $m = ['tips' => '浏览器技巧', 'tool' => '在线工具', 'learn' => '浏览器学堂',
          'gallery' => '灵感画廊', 'download' => '浏览器下载'];
    return $m[$section] ?? ($section === '' ? '全部内容' : ucfirst($section));
}

/** 该 section（或工具分类）的条数：与列表页同源的真值，取不到就用已有页的行数兜底 */
function lqwww_total(string $section, string $cat = ''): int
{
    $section = (string)preg_replace('/[^a-z0-9-]/', '', strtolower($section));
    if ($section === 'tool' && $cat !== '') {
        $def = dirname(__DIR__, 2) . '/yecha/parts/cat-def.php';
        if (is_file($def)) { require_once $def; }
        if (function_exists('yc_tools_by_cat')) {
            return count((array)(yc_tools_by_cat()[$cat] ?? []));
        }
    }
    if ($section === '') {
        return (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('contents')
            . " WHERE type='post' AND status='publish' AND deleted_at IS NULL");
    }
    return (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('contents')
        . " WHERE type='post' AND status='publish' AND deleted_at IS NULL AND slug LIKE ?", [$section . '\_\_%']);
}

/* ============================ 6. 右栏（www 卡片形状） ============================ */

function lqwww_aside_box(string $title, string $inner, string $moreHref = '', string $moreText = ''): string
{
    $h = '<nav class="rounded-xl border bg-card p-3 text-sm shadow-sm">';
    $h .= '<div class="mb-1 flex items-center justify-between px-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">'
        . '<span>' . e($title) . '</span>';
    if ($moreHref !== '') {
        $h .= '<a class="text-primary hover:underline" href="' . e($moreHref) . '">' . e($moreText !== '' ? $moreText : '更多') . '</a>';
    }
    return $h . '</div>' . $inner . '</nav>';
}

/** 右下角浮动「快速记录」（参照 body 骨架里的那个 button，逐字节照抄）；
 *  实例侧把它接到真实可用的 /notes（云笔记）—— 参照那侧是个没有落点的 button。 */
function lqwww_fab(): string
{
    /* ★★ S3（仅 /download/*，2026-10-11 站主裁定）：老站 /download/* 页头【无】「快速记录」⇒ 该页型不输出。
       ★ 安全依据：该函数尾部那段脚本只服务 fab（querySelector 那个按钮 + if(b) 守卫），
         且本函数【只被 layout.php:255 一处调用】⇒ 一起不输出无副作用。 */
    if (lqwww_is_download()) { return ''; }
    require_once __DIR__ . '/lq-skel.php';
    $lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
    $h = lqskel('home_fab');
    if ($h === '') { return ''; }
    return $h . '<script>(function(){var b=document.querySelector("button[aria-label=\"快速记录\"]");'
        . 'if(b){b.addEventListener("click",function(){location.href="' . e($lp . '/notes') . '";});}})();</script>';
}

/** 页脚之后那条 `div.py-4`（参照 body 里 `#root` **之外**的隐私政策条；
 *  骨架 = `lqwww/skel/home_tail.html`，里面那个 {{tail}} 占位符由这里填一个真链接）。 */
function lqwww_tail(): string
{
    require_once __DIR__ . '/lq-skel.php';
    $lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
    return lqskel_fill(lqskel('home_tail'), [
        'tail' => '',
    ], 'tail');
}

/* ★★ lqta 2026-10-06 —— content 行里被烘进了「整页渲染」（<!DOCTYPE html> … <header> … <aside> … <footer>）。
 * 上一棒的做法是「按裸文本数 <div> 配平」——那会把**内联运行时 JS 字符串里的 '<div>'** 也数进去，
 * 于是截断点一路跑到内层页面末尾：内层的 nav / reader-bar / comments-area / </main> / 一串 </div>
 * 全被带进工具页（实测 /tool/text：nav·reader-bar·comments 各出现 **2 份**）。
 * 本版改成**按 HTML 词法扫描**：跳过注释、<!…/<?…、以及 script/style 的正文，
 * 只有真正的标签参与计数（JS 字符串 / 注释 / 属性值里的 '<' 不再算数）。
 *
 * 实测 137/137 行的外形完全一致（`_lqta` 的 137 行闸门 PASS=137 FAIL=0）：
 *   工具根 <div class="space-y-6" data-lq-tool=… data-lq-runtime=…>
 *     ├─ <div><p class="mt-2 max-w-2xl …">…</p></div>   ⇐ 与骨架 lead 重复，**丢掉**
 *     ├─ <div class="rounded-xl border bg-card … max-w-3xl"> ⇐ 参照的"工具块"就是它 ⇒ 挂载点搬到它身上
 *     ├─ <style>…</style>    工具自己的样式
 *     └─ <script>…</script>  LQTOOL 运行时
 *   ⇒ 外壳与参照逐项同族：main > div.space-y-6 > [nav 面包屑, div(h1+p), 那张卡片]
 *
 * 幂等：找不到锚点 / 配平失败一律**原样返回并记日志**（绝不猜、绝不砍错）。
 * 注：content 行一个字节都没改，本剥离只发生在渲染时（呈现层 themes/bl）。
 */

/** 标签起始处 $lt 之后、该标签 '>' 之后的那一个偏移（引号里的 '>' 不算） */
function lq4_tag_end(string $h, int $lt)
{
    $len = strlen($h); $i = $lt + 1; $q = '';
    while ($i < $len) {
        $ch = $h[$i];
        if ($q !== '') { if ($ch === $q) { $q = ''; } }
        elseif ($ch === '"' || $ch === "'") { $q = $ch; }
        elseif ($ch === '>') { return $i + 1; }
        $i++;
    }
    return false;
}

/** 从 $start 处的 <$tag …> 起，返回与之配对的那个 </$tag> **之后**的偏移。
 *  ★ 只认真正的标签：注释 / <!doctype / <?… / script|style 正文整段跳过。 */
function lq4_close_at(string $h, int $start, string $tag)
{
    $len = strlen($h); $i = $start; $depth = 0; $tag = strtolower($tag);
    while ($i < $len) {
        $lt = strpos($h, '<', $i);
        if ($lt === false) { return false; }
        if (substr($h, $lt, 4) === '<!--') { $e = strpos($h, '-->', $lt + 4); $i = ($e === false) ? $len : $e + 3; continue; }
        if (substr($h, $lt, 2) === '<!' || substr($h, $lt, 2) === '<?') { $e = strpos($h, '>', $lt); $i = ($e === false) ? $len : $e + 1; continue; }
        if (!preg_match('/\G<(\/?)([a-zA-Z][a-zA-Z0-9:._-]*)/', $h, $m, 0, $lt)) { $i = $lt + 1; continue; }
        $closing = ($m[1] === '/');
        $name    = strtolower($m[2]);
        $te      = lq4_tag_end($h, $lt);
        if ($te === false) { return false; }
        if ($name === 'script' || $name === 'style') {
            if (!$closing) {
                $p = stripos($h, '</' . $name, $te);
                if ($p === false) { return false; }
                $e2 = strpos($h, '>', $p);
                $i = ($e2 === false) ? $len : $e2 + 1;
                continue;
            }
        }
        if ($name === $tag) {
            $selfc = (substr(rtrim(substr($h, $lt, $te - $lt)), -2) === '/>');
            if ($closing) { $depth--; if ($depth === 0) { return $te; } }
            elseif (!$selfc) { $depth++; }
        }
        $i = $te;
    }
    return false;
}

/** [ $from, $to ) 这一段的**直接子元素**，返回 [ [tag, start, end], … ] */
function lq4_children_at(string $h, int $from, int $to): array
{
    $out = []; $i = $from;
    while ($i < $to) {
        $lt = strpos($h, '<', $i);
        if ($lt === false || $lt >= $to) { break; }
        if (substr($h, $lt, 4) === '<!--') { $e = strpos($h, '-->', $lt + 4); $i = ($e === false) ? $to : $e + 3; continue; }
        if (substr($h, $lt, 2) === '<!' || substr($h, $lt, 2) === '<?') { $e = strpos($h, '>', $lt); $i = ($e === false) ? $to : $e + 1; continue; }
        if (!preg_match('/\G<(\/?)([a-zA-Z][a-zA-Z0-9:._-]*)/', $h, $m, 0, $lt)) { $i = $lt + 1; continue; }
        if ($m[1] === '/') { $i = $lt + 1; continue; }
        $name = strtolower($m[2]);
        $te   = lq4_tag_end($h, $lt);
        if ($te === false) { break; }
        if ($name === 'script' || $name === 'style') {
            $p = stripos($h, '</' . $name, $te);
            $e2 = ($p === false) ? false : strpos($h, '>', $p);
            $end = ($e2 === false) ? $to : $e2 + 1;
            $out[] = [$name, $lt, $end];
            $i = $end;
            continue;
        }
        if (substr(rtrim(substr($h, $lt, $te - $lt)), -2) === '/>') { $out[] = [$name, $lt, $te]; $i = $te; continue; }
        $end = lq4_close_at($h, $lt, $name);
        if ($end === false) { break; }
        $out[] = [$name, $lt, $end];
        $i = $end;
    }
    return $out;
}

/** 把每个 script/style 的**正文**抹掉（只用于"数标记"，不用于切片） */
function lq4_without_raw(string $h): string
{
    return (string)preg_replace_callback(
        '/<(script|style)\b[^>]*>.*?<\/\1\s*>/is',
        static function (array $m): string { return '<' . $m[1] . '></' . $m[1] . '>'; },
        $h
    );
}

/* ★★ lqta 2026-10-06 —— 参照「工具块」外壳**逐页实测**（main > div.space-y-6 的第 3 个子元素）。
 *  参照自己就有 5 种（不是我们编的）：
 *    · rounded-xl border bg-card text-card-foreground shadow-sm mx-auto w-full max-w-3xl    99 页（主形态）
 *    · 其余 4 种见下表。
 *  ⇒ 工具页按**参照那一页**的形态套（同一类里的 slug 共用同一串类名）。
 *  依据 = `_lqta/out/toolblock.tsv`（136 页逐页抓取 + 逐页比对）。
 */
function lq4_ref_toolblock_class(string $slug): string
{
    static $map = null;
    if ($map === null) {
        $default = 'rounded-xl border bg-card text-card-foreground shadow-sm mx-auto w-full max-w-3xl';
        $map = [];
        $groups = [
        'space-y-4' => ['agediff', 'clock', 'contrast', 'discount', 'faviconx', 'gradient', 'md-badge', 'md-convert', 'md-diagram', 'md-format', 'md-fromoffice', 'md-github-cards', 'md-lint', 'md-mindmap', 'md-slide', 'md-toc', 'md-todocx', 'md-wechat', 'md2html', 'md2image', 'md2pdf', 'md2word', 'moonphase', 'petage', 'teatimer'],
        'rounded-xl border bg-card text-card-foreground shadow-sm' => ['base64', 'color', 'json', 'password', 'qrcode', 'random', 'text', 'timestamp', 'todo', 'unit'],
        'rounded-xl border bg-card text-card-foreground shadow-sm mx-auto max-w-xs' => ['calculator'],
        'rounded-xl border bg-card text-card-foreground shadow-sm mx-auto max-w-md' => ['calendar'],
        /* ★ 2026-10-07 · 参照 /tool/markdown 的**外壳**是这一串（census: ref blockCls），原先没登记 ⇒ 落到了默认壳 */
        'flex min-w-0 flex-1 flex-col rounded-xl border bg-card' => ['markdown'],
        ];
        foreach ($groups as $cls => $slugs) { foreach ($slugs as $s) { $map[$s] = $cls; } }
        $map['__default__'] = $default;
    }
    return $map[$slug] ?? ($map['__default__'] ?? '');
}

/* ★★ 2026-10-07 · ⑤a「卡内 h1+p」一处注入 -------------------------------------------------
 * 参照的工具卡里**同时**有「页面级 h1+lead」与「卡内 h1+p」两组，我们此前只有前者。
 * 本函数按 slug 返回参照那张卡的 (h1, p) 文案（逐字节取自 seo/tool/<slug>.html，**已去掉 React SSR 的
 * `<!-- -->` 相邻文本节点分隔符**），由 lq4_tool_fragment() **加一层**插到卡片最前面 ——
 * 我们自己的 lead 原样保留、**不替换**。
 * 只收「与我们的 lead 逐字相等或近似」的 93 页；相差大的 7 页（audiotrim markdown barcode tax stt
 * railfence regex）**故意不收**，等站主定。
 */
function lq4_ref_card_title(string $slug): ?array
{
    static $map = null;
    if ($map === null) {
        $map = [
            'affine' => ['仿射密码', 'E(x) = (a·x + b) mod 26，a 需与 26 互质，经典单表替换密码。'],
            'age' => ['年龄计算', '根据出生日期计算精确年龄。'],
            'area' => ['电话区号', '城市长途区号查询（部分）。'],
            'audioconv' => ['音频格式转换', '将常见音频格式统一转换为 WAV（无损 PCM 16bit）。'],
            'audiogain' => ['音量调节', '增强或降低音量（0 - 200%），导出为 WAV。'],
            'audiojoin' => ['音频拼接', '按顺序合并多段音频为一段（WAV 导出）。'],
            'audiospeed' => ['音频变速', '调整播放速度（0.5x - 2x），试听后导出为 WAV。'],
            'avatar' => ['头像生成', '根据文字生成纯几何 SVG 头像（可下载）。'],
            'baseconv' => ['进制转换', '在 2 / 8 / 10 / 16 进制之间互转，支持大整数。'],
            'binary' => ['二进制编解码', '文本与 0/1 二进制串互转，便于理解字符编码。'],
            'blood' => ['血型性格', '常见血型性格与相处参考（娱乐向）。'],
            'bmi' => ['BMI 计算', '身体质量指数与参考区间。'],
            'browserinfo' => ['浏览器信息', '查看当前浏览器与设备的完整信息，全部来自本地，不上传。'],
            'caesar' => ['凯撒密码', '经典移位密码（默认位移 3），支持加密与解密。'],
            'capital' => ['首都查询', '国家 → 首都（部分常用）。'],
            'case' => ['命名风格转换', '在 camelCase / PascalCase / snake_case / kebab-case / 常量 / 标题 之间互转。'],
            'chmod' => ['chmod 计算器', '勾选权限位，实时得到数字（如 755）与符号（如 rwxr-xr-x）表示。'],
            'cidr' => ['CIDR 计算', '计算网段、掩码、可用主机范围。'],
            'coin' => ['抛硬币', '随机正反面决定。'],
            'compound' => ['复利计算', '本金按年化利率复利增长终值。'],
            'constellation' => ['星座查询', '根据出生日期查询星座。'],
            'countdown' => ['倒计时', '距离目标时间的实时倒计时。'],
            'countrycode' => ['国家代码', '国家名 / ISO 代码 / 电话区号对照。'],
            'cron' => ['Cron 解析', '解析 5 段 Cron 表达式，列出最近 5 次执行时间。'],
            'csv' => ['CSV / JSON 互转', '表格与 JSON 数组互相转换，支持引号转义。'],
            'dynasty' => ['中国历史朝代', '主要朝代与存续年代。'],
            'emoji' => ['表情符号', '点击复制常用 emoji。'],
            'extractlinks' => ['提取链接', '从文本中提取所有 http/https 链接，每行一个，方便批量复制。'],
            'favicon' => ['Favicon 生成', '上传图片，生成多尺寸 PNG 图标。'],
            'gitignore' => ['.gitignore 生成', '勾选技术栈，生成对应 .gitignore 模板。'],
            'group' => ['随机分组', '把名单随机均分到若干组。'],
            'hash' => ['Hash 计算', '计算文本的 MD5 / SHA-1 / SHA-256 / SHA-512（本地计算，不上传）。'],
            'hex' => ['HEX 编解码', '文本与十六进制互转（UTF-8 安全），常用于调试与字节查看。'],
            'htmlentity' => ['HTML 实体编解码', 'HTML 特殊字符（&amp; &lt; &gt; &quot; &#x27;）与实体（&amp;amp; 等）互转。'],
            'httpcode' => ['HTTP 状态码', '查询常见 HTTP 状态码含义。'],
            'idcard' => ['身份证信息', '校验 18 位身份证，提取出生日期、性别与年龄。'],
            'imgbase64' => ['图片转 Base64', '上传图片得到 DataURL，或粘贴 DataURL 预览图片。'],
            'imgcompress' => ['图片压缩', '在浏览器内降低 JPEG 质量，本地压缩不上传。'],
            'imgformat' => ['图片格式转换', 'PNG / JPEG / WebP 互转（浏览器支持范围内）。'],
            'imggray' => ['图片黑白', '转灰度图。'],
            'imggrid' => ['九宫格切图', '把图片切成 3×3 九张，分别下载。'],
            'imginfo' => ['图片信息', '查看图片尺寸、格式与体积。'],
            'imginvert' => ['图片反相', '颜色反相处理。'],
            'imgmosaic' => ['图片马赛克', '像素化局部或全部区域（整图）。'],
            'imgpick' => ['图片取色', '点击图片任意位置取色（HEX）。'],
            'imgradius' => ['图片圆角', '为图片添加圆角裁剪。'],
            'imgresize' => ['图片改尺寸', '按目标宽度等比缩放。'],
            'imgwatermark' => ['图片水印', '在右下角添加半透明文字水印。'],
            'installment' => ['分期计算', '估算每期还款（含手续费）。'],
            'ip' => ['IP 查询', '查询本机公网 IP 与大致地理位置（调用公开接口）。'],
            'jwt' => ['JWT 解码', '解码 JSON Web Token 的 header 与 payload（仅本地解析，不上传）。'],
            'lorem' => ['假文生成', '生成占位用中文段落，方便排版预览。'],
            'lottery' => ['抽奖助手', '从名单中随机抽取中奖者，不重复，适合活动与课堂点名。'],
            'luhn' => ['银行卡校验', 'Luhn 算法校验银行卡 / 信用卡号是否有效，并识别 Visa、MasterCard、银联、JCB 等卡组织。'],
            'mock' => ['Mock 数据', '按字段名快速生成示例 JSON 数据（姓名/城市/年龄等智能识别）。'],
            'morse' => ['摩斯电码', '英文与数字与摩斯电码（. -）互转。'],
            'mortgage' => ['房贷计算器', '等额本息月供与总利息估算。'],
            'numcn' => ['数字转中文', '阿拉伯数字转中文小写（如 1234 → 一千二百三十四）。'],
            'numrmb' => ['中文大写金额', '数字转人民币大写（如 1234.5 → 壹仟贰佰叁拾肆元伍角）。'],
            'paint' => ['在线画板', '自由涂鸦画板，支持画笔/橡皮/颜色与粗细调节，一键导出 PNG。'],
            'palette' => ['配色方案', '输入种子词，生成一组协调的配色（HSL）。'],
            'period' => ['生理周期', '按周期估算下次日期（仅供参考）。'],
            'plate' => ['车牌归属地', '车牌首位省份简称 → 省份（部分）。'],
            'pomodoro' => ['番茄钟', '25 分钟专注计时。'],
            'pregnancy' => ['预产期计算', '按末次月经（LMP）+280 天估算预产期。'],
            'punycode' => ['Punycode 转换', '中文域名与国际化域名（IDN）的 Punycode 互转。'],
            'recorder' => ['录音', '使用浏览器麦克风录制音频（WebM），本地完成不上传。'],
            'relatives' => ['亲戚称呼', '以“我”为基准查询常见亲属称谓（基础版）。'],
            'roman' => ['罗马数字转换', '阿拉伯数字（1-3999）与罗马数字互转。'],
            'rot13' => ['ROT13', '字母位移 13 位的自逆密码，常用于文本打码。'],
            'screenrec' => ['屏幕录制', '录制屏幕与系统声音（WebM），浏览器本地完成。'],
            'simptrad' => ['简繁转换', '简体中文与繁体中文（正体）一键互转，227 常用字映射，本地处理不上传，适合文档与内容转换。'],
            'size' => ['尺码对照', '男鞋欧码 / 脚长（cm）对照（部分常用）。'],
            'snowflake' => ['雪花 ID 解析', '解析 Twitter 雪花算法 ID，得到时间戳、机器号与序列号。'],
            'svg2png' => ['SVG 转 PNG', '粘贴 SVG 代码，转换为 PNG 下载。'],
            'textdiff' => ['文本对比', '逐行对比两段文本，红色为删除、绿色为新增。'],
            'tianti' => ['天干地支', '年份干支纪年（如 2026 丙午）。'],
            'totp' => ['TOTP 动态口令', '根据 Base32 密钥生成 Google 验证器风格 6 位动态码（每 30 秒刷新）。'],
            'tts' => ['文字转语音', '浏览器本地语音合成（TTS），支持多语音、语速与音调调节。'],
            'ua' => ['User-Agent 解析', '解析浏览器、操作系统与设备类型。'],
            'unicode' => ['Unicode 编解码', '文本与 \\uXXXX 形式的 Unicode 转义互转，便于调试与传输。'],
            'url' => ['URL 编解码', '对网址或参数进行百分比编码 / 解码，处理中文与特殊字符。'],
            'uuid' => ['UUID 生成', '生成 RFC 4122 v4 随机 UUID。'],
            'videotrim' => ['视频裁剪', '选取起止时间导出视频片段（WebM），全部在本地完成。'],
            'vigenere' => ['维吉尼亚密码', '多表替换密码，用密钥循环决定每个字母的位移。'],
            'whattoeat' => ['今天吃什么', '选择困难症救星，随机推荐。'],
            'wheel' => ['转盘抽奖', '自定义奖项列表，点击旋转随机抽奖，全部本地完成。'],
            'width' => ['全角半角转换', '在英文全角（ＡＢＣ）与半角（ABC）之间互转，适合中英混排排版。'],
            'wordcount' => ['字数统计', '实时统计字符、汉字、英文单词、行数、段落与预计阅读时间。'],
            'worldclock' => ['世界时钟', '查看多个时区的当前时间（每秒刷新）。'],
            'xml' => ['XML 格式化', '缩进美化 XML，按层级换行，便于阅读与排查结构。'],
            'yaml' => ['YAML / JSON 互转', '配置文件格式互转（基础结构：映射 / 列表 / 标量）。'],
            'zodiac' => ['生肖查询', '根据年份查询生肖属相。'],
            'barcode' => ['条形码生成', '生成 Code39 条形码（支持 A-Z 0-9 及 - . $ / + % 空格）。'],
            'tax' => ['个税计算', '按年度综合所得（含 6 万免征额与专项扣除）估算。'],
            'audiotrim' => ['音频裁剪', '选取起止时间并导出为 WAV，支持拖动滑块精确定位。'],
            'stt' => ['语音转文字', '浏览器本地语音识别（需联网调用系统引擎），实时转写中文。'],
            'railfence' => ['栅栏密码', '按固定栏数把明文写成锯齿形，再逐行读出形成密文（换位密码）。'],
        ];
    }
    return $map[$slug] ?? null;
}

/**
 * ★ 2026-10-07 · 锚定前先把 `<script>`/`<style>` **整段**（含两个标签）换成**等长空格**。
 *   理由：本主题用**字符串锚**（`strpos($html,'data-lq-tool=')`）找工具根，而页内内联运行时的源码/注释里
 *   也含 `data-lq-tool=`（例：运行时头部注释 `<div data-lq-tool="imgcompress" …>`）⇒ 字符串锚会锚到错位置，
 *   与运行时那侧用 DOM 选择器（`LQ.$$('[data-lq-tool]')`）的语义不一致。掩掉后锚定只在**标记**里发生。
 *   ★ 函数内自检：长度必须不变、首尾字节必须未动（否则返回原串）。
 */
function lq4_mask_script_style(string $html): string
{
    $out = $html;
    foreach (['script', 'style'] as $tag) {
        $off = 0;
        while (($lt = stripos($out, '<' . $tag, $off)) !== false) {
            $gt = strpos($out, '>', $lt);
            if ($gt === false) { break; }
            if (substr(rtrim(substr($out, $lt, $gt - $lt)), -2) === '/>') { $off = $gt + 1; continue; }
            $close = stripos($out, '</' . $tag, $gt);
            if ($close === false) { break; }
            $end = strpos($out, '>', $close);
            if ($end === false) { break; }
            $len = $end + 1 - $lt;                       // ★ 整段（含开、闭两个标签）
            $out = substr($out, 0, $lt) . str_repeat(' ', $len) . substr($out, $end + 1);
            $off = $lt + $len;
        }
    }
    if (strlen($out) !== strlen($html)) {
        error_log('[lqta] mask: length changed ' . strlen($html) . ' -> ' . strlen($out));
        return $html;
    }
    if ($html !== '' && (substr($out, 0, 1) !== substr($html, 0, 1) || substr($out, -1) !== substr($html, -1))) {
        error_log('[lqta] mask: head/tail altered');
        return $html;
    }
    return $out;
}

function lq4_tool_fragment(string $html, string $slug = ''): string
{
    // ★ 只在**标记**里锚定（整段掩掉 script/style，等长替换 ⇒ 偏移不变）
    $masked = lq4_mask_script_style($html);
    $anchor = strpos($masked, 'data-lq-tool=');
    if ($anchor === false) { error_log('[lqta] tool body: no data-lq-tool anchor'); return $html; }
    $lt = strrpos(substr($html, 0, $anchor), '<');
    if ($lt === false) { error_log('[lqta] tool body: no tag start before the anchor'); return $html; }
    $rootEnd = lq4_close_at($html, $lt, 'div');
    if ($rootEnd === false) { error_log('[lqta] tool body: tool root div not balanced'); return $html; }
    $tagEnd  = lq4_tag_end($html, $lt);
    $openTag = substr($html, $lt, $tagEnd - $lt);
    $mount   = '';
    if (preg_match('/\sdata-lq-tool="[^"]*"/', $openTag, $m)) { $mount .= $m[0]; }
    if (preg_match('/\sdata-lq-runtime="[^"]*"/', $openTag, $m2)) { $mount .= $m2[0]; }
    if ($mount === '') { error_log('[lqta] tool body: mount attrs not found'); return $html; }

    $kids = lq4_children_at($html, $tagEnd, $rootEnd - 6);
    if (!$kids) { error_log('[lqta] tool body: tool root has no element children'); return $html; }
    $keep = [];
    foreach ($kids as $k) {
        $seg = substr($html, $k[1], min(200, $k[2] - $k[1]));
        /* 丢掉的只有"烘焙进去的、和骨架 lead 重复的那一段" */
        if ($k[0] === 'div' && preg_match('/^<div[^>]*>\s*<p class="mt-2 max-w-2xl/', $seg)) { continue; }
        $keep[] = $k;
    }
    if (!$keep || $keep[0][0] !== 'div') { error_log('[lqta] tool body: unexpected child shape'); return $html; }

    /* 挂载点搬到卡片上；并把这张卡片的 class 换成**参照那一页**的工具块 class
       （参照自己有 5 种外壳：99 主形态 / 25 `space-y-4` / 10 通栏卡 / 1 `max-w-xs` / 1 `max-w-md`） */
    $fte = lq4_tag_end($html, $keep[0][1]);
    $fop = substr($html, $keep[0][1], $fte - $keep[0][1]);
    $cls = ($slug !== '' && function_exists('lq4_ref_toolblock_class')) ? lq4_ref_toolblock_class($slug) : '';
    if ($cls !== '') {
        if (preg_match('/\sclass="[^"]*"/', $fop)) {
            $fop = (string)preg_replace('/\sclass="[^"]*"/', ' class="' . $cls . '"', $fop, 1);
        } else {
            $fop = (string)preg_replace('/\s*>$/', ' class="' . $cls . '">', $fop, 1);
        }
    }
    if (strpos($fop, 'data-lq-tool=') === false) {
        $fop = (string)preg_replace('/\s*>$/', ' ' . trim($mount) . '>', $fop, 1);
    }
    $keep[0][3] = $fop;                      /* 用改写后的开标签（属性长度变了，但下面按偏移重新截取，不依赖长度） */
    /* ⑤a：卡内 h1+p —— ★ 守卫只看**这张卡自己的开头 240 字节**。
       上一版扫的是整行 `$html`，可这一行里还留着**烘焙过的旧整页外壳**（它自己也有一个 text-2xl 的 h1）
       ⇒ 每一页都被判成"已有卡内标题"、全部跳过 ⇒ 产物 CHANGED=0（被清单闸照出来的）。*/
    $out = '';
    $lq4_injected = 0;
    foreach ($keep as $k) {
        if (isset($k[3])) {
            $te2 = lq4_tag_end($html, $k[1]);
            /* ★ 注入点：**卡片第一个子元素**（class 含 space-y-）的开标签之后 —— 参照那张卡的标题就在
               内层 `space-y-4 p-5` 包装的最前面（所以 h1 的 x 是 277 而不是 257、宽 726 而不是 766）。
               没有这种内层包装时，退回"卡片开标签之后"。★ 守卫也随之内移：只看**注入点之后** 240 字节。 */
            $lq4_ins = $te2;
            $lq4_tag3 = '';
            if (preg_match('/\G\s*<div\s[^>]*class="[^"]*\bspace-y-[^"]*"[^>]*>/', $html, $m3, 0, $te2)) {
                $lq4_ins = $te2 + strlen($m3[0]);
                $lq4_tag3 = $m3[0];
            }
            $lq4_title_html = '';
            if ($slug !== '' && $lq4_injected === 0) {
                if (strpos(substr($html, $lq4_ins, 240), '<h1 class="text-2xl font-bold">') === false) {
                    $t = lq4_ref_card_title($slug);
                    if ($t !== null) {
                        $lq4_title_html = '<div><h1 class="text-2xl font-bold">' . e($t[0]) . '</h1>'
                            . '<p class="mt-1 text-sm text-muted-foreground">' . e($t[1]) . '</p></div>';
                    }
                }
                $lq4_injected = 1;
            }
            if ($lq4_title_html !== '' && $lq4_tag3 !== '') {
                $out .= $k[3] . $lq4_tag3 . $lq4_title_html . substr($html, $lq4_ins, $k[2] - $lq4_ins);
            } else {
                $out .= $k[3] . $lq4_title_html . substr($html, $te2, $k[2] - $te2);
            }
        } else {
            $out .= substr($html, $k[1], $k[2] - $k[1]);
        }
    }
    if (trim($out) === '') { error_log('[lqta] tool body: empty after strip'); return $html; }
    return $out;
}
