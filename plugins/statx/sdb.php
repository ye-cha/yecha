<?php
declare(strict_types=1);
/**
 * StatX · 插件自带的 SQLite 存储层（批次 1 新增）
 *
 * ★ 为什么要自带一个连接层，而不用内核的 `Db`：
 *   ① 与 YeCha 内容库（MySQL）**彻底解耦** —— 统计挂了不影响站点，站点维护也不影响统计；
 *   ② SQLite 是**单文件**，备份/迁移/回滚都是一条 `cp`（能力②「健康检测」仍留在 MySQL，见 README）；
 *   ③ tj 的 11 张表与 40 类查询**本身就是 SQLite**（见 tj-feature-inventory-REPORT §F.1/§F.3）
 *      ⇒ 后续批次可以**逐字复刻**，不必做 MySQL 方言改写。
 *
 * ★★ 落位红线（本文件最重要的约束）：
 *   · 库文件**必须在 webroot 之外，或落在内核/nginx 已拒绝访问的 `var/` 之下**
 *     （2026-09-14 起默认 `<站点根>/var/statx/statx.db` —— 任何主机都能装，权限天然对；
 *      统计服务器那台机器上继续沿用旧路径 `/www/gushu-statx/statx.db`，见 statx_sdb_path()）；
 *   · nginx 层同时 deny `.db/.sqlite/.sqlite3` 与 **`-wal`/`-shm`**（后者是 WAL 模式新产生的两个文件，
 *     老名单的 `\.(db|…)$` 正则**盖不住** `statx.db-wal`）；
 *   · 权限 0640 / 属主 = **本机真正的 Web 用户**（不再写死 www:www；
 *     以 root 运行时本层会把库文件与 -wal/-shm 重新 chown 回去，见 statx_sdb_own()）。
 *
 * ★ PRAGMA 采用值（逐条给理由，偏离 tj 的两处已注明）：
 *   journal_mode=WAL          —— 多读单写：cron 批量写的同时后台看板可读，不必互等（tj 同款）
 *   synchronous=NORMAL        —— WAL 下只有「断电」才可能丢最近若干事务（进程崩溃不丢）；
 *                                统计数据的价值远低于每次 fsync 的写放大（tj 同款）
 *   busy_timeout=5000         —— SQLite 只有一个写者，写冲突要等待而不是立刻 SQLITE_BUSY（tj 同款，必须留）
 *   temp_store=MEMORY         —— GROUP BY / ORDER BY 的临时表进内存；本库 ≤ 数十 MB，代价可控（tj 同款）
 *   cache_size=-8000          —— **偏离 tj 的 -16000**：-8000 = 8 MiB/连接。本机是 1 vCPU/846 MB 的
 *                                小机器、同机还跑着别的站；本库规模（稳态 ≤30 MB）下 8 MiB 页缓存已能
 *                                装下最热的那部分，没必要按 16 MiB 预留
 *   wal_autocheckpoint=1000   —— 约 4 MiB WAL 即自动 checkpoint（预算「WAL 稳态 ≤8 MB」，与 tj 实测 3.99 MB 同量级）
 *   mmap_size=0               —— **偏离 tj 的 268435456**：① WAL 模式下内存映射的收益本就有限，
 *                                ② 本层的连接可能来自常驻的 php-fpm worker，按 256 MiB/连接预留
 *                                地址空间在小机器上不合算。宁可不占。
 *   foreign_keys=OFF          —— 本库**没有外键**（显式写出来，避免将来依赖 SQLite 的默认值）
 *   auto_vacuum=INCREMENTAL   —— **在本库第一次建表之前**设置：这样保留清理后可以
 *                                `PRAGMA incremental_vacuum` 增量回收，**不必跑整库重写 + 独占锁的 VACUUM**
 *                                （§F.2 #26 明确警告：VACUUM 绝不能在请求路径/大库上跑）
 *
 * ★ 迁移机制用 `PRAGMA user_version`（不额外建表）；后续批次加表/加列时把版本号 +1 并追加迁移分支。
 *
 * 本文件只定义函数与常量，被 require 时**不产生任何副作用**（不连库、不建表）。
 */

/** 本层负责的 schema 版本（user_version）
 *  v1 = 批次 1：sites / hits / hits_rollup + 2 个索引
 *  v2 = 批次 2：hits 增加 6 列（region · browser · os · ipver · ref_type · kw）——
 *       **不加任何索引**（批次 1 刻意只建 2 个，这条红线延续到本批）
 */
const STATX_SDB_SCHEMA = 2;

/** 批次 2 给 `hits` 增加的列（列名 => [类型, 默认值]）。全部有默认值 ⇒ SQLite 的 ADD COLUMN 是常数时间、不重写表 */
function statx_sdb_new_columns(): array
{
    return [
        'region'   => "TEXT NOT NULL DEFAULT ''",
        'browser'  => "TEXT NOT NULL DEFAULT ''",
        'os'       => "TEXT NOT NULL DEFAULT ''",
        'ipver'    => 'INTEGER NOT NULL DEFAULT 0',
        'ref_type' => "TEXT NOT NULL DEFAULT ''",
        'kw'       => "TEXT NOT NULL DEFAULT ''",
    ];
}

/** 插件目录 = <站点根>/plugins/statx ⇒ 站点根是它的上两级 */
function statx_sdb_site_root(): string { return dirname(__DIR__, 2); }

/**
 * ★ 默认落位（**可移植**，2026-09-14 起）：
 *   `<站点根>/var/statx/statx.db` —— 站点根下的 `var/` 本来就由内核/nginx 拒绝直接访问
 *   （`var/cache`、`var/publish` 等同类），所以它既在 webroot 之内（**任何主机都装得下、权限天然对**），
 *   又不构成「库可被下载」的风险。
 *
 * 为什么不再写死 `/www/gushu-statx/statx.db`：
 *   那是统计服务器自己的布局 —— 换一台机器 `/www` 可能不存在、或存在但 PHP 用户不可写，
 *   于是「下载即用」直接失败。旧路径**只作为兼容回退**保留（见 statx_sdb_path()）。
 */
function statx_sdb_default_path(): string
{
    return rtrim(statx_sdb_site_root(), '/') . '/var/statx/statx.db';
}

/** 旧版（统计服务器）布局：只在**显式**钉住时才会用到（见 statx_sdb_path() 的说明） */
function statx_sdb_legacy_path(): string { return '/www/gushu-statx/statx.db'; }

/**
 * ★★ 公共/系统目录拒改名单（2026-09-14 生产事故的**根治点**）。
 *
 * 事故经过（`/root/statx-final-hardening-REPORT.md` 有完整时间线）：
 *   本插件落库路径的第 ⑤ 档兜底曾是 `<sys_get_temp_dir()>/statx/statx.db`。一旦命中，
 *   `statx_sdb_own()` 会**无条件**对 `dirname()` 结果（也就是 `/tmp` 本身）做
 *   `chmod 0750` + `chown www:www` —— 于是**整台机器的 /tmp 被改成 `0750 www:www`**。
 *   同机 MySQL 的 `tmpdir` 当时正是 `/tmp` ⇒ mysqld 无法在其中建临时文件 ⇒
 *   启动即 signal 11 崩溃、4 个站 500，故障持续 1,077 秒。
 *
 * 结论（本文件从此遵守的红线）：
 *   **插件只对自己「刚刚新建」的目录改权限；任何「本来就存在」的目录一律不碰。**
 *   公共目录（/tmp · /var/tmp · /dev/shm · /run/… · 祖先含 /tmp 的路径 · 无粘滞位的世界可写目录）
 *   更是**绝对不碰** —— 即使有人在 sdb_path.php / collect.db_path / STATX_SQLITE 里显式把它指过来。
 *   这是「代码兜底」与「运维手法」两条路都堵上：兜底档已删（见 statx_sdb_path()），
 *   显式指定也被这里挡住，而且**不会**退化成静默改权限。
 *
 * @return string 命中时返回人类可读的原因（用于后台/`--task=env` 显示），未命中返回 ''
 */
function statx_sdb_public_dir_reason(string $dir): string
{
    $dir = rtrim($dir, '/');
    if ($dir === '') { $dir = '/'; }
    // realpath 解析符号链接（本机 /var/lock -> /run/lock 就是这种），解析结果也要判一次
    $rp = @realpath($dir);
    if (is_string($rp) && $rp !== '') { $dir = rtrim($rp, '/'); }
    if ($dir === '') { $dir = '/'; }

    $tmp = rtrim((string)sys_get_temp_dir(), '/');
    $rtmp = @realpath($tmp);
    if (is_string($rtmp) && $rtmp !== '') { $tmp = rtrim($rtmp, '/'); }

    // ① 固定名单：**精确等于**该目录、或位于其下
    foreach (['/tmp', '/var/tmp', '/dev/shm', '/run/lock', '/run/user', '/var/lock'] as $p) {
        if ($dir === $p || strpos($dir, $p . '/') === 0) {
            return '公共临时目录 ' . $p . '（插件绝不改它的权限/属主）';
        }
    }
    // ② PHP 实际生效的 sys_get_temp_dir()（可能被 php.ini 的 upload_tmp_dir/sys_temp_dir 改写）
    if ($tmp !== '' && $tmp !== '/' && ($dir === $tmp || strpos($dir, $tmp . '/') === 0)) {
        return 'PHP 临时目录 ' . $tmp . '（插件绝不改它的权限/属主）';
    }
    // ③ 防御性兜底：**世界可写且无粘滞位**的目录是共享目录（/tmp 是 1777，粘滞位 1）
    if ($dir !== '' && $dir !== '/' && @is_dir($dir)) {
        $m = @fileperms($dir);
        if (is_int($m) && ($m & 0002) && !($m & 01000)) {
            return '世界可写且无粘滞位的共享目录（插件绝不改它的权限/属主）';
        }
    }
    // ④ 路径无绝对根（相对路径）时不可靠判定，同样不动
    if ($dir !== '' && $dir[0] !== '/') { return '非绝对路径，无法确认归属（插件不改它）'; }
    return '';
}

/**
 * 该目录能不能真的写（不存在则看最近的存在祖先是否可写，并尝试创建）
 */
function statx_sdb_dir_usable(string $file): bool
{
    $dir = dirname($file);
    if (is_dir($dir)) { return is_writable($dir); }
    $probe = $dir;
    while ($probe !== '' && $probe !== '/' && !is_dir($probe)) { $probe = dirname($probe); }
    if ($probe === '' || !is_dir($probe) || !is_writable($probe)) { return false; }
    return @mkdir($dir, 0750, true) || is_dir($dir);
}

/** 可选覆盖：plugins/statx/sdb_path.php 返回一个字符串路径（不改代码即可换盘） */
function statx_sdb_override_path(): string
{
    $f = __DIR__ . '/sdb_path.php';
    if (!is_file($f)) { return ''; }
    $v = @include $f;
    return is_string($v) ? trim($v) : '';
}

/** 解析结果 + 来源（后台/CLI 要能告诉用户「库在哪、为什么在那」） */
function statx_sdb_resolved(): array
{
    static $r = null;
    if (is_array($r)) { return $r; }

    // ① CLI 环境变量（只在 CLI 生效 —— Web 侧不能被请求环境改写落库位置）
    if (PHP_SAPI === 'cli') {
        $e = getenv('STATX_SQLITE');
        if (is_string($e) && trim($e) !== '') {
            return $r = ['path' => trim($e), 'source' => 'env:STATX_SQLITE'];
        }
    }
    // ② plugins/statx/sdb_path.php
    $o = statx_sdb_override_path();
    if ($o !== '') { return $r = ['path' => $o, 'source' => 'sdb_path.php']; }

    // ③ 选项 collect.db_path（后台「设置」可填；**导出包不带任何本站值**）
    $cfg = '';
    if (function_exists('statx_opt')) {
        $cfg = trim((string)statx_opt('collect.db_path', ''));
    }
    if ($cfg !== '') { return $r = ['path' => $cfg, 'source' => 'option:collect.db_path']; }

    // ④ 自动落位：站点根/var/statx/statx.db（可写检查通过才用）
    $def = statx_sdb_default_path();
    if (statx_sdb_dir_usable($def)) { return $r = ['path' => $def, 'source' => 'auto:站点根/var/statx']; }

    // ⑤ ★ 已删除（2026-09-14 生产事故根因）：旧版此处兜底到 `<临时目录>/statx/statx.db`。
    //    它让「站点根/var/statx 不可写」的站把库落到 /tmp 之下，而当时 statx_sdb_own() 会
    //    对 dirname()（= /tmp 本身）无条件 chmod 0750 + chown www:www ⇒ 整机 /tmp 被改权限，
    //    同机 MySQL（tmpdir=/tmp）随后 signal 11 崩溃、起不来，4 个站 500（持续 1,077 秒）。
    //    **红线：本插件绝不把库落在公共临时目录，也绝不对公共目录改权限。**
    //    这里改为「强制报错」：返回默认位置但把 source 标成错误，让后台/CLI 明确告诉用户去修权限，
    //    而不是偷偷用一个重启就丢、还会连累整机的位置。
    return $r = [
        'path'   => $def,
        'source' => 'error:站点根/var/statx 不可写（已取消临时目录兜底；请把该目录属主/权限交给 Web 用户）',
    ];
}

/**
 * 库文件路径。解析顺序（**一个站的库永远只属于它自己**）：
 *   ① CLI 环境变量 `STATX_SQLITE`（只在 PHP_SAPI==='cli' 生效）
 *   ② `sdb_path.php`（显式指定；既有部署就是这样把自己钉在老位置上的）
 *   ③ 选项 `collect.db_path`（★ 新增：后台可配，不必改文件）
 *   ④ 自动：`<站点根>/var/statx/statx.db`（★ 默认；任何主机都装得下、权限天然对）
 *
 * ★★ 为什么**没有**「兜底到临时目录」这一条（2026-09-14 生产事故，必须记住）：
 *   旧版第 ⑤ 档是 `<临时目录>/statx/statx.db`。它把库落到 `/tmp` 之下，而当时
 *   `statx_sdb_own()` 会对 `dirname()`（= **`/tmp` 本身**）无条件 `chmod 0750` +
 *   `chown www:www` ⇒ 08:17:14 起 `/tmp` 变成 `0750 www:www`，同机 MySQL（`tmpdir=/tmp`）
 *   随即 signal 11 崩溃且起不来，4 个站 500，直到 08:35:11 恢复（**1,077 秒**）。
 *   ⇒ 现在：**没有**临时目录兜底，站点根不可写就**明确报错**（见本函数 ⑤ 处）。
 *
 * ★★ 为什么**没有**「旧路径存在就用旧路径」这一条（2026-09-14 实测踩到的坑，必须记住）：
 *   最初写了「④ 旧版 `/www/gushu-statx/statx.db` 已存在就继续用它」，本意是让统计服务器
 *   升级后无缝。结果在**同一台机器上装第二个站**时，新站会**默默挂到统计服务器的库上** ——
 *   于是新站能看到别人的全部站点登记与数据，还会往那个库里写自己的站点行。
 *   这是不能接受的：**库位置必须是本站自己的，绝不能因为别人机器的文件存在而漂移**。
 *   需要沿用老位置的部署，写一行 `sdb_path.php` 显式钉住即可（既有部署已按此处理）。
 */
function statx_sdb_path(): string
{
    $r = statx_sdb_resolved();
    return (string)$r['path'];
}

/** 库位置来源说明（后台/CLI 展示用） */
function statx_sdb_path_source(): string
{
    $r = statx_sdb_resolved();
    return (string)$r['source'];
}

/** 本层实际采用的 PRAGMA（顺序即执行顺序；journal_mode 要单独回读） */
function statx_sdb_pragmas(): array
{
    return [
        'journal_mode'        => 'WAL',
        'synchronous'         => 'NORMAL',
        'busy_timeout'        => '5000',
        'temp_store'          => 'MEMORY',
        'cache_size'          => '-8000',
        'wal_autocheckpoint'  => '1000',
        'mmap_size'           => '0',
        'foreign_keys'        => 'OFF',
    ];
}

/** 单例 PDO（sqlite）。首次打开时套 PRAGMA；库文件不存在则创建并立即纠正权限/属主 */
function statx_sdb(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }

    $file = statx_sdb_path();
    $dir  = dirname($file);
    // ★ 记录该目录是不是**我们刚刚新建**的 —— 只有自建目录才允许 chmod/chown（见 statx_sdb_own()）
    $created = false;
    if (!is_dir($dir)) { $created = @mkdir($dir, 0750, true) || is_dir($dir); }

    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
    foreach (statx_sdb_pragmas() as $k => $v) {
        try { $pdo->query('PRAGMA ' . $k . '=' . $v); } catch (Throwable $e) { /* 单个 PRAGMA 失败不致命 */ }
    }
    statx_sdb_own($file, $created);
    return $pdo;
}

/**
 * 权限/属主自愈：库文件 + -wal + -shm 一律 0640，属主 = **当前 PHP 用户**。
 *
 * ★ 可移植（2026-09-14 起）：不再写死 `www:www` —— 那是宝塔的账户名，别的机器上
 *   可能是 www-data / apache / nginx / cpanel 用户，甚至根本没有 `www` 账户；
 *   写死会让 cron（root）把库 chown 给一个不存在的用户，或在后台报一个假故障。
 *   现在：以 root 跑时改用「本轮真正的 Web 使用者」——优先 posix 解析当前 uid；
 *   只有当 `www` 账户**确实存在**且我们无从判断时才回退到它。
 *
 * ★★ 只动「自己的东西」（2026-09-14 生产事故根治，本函数是**凶手现场**）：
 *   旧版这里对 `dirname($file)` 无条件 `chmod($dir,0750)` + `chown($dir,$owner)`。
 *   当落库路径命中「临时目录兜底」时 `dirname()` 就是 **`/tmp` 本身** ⇒ 整机 /tmp 被改成
 *   `0750 www:www` ⇒ 同机 MySQL（`tmpdir=/tmp`）**signal 11 崩溃且起不来**，4 个站 500，
 *   持续 1,077 秒（08:17:14 → 08:35:11）。
 *
 *   现在三条闸门，缺一不可：
 *     ① **不是我们新建的目录，一律不 chmod/chown**（`$created` 由 statx_sdb() 传入；未传时按 false 处理）；
 *     ② **公共/系统目录一律不碰**（`statx_sdb_public_dir_reason()` —— /tmp · /var/tmp · /dev/shm ·
 *        /run/… · 祖先含 /tmp 的路径 · 无粘滞位的世界可写目录），即使有人显式把路径指过去；
 *     ③ 目录不可用时不静默改权限，而是返回 `warn` 交给后台/CLI 显示，由**运维**决定怎么修。
 *
 *   ★ 为什么目录权限「不修」也不会有问题：库文件本身固定 0640，而站点根下的 `var/statx/`
 *     是新部署时由安装流程（或 .106 的 r4 部署脚本）以 Web 用户身份创建的 ——
 *     「目录该属于谁」是**部署**的职责，不是**运行时插件**的职责。插件越权去改，
 *     改到 `/tmp` 就是这次事故。
 *
 * @param string|null $file    库文件绝对路径；null = 自行解析（兼容旧调用）
 * @param bool        $created 该库所在目录是否**刚刚由本插件新建**
 * @return array{owned:string[],skipped:string[],warn:string} 明细（后台/CLI 展示用）
 */
function statx_sdb_own(?string $file = null, bool $created = false): array
{
    if ($file === null || $file === '') { $file = statx_sdb_path(); }
    $dir  = dirname($file);
    $isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
    $owned = [];
    $skipped = [];
    $warn = '';

    // ── 闸门 ②：公共/系统目录，**无条件**不碰（这一条独立于 $created，先判）
    $pub = statx_sdb_public_dir_reason($dir);
    if ($pub !== '') {
        $warn = '未改动公共目录权限：' . $pub . ' ⇒ ' . $dir
              . '（库落在公共目录本身就不该发生；请把库改到 <站点根>/var/statx/）';
        return ['owned' => $owned, 'skipped' => [$dir, $file], 'warn' => $warn];
    }

    // 目标属主：默认不动（保持现状）；root 时尽量落到 Web 用户
    $owner = '';
    if ($isRoot) {
        // ① 显式配置（后台/CLI 可设）：collect.db_owner
        if (function_exists('statx_opt')) { $owner = trim((string)statx_opt('collect.db_owner', '')); }
        // ② 站点根目录的属主 —— 它**一定**是这台机器上正确的 Web 用户（站点就是它跑的）
        if ($owner === '' && function_exists('posix_getpwuid')) {
            $sr = @fileowner(statx_sdb_site_root());
            if ($sr !== false && (int)$sr !== 0) {
                $u = @posix_getpwuid((int)$sr);
                if (is_array($u) && !empty($u['name'])) { $owner = (string)$u['name']; }
            }
        }
        // ③ 老布局回退：www 账户存在就用它（既有统计服务器行为不变）
        if ($owner === '' && function_exists('posix_getpwnam') && @posix_getpwnam('www') !== false) {
            $owner = 'www';
        }
    }

    // ── 闸门 ①：目录只在「本插件刚新建」时才动；本来就存在的一律不碰
    if ($created && is_dir($dir)) {
        @chmod($dir, 0750);
        if ($owner !== '') { @chown($dir, $owner); @chgrp($dir, $owner); }
        $owned[] = $dir;
    } else {
        $skipped[] = $dir;
        if (is_dir($dir) && function_exists('is_writable') && !@is_writable($dir)) {
            $warn = '库目录不可写：' . $dir . '（属主/权限请由**部署**修正，插件不会去改它）';
        }
    }

    foreach ([$file, $file . '-wal', $file . '-shm'] as $f) {
        if (!is_file($f)) { continue; }
        @chmod($f, 0640);
        if ($owner !== '') { @chown($f, $owner); @chgrp($f, $owner); }
        $owned[] = $f;
    }
    return ['owned' => $owned, 'skipped' => $skipped, 'warn' => $warn];
}

/**
 * ★★ 安全自检（2026-09-14 事故后新增）：确认「插件没有、也不会把公共目录改权限」。
 *
 * 在**插件启动**（boot.php）与 `cli.php --task=env` 两处调用 —— 也就是**每次请求/每次运维查看**时
 * 都会跑一遍。它只读不写，做完四件事：
 *   ① 报告当前库路径与来源，并判定它是否落在公共/系统目录（落在那儿本身就是配置错误）；
 *   ② 检查公共目录的现状：凡是本插件可能碰过的（PHP 临时目录 + 固定名单），
 *      其权限与属主是否仍是「共享目录该有的样子」（/tmp 必须是 1777 + 粘滞位）；
 *   ③ 检查库目录本身是否被放宽成「别人也能读」，以及 `-wal`/`-shm` 是否在目录里裸奔；
 *   ④ 回归测试 `statx_sdb_own()` 的三条闸门：拿一个**不存在的**临时路径与**真实的**公共目录
 *      各跑一次判定，确认公共目录一定被判为「不碰」。
 *
 * @return array{ok:bool,path:string,source:string,checks:array<int,array{id:string,ok:bool,detail:string}>,warn:string}
 */
function statx_sdb_security_audit(): array
{
    $checks = [];
    $add = static function (string $id, bool $ok, string $detail) use (&$checks): void {
        $checks[] = ['id' => $id, 'ok' => $ok, 'detail' => $detail];
    };

    $res  = statx_sdb_resolved();
    $file = (string)$res['path'];
    $dir  = dirname($file);
    $warn = '';

    // ① 库位置不得落在公共/系统目录
    $pub = statx_sdb_public_dir_reason($dir);
    $add('db_path_not_public', $pub === '',
        $pub === '' ? '库目录不在公共目录：' . $dir : '★ 库目录落在公共目录：' . $pub);
    if ($pub !== '') { $warn = '★ 库文件落在公共目录，请改到 <站点根>/var/statx/：' . $dir; }

    // ② 公共目录现状 —— /tmp 必须仍是 1777（含粘滞位），属主不该被本插件改走
    foreach (['/tmp', '/var/tmp', '/dev/shm', '/run/lock'] as $p) {
        if (!@is_dir($p)) { continue; }
        clearstatcache(true, $p);
        $m   = @fileperms($p);
        $oct = is_int($m) ? substr(sprintf('%o', $m), -4) : '????';
        $usr = function_exists('posix_getpwuid') ? @posix_getpwuid((int)@fileowner($p)) : false;
        $grp = function_exists('posix_getgrgid') ? @posix_getgrgid((int)@filegroup($p)) : false;
        $own = (is_array($usr) ? $usr['name'] : '?') . ':' . (is_array($grp) ? $grp['name'] : '?');
        // 公共目录必须是「世界可写 + 粘滞位」（1777）；仅当本插件是唯一可疑改动者时才报警
        $okPerm = is_int($m) && ($m & 0002) && ($m & 01000);
        $add('public_dir_' . str_replace('/', '_', $p), $okPerm,
            $p . ' = ' . $oct . ' ' . $own . ($okPerm ? '（正常）' : ' ★ 共享目录应有的权限是 1777（世界可写 + 粘滞位），当前不是'));
        if (!$okPerm) { $warn = ($warn === '' ? '' : $warn . ' | ') . '公共目录 ' . $p . ' 权限异常：' . $oct; }
    }

    // ③ 库目录/库文件是否被放宽
    if (is_dir($dir)) {
        clearstatcache(true, $dir);
        $m   = @fileperms($dir);
        $oct = is_int($m) ? substr(sprintf('%o', $m), -4) : '????';
        $okO = !(is_int($m) && ($m & 0007)); // 其他用户不得有任何权限
        $add('db_dir_not_world', $okO, '库目录 ' . $dir . ' = ' . $oct . ($okO ? '（不对外开放）' : ' ★ 其他用户可访问'));
        if (!$okO) { $warn = ($warn === '' ? '' : $warn . ' | ') . '库目录对外开放：' . $dir . ' ' . $oct; }
    }
    foreach ([$file, $file . '-wal', $file . '-shm'] as $f) {
        if (!@is_file($f)) { continue; }
        clearstatcache(true, $f);
        $m   = @fileperms($f);
        $oct = is_int($m) ? substr(sprintf('%o', $m), -4) : '????';
        $okO = !(is_int($m) && ($m & 0007));
        $add('db_file_not_world_' . basename($f), $okO, $f . ' = ' . $oct . ($okO ? '（不对外开放）' : ' ★ 其他用户可读'));
        if (!$okO) { $warn = ($warn === '' ? '' : $warn . ' | ') . '库文件对外开放：' . $f . ' ' . $oct; }
    }

    // ④ 回归测试：公共目录必须被判为「不碰」；站点根下的普通目录不应被误判
    $tmpProbe = rtrim((string)sys_get_temp_dir(), '/') . '/statx/statx.db';
    $r1 = statx_sdb_public_dir_reason(dirname($tmpProbe));
    $add('guard_blocks_php_tmp', $r1 !== '', $r1 !== ''
        ? '闸门生效：' . dirname($tmpProbe) . ' → ' . $r1
        : '★ 闸门失效：' . dirname($tmpProbe) . ' 未被识别为公共目录');
    if ($r1 === '') { $warn = ($warn === '' ? '' : $warn . ' | ') . '★ 公共目录闸门对 PHP 临时目录失效'; }

    $r2 = statx_sdb_public_dir_reason('/tmp');
    $add('guard_blocks_tmp', $r2 !== '', $r2 !== '' ? '/tmp → ' . $r2 : '★ 闸门失效：/tmp 未被识别为公共目录');
    if ($r2 === '') { $warn = ($warn === '' ? '' : $warn . ' | ') . '★ 公共目录闸门对 /tmp 失效'; }

    $siteProbe = rtrim(statx_sdb_site_root(), '/') . '/var/statx';
    $r3 = statx_sdb_public_dir_reason($siteProbe);
    $add('guard_allows_site_root', $r3 === '', $r3 === ''
        ? '站点内目录可正常使用：' . $siteProbe
        : '★ 误判：站点内目录被判为公共目录 → ' . $r3 . '（' . $siteProbe . '）');
    if ($r3 !== '') { $warn = ($warn === '' ? '' : $warn . ' | ') . '★ 公共目录闸门误判站点内目录：' . $siteProbe; }

    $ok = true;
    foreach ($checks as $c) { if (!$c['ok']) { $ok = false; break; } }
    return ['ok' => $ok, 'path' => $file, 'source' => (string)$res['source'], 'checks' => $checks, 'warn' => $warn];
}

/* ============================== 表结构（本批只建本批要写的表） ============================== */

/**
 * ★ 本批只建 3 张表：
 *   · `hits`        —— 明细（本批新增 sid/uid/完整 ref/utm/坐标/事件名；点击与事件**复用本表**，
 *                      用 kind 区分 pv|cl|ev。理由见 README「与 tj 的差异」：本批体量小，
 *                      一张表 = 一条写入路径 + 一套索引；将来点击量涨起来再按 tj 拆出 clicks/events）
 *   · `hits_rollup` —— 按天汇总（永久保留）
 *   · `sites`       —— 站点登记（site_key → host / token / 注入状态）
 *   ⚠️ 刻意**不建** tj 的 bot_visit / clicks / events / conversions / crawl_alert / admin / sys_settings
 *      —— 前三个在本批分别由 hits.is_bot / hits.kind 承担，其余留到后续批次。
 *
 * ★★ 索引克制（tj 的 hits 有 7 个索引，占该表 38.3% ⇒ 每次 INSERT 维护 7 棵树）：
 *   本批只建 **2 个**，且都对应本批就会执行的查询：
 *     idx_hits_site_d     (site_key, d)   —— rollup / KPI / 趋势 / 热门页 / 备份：全部按「站 + 日期区间」
 *     idx_hits_site_uid   (site_key, uid) —— uid 跨天可关联：新老访客 / 留存 / 访客轨迹（本批验收也要用它）
 *   刻意**不建**：url / ref / title / sid / ip 上的索引（tj 的 idx_hits_site_url、site_rt_ts、site_title、
 *     site_ip_ts、site_sid 五个）—— 前四个对应的查询本批还没有，sid 相关的流向/深度在**下一批**再加
 *     （`CREATE INDEX` 在 SQLite 上是廉价的在线操作，届时不需重建表）。
 */
function statx_sdb_ddl(): array
{
    return [
        // 站点登记：列与 MySQL 时代一一对应（admin/cli 显示注入状态、验证时间都在用），外加 domains
        "CREATE TABLE IF NOT EXISTS sites (
            site_key     TEXT PRIMARY KEY,
            label        TEXT NOT NULL DEFAULT '',
            host         TEXT NOT NULL DEFAULT '',
            domains      TEXT NOT NULL DEFAULT '',
            enabled      INTEGER NOT NULL DEFAULT 0,
            local_root   TEXT NOT NULL DEFAULT '',
            tj_token     TEXT NOT NULL DEFAULT '',
            inject_where TEXT NOT NULL DEFAULT '',
            inject_tag   TEXT NOT NULL DEFAULT '',
            inject_at    TEXT DEFAULT NULL,
            verify_at    TEXT DEFAULT NULL,
            verify_ok    INTEGER NOT NULL DEFAULT 0,
            verify_note  TEXT NOT NULL DEFAULT '',
            script_url   TEXT NOT NULL DEFAULT '',
            endpoint_url TEXT NOT NULL DEFAULT '',
            first_hit_at TEXT DEFAULT NULL,
            last_hit_at  TEXT DEFAULT NULL,
            hits_total   INTEGER NOT NULL DEFAULT 0,
            sort         INTEGER NOT NULL DEFAULT 100,
            note         TEXT NOT NULL DEFAULT '',
            created_at   TEXT NOT NULL DEFAULT ''
        )",

        // 明细：ts 存**北京时间**字符串（与 statx 现有口径一致，也让既有比较语句原样可用），
        // d 为冗余的北京日期列 → 所有按天查询走索引，**不在 SQL 里做时区运算**（§F.2 #12/#13 的坑）
        "CREATE TABLE IF NOT EXISTS hits (
            id           INTEGER PRIMARY KEY,
            ts           TEXT NOT NULL,
            d            TEXT NOT NULL,
            site_key     TEXT NOT NULL DEFAULT '',
            host         TEXT NOT NULL DEFAULT '',
            path         TEXT NOT NULL DEFAULT '/',
            kind         TEXT NOT NULL DEFAULT 'pv',
            is_landing   INTEGER NOT NULL DEFAULT 0,
            ref          TEXT NOT NULL DEFAULT '',
            ref_domain   TEXT NOT NULL DEFAULT '',
            ref_kind     TEXT NOT NULL DEFAULT 'none',
            utm_source   TEXT NOT NULL DEFAULT '',
            utm_medium   TEXT NOT NULL DEFAULT '',
            utm_campaign TEXT NOT NULL DEFAULT '',
            utm_term     TEXT NOT NULL DEFAULT '',
            utm_content  TEXT NOT NULL DEFAULT '',
            sid          TEXT NOT NULL DEFAULT '',
            uid          TEXT NOT NULL DEFAULT '',
            is_new       INTEGER NOT NULL DEFAULT 0,
            visitor_hash TEXT NOT NULL DEFAULT '',
            width        INTEGER NOT NULL DEFAULT 0,
            height       INTEGER NOT NULL DEFAULT 0,
            vw           INTEGER NOT NULL DEFAULT 0,
            vh           INTEGER NOT NULL DEFAULT 0,
            lang         TEXT NOT NULL DEFAULT '',
            x            INTEGER NOT NULL DEFAULT 0,
            y            INTEGER NOT NULL DEFAULT 0,
            en           TEXT NOT NULL DEFAULT '',
            device       TEXT NOT NULL DEFAULT 'unknown',
            is_bot       INTEGER NOT NULL DEFAULT 0,
            bot_cls      TEXT NOT NULL DEFAULT '',
            bot_name     TEXT NOT NULL DEFAULT '',
            region       TEXT NOT NULL DEFAULT '',
            browser      TEXT NOT NULL DEFAULT '',
            os           TEXT NOT NULL DEFAULT '',
            ipver        INTEGER NOT NULL DEFAULT 0,
            ref_type     TEXT NOT NULL DEFAULT '',
            kw           TEXT NOT NULL DEFAULT ''
        )",
        "CREATE INDEX IF NOT EXISTS idx_hits_site_d   ON hits(site_key, d)",
        "CREATE INDEX IF NOT EXISTS idx_hits_site_uid ON hits(site_key, uid)",

        // 按天汇总（PK 自带唯一索引；不再额外建 d 单列索引 —— 站点数是个位数，无需跨站按日索引）
        "CREATE TABLE IF NOT EXISTS hits_rollup (
            site_key  TEXT NOT NULL,
            d         TEXT NOT NULL,
            pv        INTEGER NOT NULL DEFAULT 0,
            uv        INTEGER NOT NULL DEFAULT 0,
            sess      INTEGER NOT NULL DEFAULT 0,
            newv      INTEGER NOT NULL DEFAULT 0,
            landing   INTEGER NOT NULL DEFAULT 0,
            ref_ext   INTEGER NOT NULL DEFAULT 0,
            bot_hits  INTEGER NOT NULL DEFAULT 0,
            ref_top   TEXT NOT NULL DEFAULT '',
            page_top  TEXT NOT NULL DEFAULT '',
            dev_top   TEXT NOT NULL DEFAULT '',
            bot_top   TEXT NOT NULL DEFAULT '',
            built_at  TEXT NOT NULL DEFAULT '',
            PRIMARY KEY (site_key, d)
        )",
    ];
}

/** 建表（幂等）+ 版本迁移。返回可打印的日志行 */
function statx_sdb_init(): array
{
    $pdo = statx_sdb();
    $log = [];

    $existing = [];
    foreach (statx_sdb_all("SELECT name FROM sqlite_master WHERE type='table'") as $r) { $existing[(string)$r['name']] = 1; }
    $fresh = !isset($existing['hits']);

    // ★ auto_vacuum 必须在**第一次建表之前**落到库头里 —— 否则之后只能靠整库 VACUUM 改（红线：不在请求/cron 里跑 VACUUM）。
    //   实测：新建文件后先跑 `journal_mode=WAL` 会把库头写死（auto_vacuum=0），所以这里要**显式确认**：
    //   空库时用一次 VACUUM 把 auto_vacuum=INCREMENTAL 落地（空库 VACUUM 是微秒级、无锁风险），
    //   非空库则只能记录「未启用」，绝不在这里 VACUUM。
    if ($fresh) {
        try {
            $pdo->query('PRAGMA auto_vacuum=INCREMENTAL');
            if ((int)$pdo->query('PRAGMA auto_vacuum')->fetchColumn() !== 2) {
                $pdo->exec('VACUUM');                       // 空库：代价可忽略，且此时还没有任何表
            }
            $log[] = 'auto_vacuum=' . (string)$pdo->query('PRAGMA auto_vacuum')->fetchColumn() . '（2=INCREMENTAL）';
        } catch (Throwable $e) {
            $log[] = 'auto_vacuum 设置失败（不致命）：' . $e->getMessage();
        }
    } else {
        $av = (int)$pdo->query('PRAGMA auto_vacuum')->fetchColumn();
        if ($av !== 2) { $log[] = 'auto_vacuum=' . $av . '（已存在的库：保持现状，不做 VACUUM）'; }
    }

    $ver = (int)$pdo->query('PRAGMA user_version')->fetchColumn();

    if ($fresh) {
        foreach (statx_sdb_ddl() as $sql) { $pdo->exec($sql); }
        $pdo->exec('PRAGMA user_version=' . STATX_SDB_SCHEMA);
        $log[] = 'sqlite schema created v' . STATX_SDB_SCHEMA . ' (' . statx_sdb_path() . ')';
    } else {
        // 已存在：逐条 DEFENSIVE 补齐（CREATE ... IF NOT EXISTS 天然幂等）
        $n = 0;
        foreach (statx_sdb_ddl() as $sql) { $pdo->exec($sql); $n++; }
        if ($ver < STATX_SDB_SCHEMA) { $pdo->exec('PRAGMA user_version=' . STATX_SDB_SCHEMA); }
        $log[] = 'sqlite schema ok v' . max($ver, STATX_SDB_SCHEMA) . ' (checked ' . $n . ' ddl)';
    }

    // user_version 只增不减；下面这行保证「库在但版本是 0」的边界情况也能被修正
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() !== STATX_SDB_SCHEMA) {
        $pdo->exec('PRAGMA user_version=' . STATX_SDB_SCHEMA);
    }

    // ★ 批次 2 迁移：给**已存在**的 hits 补 6 列 + 回填 ref_type/kw（历史行的 ref 里本来就有答案）
    foreach (statx_sdb_upgrade($ver) as $line) { $log[] = $line; }

    // ★ 这里**显式**用 (null,false)：建表流程不掌握「目录是否自建」这个事实，
    //   宁可不改目录，也不冒险把「本来就存在」的目录当成自己的（那就是 /tmp 事故的成因）。
    statx_sdb_own(null, false);

    foreach (array_keys(statx_sdb_expected_objects()) as $o) { $log[] = '  object ok: ' . $o; }
    return $log;
}

/** 本批应有对象（表 + 索引）—— 用于建表后的自检与 report */
function statx_sdb_expected_objects(): array
{
    return [
        'sites'             => 'table',
        'hits'              => 'table',
        'hits_rollup'       => 'table',
        'idx_hits_site_d'   => 'index',
        'idx_hits_site_uid' => 'index',
    ];
}

/* ============================== 批次 2 迁移（加列 + 回填派生字段） ============================== */

/**
 * 幂等迁移：给已存在的 `hits` 补批次 2 的 6 列（已存在则跳过），然后回填 `ref_type` / `kw`。
 * ★ 为什么 ADD COLUMN 是安全的：SQLite 的 `ALTER TABLE … ADD COLUMN` 带**常量默认值**时
 *   只改库头的表定义，**不重写任何数据页** ⇒ 22 万行也是毫秒级（本机实测见报告）。
 * ★ 回填为什么只做 ref_type/kw：这两列**能从已有的 `ref` 推出来**；
 *   而 `region` 推不出来 —— 历史行里根本没有 IP（"不存 IP"的必然代价），只能从本批之后开始有。
 */
function statx_sdb_upgrade(int $fromVer = 0): array
{
    $log = [];
    try {
        $pdo = statx_sdb();
        $have = [];
        foreach (statx_sdb_all('PRAGMA table_info(' . STATX_SDB_HITS . ')') as $c) {
            $have[(string)$c['name']] = 1;
        }
        if (!$have) { return ['hits 表尚不存在：跳迁移']; }

        $added = [];
        foreach (statx_sdb_new_columns() as $name => $decl) {
            if (isset($have[$name])) { continue; }
            $pdo->exec('ALTER TABLE ' . STATX_SDB_HITS . ' ADD COLUMN ' . $name . ' ' . $decl);
            $added[] = $name;
        }
        if ($added) { $log[] = 'hits 迁移：新增列 ' . implode('/', $added); }
        else { $log[] = 'hits 迁移：列已齐（无操作）'; }

        foreach (statx_sdb_backfill_derive() as $l) { $log[] = $l; }
    } catch (Throwable $e) {
        $log[] = '批次 2 迁移未完成（不致命，页面照常渲染）：' . $e->getMessage();
    }
    return $log;
}

/** 把历史行的 `ref` 重新算一遍 ref_type/kw（有界：最多 max 行，避免在大库上长时间持写锁） */
function statx_sdb_backfill_derive(int $max = 20000): array
{
    if (!function_exists('statx_derive_ref')) { return ['派生字段回填：跳过（derive.php 未加载）']; }
    $n = (int)statx_sdb_val("SELECT COUNT(*) FROM " . STATX_SDB_HITS . " WHERE ref_type='' AND ref<>''");
    if ($n === 0) { return ['派生字段回填：无需（历史行都已算过）']; }

    $lim  = min($n, max(1, $max));
    $rows = statx_sdb_all('SELECT id, ref, host FROM ' . STATX_SDB_HITS
        . " WHERE ref_type='' AND ref<>'' LIMIT " . $lim);
    $pdo = statx_sdb();
    $st  = $pdo->prepare('UPDATE ' . STATX_SDB_HITS . ' SET ref_type=?, kw=? WHERE id=?');
    $pdo->beginTransaction();
    foreach ($rows as $r) {
        $d = statx_derive_ref((string)$r['ref'], (string)$r['host']);
        $st->execute([$d['type'], mb_substr($d['kw'], 0, 100), (int)$r['id']]);
    }
    $pdo->commit();
    return ['派生字段回填：' . count($rows) . ' 行（ref_type/kw）'
        . ($n > $lim ? ('，余 ' . ($n - $lim) . ' 行留给下次') : '')
        . '；region 无法回填 —— 历史行没有 IP（不存 IP 的代价）'];
}

/* ============================== 小工具（占位符一律用 ?，不做字符串拼值） ============================== */
function statx_sdb_all(string $sql, array $p = []): array
{
    $st = statx_sdb()->prepare($sql);
    $st->execute($p);
    $r = $st->fetchAll();
    $st->closeCursor();
    return is_array($r) ? $r : [];
}

function statx_sdb_one(string $sql, array $p = []): ?array
{
    $st = statx_sdb()->prepare($sql);
    $st->execute($p);
    $r = $st->fetch();
    $st->closeCursor();
    return is_array($r) ? $r : null;
}

function statx_sdb_val(string $sql, array $p = []): mixed
{
    $st = statx_sdb()->prepare($sql);
    $st->execute($p);
    $v = $st->fetchColumn();
    $st->closeCursor();
    return $v;
}

function statx_sdb_run(string $sql, array $p = []): int
{
    $st = statx_sdb()->prepare($sql);
    $st->execute($p);
    $n = $st->rowCount();
    $st->closeCursor();
    return (int)$n;
}

/**
 * 分批删除（保留清理的核心）。`DELETE … LIMIT n` 在 SQLite 上**不是标准语法**
 * （需要编译期 SQLITE_ENABLE_UPDATE_DELETE_LIMIT），因此这里统一用**等价且可移植**的写法：
 *     DELETE FROM t WHERE rowid IN (SELECT rowid FROM t WHERE <cond> LIMIT n)
 * 语义与 tj 想表达的「一次删 5000 行、循环到删不动为止」完全一致，但**不会长时间持写锁**。
 */
function statx_sdb_delete_batch(string $table, string $where, array $par, int $batch): array
{
    $batch = max(1, $batch);
    $out = ['deleted' => 0, 'batches' => 0, 'native_limit' => false];
    $pdo = statx_sdb();
    for ($i = 0; $i < 1000; $i++) {
        $sql = "DELETE FROM {$table} WHERE rowid IN (SELECT rowid FROM {$table} WHERE {$where} LIMIT {$batch})";
        $n = statx_sdb_run($sql, $par);
        $out['deleted'] += $n;
        $out['batches']++;
        if ($n < $batch) { break; }
    }
    $out['native_limit'] = statx_sdb_native_delete_limit();
    return $out;
}

/** 本机 SQLite 是否支持 `DELETE … LIMIT`（信息项：本层不用它，但报告里要给出实测值） */
function statx_sdb_native_delete_limit(): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    try {
        $pdo = statx_sdb();
        $pdo->exec('CREATE TEMP TABLE IF NOT EXISTS _stx_dl(x INTEGER)');
        $pdo->exec('DELETE FROM _stx_dl WHERE x=1 LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/* ============================== 自检 / 体积 ============================== */

function statx_sdb_file_info(): array
{
    $f = statx_sdb_path();
    @clearstatcache(true, $f);   // ⚠️ 不清理 stat 缓存的话，同一进程里 VACUUM/写入之后读到的是旧大小（实测踩到）
    $out = ['path' => $f, 'source' => statx_sdb_path_source(), 'exists' => is_file($f),
            'bytes' => 0, 'wal' => 0, 'shm' => 0, 'writable' => false,
            'mode' => '', 'owner' => '', 'group' => '', 'perm_ok' => false,
            'outside_webroot' => !str_contains($f, '/www/wwwroot/')];
    if ($out['exists']) {
        @clearstatcache(true, $f);
        $out['bytes'] = (int)@filesize($f);
        $p = @fileperms($f);
        // ⚠️ fileperms() 带文件类型位（0100640）⇒ 必须只取低 9 位，否则「0640」永远不等于「640」
        $out['mode'] = $p !== false ? substr(sprintf('%03o', $p & 0777), -3) : '';
        $u = function_exists('posix_getpwuid') ? @posix_getpwuid((int)@fileowner($f)) : null;
        $g = function_exists('posix_getgrgid') ? @posix_getgrgid((int)@filegroup($f)) : null;
        $out['owner'] = is_array($u) ? (string)$u['name'] : '';
        $out['group'] = is_array($g) ? (string)$g['name'] : '';
        // ★ 可移植的判定：**当前进程能不能写**才是真问题（不再假设主机一定有名为 www 的账户）
        $out['writable'] = is_writable($f);
        $out['perm_ok']   = $out['writable'];
    } else {
        // 还不存在：目录可写就算 OK（首次打开会创建）
        $out['writable'] = statx_sdb_dir_usable($f);
        $out['perm_ok']  = $out['writable'];
    }
    foreach (['-wal' => 'wal', '-shm' => 'shm'] as $suf => $k) {
        if (is_file($f . $suf)) { $out[$k] = (int)@filesize($f . $suf); }
    }
    return $out;
}

/** 表 / 索引 / 行数 / 单行占用（dbstat 可用时给实测值，否则给 PRAGMA page_count 估算） */
function statx_sdb_stats(): array
{
    $out = ['dbstat' => statx_sdb_dbstat_ok(), 'tables' => [], 'indexes' => [], 'page_size' => 0,
            'page_count' => 0, 'freelist' => 0, 'user_version' => 0, 'pragmas' => []];
    $pdo = statx_sdb();
    $out['page_size']    = (int)$pdo->query('PRAGMA page_size')->fetchColumn();
    $out['page_count']   = (int)$pdo->query('PRAGMA page_count')->fetchColumn();
    $out['freelist']     = (int)$pdo->query('PRAGMA freelist_count')->fetchColumn();
    $out['user_version'] = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    foreach (['journal_mode', 'synchronous', 'busy_timeout', 'temp_store', 'cache_size',
              'wal_autocheckpoint', 'mmap_size', 'foreign_keys', 'auto_vacuum'] as $k) {
        try { $out['pragmas'][$k] = (string)$pdo->query('PRAGMA ' . $k)->fetchColumn(); } catch (Throwable $e) { $out['pragmas'][$k] = '?'; }
    }
    foreach (['sites', 'hits', 'hits_rollup'] as $t) {
        $rows = (int)statx_sdb_val('SELECT COUNT(*) FROM ' . $t);
        $out['tables'][$t] = ['rows' => $rows];
    }
    if ($out['dbstat']) {
        // dbstat 逐页一行 ⇒ GROUP BY name 汇总；表与索引各自算「含自身页」的实际占用
        $rows = [];
        $idx  = [];
        foreach (statx_sdb_all('SELECT name, SUM(pgsize) b FROM dbstat GROUP BY name') as $r) {
            $n = (string)$r['name'];
            $b = (int)$r['b'];
            if (in_array($n, ['sites', 'hits', 'hits_rollup'], true)) { $rows[$n] = $b; }
            elseif (!str_starts_with($n, 'sqlite_')) { $idx[$n] = $b; }
        }
        $out['tables'] = [];
        foreach ($rows as $n => $b) {
            $c = (int)statx_sdb_val('SELECT COUNT(*) FROM ' . $n);
            $out['tables'][$n] = ['rows' => $c, 'bytes' => $b,
                'bytes_per_row' => $c > 0 ? round($b / $c, 1) : 0];
        }
        // 索引 → 归属表（本批索引都建在 hits 上）
        $out['indexes'] = [];
        foreach ($idx as $n => $b) {
            $out['indexes'][$n] = ['bytes' => $b, 'table' => str_contains($n, '_rollup') ? 'hits_rollup' : 'hits'];
        }
    }
    return $out;
}

/** dbstat 虚拟表是否可用（tj 的 38.3% 就是这么量出来的；不可用时本层退回 page_count 估算） */
function statx_sdb_dbstat_ok(): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    try { statx_sdb_val('SELECT COUNT(*) FROM dbstat'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** 维护：增量回收空闲页 + 让 SQLite 自己决定要不要重建统计信息（都只读/廉价，可在 cron 里跑） */
function statx_sdb_maintain(): array
{
    $out = ['incremental_vacuum' => 0, 'freelist_before' => 0, 'freelist_after' => 0, 'optimize' => 'skip'];
    try {
        $pdo = statx_sdb();
        $out['freelist_before'] = (int)$pdo->query('PRAGMA freelist_count')->fetchColumn();
        $pdo->query('PRAGMA incremental_vacuum');
        $out['incremental_vacuum'] = 1;
        $out['freelist_after'] = (int)$pdo->query('PRAGMA freelist_count')->fetchColumn();
        $pdo->query('PRAGMA optimize');
        $out['optimize'] = 'ok';
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
    }
    return $out;
}
