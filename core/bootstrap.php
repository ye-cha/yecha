<?php
declare(strict_types=1);

/** bootstrap：会话 / 错误 / 自动加载 / 语言 / 选项缓存。调用前提：config.inc.php 已存在 */

require __DIR__ . '/helpers.php';
require_once __DIR__ . '/version.php';

error_reporting(cfg('DEBUG', false) ? E_ALL : E_ALL & ~E_DEPRECATED);
ini_set('display_errors', cfg('DEBUG', false) ? '1' : '0');
if (!cfg('DEBUG', false)) {
    ini_set('log_errors', '1');
    ini_set('error_log', dirname(__DIR__) . '/var/log/php-error.log');
}
date_default_timezone_set(cfg('TZ', 'Asia/Shanghai'));

// 会话安全属性
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Lang::load(cfg('SITE_LANG', 'zh_CN'));

/* ---------- options KV 缓存（autoload=1 载入内存） ---------- */
$GLOBALS['YE_OPTIONS'] = [];
function load_options(): void
{
    if (!empty($GLOBALS['YE_OPTIONS_LOADED'])) { return; }
    $GLOBALS['YE_OPTIONS_LOADED'] = true;
    try {
        $rows = Db::rows('SELECT name, value FROM ' . Db::t('options') . ' WHERE autoload=1');
    } catch (Throwable $e) {
        $rows = [];
    }
    foreach ($rows as $r) {
        $GLOBALS['YE_OPTIONS'][$r['name']] = $r['value'];
    }
}

function get_option(string $name, mixed $default = null): mixed
{
    load_options();
    return $GLOBALS['YE_OPTIONS'][$name] ?? $default;
}

function set_option(string $name, mixed $value, int $autoload = 1): void
{
    $v = is_scalar($value) || $value === null ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE);
    Db::deleteBy('options', 'name=?', [$name]);
    Db::insert('options', ['name' => $name, 'value' => $v, 'autoload' => $autoload]);
    $GLOBALS['YE_OPTIONS'][$name] = $v;
    Cache::flush('misc');
}

/* ---------- 站点基本值 ---------- */
function site_name(): string { return (string)get_option('site.name', 'YeCha'); }
function site_subtitle(): string { return (string)get_option('site.subtitle', '夜叉 · 极简博客'); }

/* ---------- 多语界面：站点名/副标题/日期按当前语言输出 ---------- */
function site_label(): string
{
    $s = Lang::short();
    if ($s === Poly::defaultLang() || $s === 'zh') { return site_name(); }
    $n = (string)get_option('site.name_' . $s, '');
    return $n !== '' ? $n : site_name();
}
function site_tagline(): string
{
    $s = Lang::short();
    if ($s === Poly::defaultLang() || $s === 'zh') { return site_subtitle(); }
    $t = (string)get_option('site.subtitle_' . $s, '');
    return $t !== '' ? $t : __('site.tagline');
}
/** 各语言月份名（lang_month 用） */
function lang_months(): array
{
    static $map = null;
    if ($map !== null) { return $map; }
    $s = Lang::short();
    switch ($s) {
        case 'es':
            $m = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
            break;
        case 'ko':
            $m = ['1월','2월','3월','4월','5월','6월','7월','8월','9월','10월','11월','12월'];
            break;
        case 'ja':
            $m = ['1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月'];
            break;
        case 'fr':
            $m = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
            break;
        case 'ru':
            $m = ['январь','февраль','март','апрель','май','июнь','июль','август','сентябрь','октябрь','ноябрь','декабрь'];
            break;
        case 'ar':
            $m = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
            break;
        default: // zh / tw / en
            $m = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            break;
    }
    return $map = $m;
}
/** 列表/文章日期：zh/tw 2026年08月12日 · ja 2026年8月12日 · ko 2026년 8월 12일 · es 12 de agosto de 2026 · en Aug 12, 2026 · fr 12 août 2026 · ru 12 августа 2026 · ar 12 أغسطس 2026 */
function lang_date(int|string $ts): string
{
    $ts = is_numeric($ts) ? (int)$ts : strtotime((string)$ts);
    $y = (int)date('Y', $ts); $m = (int)date('n', $ts); $d = (int)date('j', $ts);
    switch (Lang::short()) {
        case 'es': return $d . ' de ' . lang_months()[$m - 1] . ' de ' . $y;
        case 'ko': return $y . '년 ' . $m . '월 ' . $d . '일';
        case 'ja': return $y . '年' . $m . '月' . $d . '日';
        case 'en': return date('M j, Y', $ts);
        case 'fr': return $d . ' ' . lang_months()[$m - 1] . ' ' . $y;
        case 'ru': // 属格月份：12 августа 2026
            $gen = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
            return $d . ' ' . $gen[$m - 1] . ' ' . $y;
        case 'ar': return $d . ' ' . lang_months()[$m - 1] . ' ' . $y;
        default:   return $y . '年' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . '月' . str_pad((string)$d, 2, '0', STR_PAD_LEFT) . '日';
    }
}
/** 月份链接文字：zh/tw 2026年8月 · ja 2026年8月 · ko 2026년 8월 · es agosto de 2026 · en Aug 2026 · fr août 2026 · ru август 2026 · ar أغسطس 2026 */
function lang_month(int $y, int $m): string
{
    switch (Lang::short()) {
        case 'es': return lang_months()[$m - 1] . ' de ' . $y;
        case 'ko': return $y . '년 ' . $m . '월';
        case 'ja': return $y . '年' . $m . '月';
        case 'en': return date('M Y', mktime(0, 0, 0, $m, 1, $y));
        case 'fr': return lang_months()[$m - 1] . ' ' . $y;
        case 'ru': return lang_months()[$m - 1] . ' ' . $y;
        case 'ar': return lang_months()[$m - 1] . ' ' . $y;
        default:   return $y . '年' . $m . '月';
    }
}
/** 归档/侧栏月份项（含篇数）：en/fr/ru/ar 「month YYYY (n)」 · zh/tw/ja/ko/es「…（n）」 */
function lang_month_item(int $y, int $m, int $n): string
{
    return in_array(Lang::short(), ['en', 'fr', 'ru', 'ar'], true)
        ? lang_month($y, $m) . ' (' . $n . ')'
        : lang_month($y, $m) . '（' . $n . '）';
}
/** 当前界面语言的排版方向（阿拉伯语 rtl，其余 ltr） */
function ui_dir(): string
{
    $dir = Poly::catalog()[Lang::short()]['dir'] ?? 'ltr';
    return $dir === 'rtl' ? 'rtl' : 'ltr';
}

/* ---------- 多语界面：前台链接按当前语言加 /{code} 前缀（登录/注册/用户中心） ---------- */
function ui_lp(): string
{
    if (!Poly::on()) { return ''; }
    $s = Lang::short();
    return $s === Poly::defaultLang() ? '' : '/' . $s;
}
function ui_user(string $sub = ''): string { return ui_lp() . '/user' . ($sub !== '' ? '/' . $sub : ''); }
function ui_login(string $redirect = ''): string
{
    $u = ui_lp() . '/login';
    return $redirect !== '' ? $u . '?redirect=' . rawurlencode($redirect) : $u;
}
function ui_register(string $redirect = ''): string
{
    $u = ui_lp() . '/register';
    return $redirect !== '' ? $u . '?redirect=' . rawurlencode($redirect) : $u;
}
/** 语言切换：把当前页面路径转到指定语言（/tag/ ↔ /en/tag/、/文章.html ↔ /en/文章.html…）
 * 先剥离路径上任意已知语言前缀，再补目标语言前缀，避免 /en/en/… 重复；保留 query（如 ?q=、?page=） */
function ui_lang_url(string $code): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?? '/';
    $default = Poly::defaultLang();
    $known = Poly::enabled();
    $seg = ltrim($path, '/');
    $first = ($pos = strpos($seg, '/')) !== false ? substr($seg, 0, $pos) : $seg;
    if ($first !== '' && in_array($first, $known, true)) {
        $rest = $pos === false ? '/' : '/' . ltrim(substr($seg, $pos), '/');
    } else {
        $rest = $path === '/' ? '/' : '/' . ltrim($seg, '/');
    }
    $out = $code === $default ? ($rest === '/' ? '/' : $rest) : '/' . $code . $rest;
    // 保留原始 query（语言切换不丢搜索词/翻页）
    if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '') {
        parse_str($_SERVER['QUERY_STRING'], $qv);
        unset($qv['__lang'], $qv['lang']);
        if ($qv) { $out .= '?' . http_build_query($qv); }
    }
    return $out;
}
/** 兼容：zh↔en 单一切换（其它语言/更多语言请用 ui_lang_url 循环） */
function ui_other_lang_url(): string
{
    return ui_lang_url(Lang::short() === 'en' ? Poly::defaultLang() : 'en');
}

/** 统计代码读取：归一历史双重转义（字面 \n/\r → 真实换行），防异常内容破坏页面内联脚本 */
function stat_html(string $key): string
{
    $v = (string)get_option($key, '');
    return $v === '' ? '' : str_replace(["\\n", "\\r"], ["\n", "\r"], $v);
}

/* ---------- 主题可配置项（后台「主题」页） ---------- */
function theme_opt(string $key, mixed $def = null): mixed{
    $raw = get_option('theme.options', null);
    $arr = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    return (is_array($arr) && array_key_exists($key, $arr)) ? $arr[$key] : $def;
}
function theme_opt_set(array $kv): void
{
    $raw = get_option('theme.options', '{}');
    $arr = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    foreach ($kv as $k => $v) { $arr[$k] = $v; }
    set_option('theme.options', json_encode($arr, JSON_UNESCAPED_UNICODE));
}

/* ---------- 多语默认：zh(默认) + en(English)，AI 接口默认 DeepSeek（无 key 仅人工翻译） ---------- */
function poly_defaults(): void
{
    // 语言：确保含 zh 与 en（英文默认开通；已在运行的站会就地补上）
    $raw = get_option('site.languages', null);
    $list = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    if (!is_array($list) || !in_array('en', $list, true)) {
        set_option('site.languages', json_encode(['zh', 'en'], JSON_UNESCAPED_UNICODE));
    }
    if (get_option('site.default_lang', null) === null) {
        set_option('site.default_lang', 'zh');
    }
    foreach (['ai.base' => 'https://api.deepseek.com/chat/completions', 'ai.model' => 'deepseek-chat', 'ai.key' => '', 'ai.protect' => 'YeCha,夜叉'] as $k => $v) {
        if (get_option($k, null) === null) { set_option($k, $v); }
    }
    // P-4 评论防骚扰默认值
    if (get_option('comment.blacklist', null) === null) {
        set_option('comment.blacklist', "博彩\n代开发票\n加微信\n兼职刷单\n私聊我");
    }
    if (get_option('comment.max_links', null) === null) { set_option('comment.max_links', '3'); }
    if (get_option('comment.banned', null) === null) { set_option('comment.banned', ''); }
}
poly_defaults();

/* ---------- 安全响应头（前台与后台统一；CLI 下自动忽略） ---------- */
function send_security_headers(): void
{
    if (headers_sent() || PHP_SAPI === 'cli') { return; }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 1; mode=block');
}
send_security_headers();
