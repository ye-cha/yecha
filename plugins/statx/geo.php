<?php
declare(strict_types=1);
/**
 * StatX · 地域解析（ip2region xdb 读取器，纯 PHP、零依赖、绝不联网）
 *
 * ★ 为什么这个文件必须存在（批次 1 留下的唯一硬缺口）：
 *   批次 1 定的隐私模型是**不存 IP** —— 数据库里只有 `visitor_hash`（逐日轮换盐的 sha256 前 16 位）。
 *   而「地域」只能由 IP 得出 ⇒ **地域必须在「唯一还看得到 IP 的那一刻」算出来**，
 *   也就是采集端点（`endpoint.php`）里：算出**地域字符串**后随 spool 落库，
 *   **IP 本身依旧一个字节都不落盘**（这是本文件与 tj 的 `hits.ip` + Python 侧算地域的**关键差异**：
 *   tj 把明文 IP 存库、事后才解析地域；本插件让 IP 只活在这一次请求的内存里）。
 *
 * ★ 库从哪来：**不联网下载**（红线）。本机已有的那一份官方 `ip2region_v6.xdb`：
 *   `/opt/tj/data/ip2region_v6.xdb`（37,277,813 B；sha256 `939f6b46…0b7506ed`；
 *   git blob SHA-1 `15002d53…` 与 GitHub 官方 API 自报 `sha` 一致；库数据日期 2026-08-14）。
 *   与 tj 在 41 上装的是**同一份**官方文件。
 *
 * ★ xdb 格式（照 ip2region v3.17.0 官方 binding 的 searcher/util 逐条实现，不是猜的）：
 *   头部区 256 B：version(u16@0) · indexPolicy(u16@2) · createdAt(u32@4)
 *                · startIndexPtr(u32@8) · endIndexPtr(u32@12) · ipVersion(u16@16) · runtimePtrBytes(u16@18)
 *   向量索引区：紧接头部，256 行 × 256 列 × 8 B（每格 4B 起始段指针 + 4B 结束段指针）
 *   段索引区：[startIndexPtr, endIndexPtr]，每条 = 起 IP(byteNum) + 止 IP(byteNum) + 数据长度(u16) + 数据指针(u32)
 *             ⇒ IPv4 每条 4+4+2+4 = **14 B**；IPv6 每条 16+16+2+4 = **38 B**
 *   数据区：d_ptr 处 d_len 字节的 UTF-8 文本（v3 的字段是 `国家|省份|城市|ISP`，注意与老版四段式不同）
 *   ★ IPv4 的段内 IP 是**小端存放**（历史遗留），比较时要逐字节倒着比；IPv6 是正常大端，直接 memcmp。
 *
 * ★ 取值方式选「file only」而不是「把 512 KB 向量索引读进内存」：
 *   热图/看板是后台请求，端点则是**每个访客一次**，所以单次查询的 IO 数比吞吐更重要。
 *   实测本文件结构：向量索引 524,288 B、段索引 ≈27.9 MB、共 ≈73.5 万段。
 *   file-only 模式每次查询 = 1 次 8 B（向量索引）+ ⌈log2(段数)⌉≈20 次 38 B + 1 次数据读
 *   ⇒ **约 22 次极短 pread**，量级 20–60 µs，且与库多大无关（对比：整块读 512 KB 每次都要 0.3–0.5 ms）。
 *   ★ 顺带一条硬事实：**没有把 37 MB 整个读进内存** —— 那样每个 php-fpm worker 都会常驻 37 MB。
 *
 * ★ 失效即降级（绝不拖累采集）：文件不存在 / 头部版本不认识 / 任何异常 ⇒ 返回 ''（= 地域未知），
 *   **不抛错、不重试、不写日志**。这样即使库被删掉，采集链路也只是「地域一列空着」，其它一切照常。
 *
 * 本文件只定义函数与常量，被 require 时**不产生任何副作用**（不开文件、不查库）。
 */

/** 官方的 xdb 固定常量（照 binding 抄，勿改） */
const STATX_GEO_HEADER_LEN  = 256;
const STATX_GEO_VEC_COLS    = 256;
const STATX_GEO_VEC_SIZE    = 8;
const STATX_GEO_V4_ID       = 4;
const STATX_GEO_V6_ID       = 6;

/**
 * 库文件落位。**按顺序取第一个存在的**（先 IPv4 专用库、再 IPv6 库）。
 * 允许用「同一个 xdb 同时吃 v4/v6」：若只有 v6 库，IPv4 地址会被映射成
 * IPv4-mapped IPv6（`::ffff:a.b.c.d`）再去查 —— 实测该 v6 库存了这段映射区间（见报告）。
 *
 * ★ 2026-09-14 可移植化：地域库是**可选**的，ZIP **不带**（v4 11 MB / v6 37 MB 太大）。
 *   查找顺序（第一个存在的即用）：
 *     ① CLI 环境变量 STATX_GEO_DB；
 *     ② 选项 `geo.db`（后台/CLI 可填；也可填目录，会自动补文件名）；
 *     ③ `<站点根>/var/statx/ip2region{, _v4,_v6}.xdb` ★ 推荐放这里（站内 var/ 已被拒绝访问）；
 *     ④ `<插件目录>/data/ip2region*.xdb`（想把库跟插件放一起的用户）；
 *     ⑤ `/usr/share/ip2region/*.xdb`（系统包安装的位置）；
 *     ⑥ `/opt/tj/data/*.xdb`（统计服务器的老位置，仅作兼容）。
 *   都找不到就**优雅降级**：地域列留空，采集链路一切照常（见文件头「失效即降级」）。
 */
function statx_geo_db_paths(): array
{
    $env = PHP_SAPI === 'cli' ? trim((string)getenv('STATX_GEO_DB')) : '';
    if ($env !== '') { return [$env]; }

    $out = [];
    $cfg = '';
    if (function_exists('statx_opt')) { $cfg = trim((string)statx_opt('geo.db', '')); }
    if ($cfg !== '') {
        if (is_dir($cfg)) {
            foreach (['ip2region.xdb', 'ip2region_v6.xdb', 'ip2region_v4.xdb'] as $n) { $out[] = rtrim($cfg, '/') . '/' . $n; }
        } else {
            $out[] = $cfg;
        }
    }
    $root = dirname(__DIR__, 2);            // 站点根
    foreach ([
        $root . '/var/statx/ip2region.xdb',    $root . '/var/statx/ip2region_v6.xdb',    $root . '/var/statx/ip2region_v4.xdb',
        __DIR__ . '/data/ip2region.xdb',       __DIR__ . '/data/ip2region_v6.xdb',       __DIR__ . '/data/ip2region_v4.xdb',
        '/usr/share/ip2region/ip2region.xdb',  '/usr/share/ip2region/ip2region_v6.xdb',  '/usr/share/ip2region/ip2region_v4.xdb',
        '/opt/tj/data/ip2region.xdb',          '/opt/tj/data/ip2region_v4.xdb',          '/opt/tj/data/ip2region_v6.xdb',
    ] as $p) { $out[] = $p; }
    return array_values(array_unique($out));
}

/** 库文件信息（自检 / 报告用；不参与采集路径） */
function statx_geo_db_info(): array
{
    $out = ['paths' => statx_geo_db_paths(), 'found' => '', 'bytes' => 0, 'version' => 0,
            'ip_version' => 0, 'segment' => 0, 'readable' => false];
    foreach (statx_geo_db_paths() as $p) {
        if (!is_file($p)) { continue; }
        $out['found']  = $p;
        $out['bytes']  = (int)@filesize($p);
        $out['readable'] = is_readable($p);
        $h = @statx_geo_header($p);
        if ($h !== null) {
            $out['version']    = $h['version'];
            $out['ip_version'] = $h['ip_version'];
            $out['segment']    = $h['index_size'];
        }
        break;
    }
    return $out;
}

/** 读并解析头部（256 B）；失败返回 null。**静态缓存**：同一进程只读一次文件头 */
function statx_geo_header(string $file): ?array
{
    static $cache = [];
    if (array_key_exists($file, $cache)) { return $cache[$file]; }
    $cache[$file] = null;
    $fp = @fopen($file, 'rb');
    if ($fp === false) { return null; }
    $b = @fread($fp, STATX_GEO_HEADER_LEN);
    @fclose($fp);
    if (!is_string($b) || strlen($b) < 20) { return null; }

    $u16 = static fn(int $o): int => (ord($b[$o])) | (ord($b[$o + 1]) << 8);
    $u32 = static fn(int $o): int => (ord($b[$o])) | (ord($b[$o + 1]) << 8)
        | (ord($b[$o + 2]) << 16) | (ord($b[$o + 3]) << 24);

    $version = $u16(0);
    if ($version < 2 || $version > 3) { return null; }              // 不认识的结构 ⇒ 直接放弃
    // 结构 2.0（老库）只有 IPv4；结构 3.0 起由头部显式声明 ipVersion
    $ipv = $version >= 3 ? $u16(16) : STATX_GEO_V4_ID;
    if ($ipv !== STATX_GEO_V4_ID && $ipv !== STATX_GEO_V6_ID) { return null; }

    $cache[$file] = [
        'version'    => $version,
        'ip_version' => $ipv,
        'byte_num'   => $ipv === STATX_GEO_V6_ID ? 16 : 4,
        'index_size' => $ipv === STATX_GEO_V6_ID ? 38 : 14,
        'start'      => $u32(8),
        'end'        => $u32(12),
    ];
    return $cache[$file];
}

/** IP 字符串 → 二进制（v4 4 B / v6 16 B）；非法返回 null。**不依赖 ext-sockets**（inet_pton 属标准库） */
function statx_geo_ipbin(string $ip): ?string
{
    $ip = trim($ip);
    if ($ip === '') { return null; }
    $b = @inet_pton($ip);
    return is_string($b) ? $b : null;
}

/** IPv4 二进制 → IPv4-mapped IPv6 二进制（`::ffff:a.b.c.d`，16 B） */
function statx_geo_v4_to_v6(string $b4): string
{
    return str_repeat("\x00", 10) . "\xff\xff" . $b4;
}

/**
 * 查一次地域。返回 `国家|省份|城市|ISP` 形态的字符串（未命中/出错一律返回 ''）。
 * @param string $ip 点分 IPv4 或 IPv6 文本（端点上直接喂 $_SERVER['REMOTE_ADDR']）
 */
function statx_geo_lookup(string $ip): string
{
    try {
        return statx_geo_lookup_ex($ip);
    } catch (Throwable $e) {
        return '';                                  // 红线：地域失败绝不影响采集
    }
}

/** 内部实现：分派到某个库 */
function statx_geo_lookup_ex(string $ip): string
{
    $bin = statx_geo_ipbin($ip);
    if ($bin === null) { return ''; }

    foreach (statx_geo_db_paths() as $file) {
        if (!is_file($file)) { continue; }
        $h = statx_geo_header($file);
        if ($h === null) { continue; }
        if (strlen($bin) === $h['byte_num']) {
            $r = statx_geo_search($file, $h, $bin);
            if ($r !== '') { return $r; }
            continue;                               // 该库没这段数据 ⇒ 试试下一个库
        }
        // 拿 4 字节地址去查 16 字节的库（或反之）：按 IPv4-mapped 规则转换后再试
        if ($h['byte_num'] === 16 && strlen($bin) === 4) {
            $r = statx_geo_search($file, $h, statx_geo_v4_to_v6($bin));
            if ($r !== '') { return $r; }
        }
    }
    return '';
}

/** 在给定库中做「向量索引定位 → 段索引二分 → 读数据」 */
function statx_geo_search(string $file, array $h, string $bin): string
{
    $fp = @fopen($file, 'rb');
    if ($fp === false) { return ''; }
    try {
        // ① 向量索引：用 IP 的前两个字节定位（256×256 表）
        $idx = (ord($bin[0]) * STATX_GEO_VEC_COLS + ord($bin[1])) * STATX_GEO_VEC_SIZE;
        $vec = statx_geo_read($fp, STATX_GEO_HEADER_LEN + $idx, STATX_GEO_VEC_SIZE);
        if ($vec === null) { return ''; }
        $sPtr = statx_geo_u32($vec, 0);
        $ePtr = statx_geo_u32($vec, 4);
        if ($sPtr === 0 || $ePtr === 0) { return ''; }   // 该段无数据（官方实现同样在此直接返回空）

        // ② 段索引二分（比较规则随 IP 版本不同，见文件头说明）
        $isV6  = ($h['byte_num'] === 16);
        $bytes = $h['byte_num'];
        $size  = $h['index_size'];
        $l = 0;
        $r = intdiv($ePtr - $sPtr, $size);
        $dLen = 0; $dPtr = 0;
        while ($l <= $r) {
            $m = ($l + $r) >> 1;
            $seg = statx_geo_read($fp, $sPtr + $m * $size, $size);
            if ($seg === null || strlen($seg) < $size) { break; }
            // ★ 必须**两侧**都比（官方实现如此）：只看起点，会把「落在段中间」的地址误判成「比本段大」
            //   而向右跳过去 —— 实测这个坑会让 240e:34c:… 这类地址全部查空。
            if (statx_geo_cmp($bin, $seg, $bytes, $isV6, 0) < 0) {
                $r = $m - 1;
            } elseif (statx_geo_cmp($bin, $seg, $bytes, $isV6, $bytes) > 0) {
                $l = $m + 1;
            } else {
                $dLen = ord($seg[$bytes * 2]) | (ord($seg[$bytes * 2 + 1]) << 8);
                $dPtr = statx_geo_u32($seg, $bytes * 2 + 2);
                break;
            }
        }
        if ($dLen === 0 || $dPtr === 0) { return ''; }

        // ③ 读数据段
        $data = statx_geo_read($fp, $dPtr, $dLen);
        if ($data === null) { return ''; }
        $out = @iconv('UTF-8', 'UTF-8//IGNORE', $data);
        return is_string($out) ? trim($out) : '';
    } finally {
        @fclose($fp);
    }
}

/**
 * 索引内 IP 与输入 IP 比较（$off 指定比段内第几个 IP：0=起 IP，byte_num=止 IP）。
 * IPv4：索引里存的是**小端**（历史遗留）⇒ 逐字节倒着比；
 * IPv6：正常大端 ⇒ 直接按二进制串比较。
 */
function statx_geo_cmp(string $ip, string $seg, int $bytes, bool $isV6, int $off): int
{
    if ($isV6) { return strcmp($ip, substr($seg, $off, 16)); }
    for ($i = 0; $i < 4; $i++) {
        $a = ord($ip[$i]);
        $b = ord($seg[$off + 3 - $i]);
        if ($a !== $b) { return $a < $b ? -1 : 1; }
    }
    return 0;
}

/** 小端 u32 */
function statx_geo_u32(string $b, int $o): int
{
    return (ord($b[$o])) | (ord($b[$o + 1]) << 8) | (ord($b[$o + 2]) << 16) | (ord($b[$o + 3]) << 24);
}

/** 定长读取（失败返回 null） */
function statx_geo_read($fp, int $off, int $len): ?string
{
    if (@fseek($fp, $off) !== 0) { return null; }
    $b = @fread($fp, $len);
    return (is_string($b) && strlen($b) === $len) ? $b : null;
}

/* ============================== 地域展示口径 ============================== */

/**
 * 把 `国家|省份|城市|ISP` 压成看板要用的**两级**显示名：
 *   中国 → `省·市`（省=市时只留一个，避免「北京市北京市」）；
 *   非中国 → `国家·城市`。
 * 目的：地域 Top10 表要窄（一屏看得下），而完整串留给 CSV / title 提示。
 */
function statx_geo_short(string $region): string
{
    if ($region === '') { return '(未知)'; }
    if (preg_match('~^未覆盖~u', $region)) { return $region; }
    $p = explode('|', $region);
    $cn = (string)($p[0] ?? '');
    $prov = (string)($p[1] ?? '');
    $city = (string)($p[2] ?? '');
    $norm = static function (string $s): string {
        $s = trim($s);
        return in_array($s, ['0', ''], true) ? '' : $s;
    };
    $prov = $norm($prov);
    $city = $norm($city);
    if ($cn === '中国') {
        if ($prov !== '' && $city !== '' && $prov !== $city) { return $prov . '·' . $city; }
        return $prov !== '' ? $prov : ($city !== '' ? $city : '中国');
    }
    if ($cn !== '' && $cn !== '0') {
        return $city !== '' && $city !== $cn ? ($cn . '·' . $city) : $cn;
    }
    return '(未知)';
}
