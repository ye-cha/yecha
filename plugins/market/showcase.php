<?php
/**
 * t38 · 在线主题 / 插件页（主题页 /theme[/<id>]，插件页 /plugin[/<id>]）
 * 数据源：直接扫描 themes/<id>/theme.json 与 plugins/<id>/plugin.php（不手写清单，不随版本过期）。
 * 安装包：/download/pkg/<type>-<id>-<ver>.tar.gz（由 /root/ta38/build 生成，索引 download/pkg/index.json）。
 * 双语：界面中英切换；条目名/简介用下方 EN 词典（缺省回落中文元数据）。
 */

/* ---------------- 数据扫描 ---------------- */

function yc_sc_en(): bool
{
    return Poly::on() && Lang::short() !== Poly::defaultLang();
}

function yc_sc_origin(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'yecha.org');
    if ($host === '' || !preg_match('/^[a-z0-9.\-:]+$/i', $host)) { $host = 'yecha.org'; }
    return 'https://' . $host;
}

/** 主题元数据：id => array */
function yc_sc_themes(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $root = dirname(__DIR__, 2) . '/themes';
    $out = [];
    foreach (glob($root . '/*/theme.json') ?: [] as $f) {
        $id = basename(dirname($f));
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j)) { $j = []; }
        $out[$id] = [
            'id' => $id,
            'type' => 'theme',
            'name' => trim((string)($j['name'] ?? $id)) !== '' ? (string)$j['name'] : $id,
            'ver' => (string)($j['version'] ?? ''),
            'author' => (string)($j['author'] ?? 'YeCha'),
            'license' => (string)($j['license'] ?? 'MIT'),
            'desc' => trim((string)($j['description'] ?? '')),
            'requires' => (string)($j['requires_core'] ?? ($j['requires']['core'] ?? '')),
            'settings' => is_array($j['settings'] ?? null) ? $j['settings'] : [],
            'dir' => $root . '/' . $id,
        ];
    }
    ksort($out);
    $cache = $out;
    return $out;
}

/** 插件元数据：id => array（目录即清单；无 plugin.php 的按扩展库登记，保证与实际目录数一致） */
function yc_sc_plugins(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $root = dirname(__DIR__, 2) . '/plugins';
    $out = [];
    foreach ((array)scandir($root) as $d) {
        if ($d === '.' || $d === '..' || !is_dir($root . '/' . $d)) { continue; }
        $pf = $root . '/' . $d . '/plugin.php';
        $m = is_file($pf) ? @include $pf : null;
        if (!is_array($m)) { $m = []; }
        $id = (string)($m['id'] ?? $d);
        $out[$d] = [
            'id' => $id,
            'dir' => $root . '/' . $d,
            'type' => 'plugin',
            'name' => trim((string)($m['name'] ?? '')) !== '' ? (string)$m['name'] : $d,
            'ver' => (string)($m['ver'] ?? ''),
            'author' => (string)($m['author'] ?? 'YeCha'),
            'license' => (string)($m['license'] ?? 'MIT'),
            'desc' => (string)($m['desc'] ?? ''),
            'requires' => (string)($m['requires']['core'] ?? ($m['requires_core'] ?? '')),
            'tables' => is_array($m['tables'] ?? null) ? $m['tables'] : [],
            'pages' => is_array($m['pages'] ?? null) ? $m['pages'] : [],
            'manifest' => $m !== [],
            'enabled' => (string)get_option('plugin.' . $id . '.enabled', '0') === '1',
        ];
    }
    ksort($out);
    $cache = $out;
    return $out;
}

/** 安装包索引（下载页构建产物）：key => [file,size,sha256] */
function yc_sc_pkgs(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $f = dirname(__DIR__, 2) . '/download/pkg/index.json';
    $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    $cache = is_array($j) ? $j : [];
    return $cache;
}

function yc_sc_pkg(array $it): ?array
{
    $all = yc_sc_pkgs();
    $key = $it['type'] . '-' . $it['id'];
    return isset($all[$key]) ? $all[$key] : null;
}

/** 缩略图：优先真截图（uploads/market/shots），其次主题 logo，最后占位 SVG */
function yc_sc_thumb(array $it): string
{
    $base = dirname(__DIR__, 2) . '/uploads/market';
    foreach (['png', 'jpg', 'jpeg', 'webp', 'gif'] as $ext) {
        $p = $base . '/thumbs/' . $it['type'] . '-' . $it['id'] . '.' . $ext;
        if (is_file($p)) { return '/uploads/market/thumbs/' . $it['type'] . '-' . $it['id'] . '.' . $ext; }
    }
    return '/uploads/market/thumbs/' . $it['type'] . '-' . $it['id'] . '.svg';
}

function yc_sc_shots(array $it): array
{
    $base = dirname(__DIR__, 2) . '/uploads/market/shots/' . $it['id'];
    $out = [];
    foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
        foreach (glob($base . '/*.' . $ext) ?: [] as $f) {
            $out[] = '/uploads/market/shots/' . $it['id'] . '/' . basename($f);
        }
    }
    sort($out);
    return array_values(array_unique($out));
}

/** 文件清单（相对路径 + 大小，最多 $limit 条） */
function yc_sc_files(array $it, int $limit = 240): array
{
    $root = $it['dir'];
    $out = [];
    $n = 0;
    $walk = function (string $dir, string $rel) use (&$walk, &$out, &$n, $limit): void {
        foreach ((array)scandir($dir) as $e) {
            if ($e === '.' || $e === '..') { continue; }
            $p = $dir . '/' . $e;
            $r = $rel === '' ? $e : $rel . '/' . $e;
            if (is_dir($p)) { $walk($p, $r); if ($n >= $limit) { return; } continue; }
            $out[] = ['p' => $r, 's' => (int)@filesize($p)];
            $n++;
            if ($n >= $limit) { return; }
        }
    };
    $walk($root, '');
    sort($out);
    return $out;
}

function yc_sc_size(int $b): string
{
    if ($b >= 1048576) { return number_format($b / 1048576, 1) . ' MB'; }
    if ($b >= 1024) { return number_format($b / 1024, 1) . ' KB'; }
    return $b . ' B';
}

function yc_sc_dirsize(string $dir): int
{
    $s = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile()) { $s += (int)$f->getSize(); }
    }
    return $s;
}

/* ---------------- 英文词典（缺译时回落中文元数据；本词典为本轮新增文案） ---------------- */

function yc_sc_en_map(): array
{
    return [
        // themes
        'antai' => ['Antai', 'Clean card-style blog: airy background, soft indigo accents, rounded cards, system sans-serif and a large search field.'],
        'astra' => ['Astra', 'Light and airy blue-tone blog theme.'],
        'bl' => ['yecha (minimal)', 'A minimal single-column variant of the default YeCha theme.'],
        'chawiki' => ['ChaWiki', 'Wiki/documentation three-column layout in tea-green and tea-brown, with article typography, floating images and a lightbox.'],
        'duyi' => ['Duyi', 'GitHub-style minimal blog: pure white, restrained blue accent, system sans-serif.'],
        'forest' => ['Forest', 'Deep pine green and moss-paper theme with serif headings; portal home, article/comment templates and styled RSS/sitemap.'],
        'hanyan' => ['Hanyan', 'High-contrast black-and-white minimal theme with monochrome accents.'],
        'help' => ['Help Center', 'Documentation theme with a fixed sidebar tree, toolbar and three-part layout; suits product docs, FAQ and knowledge bases.'],
        'huangyu' => ['Huangyu', 'Minimal personal blog: white background with teal accents and a simple post list.'],
        'kejiyuan' => ['Kejiyuan', 'Blue tech-blog style with a post list and tag cloud.'],
        'kuake' => ['Kuake', 'Paper-white minimal single-column blog with red link accents.'],
        'liuchuan' => ['Liuchuan', 'GitHub-blue personal blog on a light grey background.'],
        'lvjian' => ['Lvjian', 'Deep green header, left-aligned hero, compact card grid and green action links.'],
        'mayu' => ['Mayu', 'Developer-community style with cyan-blue accents and a clean technical layout.'],
        'mobai' => ['Mobai', 'u.sb-style warm grey monochrome, centred display titles and a two-column card grid.'],
        'octern' => ['WarmPaper', 'Warm paper background, ochre accents and serif headings, with dark mode; the flagship original theme.'],
        'paiji' => ['Paiji', 'Minimal notes blog with warm paper, wine-red accents and serif reading type.'],
        'pidan' => ['Pidan', 'Terracotta accents, cream background and a handwritten-style typeface; playful and cosy.'],
        'pinyun' => ['Pinyun', 'Fresh cloud-style personal blog in pale green with a large search field.'],
        'pyq' => ['Moments', 'WeChat Moments-style feed: fixed grey background, narrow white column, cover plus avatar, nine-grid images.'],
        'qichu' => ['Qichu', 'Warm paper, brown serif accents and a centred single-column profile with post stream.'],
        'rongguang' => ['Rongguang', 'Tufte-style paper minimalism: warm white, ink-blue accents and generous serif whitespace.'],
        'tales-hue' => ['Tales Hue', 'Colourful story-catalogue theme built on six traditional Chinese colours; bilingual and light/dark.'],
        'tales-ink' => ['Tales Ink', 'Black-and-white story-catalogue theme with a single pine-green accent; bilingual and light/dark.'],
        'teadoc' => ['TeaDoc', 'Documentation theme: hero home with a learning tree and a three-column article page; two palettes with light/dark.'],
        'tianqing' => ['Tianqing', 'Ru-ware sky-blue accents, soft cool background, rounded card grid, dark mode and a drawer menu.'],
        'wenshu' => ['Wenshu', 'MkDocs Material-style documentation theme in indigo.'],
        'xinglan' => ['Xinglan', 'Modern minimal blog with a soft blue accent and a large search field.'],
        'xinlv' => ['Xinlv', 'Emerald green and slate grey, kai-style body text and a three-column layout.'],
        'yecha' => ['yecha', 'The default minimal YeCha theme.'],
        'yuesi' => ['Yuesi', 'Fresh personal blog with a sky-blue background and cyan accents.'],
        'yunku' => ['Yunku', 'Knowledge-base portal: large search hero, category sections and a latest-post list.'],
        'zhijian' => ['Zhijian', 'AstroPaper-style notebook: warm paper, terracotta accents and serif body text.'],
        'zhiku' => ['Zhiku', 'Knowledge-base documentation style with a light blue-grey background and clean white cards.'],
        // plugins
        'ai' => ['AI capabilities', 'AI layer for other plugins: chat, multilingual translation, knowledge-base RAG Q&A, support endpoint and call logs.'],
        'autosave' => ['Auto-save', 'Keeps multiple draft snapshots while writing; view, restore or delete them from the admin panel.'],
        'demo' => ['Live demos', 'Public page listing YeCha demo sites with scheduled uptime checks and visitor applications.'],
        'entry-static' => ['Static entry pages', 'Bakes published entry pages into static HTML served by nginx; edits invalidate the cache immediately.'],
        'forum' => ['Forum', 'Lightweight forum: boards, threads, replies, pagination and Markdown; members post, guests read.'],
        'guardweb' => ['Anti-scraping', 'Blocks AI crawlers, scrapers and high-frequency requests while allow-listing search engines; includes logs and a global switch.'],
        'home-static' => ['Static home page', 'Bakes the home page (per language) into static HTML for millisecond responses.'],
        'imgopt' => ['Image optimiser', 'Localises remote images, converts them to WebP and generates thumbnails.'],
        'links' => ['Blog directory', 'Link and blog directory: submissions, review, monthly picks, RSS aggregation and traffic receipts.'],
        'lqbookmarks' => ['Bookmarks', 'Server side of the bookmark manager: cloud sync and single-bookmark sharing.'],
        'lqlegacy' => ['Legacy password upgrade', 'Transparently upgrades legacy password hashes to modern ones on first login.'],
        'lqnotes' => ['Cloud notes', 'Markdown notes with notebooks, trash, sync API, public share pages and version history.'],
        'market' => ['Market submissions', 'App-market workflow: public submissions plus admin verification and publishing.'],
        'poly' => ['Multilingual', 'Translation groups, AI translation, /en/ language paths and hreflang.'],
        'site' => ['Corporate website', 'Company site plugin: products, cases, news, jobs, contact form and a theme engine.'],
        'statx' => ['Statistics & health', 'Self-hosted statistics and site health checks with local SQLite storage (no third-party service).'],
        'subscribe' => ['Newsletter', 'Subscribe form, double opt-in, unsubscribe, list management and weekly sends.'],
        'mall' => ['Mall', 'Mall plugin: products, SKU, cart, orders and payments sharing the site user centre.'],
        'lqprobe' => ['lqprobe (library)', 'Support library loaded by the lq* plugins; no standalone manifest.'],
        'lqtopics' => ['lqtopics (library)', 'Support library loaded by the lq* plugins; no standalone manifest.'],
    ];
}

function yc_sc_label(array $it): array
{
    if (yc_sc_en()) {
        $map = yc_sc_en_map();
        if (isset($map[$it['id']])) { return [$map[$it['id']][0], $map[$it['id']][1]]; }
        return [ucfirst($it['id']), ''];
    }
    return [$it['name'], $it['desc']];
}

function yc_sc_t(string $zh, string $en): string
{
    return yc_sc_en() ? $en : $zh;
}

/** 主题 settings 的英文标签（缺省回落 key 本身，保证英文页不出现中文标签） */
function yc_sc_setting_en(string $key): string
{
    static $map = [
        'width' => 'Page width', 'accent' => 'Accent colour', 'per_page' => 'Posts per page',
        'latest_n' => 'Latest posts in sidebar', 'show_sidebar' => 'Show right sidebar',
        'hero_title' => 'Home hero title', 'hero_sub' => 'Home hero subtitle',
        'notice' => 'Home notice', 'home_style' => 'Home layout', 'layout' => 'Layout',
        'logo' => 'Logo', 'footer' => 'Footer text', 'font' => 'Font family',
        'color' => 'Colour scheme', 'dark' => 'Dark mode', 'toc' => 'Table of contents',
    ];
    return $map[$key] ?? $key;
}

/** 作者名的英文写法（只处理已知中文署名，其余原样） */
function yc_sc_author(string $author): string
{
    $map = ['51普洱茶网' => '51Puer', '普洱茶网' => '51Puer'];
    return $map[$author] ?? $author;
}

/* ---------------- 渲染 ---------------- */

function yc_sc_css(): string
{
    return '<style>'
        . '.sc-wrap{max-width:1120px;margin:0 auto}'
        . '.sc-hero{padding:14px 0 6px}.sc-hero h1{font-size:28px;margin:8px 0 8px}.sc-hero p{color:#6f767e;font-size:14px;line-height:1.9;max-width:820px}'
        . '.sc-tabs{display:flex;gap:8px;margin:14px 0 4px}.sc-tab{padding:6px 16px;border-radius:999px;border:1px solid var(--line,#e6e6e2);font-size:14px;color:#333;background:#fff}.sc-tab.on{background:#AC4429;border-color:#AC4429;color:#fff;font-weight:600}'
        . '.sc-search{display:flex;gap:8px;margin:12px 0 4px;max-width:440px}.sc-search input{flex:1;border:1px solid var(--line,#e6e6e2);border-radius:999px;padding:8px 14px;font:inherit;background:#fff;color:inherit}.sc-search button{border:0;border-radius:999px;padding:8px 18px;background:#AC4429;color:#fff;font:inherit;cursor:pointer}'
        . '.sc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px;margin:16px 0 8px}'
        . '.sc-card{border:1px solid var(--line,#e6e6e2);border-radius:16px;background:#fff;overflow:hidden;text-decoration:none;color:inherit;display:flex;flex-direction:column;transition:box-shadow .15s,transform .15s}'
        . '.sc-card:hover{box-shadow:0 10px 26px rgba(0,0,0,.08);transform:translateY(-2px)}'
        . '.sc-thumb{aspect-ratio:16/9;background:#f2ede6;display:block;width:100%;object-fit:cover}'
        . '.sc-body{padding:12px 14px 14px}.sc-top{display:flex;align-items:center;gap:6px}.sc-chip{font-size:11px;border-radius:999px;padding:1px 9px;background:#F3E2D2;color:#8F3520}.sc-ver{margin-left:auto;color:#9a9a90;font-size:11.5px}'
        . '.sc-name{display:block;font-size:15.5px;font-weight:700;margin:7px 0 4px;color:#1f2328}.sc-desc{color:#6f767e;font-size:12.5px;line-height:1.7;min-height:2.6em}'
        . '.sc-meta{color:#9a9a90;font-size:12px;margin-top:8px;display:flex;justify-content:space-between;gap:8px}'
        . '.sc-detail{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,0.95fr);gap:26px;margin:16px 0 8px}'
        . '.sc-shot{width:100%;border:1px solid var(--line,#e6e6e2);border-radius:14px;display:block;background:#f2ede6}'
        . '.sc-gal{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(180px,100%),1fr));gap:10px;margin-top:12px}.sc-gal img{width:100%;border-radius:10px;border:1px solid var(--line,#e6e6e2);display:block}'
        . '.sc-kv{width:100%;border-collapse:collapse;font-size:13.5px}.sc-kv th{text-align:left;color:#6f767e;font-weight:500;width:112px;padding:6px 0;vertical-align:top}.sc-kv td{padding:6px 0;color:#1f2328;word-break:break-word}'
        . '.sc-h2{font-size:18px;margin:26px 0 8px}'
        . '.sc-files{font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;background:#fbfaf7;border:1px solid var(--line,#e6e6e2);border-radius:12px;padding:12px 14px;max-height:280px;overflow:auto;white-space:pre}'
        . '.sc-install{background:#fbf7f1;border:1px solid #eadfce;border-radius:14px;padding:14px 16px;margin-top:10px}'
        . '.sc-cmd{font:12.5px/1.75 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;background:#1f2328;color:#f4f4f2;border-radius:10px;padding:12px 14px;overflow:auto;white-space:pre;margin:8px 0 10px}'
        . '.sc-btn{display:inline-block;background:#AC4429;color:#fff;border-radius:999px;padding:9px 18px;font-size:14px;font-weight:600;text-decoration:none;border:0;cursor:pointer}'
        . '.sc-btn.ghost{background:transparent;color:#AC4429;border:1px solid #AC4429}'
        . '.sc-note{color:#9a9a90;font-size:12.5px;line-height:1.8}'
        . '.sc-crumb{font-size:12.5px;color:#9a9a90;margin:10px 0 0}.sc-crumb a{color:#AC4429}'
        . '@media(max-width:860px){.sc-detail{grid-template-columns:minmax(0,1fr)}}'
        . '@media(max-width:600px){.sc-grid{grid-template-columns:minmax(0,1fr)}}'
        . 'html.dark .sc-card,html.dark .sc-tab,html.dark .sc-search input{background:#1f2328;border-color:#333;color:#e8e8e4}'
        . 'html.dark .sc-name{color:#f2f3f5}html.dark .sc-shot,html.dark .sc-thumb{background:#2a2f36}'
        . 'html.dark .sc-files{background:#1a1e23;border-color:#333;color:#c9cfd6}'
        . 'html.dark .sc-install{background:#241f1a;border-color:#3a2f24}'
        . '</style>';
}

function yc_sc_card(array $it, string $lp): string
{
    [$name, $desc] = yc_sc_label($it);
    $url = $lp . '/' . $it['type'] . '/' . rawurlencode($it['id']) . '/';
    $typeZh = $it['type'] === 'theme' ? yc_sc_t('主题', 'Theme') : yc_sc_t('插件', 'Plugin');
    $desc = $desc !== '' ? $desc : ($it['type'] === 'theme' ? yc_sc_t('YeCha 主题', 'YeCha theme') : yc_sc_t('YeCha 插件', 'YeCha plugin'));
    return '<a class="sc-card" href="' . e($url) . '">'
        . '<img class="sc-thumb" src="' . e(yc_sc_thumb($it)) . '" alt="' . e($name) . '" loading="lazy">'
        . '<div class="sc-body"><div class="sc-top"><span class="sc-chip">' . e($typeZh) . '</span>'
        . ($it['ver'] !== '' ? '<span class="sc-ver">v' . e($it['ver']) . '</span>' : '')
        . '</div><b class="sc-name">' . e($name) . '</b>'
        . '<div class="sc-desc">' . e(mb_substr($desc, 0, 110)) . '</div>'
        . '<div class="sc-meta"><span>' . e(yc_sc_author($it['author'])) . '</span><span>' . e($it['license']) . '</span></div>'
        . '</div></a>';
}

function yc_sc_list(string $type): string
{
    $lp = yc_sc_en() ? '/en' : '';
    $all = $type === 'theme' ? yc_sc_themes() : yc_sc_plugins();
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 40);
    $list = [];
    foreach ($all as $id => $it) {
        if ($q !== '') {
            [$nm, $ds] = yc_sc_label($it);
            $hay = $nm . ' ' . $ds . ' ' . $id . ' ' . $it['author'];
            if (mb_stripos($hay, $q) === false) { continue; }
        }
        $list[$id] = $it;
    }
    $cards = '';
    foreach ($list as $it) { $cards .= yc_sc_card($it, $lp); }
    if ($cards === '') { $cards = '<div class="empty">' . yc_sc_t('没有匹配的条目。', 'Nothing matches that search.') . '</div>'; }

    $isT = $type === 'theme';
    $h1 = $isT ? yc_sc_t('主题', 'Themes') : yc_sc_t('插件', 'Plugins');
    $lead = $isT
        ? yc_sc_t('本页主题全部随站安装，可直接在后台「主题」切换；每套均可查看详情并下载安装包（tar.gz）。',
                  'Every theme installed on this site, switchable from the admin panel. Open any theme for details and a tar.gz package.')
        : yc_sc_t('本页插件全部为站点现有目录，可查看清单、依赖与安装包；启用/停用请在后台「插件」操作。',
                  'Every plugin directory on this site. Open one for its manifest, dependencies and package; enable or disable it in the admin panel.');
    $tabs = '<div class="sc-tabs">'
        . '<a class="sc-tab' . ($isT ? ' on' : '') . '" href="' . $lp . '/theme">' . yc_sc_t('主题', 'Themes') . ' <b>' . count(yc_sc_themes()) . '</b></a>'
        . '<a class="sc-tab' . (!$isT ? ' on' : '') . '" href="' . $lp . '/plugin">' . yc_sc_t('插件', 'Plugins') . ' <b>' . count(yc_sc_plugins()) . '</b></a>'
        . '<a class="sc-tab" href="' . $lp . '/store">' . yc_sc_t('应用市场', 'App market') . '</a>'
        . '</div>';
    return yc_sc_css()
        . '<div class="sc-wrap"><div class="sc-hero"><h1>' . e($h1) . ' · ' . count($all) . ' ' . yc_sc_t('个', 'items') . '</h1><p>' . e($lead) . '</p></div>'
        . $tabs
        . '<form class="sc-search" method="get" action="' . $lp . '/' . $type . '"><input name="q" value="' . e($q) . '" placeholder="' . e(yc_sc_t('搜索名称 / 简介 / 作者…', 'Search name, description or author…')) . '"><button type="submit">' . yc_sc_t('搜索', 'Search') . '</button></form>'
        . ($q !== '' ? '<p class="sc-note">' . e(yc_sc_t('搜索', 'Search')) . ' “' . e($q) . '” · ' . count($list) . ' ' . yc_sc_t('个结果', 'results') . '</p>' : '')
        . '<div class="sc-grid">' . $cards . '</div>'
        . '<p class="sc-note" style="margin-top:16px">' . yc_sc_t(
            '数据直接从 themes/ 与 plugins/ 目录元数据生成；安装包与 SHA-256 见详情页。<a href="/docs">安装与升级文档 →</a>',
            'Generated live from the themes/ and plugins/ metadata. Packages and SHA-256 are on each detail page. <a href="/en/docs">Install and upgrade docs →</a>') . '</p>'
        . '</div>';
}

function yc_sc_detail(string $type, string $id): ?string
{
    $all = $type === 'theme' ? yc_sc_themes() : yc_sc_plugins();
    if (!isset($all[$id])) { return null; }
    $it = $all[$id];
    [$name, $desc] = yc_sc_label($it);
    $lp = yc_sc_en() ? '/en' : '';
    $origin = yc_sc_origin();
    $pkg = yc_sc_pkg($it);
    $files = yc_sc_files($it);
    $shots = yc_sc_shots($it);
    $size = yc_sc_dirsize($it['dir']);
    $thumb = yc_sc_thumb($it);

    // 功能点：主题取 settings 项，插件取 manifest 能力
    $points = '';
    if ($type === 'theme') {
        $en = yc_sc_en();
        foreach ((array)$it['settings'] as $s) {
            if (!is_array($s)) { continue; }
            $key = (string)($s['key'] ?? '');
            $lb = $en ? yc_sc_setting_en($key !== '' ? $key : (string)($s['label'] ?? '')) : (string)($s['label'] ?? $key);
            $hp = $en ? '' : (string)($s['help'] ?? '');
            if ($lb === '') { continue; }
            $def = (string)($s['def'] ?? '');
            $points .= '<li><b>' . e($lb) . '</b>' . ($hp !== '' ? ' — ' . e($hp) : '')
                . (!$en && $def !== '' ? '（' . e(yc_sc_t('默认', 'default')) . ' ' . e($def) . '）' : '') . '</li>';
        }
        if ($points === '') {
            $points = '<li>' . yc_sc_t('沿用 YeCha 主题机制：views/ 模板 + theme.json 元数据 + 后台可调设置。', 'Standard YeCha theme: views/ templates, theme.json metadata and admin-side settings.') . '</li>';
        }
    } else {
        if ($it['tables'] !== []) {
            $points .= '<li><b>' . yc_sc_t('数据表', 'Tables') . '</b> — ' . e(implode(', ', array_slice($it['tables'], 0, 14))) . (count($it['tables']) > 14 ? ' …' : '') . '</li>';
        }
        if ($it['pages'] !== []) {
            $pages = [];
            foreach ($it['pages'] as $p) { if (is_array($p)) { $pages[] = '/' . (string)($p['slug'] ?? ''); } }
            $points .= '<li><b>' . yc_sc_t('前台页面', 'Front-end pages') . '</b> — ' . e(implode(', ', $pages)) . '</li>';
        }
        $points .= '<li><b>' . yc_sc_t('状态', 'Status') . '</b> — ' . ($it['enabled'] ? yc_sc_t('已启用', 'enabled') : yc_sc_t('未启用', 'disabled')) . '</li>';
        $points .= '<li><b>' . yc_sc_t('清单', 'Manifest') . '</b> — plugin.php' . ($it['manifest'] ? ' ✓' : ' ' . yc_sc_t('（扩展库，无独立清单）', '(library, no standalone manifest)')) . '</li>';
    }

    $fileLines = '';
    foreach ($files as $f) { $fileLines .= $f['p'] . str_repeat(' ', max(1, 46 - mb_strlen($f['p']))) . yc_sc_size((int)$f['s']) . "\n"; }
    if (count($files) >= 240) { $fileLines .= '…'; }
    if ($fileLines === '') { $fileLines = yc_sc_t('（空目录）', '(empty)'); }

    $dl = '';
    $cmd = '';
    if ($pkg !== null) {
        $dlUrl = '/download/pkg/' . $pkg['file'];
        $abs = $origin . $dlUrl;
        $dl = '<a class="sc-btn" href="' . e($dlUrl) . '" download>' . yc_sc_t('下载安装包', 'Download package') . ' · ' . yc_sc_size((int)$pkg['size']) . '</a>';
        $root = $type === 'theme' ? 'themes' : 'plugins';
        $cmd = '# ' . yc_sc_t('在站点根目录执行', 'run from the site root') . "\n"
            . 'curl -fL ' . $abs . ' -o /tmp/' . $it['id'] . '.tar.gz' . "\n"
            . 'tar xzf /tmp/' . $it['id'] . '.tar.gz -C ' . $root . '/' . "\n"
            . '# sha256: ' . $pkg['sha256'];
    } else {
        $dl = '<span class="sc-note">' . yc_sc_t('安装包生成中：可先用下方命令从本机目录打包。', 'Package not built yet; use the command below to archive the local directory.') . '</span>';
        $cmd = 'tar czf ' . $it['id'] . '.tar.gz -C ' . ($type === 'theme' ? 'themes' : 'plugins') . ' ' . $it['id'];
    }

    $gal = '';
    foreach ($shots as $s) { $gal .= '<img src="' . e($s) . '" alt="' . e($name) . '" loading="lazy">'; }

    $typeLabel = $type === 'theme' ? yc_sc_t('主题', 'Theme') : yc_sc_t('插件', 'Plugin');
    $updated = date('Y-m-d', (int)@filemtime($it['dir']));
    $requireTxt = $it['requires'] !== '' ? ('YeCha ' . $it['requires']) : yc_sc_t('未声明', 'not declared');

    return yc_sc_css()
        . '<div class="sc-wrap"><div class="sc-crumb"><a href="' . $lp . '/">' . yc_sc_t('首页', 'Home') . '</a> / <a href="' . $lp . '/' . $type . '">' . ($type === 'theme' ? yc_sc_t('主题', 'Themes') : yc_sc_t('插件', 'Plugins')) . '</a> / ' . e($name) . '</div>'
        . '<div class="sc-hero"><h1>' . e($name) . '</h1><p>' . e($desc) . '</p></div>'
        . '<div class="sc-detail"><div>'
        . '<img class="sc-shot" src="' . e($thumb) . '" alt="' . e($name) . '">'
        . ($gal !== '' ? '<div class="sc-gal">' . $gal . '</div>' : '')
        . '</div><div>'
        . '<table class="sc-kv">'
        . '<tr><th>' . yc_sc_t('类型', 'Type') . '</th><td>' . e($typeLabel) . '</td></tr>'
        . '<tr><th>' . yc_sc_t('版本', 'Version') . '</th><td>' . ($it['ver'] !== '' ? 'v' . e($it['ver']) : '—') . '</td></tr>'
        . '<tr><th>' . yc_sc_t('作者', 'Author') . '</th><td>' . e(yc_sc_author($it['author'])) . '</td></tr>'
        . '<tr><th>' . yc_sc_t('许可', 'License') . '</th><td>' . e($it['license']) . '</td></tr>'
        . '<tr><th>' . yc_sc_t('依赖', 'Requires') . '</th><td>' . e($requireTxt) . '</td></tr>'
        . '<tr><th>' . yc_sc_t('目录大小', 'Size on disk') . '</th><td>' . e(yc_sc_size($size)) . ' · ' . count($files) . (count($files) >= 240 ? '+' : '') . ' ' . yc_sc_t('个文件', 'files') . '</td></tr>'
        . '<tr><th>' . yc_sc_t('更新', 'Updated') . '</th><td>' . e($updated) . '</td></tr>'
        . '</table>'
        . '<h2 class="sc-h2">' . yc_sc_t('安装', 'Install') . '</h2>'
        . '<div>' . $dl . '</div>'
        . '<div class="sc-install"><div class="sc-note">' . yc_sc_t('或复制命令（tar.gz 解压即安装）', 'Or copy the command (untar to install)') . '</div>'
        . '<pre class="sc-cmd" id="scCmd">' . e($cmd) . '</pre>'
        . '<button type="button" class="sc-btn ghost" id="scCopy">' . yc_sc_t('复制安装命令', 'Copy install command') . '</button>'
        . '<div class="sc-note" style="margin-top:8px">' . yc_sc_t('主题装上后在后台「主题」选择；插件装上后在后台「插件」启用。', 'Select the theme in the admin panel; enable the plugin there too.') . '</div></div>'
        . '</div></div>'
        . '<h2 class="sc-h2">' . yc_sc_t('功能点', 'What it does') . '</h2><ul style="line-height:2;color:#333">' . $points . '</ul>'
        . '<h2 class="sc-h2">' . yc_sc_t('文件清单', 'File list') . '</h2><pre class="sc-files">' . e($fileLines) . '</pre>'
        . '<p class="sc-note" style="margin:14px 0 6px">' . yc_sc_t('安装包校验（SHA-256）', 'Package checksum (SHA-256)') . '：' . ($pkg !== null ? '<code>' . e($pkg['sha256']) . '</code>' : '—') . '</p>'
        . '<script>(function(){var b=document.getElementById("scCopy"),c=document.getElementById("scCmd");if(!b||!c)return;b.addEventListener("click",function(){var t=c.textContent||"";function ok(){b.textContent="' . (yc_sc_en() ? 'Copied' : '已复制') . '";setTimeout(function(){b.textContent="' . (yc_sc_en() ? 'Copy install command' : '复制安装命令') . '";},1600);}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(ok,ok);}else{var a=document.createElement("textarea");a.value=t;document.body.appendChild(a);a.select();try{document.execCommand("copy");}catch(e){}document.body.removeChild(a);ok();}});})();</script>'
        . '</div>';
}

/** 英文版应用市场总览（/en/store）：沿用主题/插件两页的数据，界面全英文 */
function yc_sc_store_en(): string
{
    $nt = count(yc_sc_themes());
    $np = count(yc_sc_plugins());
    $cards = '';
    foreach (yc_sc_plugins() as $it) { $cards .= yc_sc_card($it, '/en'); }
    foreach (yc_sc_themes() as $it) { $cards .= yc_sc_card($it, '/en'); }
    return yc_sc_css()
        . '<div class="sc-wrap"><div class="sc-hero"><h1>App market · ' . ($nt + $np) . ' items</h1>'
        . '<p>Every theme and plugin installed on this site, generated live from their metadata. Open a dedicated page for details, the tar.gz package and its SHA-256. Install and enable from the admin panel (Market / Themes / Plugins).</p></div>'
        . '<div class="sc-tabs">'
        . '<a class="sc-tab" href="/en/theme">Themes <b>' . $nt . '</b></a>'
        . '<a class="sc-tab" href="/en/plugin">Plugins <b>' . $np . '</b></a>'
        . '<a class="sc-tab on" href="/en/store">App market</a>'
        . '</div>'
        . '<div class="sc-grid">' . $cards . '</div>'
        . '<p class="sc-note" style="margin-top:16px">Install and upgrade guide: <a href="/en/docs">/en/docs</a>.</p></div>';
}

/** 下载中心（/downloads）：完整安装包 + 主题包 + 插件包 + 文档/更新日志入口 */
function yc_sc_downloads(): string
{
    $root = dirname(__DIR__, 2);
    $en = yc_sc_en();
    $lp = $en ? '/en' : '';
    $pkg = yc_sc_pkgs();

    // 完整安装包（download/*.zip，按 mtime 倒序）
    $zips = [];
    foreach (glob($root . '/download/*.zip') ?: [] as $f) {
        $base = basename($f);
        $sha = '';
        $sf = $f . '.sha256';
        if (is_file($sf)) {
            $m = preg_match('/\b([a-f0-9]{64})\b/i', (string)file_get_contents($sf), $mm);
            if ($m) { $sha = strtolower($mm[1]); }
        }
        $zips[] = ['file' => $base, 'size' => (int)@filesize($f), 'ts' => (int)@filemtime($f), 'sha' => $sha];
    }
    usort($zips, fn($a, $b) => $b['ts'] <=> $a['ts']);
    $core = $zips[0] ?? null;
    $hist = array_slice($zips, 1);

    $coreHtml = '';
    if ($core !== null) {
        $coreHtml = '<div class="sc-install"><b style="font-size:15.5px">' . e($core['file']) . '</b>'
            . '<div class="sc-note" style="margin:6px 0 10px">' . e(yc_sc_size($core['size'])) . ' · ' . e(date('Y-m-d', $core['ts']))
            . ($core['sha'] !== '' ? ' · SHA-256 <code>' . e(substr($core['sha'], 0, 16)) . '…</code>' : '') . '</div>'
            . '<a class="sc-btn" href="/download/' . e(rawurlencode($core['file'])) . '" download>' . yc_sc_t('下载完整包', 'Download full package') . '</a>'
            . ($core['sha'] !== '' ? ' <a class="sc-btn ghost" href="/download/' . e(rawurlencode($core['file'])) . '.sha256">SHA-256</a>' : '')
            . '<div class="sc-note" style="margin-top:10px">' . yc_sc_t('解压后按文档配置 config.inc.php，访问站点根完成安装。', 'Unpack, configure config.inc.php, then open the site root to run the installer.') . ' <a href="' . $lp . '/docs">' . yc_sc_t('安装文档 →', 'Install docs →') . '</a></div>'
            . '<div class="sc-note" style="margin-top:6px">' . yc_sc_t('本站当前版本 1.0.0；核心完整包最新公开构建为 1.0 Beta b2 Build20260928，1.0.0 的主题包与插件包见下方。', 'This site runs 1.0.0; the newest published core build is 1.0 Beta b2 Build20260928. 1.0.0 theme and plugin packages are listed below.') . '</div></div>';
    }
    $histHtml = '';
    foreach ($hist as $z) {
        $histHtml .= '<div style="display:flex;gap:12px;align-items:baseline;padding:6px 0;border-bottom:1px dashed var(--line,#e6e6e2);font-size:13px">'
            . '<a href="/download/' . e(rawurlencode($z['file'])) . '" style="font-weight:600">' . e($z['file']) . '</a>'
            . '<span class="sc-note" style="margin-left:auto">' . e(yc_sc_size($z['size'])) . ' · ' . e(date('Y-m-d', $z['ts'])) . '</span></div>';
    }

    $themeRows = '';
    $themes = yc_sc_themes();
    foreach ($themes as $id => $it) {
        $p = $pkg['theme-' . $id] ?? null;
        [$nm] = yc_sc_label($it);
        $themeRows .= '<tr><td><a href="' . $lp . '/theme/' . rawurlencode($id) . '/">' . e($nm) . '</a></td>'
            . '<td>v' . e($it['ver']) . '</td><td>' . ($p ? e(yc_sc_size((int)$p['size'])) : '—') . '</td>'
            . '<td>' . ($p ? '<a href="/download/pkg/' . e($p['file']) . '" download>' . yc_sc_t('下载', 'Download') . '</a>' : '—') . '</td></tr>';
    }
    $pluginRows = '';
    $plugins = yc_sc_plugins();
    foreach ($plugins as $id => $it) {
        $p = $pkg['plugin-' . $id] ?? null;
        [$nm] = yc_sc_label($it);
        $pluginRows .= '<tr><td><a href="' . $lp . '/plugin/' . rawurlencode($id) . '/">' . e($nm) . '</a></td>'
            . '<td>v' . e($it['ver'] !== '' ? $it['ver'] : '—') . '</td><td>' . ($p ? e(yc_sc_size((int)$p['size'])) : '—') . '</td>'
            . '<td>' . ($p ? '<a href="/download/pkg/' . e($p['file']) . '" download>' . yc_sc_t('下载', 'Download') . '</a>' : '—') . '</td></tr>';
    }
    $th = '<tr><th>' . yc_sc_t('名称', 'Name') . '</th><th>' . yc_sc_t('版本', 'Version') . '</th><th>' . yc_sc_t('大小', 'Size') . '</th><th>' . yc_sc_t('安装包', 'Package') . '</th></tr>';

    return yc_sc_css()
        . '<div class="sc-wrap"><div class="sc-hero"><h1>' . yc_sc_t('下载中心', 'Download center') . '</h1>'
        . '<p>' . yc_sc_t('完整安装包、全部主题包与插件包都在这里，均附 SHA-256。站点当前版本 1.0.0，已装 34 套主题、20 个插件。',
                           'The full installer plus every theme and plugin package, each with SHA-256. This site runs 1.0.0 with 34 themes and 20 plugins installed.') . '</p></div>'
        . '<h2 class="sc-h2">' . yc_sc_t('完整安装包', 'Full package') . '</h2>' . $coreHtml
        . ($histHtml !== '' ? '<details style="margin-top:10px"><summary class="sc-note" style="cursor:pointer">' . yc_sc_t('历史版本', 'Previous versions') . ' (' . count($hist) . ')</summary>' . $histHtml . '</details>' : '')
        . '<h2 class="sc-h2">' . yc_sc_t('主题包', 'Theme packages') . ' · ' . count($themes) . '</h2>'
        . '<table class="sc-kv">' . $th . $themeRows . '</table>'
        . '<h2 class="sc-h2">' . yc_sc_t('插件包', 'Plugin packages') . ' · ' . count($plugins) . '</h2>'
        . '<table class="sc-kv">' . $th . $pluginRows . '</table>'
        . '<h2 class="sc-h2">' . yc_sc_t('文档与更新日志', 'Docs and changelog') . '</h2>'
        . '<p><a class="sc-btn ghost" href="' . $lp . '/docs">' . yc_sc_t('使用文档', 'Documentation') . '</a> '
        . '<a class="sc-btn ghost" href="' . $lp . '/changelog">' . yc_sc_t('更新日志', 'Changelog') . '</a> '
        . '<a class="sc-btn ghost" href="' . $lp . '/theme">' . yc_sc_t('在线主题', 'Themes') . '</a> '
        . '<a class="sc-btn ghost" href="' . $lp . '/plugin">' . yc_sc_t('在线插件', 'Plugins') . '</a></p>'
        . '</div>';
}

/** t39：应用市场汇总页（/market）——主题 / 插件两块 + 计数 + 入口 */
function yc_sc_market(): string
{
    $lp = yc_sc_en() ? '/en' : '';
    $themes = yc_sc_themes();
    $plugins = yc_sc_plugins();
    $nt = count($themes);
    $np = count($plugins);
    $block = function (array $list, string $type, string $href, int $total) use ($lp): string {
        $isT = $type === 'theme';
        $cards = '';
        $i = 0;
        foreach ($list as $it) {
            if ($i++ >= 4) { break; }
            $cards .= yc_sc_card($it, $lp);
        }
        $title = $isT ? yc_sc_t('主题', 'Themes') : yc_sc_t('插件', 'Plugins');
        $desc = $isT
            ? yc_sc_t('换一套外观：博客、文档、杂志、极简，安装即用。', 'Change the look: blog, docs, magazine and minimal layouts, ready to install.')
            : yc_sc_t('按月订阅、论坛、友链、统计、多语言——按需启用。', 'Subscriptions, forum, links, stats and multilingual — enable what you need.');
        return '<section class="mk-panel"><div class="mk-head"><div><h2>' . e($title) . ' <b>' . $total . '</b></h2><p>' . e($desc) . '</p></div>'
            . '<a class="sc-btn ghost" href="' . e($href) . '">' . yc_sc_t('查看全部', 'View all') . ' →</a></div>'
            . '<div class="sc-grid mk-grid">' . $cards . '</div></section>';
    };
    $css = '<style>'
        . '.mk-top{display:flex;align-items:baseline;gap:14px;flex-wrap:wrap;margin:4px 0 2px}'
        . '.mk-top h1{font-size:30px;margin:6px 0 0}'
        . '.mk-kpi{display:flex;gap:10px;margin:12px 0 6px;flex-wrap:wrap}'
        . '.mk-kpi a{border:1px solid var(--line,#e6e6e2);background:#fff;border-radius:12px;padding:8px 16px;text-decoration:none;color:inherit;font-size:13.5px;line-height:1.5}'
        . '.mk-kpi a b{font-size:19px;color:#AC4429;margin-right:6px}'
        . '.mk-panel{margin:22px 0 4px;border:1px solid var(--line,#e6e6e2);border-radius:18px;background:#fbfaf7;padding:18px 18px 8px}'
        . '.mk-head{display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:6px}'
        . '.mk-head h2{font-size:21px;margin:0 0 4px}.mk-head h2 b{color:#AC4429;font-size:15px;vertical-align:2px;margin-left:4px}'
        . '.mk-head p{margin:0;color:#6f767e;font-size:13px;line-height:1.8}'
        . '.mk-head .sc-btn{margin-left:auto}'
        . '.mk-grid{grid-template-columns:repeat(auto-fill,minmax(220px,1fr));margin:10px 0 6px}'
        . '.mk-links{margin:24px 0 0;display:flex;gap:10px;flex-wrap:wrap}'
        . '@media(max-width:640px){.mk-panel{padding:14px 12px 4px}.mk-head .sc-btn{margin-left:0}}'
        . 'html.dark .mk-panel{background:#1f2328;border-color:#333}html.dark .mk-kpi a{background:#1f2328;border-color:#333}'
        . '</style>';
    $lead = yc_sc_t('主题与插件都在这里：主题 34 套、插件 20 个，全部可在线看详情、下安装包。',
        'Every theme and plugin in one place — 34 themes and 20 plugins, each with online details and a package download.');
    return yc_sc_css() . $css
        . '<div class="sc-wrap"><div class="sc-crumb"><a href="' . $lp . '/">' . yc_sc_t('首页', 'Home') . '</a> / ' . yc_sc_t('应用市场', 'App market') . '</div>'
        . '<div class="mk-top"><h1>' . yc_sc_t('应用市场', 'App market') . '</h1></div>'
        . '<p class="sc-hero" style="padding:0;margin:6px 0 0">' . e($lead) . '</p>'
        . '<div class="mk-kpi">'
        . '<a href="' . $lp . '/theme"><b>' . $nt . '</b>' . yc_sc_t('主题', 'themes') . '</a>'
        . '<a href="' . $lp . '/plugin"><b>' . $np . '</b>' . yc_sc_t('插件', 'plugins') . '</a>'
        . '<a href="' . $lp . '/downloads"><b>' . ($nt + $np) . '</b>' . yc_sc_t('安装包（tar.gz）', 'packages (tar.gz)') . '</a>'
        . '</div>'
        . $block($themes, 'theme', $lp . '/theme', $nt)
        . $block($plugins, 'plugin', $lp . '/plugin', $np)
        . '<div class="mk-links"><a class="sc-btn ghost" href="' . $lp . '/downloads">' . yc_sc_t('下载中心', 'Download center') . '</a>'
        . '<a class="sc-btn ghost" href="' . $lp . '/docs">' . yc_sc_t('安装文档', 'Install docs') . '</a>'
        . '<a class="sc-btn ghost" href="' . $lp . '/changelog">' . yc_sc_t('更新日志', 'Changelog') . '</a></div>'
        . '</div>';
}

/** 插件页入口（由 plugins/market/page.php 分发） */
function yc_sc_page(string $slug): array
{
    $sub = (array)($GLOBALS['YC_PLUGIN_PATH'] ?? []);
    $id = isset($sub[0]) ? (string)$sub[0] : '';
    $type = $slug === 'theme' ? 'theme' : 'plugin';
    $GLOBALS['YC_NO_SIDE'] = true;
    $GLOBALS['YC_SB_TYPE'] = 'page';
    if ($id !== '') {
        $html = yc_sc_detail($type, $id);
        if ($html === null) {
            return ['html' => '<div class="empty" style="padding:70px 0">404 · ' . e(yc_sc_t('没有这个条目', 'No such item')) . '</div>', 'title' => '404 · ' . site_label(), 'status' => 404];
        }
        $it = ($type === 'theme' ? yc_sc_themes() : yc_sc_plugins())[$id];
        [$nm, $ds] = yc_sc_label($it);
        $GLOBALS['YC_META'] = ['description' => mb_substr(($ds !== '' ? $ds . ' · ' : '') . $nm . ' v' . $it['ver'] . ' · ' . yc_sc_t('在线主题/插件详情（含安装包下载）', 'online theme/plugin detail with package download'), 0, 180), 'keywords' => ''];
        return ['html' => $html, 'title' => $nm . ' · ' . site_label()];
    }
    $GLOBALS['YC_META'] = ['description' => ($type === 'theme'
        ? yc_sc_t('YeCha 主题目录：' . count(yc_sc_themes()) . ' 套主题，含版本、许可、截图与 tar.gz 安装包下载。', 'YeCha theme directory: ' . count(yc_sc_themes()) . ' themes with version, license, screenshots and tar.gz packages.')
        : yc_sc_t('YeCha 插件目录：' . count(yc_sc_plugins()) . ' 个插件，含清单、依赖、文件清单与 tar.gz 安装包下载。', 'YeCha plugin directory: ' . count(yc_sc_plugins()) . ' plugins with manifest, requirements, file list and tar.gz packages.')), 'keywords' => ''];
    return ['html' => yc_sc_list($type), 'title' => ($type === 'theme' ? yc_sc_t('主题', 'Themes') : yc_sc_t('插件', 'Plugins')) . ' · ' . site_label()];
}
