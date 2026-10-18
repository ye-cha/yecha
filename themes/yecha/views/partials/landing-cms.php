<?php
/**
 * t40 · yecha.org 首页「大 CMS 化」——中英共用同一套结构与取数
 *
 * ● 取数原则：全部**现场读**，不写死会过期的数字
 *   - 主题：themes/<id>/theme.json（元数据）/ uploads/market/thumbs 与 shots（图）
 *   - 插件：plugins/<id>/plugin.php 清单
 *   - 安装包：download/pkg/index.json（tar.gz 索引，由打包脚本产出）
 *   - 更新：/changelog 内容页正文（markdown，按 `## YYYY-MM-DD · 标题` 解析；英文取 ye_poly_contents 译文）
 *   - 社区：ye_forum_threads / ye_forum_replies / ye_forum_boards / ye_users
 *   - 版本：core/version.php 的 YC_VERSION；发布日：/changelog 首条或 CHANGELOG.md 的「发布日」
 * ● 主题/插件的中英名称与简介复用 plugins/market/showcase.php 的扫描与英文词典（yc_sc_*），
 *   与 /theme、/plugin 页同一份口径，避免两处描述打架。
 * ● 由 landing.php（中文）/ landing-en.php（英文）各给一套文案后 include 本文件。
 *   $lc_en：是否英文；$lp：语言前缀（'' 或 '/en'）；$lc_posts：最新文章（可空，空则不渲染该块）。
 */
if (!function_exists('lc40_root')) {
    function lc40_root(): string
    {
        static $r = null;
        if ($r !== null) { return $r; }
        // 预览/单测可显式指定站点根（线上不设此全局，走下面的向上查找）
        if (!empty($GLOBALS['LC40_ROOT']) && is_dir((string)$GLOBALS['LC40_ROOT'])) {
            return $r = (string)$GLOBALS['LC40_ROOT'];
        }
        // 从本文件所在目录向上找「站点根」（含 config.inc.php 与 core/），不依赖固定层级
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_file($d . '/config.inc.php') && is_dir($d . '/core') && is_dir($d . '/themes')) {
                return $r = $d;
            }
            $up = dirname($d);
            if ($up === $d) { break; }
            $d = $up;
        }
        return $r = dirname(__DIR__, 4);
    }

    /** 市场扫描函数是否可用（可用则复用同一口径；不可用则本文件自带回落扫描） */
    function lc40_market_ok(): bool
    {
        static $ok = null;
        if ($ok !== null) { return $ok; }
        $f = lc40_root() . '/plugins/market/showcase.php';
        if (is_file($f)) { require_once $f; }
        $ok = function_exists('yc_sc_themes') && function_exists('yc_sc_plugins') && function_exists('yc_sc_thumb');
        return $ok;
    }

    /** 主题清单：id => [id,type,name,ver,desc,author] */
    function lc40_themes(): array
    {
        if (lc40_market_ok()) { return yc_sc_themes(); }
        $out = [];
        foreach (glob(lc40_root() . '/themes/*/theme.json') ?: [] as $f) {
            $id = basename(dirname($f));
            $j = json_decode((string)@file_get_contents($f), true);
            $j = is_array($j) ? $j : [];
            $out[$id] = ['id' => $id, 'type' => 'theme', 'name' => (string)($j['name'] ?? $id),
                         'ver' => (string)($j['version'] ?? ''), 'desc' => (string)($j['description'] ?? ''),
                         'author' => (string)($j['author'] ?? 'YeCha')];
        }
        ksort($out);
        return $out;
    }

    /** 插件清单：dir => [id,type,name,ver,desc]（目录即清单，与 /plugin 同口径） */
    function lc40_plugins(): array
    {
        if (lc40_market_ok()) { return yc_sc_plugins(); }
        $out = [];
        $root = lc40_root() . '/plugins';
        foreach ((array)scandir($root) as $d) {
            if ($d === '.' || $d === '..' || !is_dir($root . '/' . $d)) { continue; }
            $pf = $root . '/' . $d . '/plugin.php';
            $m = is_file($pf) ? @include $pf : null;
            $m = is_array($m) ? $m : [];
            $out[$d] = ['id' => (string)($m['id'] ?? $d), 'type' => 'plugin',
                        'name' => (string)($m['name'] ?? $d), 'ver' => (string)($m['ver'] ?? ''),
                        'desc' => (string)($m['desc'] ?? '')];
        }
        ksort($out);
        return $out;
    }

    /** 条目名称/简介：中文取元数据，英文取市场英文词典（缺译回落 id，绝不把中文塞进英文页） */
    function lc40_label(array $it, bool $en): array
    {
        if ($en) {
            if (function_exists('yc_sc_en_map')) {
                $map = yc_sc_en_map();
                if (isset($map[(string)$it['id']])) { return [(string)$map[$it['id']][0], (string)$map[$it['id']][1]]; }
            }
            return [ucfirst((string)$it['id']), ''];
        }
        return [(string)($it['name'] ?? $it['id']), (string)($it['desc'] ?? '')];
    }

    /** 缩略图：优先市场缩略图（/theme、/plugin 同源同口径），缺文件则空串（前端出占位块） */
    function lc40_img(array $it): string
    {
        $id = (string)$it['id'];
        if (lc40_market_ok()) {
            $p = (string)yc_sc_thumb($it);
            if ($p !== '' && is_file(lc40_root() . $p)) { return $p; }
        }
        $base = lc40_root() . '/uploads/market/thumbs/' . $it['type'] . '-' . $id;
        foreach (['png', 'jpg', 'jpeg', 'webp', 'svg'] as $x) {
            if (is_file($base . '.' . $x)) { return '/uploads/market/thumbs/' . $it['type'] . '-' . $id . '.' . $x; }
        }
        return '';
    }

    /** 安装包数量（download/pkg/index.json 的条目数；缺索引则数 tar.gz） */
    function lc40_pkg_n(): int
    {
        $f = lc40_root() . '/download/pkg/index.json';
        if (is_file($f)) {
            $j = json_decode((string)@file_get_contents($f), true);
            if (is_array($j)) { return count($j); }
        }
        return count(glob(lc40_root() . '/download/pkg/*.tar.gz') ?: []);
    }

    /** 界面语言包数量（lang/*.php） */
    function lc40_lang_n(): int
    {
        return count(glob(lc40_root() . '/lang/*.php') ?: []);
    }

    function lc40_cut(string $s, int $n): string
    {
        $s = trim($s);
        return mb_strlen($s, 'UTF-8') > $n ? mb_substr($s, 0, $n, 'UTF-8') . '…' : $s;
    }

    /** markdown/HTML 片段转纯文本（用于摘要） */
    function lc40_plain(string $s): string
    {
        $s = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $s) ?? $s;
        $s = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $s) ?? $s;
        $s = str_replace(['**', '__', '`', '~~'], '', $s);
        $s = preg_replace('/<[^>]+>/u', '', $s) ?? $s;
        return trim($s);
    }

    /** 发布日：CHANGELOG.md 的「发布日：YYYY-MM-DD」→ 该文件 mtime */
    function lc40_file_date(): string
    {
        $f = lc40_root() . '/CHANGELOG.md';
        if (is_file($f)) {
            $s = (string)@file_get_contents($f);
            if (preg_match('/发布日[：:]\s*(\d{4}-\d{2}-\d{2})/u', $s, $m)) { return (string)$m[1]; }
            return date('Y-m-d', (int)filemtime($f));
        }
        return '';
    }

    /** 更新条目：从 /changelog 内容页正文解析 `## YYYY-MM-DD · 标题` + 其下首条要点 */
    function lc40_changelog(int $n, bool $en): array
    {
        $md = '';
        if (class_exists('Db')) {
            try {
                if ($en) {
                    $gid = (int)Db::one('SELECT id FROM ' . Db::t('contents') . " WHERE slug='changelog' AND type='page' LIMIT 1");
                    if ($gid > 0) {
                        $md = (string)Db::one('SELECT content FROM ' . Db::t('poly_contents') . ' WHERE group_id=? AND lang=? LIMIT 1', [$gid, 'en']);
                    }
                }
                if ($md === '') {
                    $md = (string)Db::one('SELECT content FROM ' . Db::t('contents') . " WHERE slug='changelog' AND type='page' LIMIT 1");
                }
            } catch (Throwable $e) {
                $md = '';
            }
        }
        $rows = [];
        if ($md !== '') {
            $cur = null;
            foreach (preg_split('/\r\n|\r|\n/', $md) ?: [] as $ln) {
                if (preg_match('/^##\s+(\d{4}-\d{2}-\d{2})\s*[·・:：\-]?\s*(.+)$/u', (string)$ln, $m)) {
                    if ($cur !== null) { $rows[] = $cur; }
                    $cur = ['date' => (string)$m[1], 'title' => lc40_plain((string)$m[2]), 'sum' => ''];
                    continue;
                }
                if ($cur === null) { continue; }
                if (preg_match('/^##\s+/u', (string)$ln)) { $rows[] = $cur; $cur = null; continue; }
                if ($cur['sum'] === '' && preg_match('/^[-*]\s+(.+)$/u', (string)$ln, $m)) { $cur['sum'] = lc40_plain((string)$m[1]); }
            }
            if ($cur !== null) { $rows[] = $cur; }
        }
        if ($rows === []) { $rows = lc40_changelog_file(); }
        $out = [];
        foreach (array_slice($rows, 0, max(1, $n)) as $r) {
            $r['sum'] = lc40_cut((string)$r['sum'], 110);
            $out[] = $r;
        }
        return $out;
    }

    /** 回落：仓库内 CHANGELOG.md（`## 1. 模块` + 首条要点），发布日期取文件「发布日」 */
    function lc40_changelog_file(): array
    {
        $f = lc40_root() . '/CHANGELOG.md';
        if (!is_file($f)) { return []; }
        $date = lc40_file_date();
        $rows = [];
        $cur = null;
        foreach (preg_split('/\r\n|\r|\n/', (string)@file_get_contents($f)) ?: [] as $ln) {
            if (preg_match('/^##\s+(?:\d+\.\s*)?(.+)$/u', (string)$ln, $m)) {
                if ($cur !== null) { $rows[] = $cur; }
                $cur = ['date' => $date, 'title' => lc40_plain((string)$m[1]), 'sum' => ''];
                continue;
            }
            if ($cur === null) { continue; }
            if ($cur['sum'] === '' && preg_match('/^[-*]\s+(.+)$/u', (string)$ln, $m)) { $cur['sum'] = lc40_plain((string)$m[1]); }
        }
        if ($cur !== null) { $rows[] = $cur; }
        $out = [];
        foreach ($rows as $r) { if ($r['sum'] !== '') { $out[] = $r; } }
        return $out;
    }

    /** 论坛：最新 n 个主题 + 站内统计（口径与 /forum 一致：threads/replies=normal，boards=show） */
    function lc40_forum(int $n, bool $en): array
    {
        $d = ['threads' => [], 'threads_n' => 0, 'replies_n' => 0, 'users_n' => 0, 'boards_n' => 0];
        if (!class_exists('Db')) { return $d; }
        try {
            $d['threads_n'] = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('forum_threads') . " WHERE status='normal'");
            $d['replies_n'] = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('forum_replies') . " WHERE status='normal'");
            $d['users_n']   = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('users'));
            $d['boards_n']  = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('forum_boards') . " WHERE status='show'");
            $rows = Db::rows('SELECT t.id, t.title, t.replies, t.last_at, t.created_at, t.pinned, b.name, b.name_en'
                . ' FROM ' . Db::t('forum_threads') . ' t LEFT JOIN ' . Db::t('forum_boards') . ' b ON b.id = t.board_id'
                . " WHERE t.status='normal' ORDER BY t.pinned DESC, t.last_at DESC, t.id DESC LIMIT " . max(1, min(10, $n)));
            foreach ((array)$rows as $r) {
                $bn = ($en && trim((string)($r['name_en'] ?? '')) !== '') ? (string)$r['name_en'] : (string)($r['name'] ?? '');
                $d['threads'][] = [
                    'id' => (int)$r['id'],
                    'title' => (string)$r['title'],
                    'replies' => (int)$r['replies'],
                    'board' => $bn,
                    'pinned' => (int)($r['pinned'] ?? 0) === 1,
                    'ago' => lc40_ago((string)(($r['last_at'] ?? '') ?: ($r['created_at'] ?? '')), $en),
                ];
            }
        } catch (Throwable $e) {
            return $d;
        }
        return $d;
    }

    function lc40_ago(string $dt, bool $en): string
    {
        $t = strtotime($dt);
        if ($t === false || $t <= 0) { return ''; }
        $s = time() - $t;
        if ($s < 60) { return $en ? 'just now' : '刚刚'; }
        if ($s < 3600) { $n = (string)max(1, (int)floor($s / 60)); return $en ? $n . ' min ago' : $n . ' 分钟前'; }
        if ($s < 86400) { $n = (string)max(1, (int)floor($s / 3600)); return $en ? $n . ' h ago' : $n . ' 小时前'; }
        if ($s < 2592000) { $n = (string)max(1, (int)floor($s / 86400)); return $en ? $n . ' d ago' : $n . ' 天前'; }
        return date('Y-m-d', $t);
    }

    /** 全部首页数据（一次取齐，按语言+语言前缀记忆） */
    function lc40_data(bool $en, string $lp = ''): array
    {
        static $memo = [];
        $k = ($en ? 'en' : 'zh') . '|' . $lp;
        if (isset($memo[$k])) { return $memo[$k]; }

        $themes = lc40_themes();
        $plugins = lc40_plugins();

        // 精选顺序（存在才取，缺了自动用其余条目补足，永远不空）
        $pickT = ['octern', 'teadoc', 'forest', 'chawiki', 'mobai', 'zhijian', 'wenshu', 'astra'];
        $pickP = ['forum', 'market', 'poly', 'home-static', 'entry-static', 'links', 'statx', 'subscribe'];

        $mk = function (array $all, array $order, string $type, string $lp2, bool $en2, int $n): array {
            $out = [];
            $used = [];
            foreach ($order as $id) {
                if (!isset($all[$id]) || isset($used[$id])) { continue; }
                $used[$id] = true;
                $out[] = lc40_card($all[$id], $type, $lp2, $en2);
                if (count($out) >= $n) { break; }
            }
            if (count($out) < $n) {
                foreach ($all as $id => $it) {
                    if (isset($used[$id])) { continue; }
                    $used[$id] = true;
                    $out[] = lc40_card($it, $type, $lp2, $en2);
                    if (count($out) >= $n) { break; }
                }
            }
            return $out;
        };

        $cl = lc40_changelog(4, $en);
        $fdate = lc40_file_date();
        foreach ($cl as $i => $r) { if ((string)$r['date'] === '') { $cl[$i]['date'] = $fdate; } }
        $release = $cl !== [] ? (string)$cl[0]['date'] : $fdate;

        $d = [
            'ver'      => defined('YC_VERSION') ? (string)YC_VERSION : '1.0.0',
            'release'  => $release,
            'themes_n' => count($themes),
            'plugins_n' => count($plugins),
            'pkg_n'    => lc40_pkg_n(),
            'lang_n'   => lc40_lang_n(),
            'themes'   => $mk($themes, $pickT, 'theme', $lp, $en, 8),
            'plugins'  => $mk($plugins, $pickP, 'plugin', $lp, $en, 8),
            'changelog' => $cl,
            'forum'    => lc40_forum(5, $en),
        ];
        return $memo[$k] = $d;
    }

    /** 单张卡片数据（名称/简介/版本/图/链接） */
    function lc40_card(array $it, string $type, string $lp, bool $en): array
    {
        [$nm, $ds] = lc40_label($it, $en);
        $id = (string)$it['id'];
        return [
            'id'   => $id,
            'name' => $nm,
            'desc' => $ds,
            'ver'  => (string)($it['ver'] ?? ''),
            'img'  => lc40_img($it),
            'href' => $lp . ($type === 'theme' ? '/theme/' : '/plugin/') . rawurlencode($id) . '/',
        ];
    }
}

$lcEn = !empty($lc_en);
$lcLp = (string)($lp ?? '');
$lc = lc40_data($lcEn, $lcLp);
$lcPosts = is_array($lc_posts ?? null) ? array_slice($lc_posts, 0, 4) : [];
$lcT = static function (string $zh, string $en) use ($lcEn): string { return $lcEn ? $en : $zh; };
?>
<style>
.lc{max-width:1180px;margin:0 auto;padding:0 20px;color:#1f2328}
.lc *{box-sizing:border-box}
.lc a{text-decoration:none}
.lc-hero{display:grid;grid-template-columns:minmax(0,1.08fr) minmax(0,.92fr);gap:36px;align-items:center;padding:40px 0 26px}
.lc-hero>*{min-width:0}
.lc-eyebrow{display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:12.5px;color:#6f767e;margin:0 0 12px}
.lc-vb{background:#b3541e;color:#fff;border-radius:999px;padding:3px 11px;font-size:12px;font-weight:800;letter-spacing:.3px}
.lc-dot{opacity:.55}
.lc-hero h1{font-size:36px;line-height:1.28;margin:0 0 12px;letter-spacing:.4px;overflow-wrap:anywhere}
.lc-lead{font-size:15.5px;line-height:1.95;color:#4d5359;margin:0 0 10px}
.lc-lead2{font-size:13.5px;line-height:1.9;color:#6f767e;margin:0 0 14px}
.lc-env{display:inline-flex;flex-wrap:wrap;gap:6px;align-items:center;background:#fbf6f0;border:1px solid #ecdcc9;color:#8a5326;border-radius:9px;padding:6px 12px;font-size:12.5px;margin:0 0 20px}
.lc-cta{display:flex;flex-wrap:wrap;gap:10px;align-items:stretch}
.lc-cta .lc-btn-main{min-width:214px}
.lc-btn-main{display:inline-flex;flex-direction:column;justify-content:center;gap:1px;padding:11px 30px;border-radius:16px;background:linear-gradient(135deg,#c15b20,#9d4317);color:#fff;box-shadow:0 10px 24px rgba(179,84,30,.26);text-align:center;line-height:1.3}
.lc-btn-main b{font-size:18px;font-weight:800;letter-spacing:1px}
.lc-btn-main small{font-size:11.5px;opacity:.86}
.lc-btn-main:hover{filter:brightness(1.05)}
.lc-btn-ghost{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:1px solid #e2cdb8;color:#1f2328;border-radius:12px;padding:10px 16px;font-size:14px;font-weight:600;background:#fff}
.lc-btn-ghost:hover{background:#fbf3e9;border-color:#d8b08f}
.lc-links{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 0}
.lc-hero-media{margin:0}
.lc-hero-media img{display:block;width:100%;height:auto;border:1px solid #e9e2d6;border-radius:14px;box-shadow:0 16px 36px rgba(0,0,0,.09);background:#f4f1ec}
.lc-hero-media figcaption{color:#9a9a90;font-size:12px;text-align:center;margin-top:8px}

.lc-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:6px 0 10px;padding:18px 0;border-top:1px solid #efe8dd;border-bottom:1px solid #efe8dd}
.lc-stat{display:block;padding:2px 14px;border-left:1px solid #f0eae0}
.lc-stat:first-child{border-left:0;padding-left:0}
.lc-stat b{display:block;font-size:26px;line-height:1.15;letter-spacing:.3px}
.lc-stat span{font-size:12.5px;color:#6f767e}
.lc-stat:hover b{color:#b3541e}

.lc-sec{margin:44px 0 0}
.lc-sec-head{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;justify-content:space-between;margin-bottom:16px}
.lc-kicker{font-size:12px;letter-spacing:2px;color:#b3541e;font-weight:800;text-transform:uppercase}
.lc-h2{font-size:25px;font-weight:800;margin:3px 0 4px;line-height:1.3}
.lc-sub{color:#6f767e;font-size:14px;margin:0}
.lc-more{font-size:13.5px;font-weight:700;color:#b3541e;white-space:nowrap}

.lc-grid{display:grid;gap:12px}
.lc-g6{grid-template-columns:repeat(auto-fill,minmax(300px,1fr))}
.lc-g4{grid-template-columns:repeat(auto-fill,minmax(250px,1fr))}
.lc-feat{border:1px solid #e9e2d6;border-radius:14px;padding:16px 18px;background:#fff}
.lc-feat .ic{font-size:20px;line-height:1}
.lc-feat b{display:block;font-size:15.5px;margin:8px 0 5px}
.lc-feat p{margin:0;color:#6f767e;font-size:13px;line-height:1.85}

.lc-card{border:1px solid #e9e2d6;border-radius:14px;background:#fff;overflow:hidden;display:flex;flex-direction:column}
.lc-card:hover{border-color:#d8b08f;box-shadow:0 8px 20px rgba(120,80,40,.08)}
.lc-thumb{display:block;aspect-ratio:16/10;background:#f5f1ea;overflow:hidden;position:relative}
.lc-thumb img{display:block;width:100%;height:100%;object-fit:cover}
.lc-thumb-ph{display:flex;align-items:center;justify-content:center;width:100%;height:100%;background:linear-gradient(135deg,#f7efe6,#efe4d7);color:#a8835f;font-weight:800;font-size:15px;letter-spacing:.5px;padding:10px;text-align:center}
.lc-card-body{padding:12px 14px 14px;display:flex;flex-direction:column;gap:6px;flex:1}
.lc-card-name{display:flex;align-items:center;gap:8px;font-size:15px;font-weight:700;color:#1f2328}
.lc-ver{font-size:11px;font-weight:700;color:#8a5326;background:#fbf3e9;border:1px solid #eeddc8;border-radius:999px;padding:1px 8px;white-space:nowrap}
.lc-card-desc{margin:0;color:#6f767e;font-size:12.5px;line-height:1.8;flex:1;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}

.lc-list{list-style:none;margin:0;padding:0}
.lc-list li{display:flex;gap:12px;align-items:baseline;padding:11px 0;border-bottom:1px dashed #eeece6}
.lc-list li:last-child{border-bottom:0}
.lc-date{font-variant-numeric:tabular-nums;font-size:12.5px;color:#9a9a90;min-width:88px}
.lc-list-a{color:#1f2328;font-weight:700;font-size:14.5px}
.lc-list-a:hover{color:#b3541e}
.lc-list p{margin:4px 0 0;color:#6f767e;font-size:12.5px;line-height:1.8}
.lc-li-main{min-width:0;flex:1}

.lc-docs{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}
.lc-doc{display:flex;gap:12px;border:1px solid #e9e2d6;border-radius:14px;padding:15px 16px;background:#fbfaf7}
.lc-doc:hover{border-color:#d8b08f;background:#fdf6ef}
.lc-doc .ic{font-size:22px;line-height:1}
.lc-doc .txt{min-width:0;flex:1}
.lc-doc b{display:block;font-size:15px;margin:0 0 3px;color:#1f2328}
.lc-doc .txt span{color:#6f767e;font-size:12.5px;line-height:1.75;display:block}
.lc-doc em{margin-left:auto;color:#b3541e;font-style:normal;align-self:center}

.lc-comm{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(0,1fr);gap:16px;align-items:start}
.lc-comm-main{border:1px solid #e9e2d6;border-radius:14px;background:#fff;padding:6px 16px}
.lc-comm-side{border:1px solid #e9e2d6;border-radius:14px;background:#fbfaf7;padding:16px}
.lc-kpi{display:grid;grid-template-columns:1fr 1fr;gap:12px 10px;margin:0 0 14px}
.lc-kpi b{display:block;font-size:22px;line-height:1.2}
.lc-kpi span{font-size:12px;color:#6f767e}
.lc-side-btn{display:block;text-align:center;background:#b3541e;color:#fff;border-radius:11px;padding:10px 14px;font-size:14px;font-weight:700}
.lc-side-btn:hover{filter:brightness(1.05)}
.lc-th{padding:11px 0;border-bottom:1px dashed #eeece6}
.lc-th:last-child{border-bottom:0}
.lc-th-a{display:block;color:#1f2328;font-weight:700;font-size:14px;line-height:1.55;overflow-wrap:anywhere}
.lc-th-a:hover{color:#b3541e}
.lc-th-meta{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:4px;font-size:12px;color:#9a9a90}
.lc-chip{background:#f4efe7;border-radius:999px;padding:1px 8px;color:#8a5326;font-size:11.5px;font-weight:600}
.lc-pin{background:#b3541e;color:#fff;border-radius:999px;padding:1px 8px;font-size:11px;font-weight:700}

.lc-cta2{margin:46px 0 0;text-align:center;padding:28px 22px;background:linear-gradient(180deg,#fdf6ef,#fbf9f5);border:1px solid #eadfce;border-radius:18px}
.lc-cta2 b{display:block;font-size:18.5px;margin-bottom:14px}
.lc-cta2 .lc-cta{justify-content:center;align-items:center}

.lc-footnav{margin:46px 0 34px;padding:22px 0 4px;border-top:1px solid #efe8dd;display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:20px}
.lc-footnav h4{margin:0 0 10px;font-size:12px;letter-spacing:1.6px;text-transform:uppercase;color:#b3541e}
.lc-footnav ul{list-style:none;margin:0;padding:0}
.lc-footnav li{margin:0 0 7px}
.lc-footnav a{color:#565b61;font-size:13px}
.lc-footnav a:hover{color:#b3541e}

@media(max-width:960px){
  .lc-hero{grid-template-columns:1fr;gap:22px;padding-top:24px}
  .lc-hero h1{font-size:30px}
  .lc-hero-media{order:2}
  .lc-stats{grid-template-columns:1fr 1fr;gap:14px 10px}
  .lc-stat:nth-child(3){border-left:0;padding-left:0}
  .lc-comm{grid-template-columns:1fr}
}
@media(max-width:640px){
  .lc{padding:0 14px}
  .lc-hero h1{font-size:26px}
  .lc-lead{font-size:14.5px}
  .lc-stats{grid-template-columns:1fr 1fr;gap:12px 8px;padding:14px 0}
  .lc-stat b{font-size:22px}
  .lc-h2{font-size:21px}
  .lc-sec{margin-top:34px}
  .lc-g6,.lc-g4,.lc-docs{grid-template-columns:1fr}
  .lc-btn-main{width:100%;padding:11px 18px}
  .lc-links{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .lc-links .lc-btn-ghost{width:100%}
  .lc-date{min-width:0;font-size:12px;display:block;margin-bottom:2px}
  .lc-list li{display:block}
  .lc-kpi{grid-template-columns:1fr 1fr}
  .lc-cta2 b{font-size:16.5px;line-height:1.6}
}
</style>
<div class="lc">

  <section class="lc-hero">
    <div class="lc-hero-txt">
      <div class="lc-eyebrow">
        <span class="lc-vb">v<?= e($lc['ver']) ?></span>
        <span><?= $lcT('发布于', 'Released') ?> <?= e($lc['release']) ?></span>
        <span class="lc-dot">·</span>
        <span>MIT</span>
        <span class="lc-dot">·</span>
        <span><?= $lcT('纯 PHP + MySQL', 'Plain PHP + MySQL') ?></span>
      </div>
      <h1><?= $lcT('轻量开源博客系统', 'Lightweight open-source publishing') ?></h1>
      <p class="lc-lead"><?= $lcT(
          'YeCha（夜叉）是纯 PHP + MySQL 的开源博客与内容建站系统：没有框架、没有 Node、没有 Redis，一个 PHP 进程就能跑起整站——Markdown 创作、多语言、主题与插件市场、全页缓存、一键升级全部内建。',
          'YeCha is a self-hosted blog and content platform in plain PHP + MySQL: no framework, no Node, no Redis — a single PHP process serves the whole site. Markdown authoring, multilingual content, a theme &amp; plugin market, full-page cache and one-click upgrades are all built in.'
      ) ?></p>
      <p class="lc-lead2"><?= $lcT(
          '适合个人博客、技术笔记、文档站、产品官网与轻社区。',
          'For personal blogs, technical notes, documentation sites, product homepages and small communities.'
      ) ?></p>
      <div class="lc-env"><?= $lcT('运行环境：PHP 8.x（8.1+）· MySQL 5.7+ · 无框架依赖', 'Requirements: PHP 8.x (8.1+) · MySQL 5.7+ · no framework deps') ?></div>
      <div class="lc-cta">
        <a class="lc-btn-main" href="<?= e($lcLp) ?>/downloads"><b><?= $lcT('下载', 'Download') ?></b><small><?= $lcT('完整包 · 主题 · 插件', 'Full package · themes · plugins') ?></small></a>
      </div>
      <div class="lc-links">
        <a class="lc-btn-ghost" href="<?= e($lcLp) ?>/docs"><?= $lcT('文档', 'Docs') ?></a>
        <a class="lc-btn-ghost" href="<?= e($lcLp) ?>/changelog"><?= $lcT('更新日志', 'Changelog') ?></a>
        <a class="lc-btn-ghost" href="<?= e($lcLp) ?>/market"><?= $lcT('应用市场', 'App market') ?></a>
        <a class="lc-btn-ghost" href="<?= e($lcLp) ?>/forum"><?= $lcT('论坛', 'Forum') ?></a>
      </div>
    </div>
    <figure class="lc-hero-media">
      <img src="/uploads/2026/09/shots/teadoc-home.png" alt="<?= $lcT('YeCha 站点界面', 'A YeCha site') ?>" width="1200" height="750" loading="eager">
      <figcaption><?= $lcT('运行在 YeCha 上的 TeaDoc 主题站点（站内实时截图）', 'A TeaDoc theme site running on YeCha (live screenshot)') ?></figcaption>
    </figure>
  </section>

  <section class="lc-stats" aria-label="<?= $lcT('站点数据', 'Site data') ?>">
    <a class="lc-stat" href="<?= e($lcLp) ?>/theme"><b><?= (int)$lc['themes_n'] ?></b><span><?= $lcT('主题', 'themes') ?></span></a>
    <a class="lc-stat" href="<?= e($lcLp) ?>/plugin"><b><?= (int)$lc['plugins_n'] ?></b><span><?= $lcT('插件', 'plugins') ?></span></a>
    <a class="lc-stat" href="<?= e($lcLp) ?>/downloads"><b><?= (int)$lc['pkg_n'] ?></b><span><?= $lcT('安装包', 'packages') ?></span></a>
    <a class="lc-stat" href="<?= e($lcLp) ?>/changelog"><b>v<?= e($lc['ver']) ?></b><span><?= e($lcT('当前版本 · ', 'current · ') . $lc['release']) ?></span></a>
  </section>

  <section class="lc-sec">
    <div class="lc-sec-head">
      <div>
        <div class="lc-kicker">Features</div>
        <h2 class="lc-h2"><?= $lcT('功能特性', 'What you get') ?></h2>
        <p class="lc-sub"><?= $lcT('少依赖、可读源码，把内容与体验的控制权交回站长。', 'Few dependencies, readable source, and full control for the site owner.') ?></p>
      </div>
    </div>
    <div class="lc-grid lc-g6">
      <div class="lc-feat"><span class="ic">🎨</span><b><?= $lcT('多主题可换', 'Many themes') ?></b><p><?= $lcT('现有 ' . (int)$lc['themes_n'] . ' 套主题，覆盖博客、文档、Wiki、知识库与门户；后台一键切换，切得动也切得回。', (int)$lc['themes_n'] . ' themes covering blogs, docs, wikis and portals; switch in the admin panel and switch back anytime.') ?></p></div>
      <div class="lc-feat"><span class="ic">🧩</span><b><?= $lcT('插件化扩展', 'Plugin architecture') ?></b><p><?= $lcT('现有 ' . (int)$lc['plugins_n'] . ' 个官方插件，按需启用；插件可注册前台页面、后台面板、侧栏块与动作钩子。', (int)$lc['plugins_n'] . ' official plugins, enabled on demand; plugins can register pages, admin screens, sidebar blocks and hooks.') ?></p></div>
      <div class="lc-feat"><span class="ic">⚡</span><b><?= $lcT('静态化加速', 'Static acceleration') ?></b><p><?= $lcT('公开页走全页缓存，可选把首页与入口页烘焙成静态 HTML 交给 nginx 直出；更新即刻失效重建。', 'Full-page cache for public pages, plus optional static HTML baking for the home and entry pages, invalidated on edit.') ?></p></div>
      <div class="lc-feat"><span class="ic">🌐</span><b><?= $lcT('多语言开箱即用', 'Multilingual built in') ?></b><p><?= $lcT('内容按语言组成组，/en/ 等前缀切换并输出 hreflang；界面语言包现有 ' . (int)$lc['lang_n'] . ' 套。', 'Content is grouped by language and served under prefixes such as /en/ with hreflang; ' . (int)$lc['lang_n'] . ' UI language packs ship with the core.') ?></p></div>
      <div class="lc-feat"><span class="ic">🛡️</span><b><?= $lcT('安全默认', 'Secure by default') ?></b><p><?= $lcT('安装包 SHA-256 校验、后台 CSRF 与权限分层、可配的防采集与评论防骚扰；文档给出上线安全清单。', 'SHA-256 verified packages, CSRF protection and role separation in the admin panel, plus configurable anti-scraping and comment spam control.') ?></p></div>
      <div class="lc-feat"><span class="ic">🚀</span><b><?= $lcT('性能可控', 'Predictable performance') ?></b><p><?= $lcT('纯 PHP + MySQL，无框架与 Composer 运行依赖；列表查询走有序索引，小配置机器也能带动。', 'Plain PHP + MySQL with no runtime framework or Composer dependencies; list queries use ordered indexes, so small VPS instances cope well.') ?></p></div>
    </div>
  </section>

  <section class="lc-sec">
    <div class="lc-sec-head">
      <div>
        <div class="lc-kicker">Themes</div>
        <h2 class="lc-h2"><?= $lcT('主题精选', 'Featured themes') ?></h2>
        <p class="lc-sub"><?= $lcT('共 ' . (int)$lc['themes_n'] . ' 套，后台可切换；每套都可在应用市场看详情与截图。', (int)$lc['themes_n'] . ' in total, switchable in the admin panel; every one has a detail page with screenshots.') ?></p>
      </div>
      <a class="lc-more" href="<?= e($lcLp) ?>/theme"><?= $lcT('全部主题', 'All themes') ?> →</a>
    </div>
    <div class="lc-grid lc-g4">
      <?php foreach ($lc['themes'] as $c): ?>
      <a class="lc-card" href="<?= e($c['href']) ?>">
        <span class="lc-thumb"><?php if ($c['img'] !== ''): ?><img src="<?= e($c['img']) ?>" alt="<?= e($c['name']) ?>" loading="lazy" width="640" height="400"><?php else: ?><span class="lc-thumb-ph"><?= e($c['name']) ?></span><?php endif; ?></span>
        <span class="lc-card-body">
          <span class="lc-card-name"><?= e($c['name']) ?><?php if ($c['ver'] !== ''): ?><span class="lc-ver">v<?= e($c['ver']) ?></span><?php endif; ?></span>
          <p class="lc-card-desc"><?= e($c['desc']) ?></p>
        </span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="lc-sec">
    <div class="lc-sec-head">
      <div>
        <div class="lc-kicker">Plugins</div>
        <h2 class="lc-h2"><?= $lcT('插件精选', 'Featured plugins') ?></h2>
        <p class="lc-sub"><?= $lcT('共 ' . (int)$lc['plugins_n'] . ' 个，装了就生效；停用即回滚，不污染内核。', (int)$lc['plugins_n'] . ' in total; install to activate, disable to roll back without touching the core.') ?></p>
      </div>
      <a class="lc-more" href="<?= e($lcLp) ?>/plugin"><?= $lcT('全部插件', 'All plugins') ?> →</a>
    </div>
    <div class="lc-grid lc-g4">
      <?php foreach ($lc['plugins'] as $c): ?>
      <a class="lc-card" href="<?= e($c['href']) ?>">
        <span class="lc-thumb"><?php if ($c['img'] !== ''): ?><img src="<?= e($c['img']) ?>" alt="<?= e($c['name']) ?>" loading="lazy" width="640" height="400"><?php else: ?><span class="lc-thumb-ph"><?= e($c['name']) ?></span><?php endif; ?></span>
        <span class="lc-card-body">
          <span class="lc-card-name"><?= e($c['name']) ?><?php if ($c['ver'] !== ''): ?><span class="lc-ver">v<?= e($c['ver']) ?></span><?php endif; ?></span>
          <p class="lc-card-desc"><?= e($c['desc']) ?></p>
        </span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="lc-sec">
    <div class="lc-sec-head">
      <div>
        <div class="lc-kicker">Changelog</div>
        <h2 class="lc-h2"><?= $lcT('最新更新', 'Latest updates') ?></h2>
        <p class="lc-sub"><?= $lcT('来自更新日志的最新条目，按发布时间倒序。', 'The most recent entries of the changelog, newest first.') ?></p>
      </div>
      <a class="lc-more" href="<?= e($lcLp) ?>/changelog"><?= $lcT('完整更新日志', 'Full changelog') ?> →</a>
    </div>
    <ul class="lc-list">
      <?php foreach ($lc['changelog'] as $r): ?>
      <li>
        <span class="lc-date"><?= e((string)$r['date']) ?></span>
        <span class="lc-li-main">
          <a class="lc-list-a" href="<?= e($lcLp) ?>/changelog"><?= e((string)$r['title']) ?></a>
          <?php if ((string)$r['sum'] !== ''): ?><p><?= e((string)$r['sum']) ?></p><?php endif; ?>
        </span>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <?php if ($lcPosts !== []): ?>
  <section class="lc-sec">
    <div class="lc-sec-head">
      <div>
        <div class="lc-kicker">Posts</div>
        <h2 class="lc-h2"><?= $lcT('最新发布', 'Latest posts') ?></h2>
        <p class="lc-sub"><?= $lcT('站内最近发布的文章与页面。', 'The most recently published posts and pages.') ?></p>
      </div>
      <a class="lc-more" href="<?= e($lcLp) ?>/archives"><?= $lcT('全部归档', 'Archive') ?> →</a>
    </div>
    <ul class="lc-list">
      <?php foreach ($lcPosts as $p): ?>
      <li>
        <span class="lc-date"><?= e(substr((string)($p['date'] ?? ''), 0, 10)) ?></span>
        <span class="lc-li-main"><a class="lc-list-a" href="<?= e((string)$p['href']) ?>"><?= e((string)$p['title']) ?></a></span>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <section class="lc-sec">
    <div class="lc-sec-head">
      <div>
        <div class="lc-kicker">Docs</div>
        <h2 class="lc-h2"><?= $lcT('文档入口', 'Documentation') ?></h2>
        <p class="lc-sub"><?= $lcT('安装、升级、二次开发都在站内文档，随版本一起更新。', 'Install, upgrade and extension guides live in the on-site docs and ship with each release.') ?></p>
      </div>
      <a class="lc-more" href="<?= e($lcLp) ?>/docs"><?= $lcT('全部文档', 'All docs') ?> →</a>
    </div>
    <div class="lc-docs">
      <a class="lc-doc" href="<?= e($lcLp) ?>/docs#install"><span class="ic">📦</span><span class="txt"><b><?= $lcT('安装', 'Install') ?></b><span><?= $lcT('环境要求、伪静态、目录权限与常见报错', 'Requirements, rewrite rules, permissions and common errors') ?></span></span><em>→</em></a>
      <a class="lc-doc" href="<?= e($lcLp) ?>/docs#upgrade"><span class="ic">⬆️</span><span class="txt"><b><?= $lcT('升级', 'Upgrade') ?></b><span><?= $lcT('升级前备份 → 覆盖 → 自检 → 回滚', 'Backup, replace, self-check and roll back') ?></span></span><em>→</em></a>
      <a class="lc-doc" href="<?= e($lcLp) ?>/docs#theme-dev"><span class="ic">🎨</span><span class="txt"><b><?= $lcT('主题开发', 'Theme development') ?></b><span><?= $lcT('目录结构、theme.json、模板与变量', 'Structure, theme.json, templates and variables') ?></span></span><em>→</em></a>
      <a class="lc-doc" href="<?= e($lcLp) ?>/docs#plugin-dev"><span class="ic">🔌</span><span class="txt"><b><?= $lcT('插件开发', 'Plugin development') ?></b><span><?= $lcT('plugin.php 清单、路由、权限与迁移', 'Manifest, routes, permissions and migrations') ?></span></span><em>→</em></a>
    </div>
  </section>

  <section class="lc-sec">
    <div class="lc-sec-head">
      <div>
        <div class="lc-kicker">Community</div>
        <h2 class="lc-h2"><?= $lcT('社区', 'Community') ?></h2>
        <p class="lc-sub"><?= $lcT('论坛里的最新主题与站点统计，数字取自论坛表本身。', 'The newest forum topics and site figures, read straight from the forum tables.') ?></p>
      </div>
      <a class="lc-more" href="<?= e($lcLp) ?>/forum"><?= $lcT('进入论坛', 'Open the forum') ?> →</a>
    </div>
    <div class="lc-comm">
      <div class="lc-comm-main">
        <?php if ($lc['forum']['threads'] !== []): ?>
        <?php foreach ($lc['forum']['threads'] as $t): ?>
        <div class="lc-th">
          <a class="lc-th-a" href="<?= e($lcLp) ?>/forum?t=<?= (int)$t['id'] ?>"><?= e($t['title']) ?></a>
          <div class="lc-th-meta">
            <?php if (!empty($t['pinned'])): ?><span class="lc-pin"><?= $lcT('置顶', 'Pinned') ?></span><?php endif; ?>
            <span class="lc-chip"><?= e($t['board']) ?></span>
            <span><?= $lcT('回复', 'replies') ?> <?= (int)$t['replies'] ?></span>
            <span><?= e($t['ago']) ?></span>
          </div>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="lc-th"><span class="lc-th-meta"><?= $lcT('论坛暂无主题，欢迎发第一帖。', 'No topics yet — be the first to post.') ?></span></div>
        <?php endif; ?>
      </div>
      <div class="lc-comm-side">
        <div class="lc-kpi">
          <div><b><?= (int)$lc['forum']['threads_n'] ?></b><span><?= $lcT('主题', 'topics') ?></span></div>
          <div><b><?= (int)$lc['forum']['replies_n'] ?></b><span><?= $lcT('回帖', 'replies') ?></span></div>
          <div><b><?= (int)$lc['forum']['users_n'] ?></b><span><?= $lcT('会员', 'members') ?></span></div>
          <div><b><?= (int)$lc['forum']['boards_n'] ?></b><span><?= $lcT('版块', 'boards') ?></span></div>
        </div>
        <a class="lc-side-btn" href="<?= e($lcLp) ?>/forum?mode=new"><?= $lcT('发新帖', 'New topic') ?></a>
      </div>
    </div>
  </section>

  <?php if (!$lcEn): ?>
  <section class="lc-sec">
    <div class="lc-sec-head">
      <div>
        <div class="lc-kicker">Case study</div>
        <h2 class="lc-h2">重点推荐案例</h2>
        <p class="lc-sub">真实站点、真实数字；页面里每个数都能追到一条取数命令。</p>
      </div>
    </div>
    <a class="lc-card" href="/case-gushuwu.html">
      <span class="lc-card-body">
        <span class="lc-card-name">古书屋 gushuwu.com —— 100 万+ 内容页，依然秒开</span>
        <p class="lc-card-desc">公版古籍站：105 万+ 篇已发布内容、12 亿+ 字全文、139,319 条繁体译文、36,217 位作者，覆盖诗 · 词 · 古籍 · 字典三书 · 名句 · 成語 · 部首 · 校勘；含各页型冷/热实测与三条内核优化的「问题 → 做法 → 实测收益」。</p>
      </span>
    </a>
  </section>
  <?php endif; ?>

  <section class="lc-cta2">
    <b><?= $lcT('一个 PHP 进程就能跑起来的博客，现在就开始。', 'A blog that runs on a single PHP process — start now.') ?></b>
    <div class="lc-cta">
      <a class="lc-btn-main" href="<?= e($lcLp) ?>/downloads"><b><?= $lcT('下载', 'Download') ?></b><small><?= $lcT('完整包 · 主题 · 插件', 'Full package · themes · plugins') ?></small></a>
      <a class="lc-btn-ghost" href="<?= e($lcLp) ?>/docs"><?= $lcT('先看文档', 'Read the docs') ?></a>
      <a class="lc-btn-ghost" href="<?= e($lcLp) ?>/forum"><?= $lcT('去论坛讨论', 'Ask in the forum') ?></a>
    </div>
  </section>

  <nav class="lc-footnav" aria-label="<?= $lcT('页脚导航', 'Footer navigation') ?>">
    <div>
      <h4><?= $lcT('产品', 'Product') ?></h4>
      <ul>
        <li><a href="<?= e($lcLp) ?>/downloads"><?= $lcT('下载中心', 'Downloads') ?></a></li>
        <li><a href="<?= e($lcLp) ?>/changelog"><?= $lcT('更新日志', 'Changelog') ?></a></li>
        <li><a href="<?= e($lcLp) ?>/docs"><?= $lcT('使用文档', 'Documentation') ?></a></li>
        <li><a href="<?= e($lcLp) ?>/developers.html"><?= $lcT('开发文档', 'Developer docs') ?></a></li>
      </ul>
    </div>
    <div>
      <h4><?= $lcT('生态', 'Ecosystem') ?></h4>
      <ul>
        <li><a href="<?= e($lcLp) ?>/theme"><?= $lcT('主题', 'Themes') ?></a></li>
        <li><a href="<?= e($lcLp) ?>/plugin"><?= $lcT('插件', 'Plugins') ?></a></li>
        <li><a href="<?= e($lcLp) ?>/market"><?= $lcT('应用市场', 'App market') ?></a></li>
        <li><a href="<?= e($lcLp) ?>/store"><?= $lcT('应用商店', 'Store') ?></a></li>
      </ul>
    </div>
    <div>
      <h4><?= $lcT('社区', 'Community') ?></h4>
      <ul>
        <li><a href="<?= e($lcLp) ?>/forum"><?= $lcT('论坛', 'Forum') ?></a></li>
        <li><a href="<?= e($lcLp) ?>/about.html"><?= $lcT('关于 YeCha', 'About YeCha') ?></a></li>
        <li><a href="<?= e($lcLp) ?>/join.html"><?= $lcT('联系与加入', 'Contact &amp; join') ?></a></li>
        <?php if (!$lcEn): ?><li><a href="/blog-circle.html">博客圈</a></li><?php endif; ?>
      </ul>
    </div>
    <div>
      <h4><?= $lcT('更多', 'More') ?></h4>
      <ul>
        <li><a href="<?= e($lcLp) ?>/search"><?= $lcT('站内搜索', 'Search') ?></a></li>
        <li><a href="/feed">RSS</a></li>
        <li><a href="/sitemap.xml">Sitemap</a></li>
        <li><a href="https://github.com/ye-cha/yecha" rel="noopener" target="_blank">GitHub ↗</a></li>
      </ul>
    </div>
  </nav>
</div>
