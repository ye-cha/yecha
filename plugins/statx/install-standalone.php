<?php
declare(strict_types=1);
/**
 * StatX · 独立入口安装器（CLI）
 *
 *   php plugins/statx/install-standalone.php              # 安装（默认文件名 stx.php）
 *   php plugins/statx/install-standalone.php --name=st    # 指定站点根下的文件名
 *   php plugins/statx/install-standalone.php --nginx      # 顺带改 nginx（会先 nginx -t，失败自动还原）
 *   php plugins/statx/install-standalone.php --uninstall  # 卸载（删站点根副本 + 还原 nginx 片段）
 *   php plugins/statx/install-standalone.php --status     # 只报告当前状态
 *
 * 为什么要这个脚本：内核 compat() 未修时插件**根本不会被载入**，所以后台没有任何入口
 * 可以点；必须有一条不经内核的通道。本脚本只做两件事：
 *   ① 在站点根放一份 st.php 的副本（可改名）—— 这样任何 nginx 配置都能把端点交给它；
 *   ② 打印/写入两条 nginx 精确匹配，让 /st 与 /st.js 落到那个副本上。
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$opt   = array();
foreach (($argv ?? array()) as $a) {
    if (str_starts_with($a, '--')) {
        $kv = explode('=', substr($a, 2), 2);
        $opt[$kv[0]] = $kv[1] ?? '1';
    }
}
$ROOT     = dirname(__DIR__, 2);
$name     = (string)($opt['name'] ?? 'stx.php');
$name     = (string)preg_replace('~[^A-Za-z0-9._-]~', '', $name);
if ($name === '' || !str_ends_with($name, '.php')) { $name = 'stx.php'; }

$src      = __DIR__ . '/st.php';
$dst      = $ROOT . '/' . $name;
$uninst   = isset($opt['uninstall']);
$status   = isset($opt['status']);
$doNginx  = isset($opt['nginx']);

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function say(bool $ok, string $s): void { out(($ok ? '  ✔ ' : '  ✘ ') . $s); }

out('=========== StatX 独立入口安装器 ===========');
out('站点根 : ' . $ROOT);
out('目标文件: ' . $dst);
out('');

/* ---------------------------------------------- 状态 */
$installed = is_file($dst);
if ($status) {
    say($installed, '站点根副本 ' . ($installed ? '存在' : '不存在'));
    if ($installed) {
        out('        md5[源]  = ' . (is_file($src) ? md5_file($src) : '-'));
        out('        md5[副本]= ' . md5_file($dst));
    }
    out('');
}

/* ---------------------------------------------- 卸载 */
if ($uninst) {
    if ($installed) {
        // 只删「确实是本安装器放上去的副本」——先比对内容，避免误删用户的同名文件
        $same = (is_file($src) && md5_file($src) === md5_file($dst));
        if ($same) { @unlink($dst); say(true, '已删除站点根副本 ' . $dst); }
        else {
            $bak = $dst . '.statx-uninstall-' . date('Ymd-His');
            @rename($dst, $bak);
            say(true, '站点根副本内容与源不同（可能被改过）→ 已改名保留：' . $bak);
        }
    } else { say(true, '站点根副本本来就不存在'); }
    out('');
    out('回滚 nginx：把安装时加的那两行删掉（脚本 --nginx 模式会留 .statx-bak 备份）：');
    out('  for f in /www/server/panel/vhost/nginx/*.conf.statx-bak; do mv "$f" "${f%.statx-bak}"; done');
    out('  nginx -t && systemctl reload nginx');
    exit(0);
}

/* ---------------------------------------------- 安装 */
if (!is_file($src)) { say(false, '源文件不存在：' . $src); exit(1); }
say(true, '源文件 md5 = ' . md5_file($src));

if ($installed && md5_file($dst) === md5_file($src)) {
    say(true, '站点根副本已是最新（无需重装）');
} else {
    if ($installed) {
        $bak = $dst . '.statx-bak-' . date('Ymd-His');
        @copy($dst, $bak);
        say(true, '已备份原副本 → ' . $bak);
    }
    if (!@copy($src, $dst)) { say(false, '复制失败（站点根不可写？）'); exit(1); }
    @chmod($dst, 0644);
    say(true, '已写入 ' . $dst . '  (' . filesize($dst) . ' B)');
}

/* ---------------------------------------------- 收集端点 URL 与 nginx 提示 */
$host    = trim((string)($opt['host'] ?? ''));
if ($host === '') {
    $sq = $ROOT . '/core/data/site.json';
    if (is_file($sq)) {
        $j = json_decode((string)file_get_contents($sq), true);
        if (is_array($j)) { $host = (string)($j['url'] ?? $j['host'] ?? ''); }
    }
}
$host = (string)preg_replace('~^https?://~', '', $host);
$host = rtrim($host, '/');
$hostShow = $host !== '' ? $host : '你的域名';

out('');
out('---- 下一步：让 /st 与 /st.js 落到这个副本 ----');
out('把下面两行加进该站 server{} 块（放在 `location ~ \.php$` 之前、宝塔的 .js 静态规则之前）：');
out('');
out('    location = /st    { try_files $uri /' . $name . '?$query_string; }');
out('    location = /st.js { try_files $uri /' . $name . '?$query_string; }');
out('');
out('然后：nginx -t && systemctl reload nginx');
out('（--nginx 模式会替你完成上面两步，并在 nginx -t 失败时自动还原）');
out('');
out('★ 完全不想动 nginx 也行 —— 用查询串形式，功能完全相同：');
out('    <script async src="https://' . $hostShow . '/' . $name . '?st=js" data-site="' . $hostShow . '"></script>');
out('');
out('---- 验收 ----');
out('    curl -sI "https://' . $hostShow . '/' . $name . '?st=js"   # 期望 200 + Content-Type: application/javascript + X-Statx: js');
out('    curl -s  "https://' . $hostShow . '/' . $name . '?s=站点key&h=' . $hostShow . '&probe=1&st=h" | head -c 40   # 期望 probe-ok');

/* ---------------------------------------------- 可选：改 nginx */
if ($doNginx) {
    out('');
    out('---- --nginx 模式 ----');
    $files = glob('/www/server/panel/vhost/nginx/*.conf');
    $hit   = null;
    foreach (($files ?: array()) as $f) {
        $t = (string)@file_get_contents($f);
        if (str_contains($t, "\n    root " . $ROOT . ';') || str_contains($t, "\n    root " . $ROOT . "\r")) { $hit = $f; break; }
        if (preg_match('~^\s*root\s+' . preg_quote($ROOT, '~') . '\s*;~m', $t)) { $hit = $f; break; }
    }
    if ($hit === null) { say(false, '没找到 root 指向本站的 vhost 配置 → 请按上面的说明手工添加两行'); exit(0); }
    say(true, '匹配到 vhost：' . $hit);

    $body = (string)file_get_contents($hit);
    if (str_contains($body, 'location = /st')) { say(true, '该 vhost 已有 location = /st（跳过，不重复写）'); exit(0); }

    $bak  = $hit . '.statx-bak';
    if (!copy($hit, $bak)) { say(false, '备份失败'); exit(1); }
    say(true, '已备份 → ' . $bak);

    $block = "\n    # StatX 独立入口（2026-09-14 由 install-standalone.php 添加；删掉本节即回滚）\n"
           . "    location = /st    { try_files \$uri /" . $name . "?\$query_string; }\n"
           . "    location = /st.js { try_files \$uri /" . $name . "?\$query_string; }\n";
    $new = preg_replace('~(\n\s*server\s*\{)~', '$1' . $block, $body, 1);
    if ($new === null || $new === $body) { say(false, '插入失败（没找到 server{）→ 请手工添加'); @unlink($bak); exit(1); }
    file_put_contents($hit, $new);

    $t = array(); $rc = 0;
    exec('nginx -t 2>&1', $t, $rc);
    if ($rc !== 0) {
        copy($bak, $hit);
        say(false, 'nginx -t 失败 → 已自动还原 ' . $hit);
        out('    ' . implode("\n    ", $t));
        exit(1);
    }
    say(true, 'nginx -t 通过');
    $r2 = 0; exec('systemctl reload nginx 2>&1', $t2, $r2);
    say($r2 === 0, $r2 === 0 ? 'systemctl reload nginx 完成' : 'reload 失败（请手工 reload）');
    out('');
    out('回滚：cp -a ' . $bak . ' ' . $hit . ' && nginx -t && systemctl reload nginx');
}
exit(0);
