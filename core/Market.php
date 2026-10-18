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

    public const DEFAULT_SOURCE = 'https://yecha.org/market/manifest.json';

    public static function source(): string
    {
        $opt = trim((string)get_option('market.source', ''));
        // 留空 = 官方云市场（yecha.org）；官方不可达时 manifest() 自动回退本站内置清单
        return $opt !== '' ? $opt : self::DEFAULT_SOURCE;
    }

    /** 拉取清单（array of items），失败抛异常 */
    public static function manifest(): array
    {
        $src = self::source();
        $raw = '';
        $remoteNote = '';
        if (preg_match('#^https://#i', $src)) {
            $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true, 'user_agent' => 'YeCha-Market/1.0']]);
            $raw = (string)@file_get_contents($src, false, $ctx);
            if ($raw === '') {
                // 官方源不可达 → 回退本站内置清单（离线可用），并记录原因供界面提示
                $remoteNote = '官方市场不可达（' . $src . '），已回退本站内置清单';
                $src = '';
            }
        } elseif ($src !== '') {
            throw new RuntimeException('market.source 仅支持 https 或留空（使用默认官方源）');
        }
        if ($src === '') {
            $file = self::dataDir() . '/manifest.json';
            if (!is_file($file)) { return []; }
            $raw = (string)file_get_contents($file);
        }
        $GLOBALS['YC_MARKET_NOTE'] = $remoteNote;
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
    /** 前台应用商店页 HTML：读当前市场源（本地 var/market/manifest.json 或远程 https manifest），
     *  列表 + 类型/搜索筛选；安装动作在后台「云市场」（安全解包+SHA-256）。核心内置，不依赖任何插件。 */
    public static function storeHtml(): string
    {
        $items = [];
        $err = '';
        try { $items = self::manifest(); } catch (Throwable $e) { $err = $e->getMessage(); }
        $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 40);
        $list = [];
        foreach ($items as $i) {
            if (!is_array($i)) { continue; }
            if ($q !== '' && mb_stripos((string)($i['name'] ?? '') . ' ' . (string)($i['desc'] ?? '') . ' ' . (string)($i['author'] ?? '') . ' ' . (string)($i['id'] ?? ''), $q) === false) { continue; }
            $list[] = $i;
        }
        $ic = fn(array $i): string => ((string)($i['icon'] ?? '') !== '' ? (string)$i['icon'] : (($i['type'] ?? '') === 'theme' ? '🎨' : '🧩'));

        $group = function (string $type, string $label, array $src) use ($ic): string {
            $rows = '';
            $n = 0;
            foreach ($src as $i) {
                if (($i['type'] ?? '') !== $type) { continue; }
                $n++;
                $href = '/app/' . rawurlencode((string)$i['id']);
                if (trim((string)($i['url'] ?? '')) !== '') { $href = (string)$i['url']; }
                $stShots = (array)($i['shots'] ?? []);
                $cover = $stShots ? '<div class="st-cover"><img src="' . e((string)$stShots[0]) . '" alt="' . e((string)($i['name'] ?? '')) . '" loading="lazy"></div>' : '';
                $rows .= '<a class="st-card' . ($cover !== '' ? ' st-cv' : '') . '" href="' . e($href) . '">'
                    . $cover
                    . '<div class="st-ic">' . $ic($i) . '</div>'
                    . '<div class="st-body"><div class="st-top"><span class="st-chip st-' . ($type === 'theme' ? 't' : 'p') . '">' . e($type === 'theme' ? '主题' : '插件') . '</span>'
                    . '<span class="st-ver">v' . e((string)($i['ver'] ?? '')) . '</span></div>'
                    . '<b class="st-name">' . e((string)($i['name'] ?? $i['id'])) . '</b>'
                    . '<div class="st-desc">' . e(mb_substr((string)($i['desc'] ?? ''), 0, 120)) . '</div>'
                    . '<div class="st-meta"><span style="color:#9a9a90;float:right">' . e((string)($i['author'] ?? 'YeCha')) . '</span></div>'
                    . '</div></a>';
            }
            if ($n === 0) { return ''; }
            return '<section class="st-sec"><h2>' . e($label) . ' <small>' . $n . ' 个</small></h2>'
                . '<div class="st-grid">' . $rows . '</div></section>';
        };

        $gPlugin = $group('plugin', '插件 Plugins', $list);
        $gTheme = $group('theme', '主题 Themes', $list);
        $body = '<div class="st-hero"><h1>应用商店</h1><p>插件与主题统一从云市场挑选；点卡片查看说明，安装 / 更新 / 启用请在<strong>后台 → 云市场</strong>进行（SHA-256 强校验 + 安全解包）。</p>'
            . ($err !== '' ? '<p style="color:#b3261e;font-size:13px;">市场源不可达：' . e($err) . '</p>' : '') . '</div>'
            . '<form class="st-search" method="get" action="/store"><input name="q" value="' . e($q) . '" placeholder="搜索插件或主题…"><button class="btn" type="submit">搜索</button></form>'
            . ($q !== '' ? '<p style="color:#6f767e;font-size:13px;">搜索“' . e($q) . '”，共 ' . count($list) . ' 个匹配。</p>' : '')
            . ($gPlugin !== '' ? $gPlugin : '')
            . ($gTheme !== '' ? $gTheme : '')
            . ($gPlugin === '' && $gTheme === '' ? '<div class="empty">' . ($err !== '' ? '市场源暂不可用：' . e($err) : '没有匹配的应用。') . '</div>' : '');

        return '<style>'
            . '.st-hero h1{font-size:26px;margin:0 0 6px;font-weight:800}.st-hero p{color:#6f767e;max-width:780px;line-height:1.8;margin:0 0 2px;font-size:14px}'
            . '.st-sec{margin:26px 0 4px}.st-sec h2{font-size:19px;margin:0 0 4px;font-weight:800;display:flex;align-items:baseline;gap:8px;padding-bottom:8px;border-bottom:1px solid var(--line,#e6e6e2)}.st-sec h2 small{color:#9a9a90;font-weight:400;font-size:12.5px}'
            . '.st-search{margin:14px 0 4px;display:flex;gap:8px;max-width:440px}.st-search input{flex:1;border:1px solid var(--line,#e6e6e2);border-radius:999px;padding:7px 14px;font:inherit;background:var(--card);color:inherit}.st-search .btn{border-radius:999px}'
            . '.st-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px;margin-top:14px}'
            . '.st-card{display:flex;gap:12px;border:1px solid var(--line,#e6e6e2);background:var(--card);border-radius:16px;padding:16px;text-decoration:none;color:inherit;transition:box-shadow .15s,transform .15s}.st-card:hover{box-shadow:0 8px 22px rgba(0,0,0,.08);transform:translateY(-2px)}'
            . '.st-ic{width:50px;height:50px;border-radius:12px;flex:none;display:flex;align-items:center;justify-content:center;font-size:26px;background:color-mix(in srgb,var(--acc,#2F7D46) 12%,transparent)}'
            . '.st-body{min-width:0}.st-top{display:flex;align-items:center;gap:6px}.st-chip{font-size:11px;border-radius:999px;padding:1px 9px}.st-chip.p{background:#E7EFEA;color:#2e6b34}.st-chip.t{background:#F3E2D2;color:#8F3520}.st-ver{margin-left:auto;color:#9a9a90;font-size:11px}'
            . '.st-name{display:block;font-size:15.5px;margin:6px 0 4px;color:var(--ink)}.st-desc{color:#6f767e;font-size:12.5px;line-height:1.7;min-height:2.4em}.st-st{font-size:11.5px;color:#9a9a90}.st-st.ok{color:#2e6b34}.st-meta{margin-top:6px}'
            . '.st-cover{border-radius:14px 14px 0 0;overflow:hidden;aspect-ratio:16/9;background:#eef1ee}.st-cover img{width:100%;height:100%;object-fit:cover;display:block}.st-card.st-cv{flex-direction:column}.st-card.st-cv .st-cover{border-radius:12px;margin:0 0 12px}'
            . '</style>'
            . '<div class="st-wrap">' . $body . '</div>';
    }
}
