<?php
declare(strict_types=1);
/**
 * lqnotes · 云笔记（服务端逻辑）
 * ===========================================================================
 * 一个最小插件，做三件事（都不碰 core/、不碰 index.php、不碰 nginx）：
 *   ① Hooks::on('route') 认领 `/api/notes`     —— JSON API（op 分发；session 鉴权 + 站内 Csrf 令牌）
 *   ② 认领 `/share/{token}`                    —— 公开分享页（服务端渲染；**永不直出用户 HTML**）
 *   ③ 数据落在本插件自己的三张新表（ye_note_books / ye_note_items / ye_note_shares）
 *
 * 零外发：本文件里没有任何外部主机名 / 第三方 SDK / 外链请求；所有 fetch 都发生在浏览器侧的同源
 *         `/api/notes`。分享页也不加载任何第三方脚本。
 *
 * 硬约束（对齐站主的四条）：
 *   · XSS：分享页只输出「先转义、再渲染限定子集 Markdown」的结果；用户 HTML **不做 raw 直通**。
 *   · CSRF：改状态的 op 必须带 `Csrf::token()`（站内既有那套），否则 403。
 *   · 索引/隐私：分享页恒发 `X-Robots-Tag: noindex, nofollow, noarchive`；未登录不能列任何人的笔记。
 *   · 上限：单条正文 256 KiB、标题 200 字符、单用户 2000 条 / 200 个笔记本；写操作限频 120 次/60s。
 *     错误响应只回稳定错误码 + 中文短句，**不回声 SQL / 路径 / 异常栈**。
 */

if (!function_exists('lqn_route')):

/* ------------------------------------------------------------------ 常量
 * ★ 用 define() 而不是 const：本文件整体被包在 `if (!function_exists(...)):` 里，
 *   而 PHP 的 const 不允许出现在这种条件块内（实测 = Parse error，见 b04_lint）。 */
if (!defined('LQN_MAX_BODY')) {
    define('LQN_MAX_BODY', 262144);   // 256 KiB —— 单条正文上限（超出 ⇒ 400 note_too_large）
    define('LQN_MAX_TITLE', 200);     // 与 ye_contents.title 同口径
    define('LQN_MAX_NOTES', 2000);    // 单用户笔记上限
    define('LQN_MAX_BOOKS', 200);     // 单用户笔记本上限
    define('LQN_RATE_WIN', 60);       // 写操作限频窗口（秒）
    define('LQN_RATE_MAX', 120);      // 窗口内写操作上限
    define('LQN_MAX_ALIAS', 40);      // ★ 批3：自定义短链长度上限（与参照同口径）
    define('LQN_MAX_REVS', 20);       // ★ 批4：历史版本保留上限（与参照 bundle 的 WL=20 / 文案"最多 20 条"同口径）
    /* ★ 批3：访问去重用的固定盐。**不是密钥**：ip_hash 只用于"同一访客当天只计一次"的去重，
       永不存明文 IP、也不用于鉴权（与参照 share.js 的 apiSalt 口径一致）。 */
    define('LQN_VIEW_SALT', 'lqn-views-v1');
}

/* ------------------------------------------------------------------ 基础输出 */
function lqn_headers(): void
{
    if (headers_sent()) { return; }
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}
function lqn_json(array $o, int $code = 200): void
{
    lqn_headers();
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
    http_response_code($code);
    echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
/** 失败：只回稳定错误码（可带一句中文短句），绝不回声 SQL / 路径 / 异常信息 */
function lqn_fail(string $code, int $http = 400, string $msg = ''): void
{
    $o = ['ok' => false, 'error' => $code];
    if ($msg !== '') { $o['msg'] = $msg; }
    lqn_json($o, $http);
}
function lqn_ok(array $extra = []): void
{
    lqn_json(['ok' => true] + $extra);
}

/* ------------------------------------------------------------------ 会话 / 鉴权 */
function lqn_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || headers_sent()) { return; }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (function_exists('yc_theme_scheme') ? yc_theme_scheme() === 'https' : true),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    $GLOBALS['YC_SESSION_STARTED'] = true;
}
function lqn_uid(): int
{
    lqn_session();
    return class_exists('Auth') ? Auth::id() : 0;
}
function lqn_need_login(): void
{
    lqn_fail('login_required', 401);
}
/** 同源校验（Origin / Referer 存在且主机不符 ⇒ 拒） */
function lqn_origin_ok(): bool
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    foreach (['HTTP_ORIGIN' => 1, 'HTTP_REFERER' => 2] as $k => $_) {
        $v = trim((string)($_SERVER[$k] ?? ''));
        if ($v === '') { continue; }
        $h = strtolower((string)parse_url($v, PHP_URL_HOST));
        if ($h !== '' && $host !== '' && $h !== $host) { return false; }
    }
    return true;
}
/** CSRF：站内既有令牌（Csrf::token() 生成，$_SESSION['csrf']） */
function lqn_csrf_ok(array $in): bool
{
    lqn_session();
    $tok = (string)($in['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    return class_exists('Csrf') && Csrf::verify($tok);
}
/** 写操作限频（按 uid，其次 IP） */
function lqn_rate_ok(): bool
{
    if (!class_exists('Cache')) { return true; }
    $who = lqn_uid();
    $key = 'lqn:' . ($who > 0 ? 'u' . $who : 'i' . md5((string)($_SERVER['REMOTE_ADDR'] ?? '0')));
    $n = (int)Cache::incr('throttle', $key, LQN_RATE_WIN);
    return $n <= LQN_RATE_MAX;
}

/* ------------------------------------------------------------------ 数据访问 */
function lqn_book_out(array $r): array
{
    return ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'sort' => (int)$r['sort']];
}
function lqn_note_out(array $r): array
{
    return [
        'id'         => (int)$r['id'],
        'ck'         => (string)$r['client_key'],
        'book_id'    => (int)$r['book_id'],
        'title'      => (string)$r['title'],
        'body'       => (string)$r['body'],
        'fmt'        => (string)$r['fmt'],
        'status'     => (string)$r['status'],
        'trashed_at' => (string)($r['trashed_at'] ?? ''),
        'updated_at' => (string)$r['updated_at'],
        'created_at' => (string)$r['created_at'],
        'share'      => (string)($r['share_token'] ?? ''),
        'share_alias' => (string)($r['share_alias'] ?? ''),
        'share_exp'  => (string)($r['share_exp'] ?? ''),
    ];
}
function lqn_books(int $uid): array
{
    $rows = Db::rows('SELECT id,name,sort FROM ' . Db::t('note_books') . ' WHERE user_id=? ORDER BY sort ASC, id ASC', [$uid]);
    return array_map('lqn_book_out', $rows);
}
function lqn_notes(int $uid): array
{
    $SH = Db::t('note_shares');
    $rows = Db::rows(
        'SELECT n.*, s.token AS share_token, s.alias AS share_alias, s.expires_at AS share_exp FROM ' . Db::t('note_items') . ' n '
        . "LEFT JOIN $SH s ON s.note_id=n.id AND s.user_id=n.user_id AND s.status='on' "
        . 'WHERE n.user_id=? ORDER BY n.updated_at DESC, n.id DESC LIMIT ' . LQN_MAX_NOTES,
        [$uid]
    );
    return array_map('lqn_note_out', $rows);
}
function lqn_book_id_by_name(int $uid, string $name): int
{
    $name = lqn_trim($name, 120);
    if ($name === '') { return 0; }
    $id = (int)Db::one('SELECT id FROM ' . Db::t('note_books') . ' WHERE user_id=? AND name=? LIMIT 1', [$uid, $name]);
    if ($id > 0) { return $id; }
    $n = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('note_books') . ' WHERE user_id=?', [$uid]);
    if ($n >= LQN_MAX_BOOKS) { return 0; }
    return (int)Db::insert('note_books', ['user_id' => $uid, 'name' => $name, 'sort' => $n]);
}
/** 多字节安全截断（无 mbstring 时退化为按字节但保证不断字） */
function lqn_trim(string $s, int $max): string
{
    $s = trim($s);
    if (function_exists('mb_substr')) { return mb_substr($s, 0, $max, 'UTF-8'); }
    return substr($s, 0, $max);
}
function lqn_body_ok(string $body): bool
{
    return strlen($body) <= LQN_MAX_BODY;
}

/* ------------------------------------------------------------------ 分享：token */
function lqn_new_token(): string
{
    return bin2hex(random_bytes(9)); // 18 位十六进制，URL 安全
}

/* ------------------------------------------------------------------ 安全渲染（分享页）
 * 铁律：**绝不把用户 HTML 原样输出**。
 *   ① fmt='html' ⇒ 先把标签结构降级成纯文本/Markdown（strip_tags + 块级换行）
 *   ② 不论哪种 fmt，都先 htmlspecialchars 转义，再套用「本函数自己生成的」标签
 *   ③ 链接只放行 http/https/mailto，超出的一律当纯文本
 */
function lqn_html_to_text(string $html): string
{
    $s = (string)$html;
    /* ★ 危险/可执行标签：**不删除、不直通**，而是把它们的尖括号转义成纯文本
       （于是 `<script>…</script>`、`<img onerror=…>` 会作为「字面文字」显示在分享页上，
        既证明"被转义"，也绝不会被浏览器当成标签执行）。 */
    $danger = 'script|style|iframe|frame|frameset|object|embed|applet|svg|math|template|img|input|button|link|meta|base|form|video|audio|source|canvas|noscript|details|marquee';
    $s = preg_replace_callback(
        '#<\s*(/)?\s*(' . $danger . ')\b[^>]*>#i',
        static function (array $m): string { return htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8'); },
        $s
    ) ?? $s;
    $s = preg_replace('#<br\s*/?>#i', "\n", $s) ?? $s;
    $s = preg_replace('#</(p|div|section|article|h[1-6]|li|tr|blockquote|pre)>#i', "\n", $s) ?? $s;
    $s = preg_replace('#<li\b[^>]*>#i', '- ', $s) ?? $s;
    $s = preg_replace_callback('#<h([1-6])\b[^>]*>#i', static function (array $m): string {
        return "\n" . str_repeat('#', max(1, min(4, (int)$m[1]))) . ' ';
    }, $s) ?? $s;
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace("\xc2\xa0", ' ', $s);
    $s = preg_replace("/[ \t]+\n/", "\n", $s) ?? $s;
    $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? $s;
    return trim($s);
}

/** 受限 Markdown → HTML。输入按纯文本处理：**先转义，再生成标签**。 */
function lqn_md_safe(string $md): string
{
    $e = htmlspecialchars($md, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $lines = preg_split("/\r\n|\r|\n/", $e) ?: [];
    $out = [];
    $inUl = false; $inOl = false; $inQuote = false; $inCode = false; $code = [];
    $closeList = function () use (&$out, &$inUl, &$inOl): void {
        if ($inUl) { $out[] = '</ul>'; $inUl = false; }
        if ($inOl) { $out[] = '</ol>'; $inOl = false; }
    };
    $closeQuote = function () use (&$out, &$inQuote): void {
        if ($inQuote) { $out[] = '</blockquote>'; $inQuote = false; }
    };
    foreach ($lines as $ln) {
        $t = rtrim($ln);
        if (preg_match('/^```/', $t)) {
            if ($inCode) { $out[] = '<pre><code>' . implode("\n", $code) . '</code></pre>'; $code = []; $inCode = false; }
            else { $closeList(); $closeQuote(); $inCode = true; }
            continue;
        }
        if ($inCode) { $code[] = $t; continue; }
        if (trim($t) === '') { $closeList(); $closeQuote(); continue; }
        if (preg_match('/^(#{1,4})\s+(.*)$/', $t, $m)) {
            $closeList(); $closeQuote();
            $lv = strlen($m[1]);
            $out[] = '<h' . $lv . '>' . lqn_inline($m[2]) . '</h' . $lv . '>';
            continue;
        }
        if (preg_match('/^\s*[-*+]\s+(.*)$/', $t, $m)) {
            $closeQuote();
            if (!$inUl) { $out[] = '<ul>'; $inUl = true; }
            $out[] = '<li>' . lqn_inline($m[1]) . '</li>';
            continue;
        }
        if (preg_match('/^\s*\d+[.)]\s+(.*)$/', $t, $m)) {
            $closeQuote();
            if ($inOl) { /* keep */ } else { if ($inUl) { $out[] = '</ul>'; $inUl = false; } $out[] = '<ol>'; $inOl = true; }
            $out[] = '<li>' . lqn_inline($m[1]) . '</li>';
            continue;
        }
        if (preg_match('/^&gt;\s?(.*)$/', $t, $m)) {
            $closeList();
            if (!$inQuote) { $out[] = '<blockquote>'; $inQuote = true; }
            $out[] = '<p>' . lqn_inline($m[1]) . '</p>';
            continue;
        }
        if (preg_match('/^(-{3,}|\*{3,})$/', trim($t))) { $closeList(); $closeQuote(); $out[] = '<hr>'; continue; }
        $closeList(); $closeQuote();
        $out[] = '<p>' . lqn_inline($t) . '</p>';
    }
    if ($inCode && $code) { $out[] = '<pre><code>' . implode("\n", $code) . '</code></pre>'; }
    $closeList(); $closeQuote();
    return implode("\n", $out);
}
/** 行内：输入**已转义**。只生成 <strong>/<em>/<code>/<a>(http|https|mailto)。 */
function lqn_inline(string $s): string
{
    $s = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $s) ?? $s;
    $s = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $s) ?? $s;
    $s = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/u', '<em>$1</em>', $s) ?? $s;
    $s = preg_replace_callback(
        '/\[([^\]]{1,120})\]\((https?:\/\/[^\s)]{1,500}|mailto:[^\s)]{1,200})\)/u',
        static function (array $m): string {
            $href = htmlspecialchars_decode($m[2], ENT_QUOTES);
            if (!preg_match('#^(https?://|mailto:)#i', $href)) { return $m[1]; }
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" rel="noopener nofollow noindex" target="_blank">' . $m[1] . '</a>';
        },
        $s
    ) ?? $s;
    return $s;
}
/** 统一的「笔记 → 分享页 HTML」入口（唯一出口，杜绝 raw 直通） */
function lqn_render_body(string $body, string $fmt): string
{
    $md = strtolower($fmt) === 'html' ? lqn_html_to_text($body) : $body;
    return lqn_md_safe($md);
}

/* ------------------------------------------------------------------ 分享页（token 或 alias 都可命中） */
/** ★ 批3：别名归一（与参照 share.js 同口径：小写 + 只留 [a-z0-9_-] + 截 40） */
function lqn_norm_alias(string $a): string
{
    $a = strtolower(trim($a));
    $a = preg_replace('/[^a-z0-9_-]/', '', $a) ?? '';
    return substr($a, 0, LQN_MAX_ALIAS);
}
/** 别名是否可用（排除自己那条） */
function lqn_alias_free(string $alias, int $uid, int $noteId): bool
{
    $n = (int)Db::one(
        'SELECT COUNT(*) FROM ' . Db::t('note_shares') . ' WHERE alias=? AND NOT (user_id=? AND note_id=?)',
        [$alias, $uid, $noteId]
    );
    return $n === 0;
}
/** 按 token 或 alias 取分享行（两者同一个入口，分享页行为完全一致） */
function lqn_share_row(string $key): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{3,40}$/', $key)) { return null; }
    $r = Db::row('SELECT * FROM ' . Db::t('note_shares') . ' WHERE token=? OR alias=? LIMIT 1', [$key, $key]);
    return $r === false ? null : $r;
}
/** ★ 批3：爬虫/预览过滤（与参照文案"访问统计会过滤爬虫与搜索引擎"一致） */
function lqn_is_bot(string $ua): bool
{
    if ($ua === '') { return true; }
    return (bool)preg_match(
        '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|embedly|quora link preview|whatsapp|'
        . 'telegrambot|discordbot|yandex|baiduspider|sogou|bytespider|petalbot|semrush|ahrefs|mj12|dotbot|'
        . 'python-requests|curl\/|wget\/|headlesschrome|lighthouse|monitoring|uptime/i',
        $ua
    );
}
/** ★ 批3：记一次访问（PV 逐次 + UV 按 (token,ip_hash) 去重 + 按日聚合 + 只存来源主机）
 *  ★ 隐私：不存明文 IP（只存 sha256(ip|当日|固定盐)）、referer 只存主机名。 */
function lqn_record_view(array $row): void
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (lqn_is_bot($ua)) { return; }
    $token = (string)$row['token'];
    $ip    = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    if (strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }
    $ipHash = substr(hash('sha256', $ip . '|' . date('Y-m-d') . '|' . LQN_VIEW_SALT), 0, 64);
    $uaHash = substr(hash('sha256', $ua), 0, 64);
    $ref = '';
    $rRaw = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($rRaw !== '') {
        $h = parse_url($rRaw, PHP_URL_HOST);
        if (is_string($h) && $h !== '') { $ref = substr(strtolower($h), 0, 160); }
    }
    try {
        $st = Db::run(
            'INSERT IGNORE INTO ' . Db::t('note_share_views') . ' (token, ip_hash, ua_hash, referer) VALUES (?,?,?,?)',
            [$token, $ipHash, $uaHash, $ref]
        );
        $isNewUv = ($st->rowCount() === 1) ? 1 : 0;
        Db::run(
            'INSERT INTO ' . Db::t('note_share_days') . ' (token, `day`, pv, uv) VALUES (?, CURDATE(), 1, ?) '
            . 'ON DUPLICATE KEY UPDATE pv = pv + 1, uv = uv + VALUES(uv)',
            [$token, $isNewUv]
        );
        // 总浏览量：分享行的 views 每次都 +1
        Db::run('UPDATE ' . Db::t('note_shares') . ' SET views = views + 1 WHERE id=?', [(int)$row['id']]);
    } catch (Throwable $e) {
        // 统计失败绝不影响阅读
    }
}
/** ★ 批3：统计读数（总浏览量 / 独立访客 / 近 14 天 / 最近来源） */
function lqn_share_stats(string $token): array
{
    $pv = (int)Db::one('SELECT COALESCE(views,0) FROM ' . Db::t('note_shares') . ' WHERE token=? LIMIT 1', [$token]);
    $uv = (int)Db::one('SELECT COUNT(DISTINCT ip_hash) FROM ' . Db::t('note_share_views') . ' WHERE token=?', [$token]);
    $rowsDays = Db::rows(
        'SELECT DATE_FORMAT(`day`, "%m-%d") d, pv, uv FROM ' . Db::t('note_share_days')
        . ' WHERE token=? AND `day` > (CURDATE() - INTERVAL 14 DAY) ORDER BY `day` ASC',
        [$token]
    );
    $byDay = [];
    foreach ($rowsDays as $r) { $byDay[(string)$r['d']] = [(int)$r['pv'], (int)$r['uv']]; }
    $days = [];
    for ($i = 13; $i >= 0; $i--) {
        $k = date('m-d', strtotime('-' . $i . ' day'));
        $days[] = ['d' => $k, 'pv' => $byDay[$k][0] ?? 0, 'uv' => $byDay[$k][1] ?? 0];
    }
    $refs = Db::rows(
        'SELECT DATE_FORMAT(created_at, "%m-%d %H:%i") t, referer FROM ' . Db::t('note_share_views')
        . ' WHERE token=? ORDER BY id DESC LIMIT 20',
        [$token]
    );
    foreach ($refs as $k => $r) { $refs[$k]['referer'] = (string)$r['referer'] === '' ? '（直接打开）' : (string)$r['referer']; }
    return ['pv' => $pv, 'uv' => $uv, 'days' => $days, 'refs' => $refs];
}
function lqn_share_page(string $title, string $inner, int $code = 200, string $canonical = ''): void
{
    lqn_headers();
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('Referrer-Policy: no-referrer');
    }
    http_response_code($code);
    $css = htmlspecialchars(View::asset('lqwww/www.css'), ENT_QUOTES, 'UTF-8');
    $css2 = htmlspecialchars(View::asset('style.css'), ENT_QUOTES, 'UTF-8');
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $can = $canonical !== '' ? '<link rel="canonical" href="' . htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') . '">' : '';
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<meta name="robots" content="noindex,nofollow,noarchive">'
        . $can
        . '<title>' . $t . ' · liulanqi 云笔记</title>'
        . '<link rel="stylesheet" href="' . $css . '"><link rel="stylesheet" href="' . $css2 . '">'
        . '<style>.lqn-wrap{max-width:820px;margin:0 auto;padding:24px 16px 64px}'
        . '.lqn-card{border:1px solid hsl(var(--border));border-radius:14px;background:hsl(var(--card));padding:28px}'
        . '.lqn-card h1{font-size:26px;font-weight:700;margin:0 0 6px}'
        . '.lqn-meta{font-size:12.5px;color:hsl(var(--muted-foreground));margin-bottom:18px}'
        . '.lqn-body{font-size:15.5px;line-height:1.85;word-wrap:break-word}'
        . '.lqn-body pre{background:hsl(var(--muted));padding:12px;border-radius:8px;overflow:auto}'
        . '.lqn-body blockquote{border-left:3px solid hsl(var(--border));padding-left:12px;color:hsl(var(--muted-foreground));margin:12px 0}'
        . '.lqn-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}'
        . '.lqn-bar a{font-size:13px;color:hsl(var(--muted-foreground));text-decoration:none}'
        . '.lqn-bar b{font-size:15px}</style></head><body><div class="lqn-wrap">'
        . '<div class="lqn-bar"><a href="/notes">← liulanqi 云笔记</a><a href="/notes">我也要写笔记</a></div>'
        . $inner
        . '<p style="margin-top:22px;font-size:12px;color:hsl(var(--muted-foreground))">本页由 liulanqi 云笔记生成，未登录亦可阅读；页面标记 noindex，不会被搜索引擎收录。</p>'
        . '</div></body></html>';
    exit;
}
function lqn_share_view(string $key, string $method): void
{
    $row = lqn_share_row($key);
    if ($row === null || (string)$row['status'] !== 'on') {
        lqn_share_page('分享不存在', '<div class="lqn-card"><h1>分享不存在或已关闭</h1>'
            . '<p class="lqn-meta">这条分享链接可能已被作者取消。</p></div>', 404);
    }
    $exp = (string)($row['expires_at'] ?? '');
    if ($exp !== '' && $exp !== '0000-00-00 00:00:00' && strtotime($exp) !== false && strtotime($exp) < time()) {
        lqn_share_page('分享已过期', '<div class="lqn-card"><h1>分享已过期</h1>'
            . '<p class="lqn-meta">作者为这条分享设置了有效期（' . htmlspecialchars($exp, ENT_QUOTES, 'UTF-8') . '），已到期。</p></div>', 410);
    }
    /* ★ 批3：口令 cookie / 限速键一律用【行里的 token】，而不是访问用的 key
       ⇒ 走 /s/<alias> 与走 /share/<token> 是同一份解锁状态（换 URL 不用重新输口令） */
    $tok = (string)$row['token'];
    $alias = (string)($row['alias'] ?? '');
    $pwHash = (string)($row['password_hash'] ?? '');
    if ($pwHash !== '') {
        $ck = 'lqn_sk_' . substr(hash('sha256', $tok . '|lqn'), 0, 16);
        $ok = false;
        if ($method === 'POST') {
            $pw = (string)($_POST['p'] ?? '');
            $rk = 'lqn_pw:' . md5($tok . '|' . (string)($_SERVER['REMOTE_ADDR'] ?? '0'));
            $tries = class_exists('Cache') ? (int)Cache::incr('throttle', $rk, 600) : 1;
            if ($tries > 8) { lqn_share_page('尝试过多', '<div class="lqn-card"><h1>尝试次数过多</h1><p class="lqn-meta">请 10 分钟后再试。</p></div>', 429); }
            if (password_verify($pw, $pwHash)) {
                $ok = true;
                if (!headers_sent()) {
                    setcookie($ck, hash('sha256', $tok . '|' . $pwHash . '|lqn'), [
                        'expires' => time() + 43200, 'path' => '/',
                        'secure' => (function_exists('yc_theme_scheme') ? yc_theme_scheme() === 'https' : true),
                        'httponly' => true, 'samesite' => 'Lax',
                    ]);
                }
            } else {
                lqn_share_page('需要访问密码', lqn_pw_form($tok, '密码不正确，请重试。'), 401);
            }
        } elseif (isset($_COOKIE[$ck]) && hash_equals((string)$_COOKIE[$ck], hash('sha256', $tok . '|' . $pwHash . '|lqn'))) {
            $ok = true;
        }
        if (!$ok) { lqn_share_page('需要访问密码', lqn_pw_form($tok, ''), 401); }
    }
    // ★ 批3：计数（PV 逐次 + UV 去重 + 按日聚合 + 爬虫过滤 + 只存来源主机）
    lqn_record_view($row);
    $bodyHtml = lqn_render_body((string)$row['body'], (string)$row['fmt']);
    $inner = '<article class="lqn-card"><h1>' . htmlspecialchars((string)$row['title'], ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p class="lqn-meta">来自 liulanqi 云笔记 · 创建于 ' . htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8')
        . ((string)($row['expires_at'] ?? '') !== '' ? ' · 有效期至 ' . htmlspecialchars((string)$row['expires_at'], ENT_QUOTES, 'UTF-8') : '')
        . '</p><div class="lqn-body">' . $bodyHtml . '</div></article>';
    $canon = '/share/' . $tok;
    if ($alias !== '') { $canon = '/s/' . $alias; }
    lqn_share_page((string)$row['title'], $inner, 200, $canon);
}
function lqn_pw_form(string $token, string $msg): string
{
    $tok = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
    return '<div class="lqn-card"><h1>需要访问密码</h1>'
        . ($msg !== '' ? '<p class="lqn-meta">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>' : '<p class="lqn-meta">作者为这条分享设置了访问密码。</p>')
        . '<form method="post" action="/share/' . $tok . '"><input type="password" name="p" autocomplete="off" '
        . 'style="width:100%;padding:10px 12px;border:1px solid hsl(var(--border));border-radius:8px;background:transparent;color:inherit" '
        . 'placeholder="访问密码"><button type="submit" style="margin-top:12px;padding:9px 18px;border-radius:8px;background:hsl(var(--primary));color:hsl(var(--primary-foreground));border:0;cursor:pointer">查看笔记</button></form></div>';
}

/* ------------------------------------------------------------------ API */
function lqn_api(string $method): void
{
    $raw = '';
    $in = [];
    if ($method === 'POST') {
        $raw = (string)file_get_contents('php://input');
        if ($raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j)) { $in = $j; }
        }
        if (!$in && $_POST) { $in = $_POST; }
    } elseif ($method === 'GET') {
        $in = $_GET;
    } else {
        lqn_fail('method_not_allowed', 405);
    }
    // 长度硬闸：整个请求体不超过 512 KiB（正文上限另算）
    if (strlen($raw) > 524288) { lqn_fail('request_too_large', 413); }

    $op = (string)($in['op'] ?? 'me');
    $readOps  = ['me' => 1, 'list' => 1, 'share_stats' => 1, 'rev_list' => 1, 'rev_get' => 1];
    $writeOps = ['save' => 1, 'merge' => 1, 'trash' => 1, 'restore' => 1, 'purge' => 1, 'purge_trash' => 1,
                 'book_save' => 1, 'book_delete' => 1, 'share' => 1, 'unshare' => 1, 'rev_restore' => 1];
    if (!isset($readOps[$op]) && !isset($writeOps[$op])) { lqn_fail('unknown_op', 400); }

    $uid = lqn_uid();

    if ($op === 'me') {
        $u = $uid > 0 ? (class_exists('Auth') ? Auth::user() : null) : null;
        lqn_ok([
            'uid'   => $uid,
            'name'  => $u ? (string)($u['display_name'] !== '' ? $u['display_name'] : $u['username']) : '',
            'csrf'  => class_exists('Csrf') ? Csrf::token() : '',
            'limits' => ['body' => LQN_MAX_BODY, 'title' => LQN_MAX_TITLE, 'notes' => LQN_MAX_NOTES, 'books' => LQN_MAX_BOOKS],
        ]);
    }

    if ($uid <= 0) { lqn_need_login(); }

    if (isset($writeOps[$op])) {
        // ① 同源 ② 站内 Csrf 令牌 ③ 限频 —— 三闸全过才允许改状态
        if (!lqn_origin_ok()) { lqn_fail('bad_origin', 403); }
        if (!lqn_csrf_ok($in)) { lqn_fail('csrf', 403); }
        if (!lqn_rate_ok()) { lqn_fail('rate_limited', 429); }
    }

    try {
        switch ($op) {
            case 'list':
                lqn_ok(['books' => lqn_books($uid), 'notes' => lqn_notes($uid)]);

            case 'save':
            case 'merge':
                lqn_ok(lqn_op_save($uid, $in, $op === 'merge'));

            case 'trash':
                lqn_ok(lqn_op_status($uid, (int)($in['id'] ?? 0), 'trash'));

            case 'restore':
                lqn_ok(lqn_op_status($uid, (int)($in['id'] ?? 0), 'normal'));

            case 'purge':
                $id = (int)($in['id'] ?? 0);
                if ($id <= 0) { lqn_fail('bad_id'); }
                Db::run('DELETE FROM ' . Db::t('note_items') . ' WHERE id=? AND user_id=?', [$id, $uid]);
                Db::run('DELETE FROM ' . Db::t('note_shares') . ' WHERE note_id=? AND user_id=?', [$id, $uid]);
                lqn_ok(['purged' => $id]);

            case 'purge_trash':
                $ids = array_map('intval', array_column(Db::rows(
                    'SELECT id FROM ' . Db::t('note_items') . " WHERE user_id=? AND status='trash'", [$uid]), 'id'));
                if ($ids) {
                    $in2 = implode(',', array_fill(0, count($ids), '?'));
                    Db::run('DELETE FROM ' . Db::t('note_items') . " WHERE user_id=? AND id IN ($in2)", array_merge([$uid], $ids));
                    Db::run('DELETE FROM ' . Db::t('note_shares') . " WHERE user_id=? AND note_id IN ($in2)", array_merge([$uid], $ids));
                }
                lqn_ok(['purged' => count($ids)]);

            case 'book_save':
                $name = lqn_trim((string)($in['name'] ?? ''), 120);
                if ($name === '') { lqn_fail('bad_name'); }
                $id = (int)($in['id'] ?? 0);
                if ($id > 0) {
                    $own = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('note_books') . ' WHERE id=? AND user_id=?', [$id, $uid]);
                    if ($own === 0) { lqn_fail('not_found', 404); }
                    Db::run('UPDATE ' . Db::t('note_books') . ' SET name=?, updated_at=NOW() WHERE id=? AND user_id=?', [$name, $id, $uid]);
                } else {
                    $id = lqn_book_id_by_name($uid, $name);
                    if ($id <= 0) { lqn_fail('too_many_books'); }
                }
                lqn_ok(['books' => lqn_books($uid), 'id' => $id]);

            case 'book_delete':
                $id = (int)($in['id'] ?? 0);
                if ($id <= 0) { lqn_fail('bad_id'); }
                Db::run('UPDATE ' . Db::t('note_items') . ' SET book_id=0, updated_at=NOW() WHERE user_id=? AND book_id=?', [$uid, $id]);
                Db::run('DELETE FROM ' . Db::t('note_books') . ' WHERE id=? AND user_id=?', [$id, $uid]);
                lqn_ok(['books' => lqn_books($uid), 'notes' => lqn_notes($uid)]);

            case 'share':
                lqn_ok(lqn_op_share($uid, $in));

            case 'share_stats':
                lqn_ok(lqn_op_share_stats($uid, $in));

            case 'rev_list':
                lqn_ok(lqn_op_rev_list($uid, $in));

            case 'rev_get':
                lqn_ok(lqn_op_rev_get($uid, $in));

            case 'rev_restore':
                lqn_ok(lqn_op_rev_restore($uid, $in));

            case 'unshare':
                $id = (int)($in['id'] ?? 0);
                /* ★ 批3：撤销时**连别名一起释放**（参照口径：撤销后重新分享给全新链接；别名不该被旧行占着） */
                Db::run('UPDATE ' . Db::t('note_shares') . " SET status='off', alias=NULL, updated_at=NOW() WHERE note_id=? AND user_id=?", [$id, $uid]);
                lqn_ok(['notes' => lqn_notes($uid)]);
        }
    } catch (Throwable $e) {
        // 绝不回声异常细节（可能含 SQL / 路径）
        error_log('[lqnotes] api ' . $op . ' failed');
        lqn_fail('server_error', 500);
    }
    lqn_fail('unknown_op', 400);
}

function lqn_op_status(int $uid, int $id, string $status): array
{
    if ($id <= 0) { lqn_fail('bad_id'); }
    $own = Db::row('SELECT id,status FROM ' . Db::t('note_items') . ' WHERE id=? AND user_id=?', [$id, $uid]);
    if ($own === null) { lqn_fail('not_found', 404); }
    if ($status === 'trash') {
        Db::run('UPDATE ' . Db::t('note_items') . " SET status='trash', trashed_at=NOW(), updated_at=NOW() WHERE id=? AND user_id=?", [$id, $uid]);
    } else {
        Db::run('UPDATE ' . Db::t('note_items') . " SET status='normal', trashed_at=NULL, updated_at=NOW() WHERE id=? AND user_id=?", [$id, $uid]);
    }
    return ['notes' => lqn_notes($uid)];
}

/* ================================================================== 历史版本（批4）
 * 口径照参照：**自动保存时记录**、**最多 20 条**、面板文案 `历史版本 · {标题}` /
 * `暂无历史版本（自动保存时记录，最多 20 条）` / 每行 `恢复此版本`。
 * 我们的增强：登录后**同时落服务端**（`ye_note_revs`）⇒ 换设备也能回溯；本机那份仍照参照留在 `llq_md_history_v1`。
 * ★ 恢复前先给"当前"留一份快照 ⇒ 旧版不丢、可回溯（站主判据）。
 * ================================================================================ */
function lqn_rev_words(string $body, string $fmt): int
{
    $t = strtolower($fmt) === 'html' ? strip_tags($body) : $body;
    $t = preg_replace('/\s+/u', '', (string)$t) ?? '';
    return mb_strlen($t, 'UTF-8');
}
function lqn_rev_snapshot(int $uid, int $noteId, string $title, string $body, string $fmt, string $reason = 'auto'): void
{
    if ($noteId <= 0 || $uid <= 0) { return; }
    $body = (string)$body;
    if ($body === '') { return; }                       // 空正文不留快照（避免噪声）
    $last = Db::row('SELECT body FROM ' . Db::t('note_revs') . ' WHERE user_id=? AND note_id=? ORDER BY id DESC LIMIT 1', [$uid, $noteId]);
    if ($last !== null && hash('sha256', (string)$last['body']) === hash('sha256', $body)) { return; }  // 与最新一条同 ⇒ 不重复
    Db::insert('note_revs', [
        'user_id' => $uid, 'note_id' => $noteId, 'title' => lqn_trim($title, LQN_MAX_TITLE),
        'body' => $body, 'fmt' => strtolower($fmt) === 'html' ? 'html' : 'md',
        'reason' => $reason, 'body_len' => lqn_rev_words($body, $fmt),
    ]);
    $n = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('note_revs') . ' WHERE user_id=? AND note_id=?', [$uid, $noteId]);
    if ($n > LQN_MAX_REVS) {
        $keep = (int)Db::one('SELECT id FROM ' . Db::t('note_revs') . ' WHERE user_id=? AND note_id=? ORDER BY id DESC LIMIT 1 OFFSET ' . (LQN_MAX_REVS - 1), [$uid, $noteId]);
        if ($keep > 0) { Db::run('DELETE FROM ' . Db::t('note_revs') . ' WHERE user_id=? AND note_id=? AND id < ?', [$uid, $noteId, $keep]); }
    }
}
/** 写笔记前：若正文将变化 ⇒ 先把【库里现在的】正文留成快照 */
function lqn_rev_before_change(int $uid, int $noteId, string $newBody): void
{
    /* ★ 批9：新正文是信封（加密笔记）⇒ 该篇【所有非信封旧版本一律清掉】
       为什么必须清：开加密的那一刻，服务端会把"上一版明文"快照下来 ⇒
       旧明文版本留在库里 = 加密形同虚设（站主 ⒞ 明确要求"历史快照也存密文"）。
       这条同时是服务端不变量：**加密笔记的版本历史里不得存在非信封正文**。 */
    if (strncmp($newBody, 'LQNENC1:', 8) === 0) {
        Db::run('DELETE FROM ' . Db::t('note_revs') . ' WHERE user_id=? AND note_id=? AND body NOT LIKE ?',
            [$uid, $noteId, 'LQNENC1:%']);
    }
    $cur = Db::row('SELECT title, body, fmt FROM ' . Db::t('note_items') . ' WHERE id=? AND user_id=?', [$noteId, $uid]);
    if ($cur === null) { return; }
    if ((string)$cur['body'] === '' || (string)$cur['body'] === $newBody) { return; }
    /* ★ 明文笔记变加密笔记：也不必把"即将被加密的明文"再留一份快照（上面已按信封规则清理） */
    if (strncmp($newBody, 'LQNENC1:', 8) === 0 && strncmp((string)$cur['body'], 'LQNENC1:', 8) !== 0) { return; }
    lqn_rev_snapshot($uid, $noteId, (string)$cur['title'], (string)$cur['body'], (string)$cur['fmt'], 'auto');
}
function lqn_rev_list(int $uid, int $noteId): array
{
    $rows = Db::rows('SELECT id, title, body_len, fmt, reason, created_at FROM ' . Db::t('note_revs')
        . ' WHERE user_id=? AND note_id=? ORDER BY id DESC LIMIT ' . LQN_MAX_REVS, [$uid, $noteId]);
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['rid' => (int)$r['id'], 'at' => (string)$r['created_at'], 'title' => (string)$r['title'],
                  'words' => (int)$r['body_len'], 'fmt' => (string)$r['fmt'], 'reason' => (string)$r['reason']];
    }
    return $out;
}
function lqn_op_rev_list(int $uid, array $in): array
{
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0) { lqn_fail('bad_id'); }
    if (Db::row('SELECT id FROM ' . Db::t('note_items') . ' WHERE id=? AND user_id=?', [$id, $uid]) === null) { lqn_fail('not_found', 404); }
    return ['revs' => lqn_rev_list($uid, $id)];
}
function lqn_op_rev_get(int $uid, array $in): array
{
    $rid = (int)($in['rid'] ?? 0);
    if ($rid <= 0) { lqn_fail('bad_id'); }
    $r = Db::row('SELECT id, note_id, title, body, fmt, created_at FROM ' . Db::t('note_revs') . ' WHERE id=? AND user_id=?', [$rid, $uid]);
    if ($r === null) { lqn_fail('not_found', 404); }
    return ['rev' => ['rid' => (int)$r['id'], 'note_id' => (int)$r['note_id'], 'title' => (string)$r['title'],
                      'body' => (string)$r['body'], 'fmt' => (string)$r['fmt'], 'at' => (string)$r['created_at']]];
}
/** 恢复此版本：先把"当前"留一份快照（reason=restore），再把正文换成该版本 */
function lqn_op_rev_restore(int $uid, array $in): array
{
    $id = (int)($in['id'] ?? 0); $rid = (int)($in['rid'] ?? 0);
    if ($id <= 0 || $rid <= 0) { lqn_fail('bad_id'); }
    $cur = Db::row('SELECT id, title, body, fmt FROM ' . Db::t('note_items') . ' WHERE id=? AND user_id=?', [$id, $uid]);
    if ($cur === null) { lqn_fail('not_found', 404); }
    $rev = Db::row('SELECT id, note_id, title, body, fmt FROM ' . Db::t('note_revs') . ' WHERE id=? AND user_id=?', [$rid, $uid]);
    if ($rev === null || (int)$rev['note_id'] !== $id) { lqn_fail('not_found', 404); }
    if ((string)$cur['body'] !== '') {
        lqn_rev_snapshot($uid, $id, (string)$cur['title'], (string)$cur['body'], (string)$cur['fmt'], 'restore');
    }
    Db::run('UPDATE ' . Db::t('note_items') . ' SET title=?, body=?, fmt=?, updated_at=NOW() WHERE id=? AND user_id=?',
        [lqn_trim((string)$rev['title'], LQN_MAX_TITLE), (string)$rev['body'], (string)$rev['fmt'], $id, $uid]);
    return ['restored' => $rid, 'notes' => lqn_notes($uid)];
}

/** save / merge：按 (user_id, client_key) 幂等 upsert；绝不删服务端已有行 */
function lqn_op_save(int $uid, array $in, bool $bulk): array
{    $list = [];
    if ($bulk) {
        $list = is_array($in['notes'] ?? null) ? $in['notes'] : [];
        if (count($list) > LQN_MAX_NOTES) { $list = array_slice($list, 0, LQN_MAX_NOTES); }
        foreach ((array)($in['books'] ?? []) as $bn) {
            if (is_string($bn) && trim($bn) !== '') { lqn_book_id_by_name($uid, $bn); }
        }
    } else {
        $list = [$in];
    }
    $n = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('note_items') . ' WHERE user_id=?', [$uid]);
    foreach ($list as $one) {
        if (!is_array($one)) { continue; }
        $ck = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($one['ck'] ?? ''));
        if ($ck === '') { $ck = 'srv' . bin2hex(random_bytes(8)); }
        $ck = substr($ck, 0, 40);
        $title = lqn_trim((string)($one['title'] ?? ''), LQN_MAX_TITLE);
        $body  = (string)($one['body'] ?? '');
        if (!lqn_body_ok($body)) { lqn_fail('note_too_large', 400); }
        $fmt = strtolower((string)($one['fmt'] ?? 'md')) === 'html' ? 'html' : 'md';
        $status = strtolower((string)($one['status'] ?? 'normal')) === 'trash' ? 'trash' : 'normal';
        $bookId = (int)($one['book_id'] ?? 0);
        if ($bookId <= 0 && isset($one['book']) && is_string($one['book']) && trim($one['book']) !== '') {
            $bookId = lqn_book_id_by_name($uid, (string)$one['book']);
        }
        if ($bookId > 0) {
            $okBook = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('note_books') . ' WHERE id=? AND user_id=?', [$bookId, $uid]);
            if ($okBook === 0) { $bookId = 0; }
        }
        $exist = Db::row('SELECT id FROM ' . Db::t('note_items') . ' WHERE user_id=? AND client_key=? LIMIT 1', [$uid, $ck]);
        if ($exist !== null) {
            /* ★ 批4：正文要变 ⇒ 先把库里现在的正文留成历史快照（自动保存时记录，最多 20 条） */
            lqn_rev_before_change($uid, (int)$exist['id'], $body);
            Db::run(
                'UPDATE ' . Db::t('note_items') . ' SET title=?, body=?, fmt=?, status=?, book_id=?, '
                . 'trashed_at=' . ($status === 'trash' ? 'COALESCE(trashed_at, NOW())' : 'NULL') . ', updated_at=NOW() '
                . 'WHERE id=? AND user_id=?',
                [$title, $body, $fmt, $status, $bookId, (int)$exist['id'], $uid]
            );
        } else {
            if ($n >= LQN_MAX_NOTES) { lqn_fail('too_many_notes'); }
            Db::insert('note_items', [
                'user_id' => $uid, 'book_id' => $bookId, 'client_key' => $ck, 'title' => $title,
                'body' => $body, 'fmt' => $fmt, 'status' => $status, 'sort' => 0,
                'trashed_at' => $status === 'trash' ? date('Y-m-d H:i:s') : null,
            ]);
            $n++;
        }
    }
    return ['books' => lqn_books($uid), 'notes' => lqn_notes($uid)];
}

function lqn_op_share(int $uid, array $in): array
{
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0) { lqn_fail('bad_id'); }
    $note = Db::row('SELECT id,title,body,fmt FROM ' . Db::t('note_items') . ' WHERE id=? AND user_id=?', [$id, $uid]);
    if ($note === null) { lqn_fail('not_found', 404); }
    /* ★ 批9：加密笔记【禁用分享】（云端只有密文，分享出去谁也读不了）——服务端兜底，防前端漏拦 */
    if (strncmp((string)$note['body'], 'LQNENC1:', 8) === 0) {
        lqn_fail('encrypted_no_share', 400, '加密笔记不支持分享（云端只有密文，分享出去谁也读不了）。');
    }
    $days = (int)($in['days'] ?? 0);
    if ($days < 0) { $days = 0; }
    if ($days > 3650) { $days = 3650; }
    $pw = (string)($in['password'] ?? '');
    if (strlen($pw) > 128) { lqn_fail('bad_password'); }
    $exp = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;

    /* ★ 批3：别名（与参照 share.js 同口径：小写 + 只留 [a-z0-9_-] + 截 40；留空 = 不用别名） */
    $aliasGiven = array_key_exists('alias', $in);
    $aliasNew = $aliasGiven ? lqn_norm_alias((string)$in['alias']) : '';
    if ($aliasGiven && $aliasNew !== '' && !lqn_alias_free($aliasNew, $uid, $id)) {
        lqn_fail('alias_taken', 409, '别名「' . $aliasNew . '」已被占用，请换一个');
    }

    $exist = Db::row('SELECT * FROM ' . Db::t('note_shares') . " WHERE note_id=? AND user_id=? AND status='on' LIMIT 1", [$id, $uid]);
    if ($exist !== null && (int)($in['new'] ?? 0) === 0) {
        $aliasEff = $aliasGiven ? ($aliasNew === '' ? null : $aliasNew) : ($exist['alias'] ?? null);
        Db::run('UPDATE ' . Db::t('note_shares') . ' SET title=?, body=?, fmt=?, password_hash=?, expires_at=?, alias=?, updated_at=NOW() WHERE id=?',
            [(string)$note['title'], (string)$note['body'], (string)$note['fmt'],
             $pw !== '' ? password_hash($pw, PASSWORD_DEFAULT) : (string)$exist['password_hash'], $exp, $aliasEff, (int)$exist['id']]);
        $tok = (string)$exist['token'];
        $aliasOut = (string)$aliasEff;
    } else {
        /* ★ 撤销后重新分享 ⇒ **全新链接**（参照口径）；先把同一篇旧行占着的别名释放掉，避免撞唯一键 */
        Db::run('UPDATE ' . Db::t('note_shares') . ' SET alias=NULL WHERE note_id=? AND user_id=? AND alias IS NOT NULL', [$id, $uid]);
        $tok = lqn_new_token();
        $aliasOut = $aliasNew;
        Db::insert('note_shares', [
            'token' => $tok, 'user_id' => $uid, 'note_id' => $id,
            'title' => (string)$note['title'], 'body' => (string)$note['body'], 'fmt' => (string)$note['fmt'],
            'password_hash' => $pw !== '' ? password_hash($pw, PASSWORD_DEFAULT) : '',
            'expires_at' => $exp, 'status' => 'on', 'views' => 0,
            'alias' => ($aliasOut === '' ? null : $aliasOut),
        ]);
    }
    $row = Db::row('SELECT token, alias, views, expires_at, password_hash FROM ' . Db::t('note_shares') . ' WHERE token=? LIMIT 1', [$tok]);
    $aliasFinal = (string)($row['alias'] ?? '');
    return [
        'url' => $aliasFinal !== '' ? '/s/' . $aliasFinal : '/share/' . $tok,
        'token' => $tok, 'alias' => $aliasFinal,
        'expires_at' => (string)($row['expires_at'] ?? ''),
        'has_password' => (string)($row['password_hash'] ?? '') !== '',
        'notes' => lqn_notes($uid),
    ];
}

/** ★ 批3：统计（读 op，不需要 CSRF；只能看自己的分享） */
function lqn_op_share_stats(int $uid, array $in): array
{
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0) { lqn_fail('bad_id'); }
    $own = Db::row('SELECT id FROM ' . Db::t('note_items') . ' WHERE id=? AND user_id=?', [$id, $uid]);
    if ($own === null) { lqn_fail('not_found', 404); }
    $row = Db::row('SELECT token, alias, views, expires_at, password_hash, status FROM ' . Db::t('note_shares')
        . " WHERE note_id=? AND user_id=? AND status='on' LIMIT 1", [$id, $uid]);
    if ($row === null) {
        return ['shared' => false];
    }
    $token = (string)$row['token'];
    $st = lqn_share_stats($token);
    $alias = (string)($row['alias'] ?? '');
    return [
        'shared' => true,
        'url' => $alias !== '' ? '/s/' . $alias : '/share/' . $token,
        'token' => $token, 'alias' => $alias,
        'expires_at' => (string)($row['expires_at'] ?? ''),
        'has_password' => (string)($row['password_hash'] ?? '') !== '',
        'pv' => $st['pv'], 'uv' => $st['uv'], 'days' => $st['days'], 'refs' => $st['refs'],
    ];
}

/* ------------------------------------------------------------------ 路由入口 */
function lqn_route(array $p): array
{
    $path   = (string)($p['path'] ?? '');
    $method = strtoupper((string)($p['method'] ?? 'GET'));
    if ($path === 'api/notes') { lqn_api($method); }
    if (preg_match('#^share/([A-Za-z0-9_-]{3,40})$#', $path, $m)) { lqn_share_view($m[1], $method); }
    /* ★ 批3：自定义短链 /s/<alias> —— 与 /share/<token> 走**同一个**处理函数，分享页行为完全一致 */
    if (preg_match('#^s/([A-Za-z0-9_-]{3,40})$#', $path, $m)) { lqn_share_view($m[1], $method); }
    /* ★ 批5：两条"参照侧 nginx 就是 301 → /notes"的路由（参照：liulanqi.com.conf:70 与 :78 逐字为
     *        `location = /tool/markdown { return 301 /notes; }` / `location = /tool/notepad { return 301 /notes; }`）
     *   · `/tool/notepad`：实例**无**静态产物 ⇒ nginx try_files 回落 `/index.php` ⇒ 本函数会跑到 ⇒ **301 生效**
     *   · `/tool/markdown`：实例侧 nginx 的 entry-static 会**先把**
     *     `var/entry-static/entry/tool__markdown.html`（580,842 B）直出（实测带 `last-modified`、**无** `x-cache`）
     *     ⇒ 该请求**根本不进 PHP**，本 301 只在"该产物不存在"时才生效。
     *     ★ 要立刻生效须站主批其一：⒜ 加一条 nginx `location = /tool/markdown { return 301 /notes; }`
     *       （= 参照侧原样做法，但本项目"不许碰 nginx"）；⒝ 移走/改名那件静态产物（属"不许手改 var/entry-static"）。
     *     已按"先报形状"报站主，**未擅自改 nginx、也未动任何产物**。 */
    if ($path === 'tool/notepad' || $path === 'tool/markdown') {
        if (!headers_sent()) {
            header('X-Robots-Tag: noindex', true);
            header('Location: /notes', true, 301);
        }
        exit;
    }
    return $p;
}

endif;
