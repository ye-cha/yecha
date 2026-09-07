<?php
declare(strict_types=1);
/**
 * Market：云市场核心
 * - 清单：option market.source（空=内置 var/market/manifest.json；http(s) URL 拉取 JSON）
 * - 安装：type=plugin|theme；package 支持 http(s) 或 var/market 相对文件；sha256 强校验；
 *         安全解包（拒绝 ../ 与绝对路径）到 plugins/<id> / themes/<id>；旧目录先备份到 var/backup/market/
 * - plugin 安装后自动：置启用、removed=0、跑 plugins/<id>/migrations
 */
final class Market
{
    private static function dataDir(): string
    {
        return dirname(__DIR__) . '/var/market';
    }

    public static function source(): string
    {
        return trim((string)get_option('market.source', ''));
    }

    /** 拉取清单（array of items），失败抛异常 */
    public static function manifest(): array
    {
        $src = self::source();
        if ($src === '') {
            $file = self::dataDir() . '/manifest.json';
            if (!is_file($file)) { return []; }
            $raw = file_get_contents($file);
        } elseif (preg_match('#^https://#i', $src)) {
            $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true, 'user_agent' => 'YeCha-Market/1.0']]);
            $raw = @file_get_contents($src, false, $ctx);
            if ($raw === false) { throw new RuntimeException('市场源不可达：' . $src); }
        } elseif (preg_match('#^http://#i', $src)) {
            throw new RuntimeException('市场源必须使用 https（拒绝明文 http 中间人篡改）');
        } else {
            throw new RuntimeException('market.source 仅支持 https 或留空（内置源）');
        }
        $raw = (string)$raw;
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // 兼容 BOM
        $j = json_decode($raw, true);
        $items = is_array($j) ? ($j['items'] ?? $j) : [];
        $out = [];
        foreach ((array)$items as $it) {
            if (!is_array($it) || empty($it['id'])) { continue; }
            $it['type'] = in_array($it['type'] ?? '', ['plugin', 'theme'], true) ? $it['type'] : 'plugin';
            $it['package'] = (string)($it['package'] ?? '');
            $it['sha256'] = strtolower((string)($it['sha256'] ?? ''));
            if ($it['package'] !== '') { $out[(string)$it['id']] = $it; }
        }
        return $out;
    }

    public static function installedVer(string $type, string $id): ?string
    {
        if ($type === 'plugin') {
            $m = Plugins::ext($id);
            return $m !== null ? (string)($m['ver'] ?? '') : null;
        }
        if ($type === 'theme') {
            $dir = dirname(__DIR__) . '/themes/' . $id;
            if (!is_dir($dir) || !is_file($dir . '/theme.json')) { return null; }
            $tj = json_decode((string)@file_get_contents($dir . '/theme.json'), true);
            return is_array($tj) ? (string)($tj['version'] ?? '') : '';
        }
        return null;
    }

    /** 下载包到临时文件并返回路径 */
    private static function download(string $package): string
    {
        $dir = self::dataDir();
        $tmp = $dir . '/.dl-' . bin2hex(random_bytes(6)) . '.zip';
        if (preg_match('#^https://#i', $package)) {
            $ctx = stream_context_create(['http' => ['timeout' => 90, 'follow_location' => 1, 'ignore_errors' => true, 'user_agent' => 'YeCha-Market/1.0']]);
            $bin = @file_get_contents($package, false, $ctx);
            if ($bin === false || strlen($bin) < 4) { @unlink($tmp); throw new RuntimeException('包下载失败'); }
            file_put_contents($tmp, $bin);
            return $tmp;
        }
        if (preg_match('#^http://#i', $package)) {
            throw new RuntimeException('包地址必须使用 https（拒绝明文 http）');
        }
        // 相对路径指向 var/market/
        $local = $package;
        if (str_starts_with($package, 'local:')) { $local = substr($package, 6); }
        $local = preg_replace('#/+#', '/', trim($local, '/'));
        if (strpos($local, '..') !== false) { throw new RuntimeException('非法包路径'); }
        $src = $dir . '/' . $local;
        if (!is_file($src)) { throw new RuntimeException('本地包不存在：' . $local); }
        copy($src, $tmp);
        return $tmp;
    }

    /** 校验 sha256（缺省在安装列表界面提示？此处强制非空） */
    private static function verify(string $file, string $expect): void
    {
        if ($expect === '') { @unlink($file); throw new RuntimeException('清单缺少 sha256，拒绝安装'); }
        $got = hash_file('sha256', $file);
        if (!hash_equals($expect, (string)$got)) { @unlink($file); throw new RuntimeException('SHA-256 校验失败（期望 ' . $expect . '，实际 ' . $got . '）'); }
    }

    /** 安装 plugin / theme */
    public static function install(array $item): array
    {
        $type = ($item['type'] ?? 'plugin') === 'theme' ? 'theme' : 'plugin';
        $id = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)($item['id'] ?? ''))) ?? '';
        if ($id === '' || $id === '.' || $id === '..') { throw new RuntimeException('无效 id'); }
        $root = $type === 'theme' ? (dirname(__DIR__) . '/themes/' . $id) : (dirname(__DIR__) . '/plugins/' . $id);
        $zipFile = self::download((string)$item['package']);
        self::verify($zipFile, (string)$item['sha256']);

        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) { @unlink($zipFile); throw new RuntimeException('无法打开 zip'); }
        // 计算共同顶层目录（若唯一且为目录则剥掉）
        $top = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if ($n === '') { continue; }
            $first = explode('/', $n)[0];
            $top[$first] = true;
        }
        $strip = count($top) === 1 ? (string)array_key_first($top) : '';

        $stage = dirname(__DIR__) . '/var/tmp/market-' . $id . '-' . bin2hex(random_bytes(4));
        $ok = false;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);
                if ($n === '' || str_ends_with($n, '/')) { continue; }
                if (str_starts_with($n, '/') || strpos($n, '..') !== false) { throw new RuntimeException('包内含非法路径：' . $n); }
                $rel = $strip !== '' ? substr($n, strlen($strip) + 1) : $n;
                $dest = $stage . '/' . $rel;
                if (!str_starts_with($dest, $stage . '/')) { throw new RuntimeException('越界路径'); }
                @mkdir(dirname($dest), 0775, true);
                copy('zip://' . $zipFile . '#' . $n, $dest);
            }
            $zip->close();
            // 核心文件校验
            if ($type === 'plugin' && !is_file($stage . '/plugin.php')) { throw new RuntimeException('插件包缺少 plugin.php'); }
            if ($type === 'theme' && !is_file($stage . '/theme.json')) { throw new RuntimeException('主题包缺少 theme.json'); }
            // 备份旧目录
            if (is_dir($root)) {
                $bk = dirname(__DIR__) . '/var/backup/market';
                @mkdir($bk, 0775, true);
                $old = $bk . '/' . $type . '-' . $id . '-' . date('YmdHis');
                if (!rename($root, $old)) { throw new RuntimeException('备份旧目录失败'); }
            }
            @mkdir($root, 0775, true);
            // 移动 staged 内容
            foreach (scandir($stage) ?: [] as $ent) {
                if ($ent === '.' || $ent === '..') { continue; }
                if (!rename($stage . '/' . $ent, $root . '/' . $ent)) { throw new RuntimeException('写入失败'); }
            }
            $ok = true;
        } finally {
            @unlink($zipFile);
            self::rmr($stage);
            if (!$ok && is_dir($root) && !is_file($type === 'plugin' ? $root . '/plugin.php' : $root . '/theme.json')) { self::rmr($root); }
        }
        // 插件激活
        if ($type === 'plugin') {
            set_option('plugin.' . $id . '.removed', '0');
            set_option('plugin.' . $id . '.enabled', '1');
            Upgrade::apply($id, fn(string $s) => null);
        }
        Cache::flush('pages');
        Cache::flush('blocks');
        return ['type' => $type, 'id' => $id];
    }

    private static function rmr(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') { continue; }
            $p = $dir . '/' . $e;
            is_dir($p) ? self::rmr($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /** 目录内现有主题（用于列表已装判断与切换） */
    public static function themes(): array
    {
        $out = [];
        foreach ((array)scandir(dirname(__DIR__) . '/themes') as $d) {
            $tf = dirname(__DIR__) . '/themes/' . $d . '/theme.json';
            if ($d === '.' || $d === '..' || !is_file($tf)) { continue; }
            $j = json_decode((string)@file_get_contents($tf), true);
            $out[$d] = is_array($j) ? (string)($j['name'] ?? $d) : $d;
        }
        return $out;
    }
}
