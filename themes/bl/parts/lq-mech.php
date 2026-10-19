<?php
/**
 * ★ [lq-mech 2026-10-04] liulanqi-yecha 主题层「站点级机制」助手（一次覆盖全站）
 *
 * 归属（本线）：**站点级 / 非学院专属**的那一半。学院线（`/learn/*` 那 7 页的内容）自己落地
 *   M-1 日期 / M-3 内链 / M-4 结构化数据 / M-5 FAQ ⇒ 本文件对 `/learn*` **主动让位**：
 *     · 相关阅读块：路径以 `/learn` 开头 ⇒ 不渲染（同族内链归学院线）；
 *     · 结构化数据 / 可见面包屑：页面内容里**已经**出现 `application/ld+json` 或
 *       `aria-label="breadcrumb"` ⇒ 本文件整段让位（学院线 M-4 落版后自动生效，不需要再改本文件）。
 *
 * 依据（每条都能指到出处，不自创）：
 *   · 栏目中文名 = 与 `core/index.php:463-464` 的 `$labels` **同一张表**；导航项文字优先取
 *     `Nav::list('top')`（站点自己配的）。
 *   · 面包屑第三级 = 该页**自己可见的 `<h1>`**（取不到就不输出第三级，不猜）。
 *   ·「最后更新」文案 = 站上既有语言键 `pg.updated_at`（`lang/zh_CN.php` = “最后更新 ”），不新增文案。
 *   · 结构化数据 `@type` 口径 = `_lqlearn/00_学院文章加强文案_7篇.md` §4.2：
 *     `Article`（文章）/ `CollectionPage` + `ItemList`（目录/列表页）/ `BreadcrumbList`（有可见面包屑的页）/
 *     `SoftwareApplication` + `ItemList`（`/download`）。**FAQPage / HowTo 本文件一律不输出**
 *     —— 按该文 §4.2 与 §3.⑧：「没落 FAQ 就别加这个标记」「不整页套 HowTo」。
 *   · `datePublished` / `dateModified` **只取数据库真列**（`ye_contents.published_at` / `updated_at`），
 *     取不到就不输出该字段，**绝不填今天**（见该文 M1 风险栏）。
 *   · 校验值 / 版本 / 体积 / 更新日期 **只读**镜像同步脚本自己写的清单
 *     `/www/wwwroot/liulanqi.com/mirror-manifest.json`（cron `7 4 * * *` = `/opt/liulanqi-mirror/run-sync.sh`
 *     写；源 `src/mirror/sync.ts:126`）。本文件**不自己算哈希、不写任何文件**。
 *
 * 回滚：删本文件 + 还原 `themes/bl/views/layout.php` 的 4 处插入 + 还原
 *       `themes/bl/views/partials/post.php` 的 2 处 `rel`。一键脚本见报告 §8。
 *
 * ★ 归属让位一览（实测口径）：
 *   · `/learn*` 结构化数据：**整个 section 让位**（学院线已落 Article/BreadcrumbList/FAQPage；见本文件内注释）；
 *   · 任意页面：内容里已有 `application/ld+json` ⇒ 我的按页节点让位；
 *   · 任意页面：内容里已有 `aria-label="breadcrumb"` ⇒ 我的可见面包屑让位；
 *   · 任意页面：内容里已有「最后更新」字样 ⇒ 我的可见日期让位（学院线 M-1 已落版）；
 *   · `/learn*` 相关阅读：让位（同族内链归学院线 M-3）。
 */

if (!function_exists('lq_mech_path')) {

    /** 当前请求路径（去语言前缀、去 index.php 前缀），口径与 index.php 的 ye_request_path() 一致 */
    function lq_mech_path(): string
    {
        $p = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($p === '') { $p = '/'; }
        $p = rawurldecode($p);
        if (preg_match('#/index\.php(/|$)#', $p, $m)) {
            $p = substr($p, strlen($m[0]) - (str_ends_with($m[0], '/') ? 1 : 0));
        }
        $segs = array_values(array_filter(explode('/', trim($p, '/')), static fn($v) => $v !== ''));
        if ($segs !== [] && function_exists('Poly') && Poly::on()
            && in_array($segs[0], Poly::enabled(), true) && $segs[0] !== Poly::defaultLang()) {
            array_shift($segs);
        }
        return $segs !== [] ? '/' . implode('/', $segs) : '/';
    }

    /** 五个 section 的中文名 —— ★ 与 core/index.php:463-464 的 $labels 同表（不自创栏目名） */
    function lq_mech_section_labels(): array
    {
        return ['tips' => '浏览器技巧', 'tool' => '在线工具', 'learn' => '浏览器学堂',
                'gallery' => '灵感画廊', 'download' => '浏览器下载'];
    }

    /** 站点声明的 section 列表（选项 url.sections）；选项缺省 ⇒ 用上面那张表的键 */
    function lq_mech_sections(): array
    {
        $j = json_decode((string)get_option('url.sections', ''), true);
        if (is_array($j) && $j !== []) {
            $out = [];
            foreach ($j as $v) { $v = strtolower(trim((string)$v)); if ($v !== '') { $out[] = $v; } }
            if ($out !== []) { return $out; }
        }
        return array_keys(lq_mech_section_labels());
    }

    /** 导航里那一项的文字（站点自己配的）；找不到返回 '' */
    function lq_mech_nav_label(string $href): string
    {
        $href = '/' . trim($href, '/');
        if ($href === '/') { $href = '/'; }
        try {
            foreach (Nav::list('top', Lang::short()) as $n) {
                $h = (string)$n['href'];
                if ($h === '/' ? $href === '/' : rtrim($h, '/') === rtrim($href, '/')) {
                    return trim((string)$n['label']);
                }
            }
        } catch (Throwable $e) { /* 取不到就返回空，绝不编 */ }
        return '';
    }

    /** 页面自己可见的第一个 <h1> 纯文本（取不到返回 ''） */
    function lq_mech_h1(string $html): string
    {
        if (!preg_match('#<h1[^>]*>(.*?)</h1>#is', $html, $m)) { return ''; }
        $t = trim(html_entity_decode(strip_tags((string)$m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $t = trim((string)preg_replace('/\s+/u', ' ', $t));
        return mb_substr($t, 0, 80);
    }

    /** 当前内容行（只读；任何异常 ⇒ null，绝不让整页 500） */
    function lq_mech_row(string $slug): ?array
    {
        static $cache = [];
        if (array_key_exists($slug, $cache)) { return $cache[$slug]; }
        $cache[$slug] = null;
        try {
            if (!class_exists('Db')) { return null; }
            $r = Db::row('SELECT id, type, published_at, updated_at, created_at FROM ' . Db::t('contents')
                . " WHERE slug=? AND status='publish' AND deleted_at IS NULL LIMIT 1", [$slug]);
            $cache[$slug] = is_array($r) ? $r : null;
        } catch (Throwable $e) { $cache[$slug] = null; }
        return $cache[$slug];
    }

    /** 同 section 的最新若干篇（相关阅读）；异常 ⇒ 空数组 */
    function lq_mech_related(string $prefix, string $exclude, int $limit = 6, string $order = 'date'): array
    {
        static $cache = [];
        $k = $prefix . '|' . $exclude . '|' . $limit . '|' . $order;
        if (array_key_exists($k, $cache)) { return $cache[$k]; }
        $cache[$k] = [];
        try {
            if (!class_exists('Db')) { return []; }
            $rows = Db::rows('SELECT slug, title FROM ' . Db::t('contents')
                . " WHERE LEFT(slug, ?)=? AND slug<>? AND status='publish' AND deleted_at IS NULL"
                . (($order === 'slug') ? ' ORDER BY slug ASC' : ' ORDER BY COALESCE(published_at, created_at) DESC, id DESC')
                . ' LIMIT ' . (int)$limit,
                [strlen($prefix), $prefix, $exclude]);
            $out = [];
            foreach ($rows as $r) {
                $sg = (string)($r['slug'] ?? '');
                if ($sg === '') { continue; }
                $u = str_contains($sg, '__') ? '/' . str_replace('__', '/', $sg) : '/' . $sg;
                $out[] = ['u' => $u, 't' => (string)($r['title'] ?? '')];
            }
            $cache[$k] = $out;
        } catch (Throwable $e) { $cache[$k] = []; }
        return $cache[$k];
    }

    /** 镜像清单（只读；读不到返回 null ⇒ 与下载有关的输出全部不渲染） */
    function lq_mech_manifest(): ?array
    {
        static $m = false;
        if ($m !== false) { return $m; }
        $m = null;
        try {
            /* ★ 路径来源：cron `7 4 * * * /opt/liulanqi-mirror/run-sync.sh`（WEB_ROOT=/www/wwwroot/liulanqi.com），
               清单文件名见 src/mirror/sync.ts:11。**只读**，本文件从不写它。 */
            $f = '/www/wwwroot/liulanqi.com/mirror-manifest.json';
            if (is_readable($f)) {
                $j = json_decode((string)@file_get_contents($f), true);
                if (is_array($j) && !empty($j['browsers']) && is_array($j['browsers'])) { $m = $j; }
            }
        } catch (Throwable $e) { $m = null; }
        return $m;
    }

    /** 体积显示（字节 → KB/MB）；数字全部来自清单，不换算成别的单位口径 */
    function lq_mech_size(int $bytes): string
    {
        if ($bytes <= 0) { return ''; }
        if ($bytes >= 1048576) { return round($bytes / 1048576, 1) . ' MB'; }
        if ($bytes >= 1024) { return round($bytes / 1024, 1) . ' KB'; }
        return $bytes . ' B';
    }

    /** 列表页条目（从页面**自己可见**的列表里取，最多 20 条；不额外查库、不编） */
    function lq_mech_list_items(string $main, string $base, int $max = 20): array
    {
        if (stripos($main, 'class="post-item"') === false) { return []; }
        if (!preg_match_all('#<h2>\s*<a\s+href="([^"]+)"[^>]*>(.*?)</a>\s*</h2>#is', $main, $mm, PREG_SET_ORDER)) { return []; }
        $out = [];
        foreach ($mm as $x) {
            if (count($out) >= $max) { break; }
            $t = trim(html_entity_decode(strip_tags((string)$x[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $u = trim((string)$x[1]);
            if ($t === '' || $u === '') { continue; }
            if (!str_starts_with($u, 'http')) { $u = rtrim($base, '/') . '/' . ltrim($u, '/'); }
            $out[] = ['@type' => 'ListItem', 'position' => count($out) + 1, 'name' => $t, 'url' => $u];
        }
        return $out;
    }

    /** 下载清单 → SoftwareApplication 列表项（只用清单里真实存在的字段） */
    function lq_mech_download_items(?array $m, int $max = 18): array
    {
        if (!is_array($m) || empty($m['browsers']) || !is_array($m['browsers'])) { return []; }
        $out = [];
        foreach ($m['browsers'] as $key => $b) {
            if (count($out) >= $max) { break; }
            if (!is_array($b)) { continue; }
            $rel = null;
            foreach ((array)($b['releases'] ?? []) as $r) {
                if (is_array($r) && ($r['channel'] ?? '') === 'stable' && !empty($r['platforms'])) { $rel = $r; break; }
            }
            if ($rel === null) {
                foreach ((array)($b['releases'] ?? []) as $r) {
                    if (is_array($r) && !empty($r['platforms'])) { $rel = $r; break; }
                }
            }
            if ($rel === null) { continue; }
            $pf = null;
            foreach ((array)$rel['platforms'] as $p) {
                if (is_array($p) && !empty($p['url'])) { $pf = $p; break; }
            }
            if ($pf === null) { continue; }
            $node = ['@type' => 'SoftwareApplication',
                     'name' => (string)($b['name'] ?? $key),
                     'downloadUrl' => (string)$pf['url']];
            if (!empty($rel['version'])) { $node['softwareVersion'] = (string)$rel['version']; }
            if (!empty($pf['size'])) { $node['fileSize'] = (int)$pf['size'] . ' B'; }
            if (!empty($b['official'])) { $node['sameAs'] = (string)$b['official']; }
            $out[] = $node;
        }
        return $out;
    }

    /** 上下文：一次算清，供 layout.php 的三处输出复用 */
    function lq_mech_ctx(string $main, string $base): array
    {
        $path  = lq_mech_path();
        $segs  = array_values(array_filter(explode('/', trim($path, '/')), static fn($v) => $v !== ''));
        $sects = lq_mech_sections();
        $labels = lq_mech_section_labels();

        $home = lq_mech_nav_label('/');
        if ($home === '') { $home = '首页'; }          // 站点导航里必有的项；真取不到才用这个兜底

        $h1 = lq_mech_h1($main);
        $crumbs = [['n' => $home, 'u' => '/']];
        $entrySlug = null;
        $isEntry = false;

        if ($segs !== []) {
            $s0 = (string)$segs[0];
            if (in_array($s0, $sects, true) && isset($labels[$s0])) {
                $crumbs[] = ['n' => $labels[$s0], 'u' => '/' . $s0];
                if (count($segs) === 2 && $segs[1] !== 'page') {
                    /* 二级是词条：slug 存储形态 `{section}__{slug}`（见 core/Router.php:86-115） */
                    $entrySlug = $s0 . '__' . (string)$segs[1];
                    $isEntry = true;
                    $crumbs[] = ['n' => $h1 !== '' ? $h1 : (string)$segs[1], 'u' => ''];
                } elseif (count($segs) >= 3 && $h1 !== '') {
                    /* 三段路径（如 /tool/c/calc）：第三级用页面自己的 h1；**不当词条**（不查行、不挂文章标记） */
                    $crumbs[] = ['n' => $h1, 'u' => ''];
                }
            } elseif (count($segs) === 1 && $h1 !== '') {
                /* 单段顶层页（/about、/links、/download 等同名页…）：面包屑第二级 = 该页自己的 h1。
                   ★ 明确排除系统路由，避免把它们也做成"面包屑页"。 */
                $sys = ['search', 'login', 'register', 'logout', 'user', 'category', 'tag', 'archives',
                        'tags', 'cats', 'pages', 'feed', 'sitemap.xml', 'count', 'healthz'];
                if (!in_array($s0, $sys, true)) { $crumbs[] = ['n' => $h1, 'u' => '']; }
            }
        }

        $row = $entrySlug !== null ? lq_mech_row($entrySlug) : null;
        $related = [];
        if ($isEntry && $entrySlug !== null && count($crumbs) >= 3) {
            $prefix = (string)$segs[0] . '__';
            $related = lq_mech_related($prefix, $entrySlug, 6);
        }

        return [
            'path'      => $path,
            'base'      => rtrim($base, '/'),
            'crumbs'    => $crumbs,
            'h1'        => $h1,
            'is_entry'  => $isEntry,
            'entry'     => $entrySlug,
            'row'       => $row,
            'related'   => $related,
            'has_ld'    => stripos($main, 'application/ld+json') !== false,
            'has_crumb' => stripos($main, 'aria-label="breadcrumb"') !== false,
            /* ★ 学院线 M-1（可见「最后更新」）已在 `/learn/*` 内容里落版（实测每页 1 处「最后更新」）
               ⇒ 页面内容自带时，本机制**不重复渲染**自己的那个 <time>（归属分开，不做两个日期）。 */
            'has_date'  => mb_strpos($main, '最后更新') !== false,
            'list_items'=> lq_mech_list_items($main, rtrim($base, '/')),
            'manifest'  => $path === '/download' ? lq_mech_manifest() : null,
        ];
    }

    /** 结构化数据附加节点（并进 layout.php 既有 @graph；让位规则见文件头） */
    function lq_mech_ld(array $ctx, string $base): array
    {
        if (!empty($ctx['has_ld'])) { return []; }   // ★ 页面内容已自带 JSON-LD ⇒ 整段让位
        /* ★ `/learn` 整段让位（含 /learn 索引）：学院线 M-4 在 `_lqlearn/00_…7篇.md` §4.2 的授权范围内，
           其 6 个子页内容**已自带** Article/BreadcrumbList/FAQPage（实测每页 3 块），
           其索引产物 `var/entry-static/extra/learn.html` 亦自带 CollectionPage/BreadcrumbList/FAQPage
           ⇒ 本机制不再往 /learn* 追加任何按页节点（避免同一 URL 上出现两个 CollectionPage/BreadcrumbList）。
           ★ 只有站点级 Organization/WebSite 仍由 layout.php 输出。
           若日后学院线撤掉该标记，把下面这一行注释掉即可恢复（本机制其余部分不变）。 */
        if (str_starts_with((string)$ctx['path'], '/learn')) { return []; }
        $base = rtrim($base, '/');
        $abs = static function (string $u) use ($base): string {
            return str_starts_with($u, 'http') ? $u : $base . '/' . ltrim($u, '/');
        };
        $out = [];

        if (count($ctx['crumbs']) >= 2) {
            $items = [];
            foreach ($ctx['crumbs'] as $i => $c) {
                $it = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => (string)$c['n']];
                if ((string)$c['u'] !== '') { $it['item'] = $abs((string)$c['u']); }
                $items[] = $it;
            }
            $out[] = ['@type' => 'BreadcrumbList', '@id' => $abs((string)$ctx['path']) . '#breadcrumb',
                      'itemListElement' => $items];
        }

        $row = $ctx['row'];
        if (!empty($ctx['is_entry']) && is_array($row) && (string)($row['type'] ?? '') === 'post') {
            $url = $abs((string)$ctx['path']);
            $a = ['@type' => 'Article', '@id' => $url . '#article',
                  'headline' => $ctx['h1'] !== '' ? (string)$ctx['h1'] : (string)$ctx['crumbs'][count($ctx['crumbs']) - 1]['n'],
                  'inLanguage' => 'zh-CN',
                  'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
                  'publisher' => ['@id' => $base . '/#org']];
            $desc = trim((string)($GLOBALS['YC_META']['description'] ?? ''));
            if ($desc !== '') { $a['description'] = $desc; }
            /* ★ 日期只取数据库真列；取不到就不输出（不填今天） */
            $pub = (string)($row['published_at'] ?? $row['created_at'] ?? '');
            $upd = (string)($row['updated_at'] ?? '');
            $pubTs = $pub !== '' ? strtotime($pub) : false;
            $updTs = $upd !== '' ? strtotime($upd) : false;
            if ($pubTs) { $a['datePublished'] = date('c', $pubTs); }
            if ($updTs) { $a['dateModified'] = date('c', $updTs); }
            $out[] = $a;
        }

        if (empty($ctx['is_entry']) && !empty($ctx['list_items'])) {
            $url = $abs((string)$ctx['path']);
            $cp = ['@type' => 'CollectionPage', '@id' => $url . '#collection',
                   'url' => $url, 'inLanguage' => 'zh-CN',
                   'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => $ctx['list_items']]];
            if ($ctx['h1'] !== '') { $cp['name'] = (string)$ctx['h1']; }
            $desc = trim((string)($GLOBALS['YC_META']['description'] ?? ''));
            if ($desc !== '') { $cp['description'] = $desc; }
            $out[] = $cp;
        }

        if ((string)$ctx['path'] === '/download' && !empty($ctx['manifest'])) {
            $items = lq_mech_download_items($ctx['manifest']);
            if ($items !== []) {
                $url = $abs('/download');
                $out[] = ['@type' => 'CollectionPage', '@id' => $url . '#mirror',
                          'url' => $url, 'name' => ($ctx['h1'] !== '' ? (string)$ctx['h1'] : 'download'),
                          'inLanguage' => 'zh-CN',
                          'mainEntity' => ['@type' => 'ItemList',
                                           'itemListElement' => array_map(static function (array $n, int $i): array {
                                               return ['@type' => 'ListItem', 'position' => $i + 1, 'item' => $n];
                                           }, $items, array_keys($items))]];
            }
        }
        return $out;
    }

    /** 可见面包屑（页面内容已自带面包屑 ⇒ 让位） */
    function lq_mech_crumb_html(array $ctx): string
    {
        if (!empty($ctx['has_crumb'])) { return ''; }
        if (count($ctx['crumbs']) < 2) { return ''; }
        $n = count($ctx['crumbs']);
        $h = '<nav class="lq-crumbs" aria-label="breadcrumb"><ol>';
        foreach ($ctx['crumbs'] as $i => $c) {
            $last = ($i === $n - 1);
            $h .= '<li>';
            if (!$last && (string)$c['u'] !== '') {
                $h .= '<a href="' . e((string)$c['u']) . '">' . e((string)$c['n']) . '</a>';
            } else {
                $h .= '<span aria-current="page">' . e((string)$c['n']) . '</span>';
            }
            if (!$last) { $h .= '<i class="sep" aria-hidden="true">›</i>'; }
            $h .= '</li>';
        }
        return $h . '</ol></nav>';
    }

    /**
     * 可见「最后更新」（只对 type=post 的词条页；type=page 的页已有 partials/page.php 的日期，不重复）
     * + 朗读 / 打印（纯前端：浏览器自带 speechSynthesis / window.print()，**不引第三方 TTS**）
     */
    function lq_mech_meta_html(array $ctx): string
    {
        /* ★ lqta 2026-10-06：工具页（views/partials/tool.php）显式关掉这一块。
         *   参照 `/tool/<slug>` 137 页全量实测**没有**「更新于 / 朗读 / 打印」，站主批准
         *   「工具页上的文章形态一律去掉」。★ 文章页（/tips/* /learn/*）一个字节不受影响 ——
         *   全站只有工具页会把 YC_NO_MECH_META 置 true（与既有的 YC_NO_SIDE 同一套开关口径）。 */
        if (!empty($GLOBALS['YC_NO_MECH_META'])) { return ''; }
        $out = '';
        $row = $ctx['row'];
        if (empty($ctx['has_date']) && !empty($ctx['is_entry']) && is_array($row) && (string)($row['type'] ?? '') === 'post') {
            $u = (string)($row['updated_at'] ?? '');
            $ts = ($u !== '' && $u !== '0000-00-00 00:00:00') ? strtotime($u) : false;
            if ($ts) {
                $out .= '<time class="lq-updated" datetime="' . e(date('c', $ts)) . '">'
                      . e(__('pg.updated_at')) . e(date('Y-m-d', $ts)) . '</time>';
            }
        }
        /* 朗读/打印只在中文界面出现：站上语言包没有这两个键，不新增键、也不在别的语言里露中文 */
        $lang = Lang::short();
        if (!empty($ctx['is_entry']) && ($lang === 'zh' || $lang === 'zh-CN')) {
            $out .= '<span class="lq-acts">'
                  . '<button type="button" class="lq-act" id="lqTts" aria-pressed="false">朗读</button>'
                  . '<button type="button" class="lq-act" id="lqPrint">打印</button>'
                  . '</span>';
            $out .= '<script>(function(){'
                  . 'function init(){'
                  . 'var b=document.getElementById("lqTts"),body=document.getElementById("articleBody");'
                  . 'if(b&&body){var s=window.speechSynthesis;'
                  . 'if(!s||typeof SpeechSynthesisUtterance==="undefined"){b.hidden=true;}'
                  . 'else{var on=false;'
                  . 'b.addEventListener("click",function(){'
                  . 'if(on){s.cancel();on=false;b.setAttribute("aria-pressed","false");b.textContent="朗读";return;}'
                  . 'var t=(body.innerText||body.textContent||"").replace(/\\s+/g," ").trim();if(!t){return;}'
                  . 'var u=new SpeechSynthesisUtterance(t.slice(0,20000));'
                  . 'u.lang=document.documentElement.lang||"zh-CN";'
                  . 'u.onend=u.onerror=function(){on=false;b.setAttribute("aria-pressed","false");b.textContent="朗读";};'
                  . 'on=true;b.setAttribute("aria-pressed","true");b.textContent="停止朗读";s.speak(u);});}}'
                  . 'var p=document.getElementById("lqPrint");'
                  . 'if(p){p.addEventListener("click",function(){window.print();});}}'
                  . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init);}else{init();}'
                  . '})();</script>';
        }
        if ($out === '') { return ''; }
        return '<div class="lq-meta">' . $out . '</div>';
    }

    /** 下载页：版本 / 平台 / 体积 / SHA256 / 更新日期（值全部来自镜像清单，只读） */
    function lq_mech_download_html(array $ctx): string
    {
        if ((string)$ctx['path'] !== '/download') { return ''; }
        $m = $ctx['manifest'];
        if (!is_array($m)) { return ''; }
        $rows = '';
        foreach ((array)$m['browsers'] as $key => $b) {
            if (!is_array($b)) { continue; }
            $rel = null;
            foreach ((array)($b['releases'] ?? []) as $r) {
                if (is_array($r) && ($r['channel'] ?? '') === 'stable' && !empty($r['platforms'])) { $rel = $r; break; }
            }
            if ($rel === null) {
                foreach ((array)($b['releases'] ?? []) as $r) {
                    if (is_array($r) && !empty($r['platforms'])) { $rel = $r; break; }
                }
            }
            if ($rel === null) { continue; }
            foreach ((array)$rel['platforms'] as $pn => $pf) {
                if (!is_array($pf) || empty($pf['url'])) { continue; }
                $rows .= '<tr><td>' . e((string)($b['name'] ?? $key)) . '</td>'
                       . '<td>' . e((string)($rel['version'] ?? '')) . '</td>'
                       . '<td>' . e((string)$pn) . '</td>'
                       . '<td>' . e(lq_mech_size((int)($pf['size'] ?? 0))) . '</td>'
                       . '<td class="h"><code>' . e((string)($pf['sha256'] ?? '')) . '</code></td>'
                       . '<td><a href="' . e((string)$pf['url']) . '" rel="noopener">下载</a></td></tr>';
            }
        }
        if ($rows === '') { return ''; }
        $updTxt = '';
        $upd = (string)($m['updatedAt'] ?? '');
        $ts = $upd !== '' ? strtotime($upd) : false;
        if ($ts) { $updTxt = e(__('pg.updated_at')) . e(date('Y-m-d', $ts)); }
        return '<section class="lq-dl">'
             . '<h2>镜像版本与校验值</h2>'
             . ($updTxt !== '' ? '<p class="lq-dl-note"><time datetime="' . e(date('c', $ts)) . '">' . $updTxt . '</time></p>' : '')
             . '<div class="lq-dl-wrap"><table class="lq-dl-t"><thead><tr>'
             . '<th>浏览器</th><th>版本</th><th>平台</th><th>体积</th><th>SHA256</th><th>镜像</th>'
             . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
             . '</section>';
    }

    /** 相关阅读 = 同 section 最新 6 篇（★ `/learn*` 让位给学院线 M-3） */
    function lq_mech_related_html(array $ctx): string
    {
        if (empty($ctx['is_entry']) || empty($ctx['related'])) { return ''; }
        if (str_starts_with((string)$ctx['path'], '/learn')) { return ''; }
        $h = '<aside class="lq-rel"><h2 class="lq-rel-cap">' . e(__('sb.latest')) . '</h2><ul>';
        foreach ($ctx['related'] as $it) {
            $h .= '<li><a href="' . e((string)$it['u']) . '">' . e((string)$it['t']) . '</a></li>';
        }
        return $h . '</ul></aside>';
    }

    /** 机制自带样式（随页面内联；不改 style.css / 不新增静态资源 ⇒ 不涉及 ?v= 缓存问题） */
    function lq_mech_style(): string
    {
        return '<style>'
            . '.lq-crumbs{margin:10px auto 0;max-width:var(--wide-w,1200px);padding:0 16px;font-size:13px;color:var(--muted)}'
            . '.lq-crumbs ol{list-style:none;display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin:0;padding:0}'
            . '.lq-crumbs li{display:flex;align-items:center;gap:6px}'
            . '.lq-crumbs .sep{font-style:normal;opacity:.55}'
            . '.lq-meta{margin:6px auto 0;max-width:var(--wide-w,1200px);padding:0 16px;display:flex;flex-wrap:wrap;align-items:center;gap:12px;font-size:13px;color:var(--muted)}'
            . '.lq-acts{display:inline-flex;gap:8px}'
            . '.lq-act{cursor:pointer;border:1px solid var(--line,#e5e7eb);background:transparent;color:inherit;border-radius:999px;padding:2px 12px;font-size:13px;line-height:1.7}'
            . '.lq-act:hover{background:var(--accent,#2563eb);border-color:var(--accent,#2563eb);color:#fff}'
            . '.lq-rel{margin:28px 0 0;border-top:1px solid var(--line,#e5e7eb);padding-top:14px}'
            . '.lq-rel-cap{font-size:15px;margin:0 0 8px}'
            . '.lq-rel ul{list-style:none;margin:0;padding:0;display:grid;gap:6px}'
            . '.lq-rel a{color:var(--accent,#2563eb);text-decoration:none}'
            . '.lq-rel a:hover{text-decoration:underline}'
            . '.lq-dl{margin:28px 0 0;border-top:1px solid var(--line,#e5e7eb);padding-top:14px}'
            . '.lq-dl h2{font-size:16px;margin:0 0 6px}'
            . '.lq-dl-note{margin:0 0 10px;font-size:13px;color:var(--muted)}'
            . '.lq-dl-wrap{overflow-x:auto}'
            . '.lq-dl-t{width:100%;border-collapse:collapse;font-size:13px}'
            . '.lq-dl-t th,.lq-dl-t td{border:1px solid var(--line,#e5e7eb);padding:6px 8px;text-align:left;vertical-align:top}'
            . '.lq-dl-t td.h code{word-break:break-all;font-size:12px}'
            . '</style>';
    }
}
