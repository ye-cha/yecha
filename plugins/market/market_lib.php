<?php
declare(strict_types=1);
// 市场收录：核心库（下载/验证/发布 manifest）
if (!function_exists('mkt_table')) {
    function mkt_table(): string { return Db::t('market_reqs'); }
    function mkt_root(): string { return dirname(__DIR__, 2); } // 站点根
    function mkt_pending_dir(): string { $d = mkt_root() . '/var/market/pending'; if (!is_dir($d)) { @mkdir($d, 0775, true); } return $d; }
    function mkt_manifest_path(): string { return mkt_root() . '/var/market/manifest.json'; }

    /** 只允许 http/https 公网下载；curl 到本地临时文件，限大小 */
    function mkt_download(string $url, string $save, int $maxBytes = 30 * 1024 * 1024): array
    {
        $p = parse_url($url);
        if (!$p || !isset($p['scheme'], $p['host']) || !in_array(strtolower((string)$p['scheme']), ['http', 'https'], true)) {
            return ['ok' => false, 'err' => '只接受 http(s) 下载地址'];
        }
        $fh = @fopen($save, 'wb');
        if ($fh === false) { return ['ok' => false, 'err' => '无法创建临时文件']; }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'YeCha-MarketVerify/1.0 (+https://yecha.org)',
            CURLOPT_NOPROGRESS => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $ok = curl_exec($ch);
        $err = (string)curl_error($ch);
        $size = (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fh);
        $fs = (int)@filesize($save);
        if (!$ok || $err !== '') { @unlink($save); return ['ok' => false, 'err' => '下载失败：' . $err]; }
        if ($code >= 400) { @unlink($save); return ['ok' => false, 'err' => '服务器返回 HTTP ' . $code]; }
        if ($fs > $maxBytes) { @unlink($save); return ['ok' => false, 'err' => '包超过 30MB 限制']; }
        $magic = @file_get_contents($save, false, null, 0, 4);
        if ($magic !== "PK\x03\x04" && $magic !== "PK\x05\x06" && $magic !== "PK\x07\x08") { @unlink($save); return ['ok' => false, 'err' => '不是有效的 ZIP 包']; }
        return ['ok' => true, 'err' => '', 'size' => $fs];
    }

    function mkt_php_bin(): string
    {
        foreach (['/usr/bin/php', '/usr/local/bin/php', '/www/server/php/83/bin/php'] as $b) {
            if (is_file($b)) { return $b; }
        }
        return 'php';
    }

    /** 遍历目录对每个 .php 做 php -l；返回错误列表（空=全部通过） */
    function mkt_lint_dir(string $dir): array
    {
        $errs = [];
        $bin = mkt_php_bin();
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') { continue; }
            $cmd = escapeshellarg($bin) . ' -l ' . escapeshellarg((string)$f->getPathname()) . ' 2>&1';
            $out = @shell_exec($cmd);
            if ($out === null || stripos((string)$out, 'No syntax errors') === false) {
                $errs[] = basename((string)$f->getPathname()) . ': ' . trim((string)$out);
            }
        }
        return $errs;
    }

    /** 验证 zip：结构（防穿越）+ 清单 + php 语法；不执行包内代码 */
    function mkt_validate_zip(string $zipFile, string $type): array
    {
        $res = ['ok' => false, 'sha' => hash_file('sha256', $zipFile) ?: '', 'err' => [], 'files' => 0, 'size' => (int)@filesize($zipFile)];
        $za = new ZipArchive();
        if (@$za->open($zipFile) !== true) { $res['err'][] = '无法打开 zip'; return $res; }
        $names = [];
        for ($i = 0; $i < $za->numFiles; $i++) {
            $n = (string)$za->getNameIndex($i);
            $n = str_replace('\\', '/', $n);
            if (str_contains($n, '../') || str_starts_with($n, '/')) { $res['err'][] = '非法路径：' . mb_substr($n, 0, 60); $za->close(); return $res; }
            $names[] = $n;
        }
        $res['files'] = count($names);
        if ($type === 'theme') {
            $hasCss = false;
            foreach ($names as $n) { if (str_ends_with(strtolower($n), '.css')) { $hasCss = true; break; } }
            if (!$hasCss) { $res['err'][] = '主题包至少需要一个 CSS 样式文件'; $za->close(); return $res; }
        } else {
            if (!in_array('plugin.php', $names, true)) { $res['err'][] = '插件包根目录需要 plugin.php'; $za->close(); return $res; }
        }
        $tmp = sys_get_temp_dir() . '/yc_mkt_' . bin2hex(random_bytes(6));
        if (!@mkdir($tmp, 0775, true)) { $res['err'][] = '无法创建临时目录'; $za->close(); return $res; }
        $za->extractTo($tmp);
        $za->close();
        $lint = mkt_lint_dir($tmp);
        // 清理
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir((string)$f->getPathname()) : @unlink((string)$f->getPathname()); }
        @rmdir($tmp);
        if ($lint) { $res['err'] = array_merge($res['err'], array_slice($lint, 0, 12)); $res['err'][] = sprintf('…共 %d 个 PHP 语法问题', count($lint)); return $res; }
        $res['ok'] = true;
        return $res;
    }

    /** 发布到市场 manifest（同 id 条目会被该版本替换），zip 已存在于 $pendingFile */
    function mkt_publish(array $req, string $pendingFile, ?string $dirOverride = null): array
    {
        $root = $dirOverride !== null ? $dirOverride : mkt_root() . '/var/market';
        if (!is_dir($root)) { return ['ok' => false, 'err' => '市场目录不存在: ' . $root]; }
        $id = strtolower(trim((string)$req['app_id']));
        $ver = trim((string)$req['ver']);
        $pkg = preg_replace('/[^a-z0-9._-]/', '', $id . '-' . $ver) ?: ($id . '-x.zip');
        if (!str_ends_with($pkg, '.zip')) { $pkg .= '.zip'; }
        if (!is_file($pendingFile)) { return ['ok' => false, 'err' => '缺少已下载的包文件（请先“验证”）']; }
        if (!@copy($pendingFile, $root . '/' . $pkg)) { return ['ok' => false, 'err' => '复制包到市场失败']; }
        $sha = hash_file('sha256', $root . '/' . $pkg) ?: '';
        $mf = $root . '/manifest.json';
        $items = [];
        if (is_file($mf)) {
            $cur = json_decode((string)file_get_contents($mf), true);
            if (is_array($cur) && isset($cur['items']) && is_array($cur['items'])) { $items = $cur['items']; }
        }
        $items = array_values(array_filter($items, fn($x) => is_array($x) && ($x['id'] ?? null) !== $id));
        $items[] = [
            'id' => $id,
            'type' => (string)($req['type'] === 'theme' ? 'theme' : 'plugin'),
            'name' => (string)$req['name'],
            'ver' => $ver,
            'author' => (string)$req['author'],
            'desc' => mb_substr((string)$req['description'], 0, 180),
            'package' => $pkg,
            'sha256' => $sha,
        ];
        $json = json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($mf, $json) === false) { return ['ok' => false, 'err' => '写 manifest 失败']; }
        return ['ok' => true, 'err' => '', 'package' => $pkg, 'sha' => $sha];
    }
}
