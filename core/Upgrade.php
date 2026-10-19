<?php
declare(strict_types=1);
/**
 * Upgrade：一键升级框架（后台一键执行 + 迁移注册）
 * - ye_migrations(component, file, applied_at)：每组件各自版本推进
 * - 迁移文件：核心 install/migrations/；插件 plugins/<id>/migrations/；主题 themes/<name>/migrations/
 *   .sql 用分号拆分逐条执行；.php 直接 require（内部使用 Db::run 等，需幂等：先查后加）
 * - 安全：所有迁移要求幂等；执行前必须备份；失败停止并记录日志；已应用文件只记不重跑。
 */
final class Upgrade
{
    public const COMPONENTS = ['core' => '系统核心', 'poly' => '多语言插件', 'notes' => '记事本插件', 'theme-yecha' => '主题 yecha'];

    public static function ensureRegistry(): void
    {
        $t = Db::t('migrations');
        $ok = Db::rows("SHOW TABLES LIKE '{$t}'");
        if (!$ok) {
            Db::run("CREATE TABLE IF NOT EXISTS {$t} (
                component VARCHAR(40) NOT NULL,
                file      VARCHAR(200) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (component, file)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    }

    public static function componentVersion(string $component): string
    {
        if ($component === 'core') { return defined('YC_VERSION') ? YC_VERSION : '?'; }
        if (str_starts_with($component, 'theme-')) { return '1.0.0'; }
        $d = Plugins::def($component);
        return $d['ver'] ?? '1.0.0';
    }

    /** 迁移目录（不存在返回 null） */
    public static function dirFor(string $component): ?string
    {
        $base = dirname(__DIR__);
        if ($component === 'core') { return $base . '/install/migrations'; }
        if (str_starts_with($component, 'theme-')) {
            $d = $base . '/themes/' . substr($component, 6) . '/migrations';
            return is_dir($d) ? $d : null;
        }
        $d = $base . '/plugins/' . $component . '/migrations';
        return is_dir($d) ? $d : null;
    }

    public static function applied(string $component): array
    {
        self::ensureRegistry();
        $rows = Db::rows('SELECT file FROM ' . Db::t('migrations') . ' WHERE component=?', [$component]);
        return array_column($rows, 'file');
    }

    public static function pending(string $component): array
    {
        $dir = self::dirFor($component);
        if ($dir === null || !is_dir($dir)) { return []; }
        $files = [];
        foreach ((array)scandir($dir) as $f) {
            if (str_ends_with($f, '.sql') || str_ends_with($f, '.php')) { $files[] = $dir . '/' . $f; }
        }
        sort($files);
        $done = self::applied($component);
        $out = [];
        foreach ($files as $f) {
            $name = basename($f);
            if (str_starts_with($name, 'README') || in_array($name, $done, true)) { continue; }
            $out[] = $f;
        }
        return $out;
    }

    /** 执行某组件全部待应用迁移；返回 ['applied'=>[],'skipped'=>[],'error'=>null] */
    public static function apply(string $component, ?callable $log = null): array
    {
        self::ensureRegistry();
        $log = $log ?? fn(string $s) => null;
        $files = self::pending($component);
        $res = ['applied' => [], 'error' => null];
        foreach ($files as $f) {
            $name = basename($f);
            try {
                if (str_ends_with(strtolower($name), '.sql')) {
                    $sql = file_get_contents($f);
                    if ($sql === false) { throw new RuntimeException('read fail: ' . $name); }
                    foreach (self::splitSql($sql) as $st) { Db::run($st); }
                } else {
                    /* .php 迁移：内部使用 Db::run 等，必须幂等。
                       ★ 2026-09-26：迁移文件里常有诊断用 `echo`（如 "ye_xxx tables exists"）。
                       插件首次启用的请求路径上，Plugins::boot() 会对「自有表缺失」的插件
                       直接 apply() 迁移 —— 那些 echo 会在 index.php 发响应头之前写出字节，
                       造成「Cannot modify header information - headers already sent」并把
                       http_response_code() 变成空操作（新装站实测踩到）。
                       这里把迁移输出收进缓冲区（正常为空），仅在有输出时写升级日志。 */
                    ob_start();
                    try {
                        require $f;
                    } finally {
                        $__migOut = (string)ob_get_clean();
                    }
                    if (trim($__migOut) !== '') {
                        $log('output ' . $component . ':' . basename($f) . ' :: ' . trim(substr($__migOut, 0, 400)));
                    }
                }
                Db::insert('migrations', ['component' => $component, 'file' => $name]);
                $res['applied'][] = $name;
                $log('applied ' . $component . ':' . $name);
            } catch (Throwable $e) {
                $res['error'] = $name . ' :: ' . $e->getMessage();
                $log('ERROR ' . $component . ':' . $name . ' :: ' . $e->getMessage());
                break;
            }
        }
        return $res;
    }

    /** 简单 SQL 拆分（忽略 -- 注释行与空段）
     *  ★ 2026-09-19 修（内核合流 2026-09-26，来源：yecha-dev core/Upgrade.php）：
     *  原实现先 trim 整段、段首是 "--" 就整段 continue，
     *  会把「上一条语句的分号 → 注释行 → 有效 SQL」的段落**连 SQL 一起吞掉**
     *  （mall 001_init.sql 有 5 张表因此静默漏建，且迁移被记为成功）。
     *  改为逐行剔除注释行/空行，只丢弃纯注释段，保留段内有效 SQL。 */
    private static function splitSql(string $sql): array
    {
        $out = [];
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $st) {
            $keep = [];
            foreach (preg_split('/\r?\n/', (string)$st) ?: [] as $ln) {
                $t = ltrim($ln);
                if ($t === '' || str_starts_with($t, '--')) { continue; }
                $keep[] = $ln;
            }
            $st = trim(implode("\n", $keep));
            if ($st !== '') { $out[] = $st; }
        }
        return $out;
    }

    /** 组件总体状态（供后台展示） */
    public static function status(): array
    {
        $out = [];
        foreach (array_keys(self::COMPONENTS) as $c) {
            $pending = self::pending($c);
            $out[$c] = [
                'name' => self::COMPONENTS[$c],
                'version' => self::componentVersion($c),
                'applied' => count(self::applied($c)),
                'pending' => $pending,
            ];
        }
        return $out;
    }

    /** 追加一行升级日志 */
    public static function log(string $s): void
    {
        @file_put_contents(dirname(__DIR__) . '/var/log/upgrade.log', date('Y-m-d H:i:s') . ' ' . $s . "\n", FILE_APPEND);
    }


    /** 远程更新检测：读取 upgrade.manifest_url → JSON{version,notes,zip,sha256} 或 null */
    public static function remoteStatus(): ?array
    {
        $url = trim((string)get_option('upgrade.manifest_url', ''));
        if ($url === '') { return null; }
        $ck = 'upg_remote_' . md5($url);
        if (class_exists('Cache')) {
            $c = Cache::get('misc', $ck);
            if ($c !== false) {
                $cJ = json_decode((string)$c, true);
                return is_array($cJ) && !empty($cJ['version']) ? $cJ : null;
            }
        }
        $raw = self::httpGet($url, 8);
        $ok = false; $m = null;
        if ($raw !== false) {
            $m = json_decode((string)$raw, true);
            $ok = is_array($m) && !empty($m['version']);
        }
        if (class_exists('Cache')) { Cache::set('misc', $ck, $ok ? (string)$raw : '{}', $ok ? 600 : 300); }
        return $ok ? $m : null;
    }

    /**
     * 版本比较：同时理解两种写法 —— ①「1.0 Beta Build20260910」（当前发布格式：主.次 [阶段] [Build 日期]）
     * ② 旧语义化写法「1.0.0-beta.2」。比较次序：主 → 次 → 修订 → 阶段（dev<alpha<beta<rc<正式）→ Build 日期 → 阶段序号。
     * 任一侧无法解析时退回旧的「只取数字 + version_compare」规则。
     * @return int  -1 / 0 / 1
     */
    public static function versionCmp(string $a, string $b): int
    {
        $pa = self::parseVersion($a);
        $pb = self::parseVersion($b);
        if ($pa === null || $pb === null) {
            $na = (string)preg_replace('/[^0-9.]/', '', $a);
            $nb = (string)preg_replace('/[^0-9.]/', '', $b);
            return version_compare($na, $nb, '>') ? 1 : (version_compare($na, $nb, '<') ? -1 : 0);
        }
        $order = ['dev' => 1, 'alpha' => 2, 'beta' => 3, 'rc' => 4, '' => 5];
        $rank = static fn(array $p): int => $order[$p['stage']] ?? 5;
        foreach ([['major'], ['minor'], ['patch']] as $k) {
            if ($pa[$k[0]] !== $pb[$k[0]]) { return $pa[$k[0]] <=> $pb[$k[0]]; }
        }
        if ($rank($pa) !== $rank($pb)) { return $rank($pa) <=> $rank($pb); }
        if ($pa['build'] !== $pb['build']) { return $pa['build'] <=> $pb['build']; }
        return $pa['stageNo'] <=> $pb['stageNo'];
    }

    /**
     * 解析版本串：`1.0 Beta Build20260910` / `1.0.0-beta.2` / `1.2.3` / `1.0 RC Build20261001`
     * @return array{major:int,minor:int,patch:int,stage:string,build:int,stageNo:int}|null
     */
    private static function parseVersion(string $v): ?array
    {
        $v = trim($v);
        if ($v === '') { return null; }
        if (!preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?[\s\-_.]*(dev|alpha|beta|rc)?[\s\-_.]*(\d+)?[\s\-_.]*(?:build[\s\-_.]*(\d{6,8}))?$/i', $v, $m)) {
            return null;
        }
        $stage = strtolower((string)($m[4] ?? ''));
        // 「1.0 Beta Build20260910」：第 5 组是阶段序号（无则 0），第 6 组是构建日期
        $hasBuild = isset($m[6]) && $m[6] !== '';
        return [
            'major' => (int)$m[1],
            'minor' => (int)($m[2] ?? 0),
            'patch' => (int)($m[3] ?? 0),
            'stage' => $stage,
            'build' => $hasBuild ? (int)$m[6] : 0,
            'stageNo' => ($hasBuild || !isset($m[5]) || $m[5] === '') ? 0 : (int)$m[5],
        ];
    }

    public static function isUpdateAvailable(array $m): bool
    {
        $cur = defined('YC_VERSION') ? (string)YC_VERSION : '0';
        return self::versionCmp((string)($m['version'] ?? '0'), $cur) > 0;
    }

    /** 简单 HTTP GET（返回 body 或 false），兼容 allow_url_fopen 关闭时用 curl */
    private static function httpGet(string $url, int $timeout): string|false
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false]);
            $out = curl_exec($ch);
            curl_close($ch);
            return is_string($out) ? $out : false;
        }
        if (ini_get('allow_url_fopen') === '1') {
            $c = @file_get_contents($url, false, null, 0, 2 * 1024 * 1024);
            return $c === false ? false : (string)$c;
        }
        return false;
    }

    /** 远程一键升级：备份→下载zip→sha256校验→解压到站点根→应用核心迁移→清缓存 */
    public static function applyRemote(array $m): array
    {
        $out = ['ok' => true, 'msg' => ''];
        $zipUrl = (string)($m['zip'] ?? '');
        if ($zipUrl === '') { $out = ['ok' => false, 'msg' => '更新源未提供 zip 包']; return $out; }
        $dir = dirname(__DIR__) . '/var/upgrade';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $zip = $dir . '/yc-update.zip';
        $bin = self::httpGet($zipUrl, 120);
        if ($bin === false || $bin === '') { $out = ['ok' => false, 'msg' => '下载更新包失败']; return $out; }
        @file_put_contents($zip, $bin);
        if (!empty($m['sha256'])) {
            if (!hash_equals((string)$m['sha256'], hash_file('sha256', $zip))) { $out = ['ok' => false, 'msg' => 'SHA-256 校验失败，已中止']; return $out; }
        }
        if (!class_exists('ZipArchive')) { $out = ['ok' => false, 'msg' => '缺少 ZipArchive 扩展，无法解包']; return $out; }
        $za = new ZipArchive();
        if ($za->open($zip) !== true) { $out = ['ok' => false, 'msg' => '更新包解压失败']; return $out; }
        $root = dirname(__DIR__);
        // 备份将被覆盖的代码目录
        $bakDir = dirname(__DIR__) . '/var/backup/upgrade-' . date('Ymd-His');
        @mkdir($bakDir, 0777, true);
        foreach (['core', 'admin', 'install', 'themes', 'plugins', 'index.php'] as $item) {
            $src = $root . '/' . $item;
            if (file_exists($src)) {
                $dest = $bakDir . '/' . $item;
                if (is_dir($src)) { @exec('cp -r ' . escapeshellarg($src) . ' ' . escapeshellarg($dest)); }
                else { @copy($src, $dest); }
            }
        }
        for ($i = 0; $i < $za->numFiles; $i++) {
            $name = $za->getNameIndex($i);
            if (str_ends_with($name, '/')) { continue; }
            if (str_starts_with($name, 'config.inc.php') || str_starts_with($name, 'var/') || str_starts_with($name, 'uploads/')) { continue; }
            $za->extractTo($root, $name);
        }
        $za->close();
        @unlink($zip);
        // 应用迁移（core + 已存在的组件）
        Upgrade::apply('core', fn(string $s) => Upgrade::log('  ' . $s));
        if (function_exists('Cache')) { Cache::flush('pages'); Cache::flush('blocks'); }
        $out['msg'] = '升级完成：新版本 ' . (string)($m['version'] ?? '') . '，备份在 ' . basename($bakDir) . '（如有异常可回滚）。';
        return $out;
    }
}