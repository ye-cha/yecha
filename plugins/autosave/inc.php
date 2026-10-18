<?php
declare(strict_types=1);
/**
 * 文章自动保存 (Autosave) · 核心逻辑
 *
 * 存储选型说明:快照放在数据表 ye_autosaves,不放 var/cache —— 框架的 Cache::flush('pages')
 * 会清空缓存目录,若快照存成缓存文件,一次清缓存就把「救命的多版本」也清掉了。
 *
 * 版本来源(reason):timer(定时) / hide(切走页面) / submit(点保存前) / manual(手工点「立即保存」)
 */

/* ==================== 表与配置 ==================== */

function asv_table(): string { return Db::t('autosaves'); }

function asv_ensure_table(): void
{
    static $done = false;
    if ($done) { return; }
    Db::run('CREATE TABLE IF NOT EXISTS ' . asv_table() . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        content_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        type VARCHAR(10) NOT NULL DEFAULT 'post',
        lang VARCHAR(12) NOT NULL DEFAULT 'zh',
        title VARCHAR(255) NOT NULL DEFAULT '',
        slug VARCHAR(190) NOT NULL DEFAULT '',
        mode VARCHAR(6) NOT NULL DEFAULT 'md',
        reason VARCHAR(12) NOT NULL DEFAULT 'timer',
        content MEDIUMTEXT NULL,
        rich_html MEDIUMTEXT NULL,
        chars INT NOT NULL DEFAULT 0,
        plain_chars INT NOT NULL DEFAULT 0,
        sig CHAR(32) NOT NULL DEFAULT '',
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_c (content_id, lang, id),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function asv_opt(string $k, string $default = ''): string
{
    $v = get_option('autosave.' . $k, null);
    return ($v === null || $v === '') ? $default : (string)$v;
}
function asv_interval(): int { return max(10, min(600, (int)asv_opt('interval', '30'))); }
function asv_keep(): int { return max(3, min(500, (int)asv_opt('keep', '30'))); }
function asv_days(): int { return max(0, min(3650, (int)asv_opt('days', '30'))); }
function asv_on_submit(): bool { return asv_opt('on_submit', '1') !== '0'; }
function asv_on_hide(): bool { return asv_opt('on_hide', '1') !== '0'; }

/** 只有登录的管理员能读写快照 */
function asv_can(): bool
{
    return class_exists('Auth') && Auth::check() && Auth::isAdmin();
}

/* ==================== 文本度量 ==================== */

/** 可见文字数(去掉 Markdown 记号与空白),与编辑器「文字归零」防呆同一口径 */
function asv_plain_len(string $s): int
{
    $t = preg_replace('/[\s#*>`~_|\[\]()!.\-=+:"\'\/\\\\]+/u', '', $s);
    return mb_strlen(trim((string)$t));
}
function asv_plain_len_html(string $s): int
{
    return asv_plain_len(strip_tags($s));
}
function asv_time(?string $t = null): string
{
    $ts = $t === null ? time() : (int)strtotime($t);
    return date('Y-m-d H:i:s', $ts);
}

/* ==================== 写快照 ==================== */

/**
 * @param array{cid?:int,type?:string,lang?:string,title?:string,slug?:string,mode?:string,
 *              reason?:string,content?:string,rich_html?:string} $d
 * @return array{ok:bool,id?:int,skipped?:string,versions?:int,plain_chars?:int,created_at?:string}
 */
function asv_save(array $d): array
{
    asv_ensure_table();
    $cid  = max(0, (int)($d['cid'] ?? 0));
    $lang = (string)preg_replace('/[^a-z\-]/', '', strtolower((string)($d['lang'] ?? 'zh')));
    if ($lang === '') { $lang = 'zh'; }
    $type = ((string)($d['type'] ?? 'post')) === 'page' ? 'page' : 'post';
    $mode = ((string)($d['mode'] ?? 'md')) === 'rich' ? 'rich' : 'md';
    $why  = (string)($d['reason'] ?? 'timer');
    if (!in_array($why, ['timer', 'hide', 'submit', 'manual'], true)) { $why = 'timer'; }

    $md   = (string)($d['content'] ?? '');
    $rich = (string)($d['rich_html'] ?? '');
    if (mb_strlen($md) > 2000000) { $md = mb_substr($md, 0, 2000000); }
    if (mb_strlen($rich) > 2000000) { $rich = mb_substr($rich, 0, 2000000); }

    $plain = max(asv_plain_len($md), asv_plain_len_html($rich));
    if ($plain === 0 && trim($md) === '' && trim($rich) === '') {
        return ['ok' => true, 'skipped' => 'empty', 'versions' => asv_count($cid, $lang)];
    }

    $sig = md5($md . "\x00" . $rich);
    $src = 'html';
    $last = Db::row('SELECT id, sig, created_at FROM ' . asv_table() . ' WHERE content_id=? AND lang=? ORDER BY id DESC LIMIT 1', [$cid, $lang]);
    if ($last !== null && (string)$last['sig'] === $sig) {
        return ['ok' => true, 'skipped' => 'dup', 'id' => (int)$last['id'], 'versions' => asv_count($cid, $lang), 'created_at' => (string)$last['created_at']];
    }

    $id = Db::insert('autosaves', [
        'content_id' => $cid,
        'type' => $type,
        'lang' => $lang,
        'title' => mb_substr(trim((string)($d['title'] ?? '')), 0, 250),
        'slug' => mb_substr(trim((string)($d['slug'] ?? '')), 0, 185),
        'mode' => $mode,
        'reason' => $why,
        'content' => $md,
        'rich_html' => $rich !== '' ? $rich : null,
        'chars' => mb_strlen($md),
        'plain_chars' => $plain,
        'sig' => $sig,
        'user_id' => (int)Auth::id(),
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'created_at' => asv_time(),
    ]);
    asv_trim($cid, $lang);
    asv_purge_expired();

    return [
        'ok' => true, 'id' => $id, 'versions' => asv_count($cid, $lang),
        'plain_chars' => $plain, 'created_at' => asv_time(),
    ];
}

function asv_count(int $cid, string $lang): int
{
    return (int)Db::one('SELECT COUNT(*) FROM ' . asv_table() . ' WHERE content_id=? AND lang=?', [$cid, $lang]);
}

/** 每篇每语言只保留最新 N 个版本(策略可配) */
function asv_trim(int $cid, string $lang): int
{
    $keep = asv_keep();
    $nth = Db::one('SELECT id FROM ' . asv_table() . ' WHERE content_id=? AND lang=? ORDER BY id DESC LIMIT 1 OFFSET ' . ($keep - 1), [$cid, $lang]);
    if ($nth === null) { return 0; }
    $st = Db::run('DELETE FROM ' . asv_table() . ' WHERE content_id=? AND lang=? AND id<?', [$cid, $lang, (int)$nth]);
    return $st->rowCount();
}

/** 超过保留天数的自动清理(days=0 表示永久保留) */
function asv_purge_expired(): int
{
    $days = asv_days();
    if ($days <= 0) { return 0; }
    $st = Db::run('DELETE FROM ' . asv_table() . ' WHERE created_at < (NOW() - INTERVAL ' . $days . ' DAY)');
    return $st->rowCount();
}

/* ==================== 读/删 ==================== */

function asv_row(int $id): ?array
{
    return Db::row('SELECT * FROM ' . asv_table() . ' WHERE id=? LIMIT 1', [$id]);
}

function asv_list(?int $cid = null, ?string $lang = null, int $limit = 100, int $offset = 0): array
{
    $w = '1'; $p = [];
    if ($cid !== null) { $w .= ' AND content_id=?'; $p[] = $cid; }
    if ($lang !== null && $lang !== '') { $w .= ' AND lang=?'; $p[] = $lang; }
    $limit = max(1, min(500, $limit));
    return Db::rows('SELECT id, content_id, type, lang, title, slug, mode, reason, chars, plain_chars, user_id, created_at
        FROM ' . asv_table() . ' WHERE ' . $w . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . max(0, $offset), $p);
}

function asv_total(?int $cid = null, ?string $lang = null): int
{
    $w = '1'; $p = [];
    if ($cid !== null) { $w .= ' AND content_id=?'; $p[] = $cid; }
    if ($lang !== null && $lang !== '') { $w .= ' AND lang=?'; $p[] = $lang; }
    return (int)Db::one('SELECT COUNT(*) FROM ' . asv_table() . ' WHERE ' . $w, $p);
}

function asv_stats(): array
{
    asv_ensure_table();
    $r = Db::row('SELECT COUNT(*) n, COUNT(DISTINCT content_id) docs, MIN(created_at) first_at, MAX(created_at) last_at, SUM(chars) chars FROM ' . asv_table());
    return [
        'n' => (int)($r['n'] ?? 0),
        'docs' => (int)($r['docs'] ?? 0),
        'first_at' => (string)($r['first_at'] ?? ''),
        'last_at' => (string)($r['last_at'] ?? ''),
        'chars' => (int)($r['chars'] ?? 0),
        'keep' => asv_keep(),
        'days' => asv_days(),
        'interval' => asv_interval(),
    ];
}

function asv_delete(int $id): bool
{
    asv_ensure_table();
    Db::deleteBy('autosaves', 'id=?', [$id]);
    return true;
}

function asv_delete_content(int $cid, string $lang = ''): int
{
    asv_ensure_table();
    if ($lang !== '') {
        $st = Db::run('DELETE FROM ' . asv_table() . ' WHERE content_id=? AND lang=?', [$cid, $lang]);
        return $st->rowCount();
    }
    $st = Db::run('DELETE FROM ' . asv_table() . ' WHERE content_id=?', [$cid]);
    return $st->rowCount();
}

function asv_clear_all(): int
{
    asv_ensure_table();
    $st = Db::run('DELETE FROM ' . asv_table());
    return $st->rowCount();
}

/** 某篇内容当前库里的可见文字数(用于「快照比正文多」的提醒) */
function asv_db_plain(int $cid): int
{
    if ($cid <= 0) { return 0; }
    $c = Db::one('SELECT content FROM ' . Db::t('contents') . ' WHERE id=?', [$cid]);
    return asv_plain_len((string)$c);
}

/* ==================== 编辑器挂载点 ==================== */

function asv_register_hooks(): void
{
    if (!class_exists('Hooks')) { return; }
    Hooks::on('admin.editor.tail', 'asv_editor_tail', 10);
}

/**
 * 往后台编辑器尾部注入:状态条 + 「快照比正文多」提醒 + 配置 + 前端脚本
 * @param array{html?:string,type?:string,id?:int,lang?:string} $p
 */
function asv_editor_tail(array $p): array
{
    if (!asv_can()) { return $p; }
    asv_ensure_table();

    $cid  = (int)($p['id'] ?? 0);
    $type = ((string)($p['type'] ?? 'post')) === 'page' ? 'page' : 'post';
    $lang = (string)($p['lang'] ?? '');
    if ($lang === '') { $lang = class_exists('Lang') ? (string)Lang::short() : 'zh'; }

    $last = Db::row('SELECT id, created_at, plain_chars, mode FROM ' . asv_table()
        . ' WHERE content_id=? AND lang=? ORDER BY id DESC LIMIT 1', [$cid, $lang]);
    $cnt = asv_count($cid, $lang);
    $curPlain = asv_db_plain($cid);
    $restore = (int)($_GET['restore'] ?? 0);

    $histUrl = '/admin/?s=plug_autosave&id=' . $cid . '&lang=' . rawurlencode($lang);
    $cfg = [
        'cid' => $cid, 'type' => $type, 'lang' => $lang,
        'interval' => asv_interval(),
        'onSubmit' => asv_on_submit() ? 1 : 0,
        'onHide' => asv_on_hide() ? 1 : 0,
        'csrf' => Csrf::token(),
        'save' => '/autosave/save',
        'get' => '/autosave/get',
        'hist' => $histUrl,
        'versions' => $cnt,
        'restore' => $restore,
        'lastAt' => $last !== null ? (string)$last['created_at'] : '',
        'lastPlain' => $last !== null ? (int)$last['plain_chars'] : 0,
        'dbPlain' => $curPlain,
    ];

    $html = '<style>'
        . '.asv-bar{margin:10px 0 0;padding:8px 12px;border:1px solid #d0d7de;border-radius:6px;background:#f6f8fa;'
        . 'font-size:12.5px;color:#57606a;display:flex;flex-wrap:wrap;align-items:center;gap:10px}'
        . '.asv-bar b{color:#1f2328;font-weight:600}'
        . '.asv-bar .asv-dot{width:8px;height:8px;border-radius:50%;background:#1f883d;flex:0 0 auto}'
        . '.asv-bar button{font:inherit;padding:3px 10px;border:1px solid #d0d7de;border-radius:6px;background:#fff;cursor:pointer}'
        . '.asv-bar button:hover{background:#f3f4f6}'
        . '.asv-bar a{color:#0969da}'
        . '.asv-warn{margin:10px 0 0;padding:10px 12px;border:1px solid #d4a72c;border-left:4px solid #bf8700;'
        . 'border-radius:0 6px 6px 0;background:#fff8c5;color:#4d2d00;font-size:13px;line-height:1.7}'
        . '.asv-warn a{color:#0969da;font-weight:600}'
        . '.asv-ok{margin:10px 0 0;padding:9px 12px;border:1px solid #d0d7de;border-left:4px solid #1f883d;'
        . 'border-radius:0 6px 6px 0;background:#f6f8fa;font-size:13px}'
        . 'html.dark .asv-bar{background:#161b22;border-color:#30363d;color:#8b949e}'
        . 'html.dark .asv-bar b{color:#e6edf3}'
        . 'html.dark .asv-warn{background:#2e2200;border-color:#6b5200;color:#e3b341}'
        . 'html.dark .asv-ok{background:#161b22;border-color:#30363d}'
        . '</style>';

    $html .= '<div class="asv-bar" id="asvBar"><span class="asv-dot"></span>'
        . '<span id="asvText">自动保存已就绪（每 ' . (int)asv_interval() . ' 秒留底）'
        . ($last !== null ? ' · 最近 ' . e(date('m-d H:i', (int)strtotime((string)$last['created_at']))) : '') . '</span>'
        . '<span>·</span><a href="' . e($histUrl) . '" id="asvHist" target="_blank" rel="noopener">历史版本 <b id="asvHistN">' . $cnt . '</b> 个</a>'
        . '<button type="button" id="asvNow">立即保存一版</button></div>';

    if ($last !== null && $curPlain + 20 < (int)$last['plain_chars']) {
        $html .= '<div class="asv-warn" id="asvWarn">⚠ 最近一次自动保存（'
            . e(date('Y-m-d H:i', (int)strtotime((string)$last['created_at']))) . '）里有 <b>' . (int)$last['plain_chars']
            . '</b> 个字的正文，而当前库里这篇只有 <b>' . $curPlain . '</b> 个字 —— 可能被覆盖或清空过。'
            . '<a href="' . e($histUrl) . '" target="_blank" rel="noopener">去历史版本里查看并载回</a>'
            . '　或直接 <a href="' . e($histUrl . '&restore=' . (int)$last['id']) . '">用最近这版覆盖编辑器</a></div>';
    } elseif ($restore > 0) {
        $html .= '<div class="asv-ok" id="asvRestore">正在载入快照 #' . $restore . '…</div>';
    }

    $html .= '<script type="application/json" id="asvCfg">'
        . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    $js = '/plugins/autosave/autosave.js?v=' . (string)@filemtime(__DIR__ . '/autosave.js');
    $html .= '<script src="' . e($js) . '"></script>';

    $p['html'] = (string)($p['html'] ?? '') . $html;
    return $p;
}

/* ==================== /autosave/* 接口 ==================== */

function asv_json(array $d, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function asv_front(): void
{
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (strpos($path, '/autosave/') === false) { return; }
    $segs = array_values(array_filter(explode('/', trim($path, '/')), static fn($s) => $s !== ''));
    if (($segs[0] ?? '') !== 'autosave') { return; }
    $act = (string)($segs[1] ?? '');
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if (!asv_can()) { asv_json(['ok' => false, 'error' => 'auth'], 403); }

    if ($act === 'save' && $method === 'POST') {
        Csrf::check();
        $r = asv_save([
            'cid' => (int)($_POST['cid'] ?? 0),
            'type' => (string)($_POST['type'] ?? 'post'),
            'lang' => (string)($_POST['lang'] ?? ''),
            'title' => (string)($_POST['title'] ?? ''),
            'slug' => (string)($_POST['slug'] ?? ''),
            'mode' => (string)($_POST['mode'] ?? 'md'),
            'reason' => (string)($_POST['reason'] ?? 'timer'),
            'content' => (string)($_POST['content'] ?? ''),
            'rich_html' => (string)($_POST['rich_html'] ?? ''),
        ]);
        asv_json($r);
    }

    if ($act === 'get' && $method === 'GET') {
        $row = asv_row((int)($_GET['id'] ?? 0));
        if ($row === null) { asv_json(['ok' => false, 'error' => 'not found'], 404); }
        asv_json([
            'ok' => true, 'id' => (int)$row['id'], 'content_id' => (int)$row['content_id'],
            'title' => (string)$row['title'], 'slug' => (string)$row['slug'], 'mode' => (string)$row['mode'],
            'reason' => (string)$row['reason'], 'created_at' => (string)$row['created_at'],
            'chars' => (int)$row['chars'], 'plain_chars' => (int)$row['plain_chars'],
            'content' => (string)($row['content'] ?? ''), 'rich_html' => (string)($row['rich_html'] ?? ''),
        ]);
    }

    if ($act === 'status' && $method === 'GET') {
        $cid = (int)($_GET['cid'] ?? 0);
        $lang = (string)($_GET['lang'] ?? '');
        asv_json(['ok' => true, 'versions' => asv_count($cid, $lang !== '' ? $lang : 'zh'), 'db_plain' => asv_db_plain($cid)]);
    }

    asv_json(['ok' => false, 'error' => 'unknown action'], 404);
}
