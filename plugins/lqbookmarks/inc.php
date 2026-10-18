<?php
declare(strict_types=1);
/**
 * lqbookmarks · 服务端逻辑（收藏/书签云同步 + 单条分享）
 * 口径全照参照：v2 结构 `{version:2,groups:[{key,label,items:[{id,name,url,desc,tags,starred,createdAt}]}]}`
 *              上限 `groups>50 || items>1000 ⇒ 400`，文案逐字 `书签超限：分组≤50、条目≤1000`
 *              结构不对 ⇒ `bookmarks 格式不正确`
 * ★ 隐私/安全：不直出用户 HTML；分享页只渲染**转义后的纯文本**；令牌随机；错误只回稳定错误码。
 */
if (!function_exists('lqb_route')):

if (!defined('LQB_MAX_GROUPS')) {
    define('LQB_MAX_GROUPS', 50);        // 照参照：分组 ≤50
    define('LQB_MAX_ITEMS', 1000);       // 照参照：条目 ≤1000
    define('LQB_MAX_BODY', 2097152);     // 请求体硬闸 2 MiB
    define('LQB_RATE_WIN', 60);
    define('LQB_RATE_MAX', 120);
}

/* ------------------------------------------------------------------ 基础输出 */
function lqb_headers(): void
{
    if (headers_sent()) { return; }
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}
function lqb_json(array $o, int $code = 200): void
{
    lqb_headers();
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
    http_response_code($code);
    echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function lqb_fail(string $code, int $http = 400, string $msg = ''): void
{
    $o = ['ok' => false, 'error' => $code];
    if ($msg !== '') { $o['msg'] = $msg; }
    lqb_json($o, $http);
}
function lqb_ok(array $extra = []): void
{
    lqb_json(['ok' => true] + $extra);
}

/* ------------------------------------------------------------------ 会话 / 鉴权（与 lqnotes 同法） */
function lqb_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || headers_sent()) { return; }
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/',
        'secure'   => (function_exists('yc_theme_scheme') ? yc_theme_scheme() === 'https' : true),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
    $GLOBALS['YC_SESSION_STARTED'] = true;
}
function lqb_uid(): int
{
    lqb_session();
    return class_exists('Auth') ? (int)Auth::id() : 0;
}
function lqb_need_login(): void { lqb_fail('login_required', 401); }
function lqb_origin_ok(): bool
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
function lqb_csrf_ok(array $in): bool
{
    lqb_session();
    $tok = (string)($in['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    return class_exists('Csrf') && Csrf::verify($tok);
}
function lqb_rate_ok(): bool
{
    if (!class_exists('Cache')) { return true; }
    $who = lqb_uid();
    $key = 'lqb:' . ($who > 0 ? 'u' . $who : 'i' . md5((string)($_SERVER['REMOTE_ADDR'] ?? '0')));
    $n = (int)Cache::incr('throttle', $key, LQB_RATE_WIN);
    return $n <= LQB_RATE_MAX;
}
function lqb_new_token(): string { return bin2hex(random_bytes(9)); }   // 18 位十六进制

/* ------------------------------------------------------------------ 书签库（v2 保形存取） */
/** 读出该用户的 v2 容器；没有则给空壳（与参照一致：version=2, groups=[]） */
function lqb_read(int $uid): array
{
    $raw = (string)Db::one('SELECT data FROM ' . Db::t('bm_sets') . ' WHERE user_id=? LIMIT 1', [$uid]);
    if ($raw === '') { return ['version' => 2, 'groups' => []]; }
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['groups']) || !is_array($j['groups'])) { return ['version' => 2, 'groups' => []]; }
    return ['version' => 2, 'groups' => $j['groups']];
}
function lqb_updated_at(int $uid): string
{
    return (string)Db::one('SELECT updated_at FROM ' . Db::t('bm_sets') . ' WHERE user_id=? LIMIT 1', [$uid]);
}
/** 整壳 JSON 原样入库（零损耗）；只做上限校验 */
function lqb_validate(array $b): array
{
    $groups = $b;
    if (isset($b['groups']) && is_array($b['groups'])) { $groups = $b['groups']; }
    if (!is_array($groups)) { return [null, 'bookmarks 格式不正确']; }
    $gc = count($groups);
    $ic = 0;
    foreach ($groups as $g) {
        if (!is_array($g)) { return [null, 'bookmarks 格式不正确']; }
        if (isset($g['items']) && is_array($g['items'])) { $ic += count($g['items']); }
    }
    if ($gc > LQB_MAX_GROUPS || $ic > LQB_MAX_ITEMS) { return [null, '书签超限：分组≤50、条目≤1000']; }
    return [['version' => 2, 'groups' => $groups], ''];
}
function lqb_write(int $uid, array $v2): void
{
    $json = json_encode($v2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { lqb_fail('encode_failed', 400); }
    Db::run(
        'INSERT INTO ' . Db::t('bm_sets') . ' (user_id, data, version, updated_at) VALUES (?,?,?,NOW()) '
        . 'ON DUPLICATE KEY UPDATE data=VALUES(data), version=VALUES(version), updated_at=NOW()',
        [$uid, $json, 2]
    );
}

/* ------------------------------------------------------------------ 分享（快照语义） */
function lqb_find_item(int $uid, string $itemId): ?array
{
    $b = lqb_read($uid);
    foreach ($b['groups'] as $g) {
        if (!isset($g['items']) || !is_array($g['items'])) { continue; }
        foreach ($g['items'] as $it) {
            if (is_array($it) && (string)($it['id'] ?? '') === $itemId) { return $it; }
        }
    }
    return null;
}
function lqb_op_share(int $uid, string $itemId, bool $on): array
{
    if ($itemId === '') { lqb_fail('bad_id'); }
    if (!$on) {
        Db::run('DELETE FROM ' . Db::t('bm_shares') . ' WHERE user_id=? AND item_id=?', [$uid, $itemId]);
        return ['shared' => false];
    }
    $it = lqb_find_item($uid, $itemId);
    if ($it === null) { lqb_fail('not_found', 404); }
    $row = Db::row('SELECT token FROM ' . Db::t('bm_shares') . ' WHERE user_id=? AND item_id=? LIMIT 1', [$uid, $itemId]);
    $tok = $row !== null ? (string)$row['token'] : lqb_new_token();
    Db::run(
        'INSERT INTO ' . Db::t('bm_shares') . ' (user_id, item_id, token, name, url, descr, created_at) VALUES (?,?,?,?,?,?,NOW()) '
        . 'ON DUPLICATE KEY UPDATE token=VALUES(token), name=VALUES(name), url=VALUES(url), descr=VALUES(descr)',
        [$uid, $itemId, $tok, (string)($it['name'] ?? ''), (string)($it['url'] ?? ''), (string)($it['desc'] ?? '')]
    );
    return ['shared' => true, 'token' => $tok, 'url' => '/b/' . $tok];
}
function lqb_share_page(string $title, string $inner, int $code = 200): void
{
    lqb_headers();
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('Referrer-Policy: no-referrer');
    }
    http_response_code($code);
    $css = htmlspecialchars(View::asset('lqwww/www.css'), ENT_QUOTES, 'UTF-8');
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<meta name="robots" content="noindex,nofollow,noarchive">'
        . '<title>' . $t . ' · liulanqi 书签</title>'
        . '<link rel="stylesheet" href="' . $css . '">'
        . '<style>.lqb-wrap{max-width:560px;margin:0 auto;padding:28px 16px 64px}'
        . '.lqb-card{border:1px solid hsl(var(--border));border-radius:14px;background:hsl(var(--card));padding:26px}'
        . '.lqb-card h1{font-size:20px;font-weight:700;margin:0 0 6px;word-break:break-word}'
        . '.lqb-meta{font-size:12.5px;color:hsl(var(--muted-foreground));margin:0 0 16px}'
        . '.lqb-url{display:block;font-size:14px;color:hsl(var(--primary));word-break:break-all;margin-bottom:14px}'
        . '.lqb-btn{display:inline-block;padding:9px 18px;border-radius:8px;background:hsl(var(--primary));'
        . 'color:hsl(var(--primary-foreground));text-decoration:none;font-size:13px}'
        . '.lqb-desc{font-size:13.5px;line-height:1.8;white-space:pre-wrap;word-break:break-word}</style>'
        . '</head><body><div class="lqb-wrap">' . $inner
        . '<p style="margin-top:20px;font-size:12px;color:hsl(var(--muted-foreground))">'
        . '本页是 liulanqi 书签的一条分享快照，未登录亦可阅读；页面标记 noindex，不会被搜索引擎收录。</p>'
        . '</div></body></html>';
    exit;
}
function lqb_share_view(string $token): void
{
    if (!preg_match('/^[A-Za-z0-9_-]{6,40}$/', $token)) { lqb_share_page('分享不存在', '', 404); }
    $r = Db::row('SELECT name, url, descr, created_at FROM ' . Db::t('bm_shares') . ' WHERE token=? LIMIT 1', [$token]);
    if ($r === null) {
        lqb_share_page('分享不存在', '<div class="lqb-card"><h1>分享不存在或已取消</h1>'
            . '<p class="lqb-meta">这条分享可能已被作者取消。</p></div>', 404);
    }
    $url = (string)$r['url'];
    $safe = preg_match('#^https?://#i', $url) ? htmlspecialchars($url, ENT_QUOTES, 'UTF-8') : '';
    $inner = '<div class="lqb-card"><h1>' . htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p class="lqb-meta">来自 liulanqi 书签 · 分享于 ' . htmlspecialchars((string)$r['created_at'], ENT_QUOTES, 'UTF-8') . '</p>'
        . ($safe !== '' ? '<a class="lqb-url" href="' . $safe . '" rel="noopener nofollow" target="_blank">' . $safe . '</a>'
                        : '<p class="lqb-meta">（这条书签没有可跳转的网址）</p>')
        . ((string)$r['descr'] !== '' ? '<div class="lqb-desc">' . htmlspecialchars((string)$r['descr'], ENT_QUOTES, 'UTF-8') . '</div>' : '')
        . '</div>';
    lqb_share_page((string)$r['name'], $inner, 200);
}

/* ------------------------------------------------------------------ API */
function lqb_api(string $method, array $seg): void
{
    lqb_session();
    $in = [];
    $raw = '';
    if ($method === 'PUT' || $method === 'POST') {
        $raw = (string)file_get_contents('php://input');
        if ($raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j)) { $in = $j; }
        }
        if (!$in && $_POST) { $in = $_POST; }
    }
    if (strlen($raw) > LQB_MAX_BODY) { lqb_fail('request_too_large', 413); }

    /* GET /api/auth/me —— 登录态自检（形态照参照：只回身份）
       ★ 批6步2b-2：一并回 csrf（同源、会话绑定；与云笔记那边同法）+ limits（前端做同口径本地校验用） */
    if ($seg === ['me']) {
        $uid = lqb_uid();
        if ($uid <= 0) { lqb_need_login(); }
        $u = class_exists('Auth') ? Auth::user() : null;
        lqb_ok(['uid' => $uid,
                'name' => $u ? (string)(($u['display_name'] ?? '') !== '' ? $u['display_name'] : ($u['username'] ?? '')) : '',
                'username' => $u ? (string)($u['username'] ?? '') : '',
                'csrf' => (class_exists('Csrf') ? (string)Csrf::token() : ''),
                /* ★ 批8-②：该账号是否还在用迁移时的合成占位邮箱（可选依赖 lqlegacy；插件不在则恒 false） */
                'email_synth' => (function_exists('lql_synth_email') ? lql_synth_email($uid) : false),
                'limits' => ['groups' => LQB_MAX_GROUPS, 'items' => LQB_MAX_ITEMS]]);
    }

    $uid = lqb_uid();
    if ($uid <= 0) { lqb_need_login(); }

    /* /api/auth/bookmarks/{id}/share（路由传 [itemId,'share'] ⇒ 2 段；★ 曾误写成 ===3 ⇒ 一路落到 404） */
    if (count($seg) === 2 && $seg[1] === 'share') {
        if ($method !== 'POST') { lqb_fail('method_not_allowed', 405); }
        if (!lqb_origin_ok()) { lqb_fail('bad_origin', 403); }
        if (!lqb_csrf_ok($in)) { lqb_fail('csrf', 403); }
        if (!lqb_rate_ok()) { lqb_fail('rate_limited', 429); }
        lqb_ok(lqb_op_share($uid, substr((string)$seg[0], 0, 60), !(isset($in['share']) && $in['share'] === false)));
    }

    /* /api/auth/bookmarks —— GET 读 / PUT 写 */
    if ($seg === []) {
        if ($method === 'GET') {
            lqb_ok(['bookmarks' => lqb_read($uid), 'updated_at' => lqb_updated_at($uid)]);
        }
        if ($method === 'PUT') {
            if (!lqb_origin_ok()) { lqb_fail('bad_origin', 403); }
            if (!lqb_csrf_ok($in)) { lqb_fail('csrf', 403); }
            if (!lqb_rate_ok()) { lqb_fail('rate_limited', 429); }
            $b = $in['bookmarks'] ?? null;
            if (!is_array($b)) { lqb_fail('bad_format', 400, 'bookmarks 格式不正确'); }
            [$v2, $err] = lqb_validate($b);
            if ($v2 === null) { lqb_fail('bad_format', 400, $err); }
            lqb_write($uid, $v2);
            lqb_ok(['updated_at' => lqb_updated_at($uid)]);
        }
        lqb_fail('method_not_allowed', 405);
    }
    lqb_fail('not_found', 404);
}

/* ------------------------------------------------------------------ 路由入口 */
function lqb_route(array $p): array
{
    $path   = (string)($p['path'] ?? '');
    $method = strtoupper((string)($p['method'] ?? 'GET'));

    if ($path === 'api/auth/bookmarks') { lqb_api($method, []); }
    if (preg_match('#^api/auth/bookmarks/([A-Za-z0-9_.\-]{1,60})/share$#', $path, $m)) { lqb_api($method, [$m[1], 'share']); }
    if ($path === 'api/auth/me') { lqb_api($method, ['me']); }
    if (preg_match('#^api/share/bookmark/([A-Za-z0-9_-]{6,40})$#', $path, $m)) {
        $r = Db::row('SELECT name, url, descr FROM ' . Db::t('bm_shares') . ' WHERE token=? LIMIT 1', [$m[1]]);
        if ($r === null) { lqb_fail('not_found', 404); }
        lqb_ok(['name' => (string)$r['name'], 'url' => (string)$r['url'], 'descr' => (string)$r['descr']]);
    }
    if (preg_match('#^b/([A-Za-z0-9_-]{3,40})$#', $path, $m)) { lqb_share_view($m[1]); }
    return $p;
}

endif;
