<?php
declare(strict_types=1);
/**
 * 词条页静态化（entry-static）· 共享逻辑
 * ============================================================================
 * 做什么：把**已发布**词条页（/entry/{slug}）渲染成静态 HTML 落到
 *         var/entry-static/entry/<slug>.html，由 nginx `try_files` 直接直出
 *         ⇒ 省掉 PHP + DB 全链路，直接缓解 php-fpm 池压力（治 504 的本）。
 *
 * ★ 本文件只定义函数；被 HTTP 直接访问（/plugins/entry-static/inc.php）时**零副作用**
 *   （与 plugins/home-static/inc.php 同款约定）。
 *
 * 失效（本文件的核心）：
 *   ・逐条精确：词条被改（updated_at 变）、被撤稿（status!=publish）、被删（deleted_at）
 *     ⇒ **立刻删掉它的静态文件**（删除是同步的、无条件的；生成是异步的）
 *       —— 删掉即回落动态渲染（= 内核真实行为，含 gone301 的 301/404），
 *          所以「draft 复活」在结构上不可能发生：文件不在，nginx 落不到静态分支。
 *   ・全局：站点选项（site./theme./home./blogger./content./plugin.*.enabled）或
 *     主题关键文件（layout.php/post.php/inc.php/css/js）变了 ⇒ 触发后台全量重建
 *     （保持直出，不在高峰期删库式失效）。
 *   ・兜底：tools/entry-static-build.sh 每次运行都会「按 DB 的 publish 集合删掉多余文件」
 *     —— 与上面的 PHP 侧互为双保险。
 */
if (!function_exists('es_root')) {

    /* ---------------- 路径 ---------------- */
    function es_root(): string { return dirname(__DIR__, 2); }
    function es_dir(): string { return es_root() . '/var/entry-static'; }
    function es_entry_dir(): string { return es_dir() . '/entry'; }
    function es_state_file(): string { return es_dir() . '/.state.json'; }
    function es_beat_file(): string { return es_dir() . '/.beat'; }
    function es_pending_file(): string { return es_dir() . '/.pending'; }
    function es_sitemap_file(): string { return es_dir() . '/sitemap.xml'; }
    function es_build_script(): string { return es_root() . '/tools/entry-static-build.sh'; }
    function es_log_file(): string { return es_root() . '/var/log/entry-static.log'; }

    /** 开关（后台可一键停用；停用后本插件的钩子全部不动手） */
    function es_on(): bool { return (string)get_option('entry_static.enabled', '1') === '1'; }

    function es_log(string $msg): void
    {
        $f = es_log_file();
        if (@is_file($f) && (int)@filesize($f) > 1048576) { @rename($f, $f . '.1'); }
        @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
    }

    /* ---------------- 状态文件（只读/写小 JSON） ---------------- */
    function es_state_load(): array
    {
        $f = es_state_file();
        if (!is_file($f)) { return []; }
        $j = json_decode((string)@file_get_contents($f), true);
        return is_array($j) ? $j : [];
    }

    function es_state_save(array $st): void
    {
        $d = es_dir();
        if (!is_dir($d)) { @mkdir($d, 0777, true); }
        $f = es_state_file();
        $tmp = $f . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($st, JSON_UNESCAPED_UNICODE)) !== false) {
            @chmod($tmp, 0666);
            @rename($tmp, $f);
        }
    }

    /** 该 slug 的静态文件路径（'' = slug 形态不安全，拒绝处理） */
    function es_file_of(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '' || str_contains($slug, '/') || str_contains($slug, '..') || str_contains($slug, "\0")) { return ''; }
        if (preg_match('~[^A-Za-z0-9._\-]~', $slug)) { return ''; }   // 词条 slug 全站均为 ASCII 安全形态（已实测 11,574 条）
        return es_entry_dir() . '/' . $slug . '.html';
    }

    function es_unlink(string $slug): bool
    {
        $f = es_file_of($slug);
        if ($f === '' || !is_file($f)) { return false; }
        return @unlink($f);
    }

    /* ---------------- 登录用户：让 nginx 把静态直出让开 ---------------- */
    /**
     * 词条页对**已登录访客**的 HTML 与匿名访客不同（顶栏用户菜单 / 前台编辑入口）。
     * 静态文件只对匿名访客成立 ⇒ 登录用户下发一枚标记 cookie，nginx 侧 map 命中即回落动态。
     * 未登录时把该 cookie 清掉（避免"曾经登录过"的访客长期吃不到静态直出）。
     */
    function es_cookie_sync(): void
    {
        if (headers_sent()) { return; }
        $has = isset($_COOKIE['yc_es_dyn']);
        $login = class_exists('Auth') && Auth::check();
        if ($login && !$has) {
            setcookie('yc_es_dyn', '1', [
                'expires' => time() + 43200, 'path' => '/',
                'secure' => (function_exists('yc_theme_scheme') ? yc_theme_scheme() === 'https' : true),
                'httponly' => true, 'samesite' => 'Lax',
            ]);
        } elseif (!$login && $has) {
            setcookie('yc_es_dyn', '', [
                'expires' => time() - 3600, 'path' => '/',
                'secure' => (function_exists('yc_theme_scheme') ? yc_theme_scheme() === 'https' : true),
                'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
    }

    /* ---------------- 变更侦测（PHP 侧看门狗） ---------------- */
    /** 与页面 HTML 有关的全局信号：站点选项 + 主题关键文件 mtime */
    function es_global_sig(): string
    {
        $opt = '';
        try {
            $opt = (string)Db::one(
                'SELECT COALESCE(MAX(updated_at), \'\') FROM ' . Db::t('options')
                . " WHERE name LIKE 'site.%' OR name LIKE 'theme.%' OR name LIKE 'home.%'"
                . " OR name LIKE 'blogger.%' OR name LIKE 'content.%' OR name LIKE 'plugin.%.enabled'"
            );
        } catch (Throwable $e) { $opt = ''; }
        $t = [];
        foreach (['views/layout.php', 'views/partials/post.php', 'views/404.php', 'inc.php',
                  'theme.json', 'style.css', 'wiki2.css', 'chawiki.css', 'chawiki.js'] as $rel) {
            $f = es_root() . '/themes/chawiki/' . $rel;
            $t[] = $rel . ':' . (int)(@filemtime($f) ?: 0);
        }
        return md5($opt . '|' . implode(',', $t));
    }

    /** 触发后台构建器（异步、防抖 20 秒；失败静默 —— 静态缺失只会回落动态，不会出错）
     *  ★ 防抖被吃掉的情形由 state 里的 dirty 标记兜住：只有**真的起了 worker** 才清 dirty，
     *    否则下一次 es_sync（≥5 秒后）看到 dirty 会再试 —— 保证「删了就一定会去重建」。 */
    function es_trigger_worker(string $args): void
    {
        $st = es_state_load();
        if (time() - (int)($st['worker_at'] ?? 0) < 20) { return; }   // 留给下一次 sync 重试（dirty 不动）
        $st['worker_at'] = time();
        $st['dirty'] = 0;
        es_state_save($st);
        $sh = es_build_script();
        if (!is_file($sh)) { es_log('worker 缺失: ' . $sh); return; }
        if (!function_exists('exec')) { es_log('exec 不可用，worker 未触发'); return; }
        @exec('/usr/bin/nohup /bin/bash ' . escapeshellarg($sh) . ' ' . $args
            . ' >> ' . escapeshellarg(es_log_file()) . ' 2>&1 &');
    }

    /**
     * 同步一次：把「已不是 publish / 已被改」的词条静态文件删掉，并唤醒构建器补齐。
     * 代价：一次聚合查询（未变则立刻返回）；变了才拉 12k 行的 slug 清单做 diff。
     */
    function es_sync(bool $force = false): array
    {
        $res = ['gate' => false, 'deleted' => 0, 'pending' => 0, 'global' => false];
        try {
            $T = Db::t('contents');
            $fp = Db::row("SELECT COUNT(*) c, COALESCE(MAX(updated_at),'') m,"
                . " SUM(status='publish' AND deleted_at IS NULL) p FROM $T");
            $sig = (string)($fp['c'] ?? '') . '|' . (string)($fp['m'] ?? '') . '|' . (string)($fp['p'] ?? '');
            $st = es_state_load();
            if (!$force && ($st['fp'] ?? '') === $sig && $sig !== '') {
                // 指纹没变，但上一轮可能有被防抖吃掉的重建请求 ⇒ 补一次
                if (!empty($st['dirty'])) { es_trigger_worker('--sync --max 200 --concurrency 2'); }
                return $res;
            }
            $res['gate'] = true;

            $rows = Db::rows("SELECT slug, status, deleted_at, updated_at FROM $T WHERE slug IS NOT NULL AND slug <> ''");
            $desired = [];
            foreach ($rows as $r) {
                $slug = (string)$r['slug'];
                if ($r['status'] !== 'publish' || $r['deleted_at'] !== null) { continue; }
                $desired[$slug] = (string)$r['updated_at'];
            }

            $old = (array)($st['slugs'] ?? []);
            $pending = [];
            foreach ($old as $slug => $info) {
                $slug = (string)$slug;
                if (!isset($desired[$slug])) {                       // 撤稿 / 删除 / slug 改名（只剩新名）
                    if (es_unlink($slug)) { $pending[] = $slug; }
                    continue;
                }
                if ((string)($info['u'] ?? '') !== $desired[$slug] || (string)($info['p'] ?? '') !== 'publish') {
                    if (es_unlink($slug)) { $pending[] = $slug; }     // 被改：先删（此后走动态=最新），再排队重建
                }
            }
            foreach ($desired as $slug => $u) {
                if (!isset($old[$slug])) { $pending[] = (string)$slug; }
            }

            $gsig = es_global_sig();
            $global = (isset($st['gsig']) && (string)$st['gsig'] !== $gsig);
            $res['global'] = $global;

            $slugs = [];
            foreach ($desired as $slug => $u) { $slugs[(string)$slug] = ['u' => $u, 'p' => 'publish']; }
            $st['slugs'] = $slugs;
            $st['fp'] = $sig;
            $st['gsig'] = $gsig;
            $st['gen_at'] = time();
            es_state_save($st);

            $pending = array_values(array_unique(array_filter($pending, fn($s) => es_file_of($s) !== '')));
            $res['deleted'] = count($pending);
            if ($pending !== [] || $global) { $st['dirty'] = 1; es_state_save($st); }
            if ($pending !== []) {
                @file_put_contents(es_pending_file(), implode("\n", array_slice($pending, 0, 2000)) . "\n");
            }
            $res['pending'] = count($pending);

            if ($global) {
                es_log('全局信号变化 → 全量重建排队（slugs=' . count($desired) . '）');
                es_trigger_worker('--all --max 1500 --concurrency 2');
            } elseif ($pending !== [] || count($desired) === 0) {
                es_trigger_worker('--sync --max 200 --concurrency 2');
            } else {
                es_trigger_worker('--sync --max 60 --concurrency 2');
            }
        } catch (Throwable $e) {
            es_log('es_sync 异常: ' . $e->getMessage());
        }
        return $res;
    }

    /** 请求结束时的看门狗（register_file 里注册） */
    function es_watch_shutdown(): void
    {
        try {
            if (PHP_SAPI === 'cli' || !es_on()) { return; }
            $now = time();
            $write = (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET');
            $isAdmin = str_contains((string)($_SERVER['SCRIPT_FILENAME'] ?? ''), '/admin/');
            $beat = es_beat_file();
            if (!$write && !$isAdmin && ($now - (int)(@filemtime($beat) ?: 0)) < 5) { return; }
            if (!is_dir(es_dir())) { return; }        // 未启用/未初始化（构建器会负责建目录）
            @touch($beat);
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            es_sync();
        } catch (Throwable $e) {
            // 静默：静态化绝不能影响正常请求
        }
    }

    /* ---------------- 后台状态页 ---------------- */
    function es_admin_stats(): array
    {
        $out = ['desired' => 0, 'present' => 0, 'pending' => 0, 'sitemap' => 0, 'lock' => false];
        try {
            $out['desired'] = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('contents')
                . " WHERE type IN ('post','page') AND status='publish' AND deleted_at IS NULL AND slug IS NOT NULL AND slug <> ''");
        } catch (Throwable $e) {}
        $d = es_entry_dir();
        $out['present'] = is_dir($d) ? count((array)glob($d . '/*.html')) : 0;
        $out['pending'] = is_file(es_pending_file()) ? count(array_filter(explode("\n", (string)@file_get_contents(es_pending_file())))) : 0;
        $out['sitemap'] = is_file(es_sitemap_file()) ? (int)@filesize(es_sitemap_file()) : 0;
        return $out;
    }
}
