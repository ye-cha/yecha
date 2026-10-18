<?php
declare(strict_types=1);
/**
 * 邮件订阅 · 核心逻辑(自包含:不依赖核心 Mail 的发件人显示名扩展)
 */

function sub_t(string $key, string $lang): string
{
    $m = [
        'subject_confirm' => ['确认订阅', 'Confirm your subscription'],
        'body_confirm'    => [
            "你好：\n\n点击下面的链接确认订阅。确认后你会在每期收到本站最新内容。\n\n%LINK%\n\n如果不是你本人操作,忽略此邮件即可。\n—— %SITE%",
            "Hello,\n\nPlease confirm your subscription by clicking the link below.\n\n%LINK%\n\nIf this wasn't you, just ignore this email.\n— %SITE%",
        ],
        'subject_issue'   => ['本期内容', "This issue's stories"],
        'ok_pending'      => ['确认邮件已发送,请查收并点击确认链接。', 'Check your inbox and click the confirmation link.'],
        'ok_confirmed'    => ['订阅成功,感谢你。', 'You are subscribed. Thank you.'],
        'ok_unsub'        => ['已退订,不会再收到邮件。', 'Unsubscribed. You will not receive further emails.'],
        'err_email'       => ['邮箱格式不正确。', 'Please enter a valid email address.'],
        'err_spam'        => ['提交过于频繁,请稍后再试。', 'Too many attempts. Please try again later.'],
        'err_mail'        => ['确认邮件发送失败,请稍后再试。', 'Could not send the confirmation email. Please try again later.'],
    ];
    return $m[$key][$lang === 'en' ? 1 : 0] ?? $key;
}

function sub_table(): string { return Db::t('subscribers'); }

function sub_ensure_table(): void
{
    static $done = false;
    if ($done) { return; }
    Db::run("CREATE TABLE IF NOT EXISTS " . sub_table() . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(190) NOT NULL,
        token CHAR(32) NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        lang VARCHAR(8) NOT NULL DEFAULT 'zh',
        ip VARCHAR(45) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        confirmed_at DATETIME NULL,
        last_sent_at DATETIME NULL,
        PRIMARY KEY (id), UNIQUE KEY uk_email (email), KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** 站内语言(有 poly 用 poly,否则 zh) */
function sub_langs(): array
{
    if (class_exists('Poly') && Poly::on()) {
        $ls = Poly::enabled();
        if ($ls) { return array_values($ls); }
    }
    return [Poly::defaultLang() ?? 'zh'];
}

function sub_default_lang(): string
{
    return class_exists('Poly') ? (string)Poly::defaultLang() : 'zh';
}

function sub_lang(): string
{
    $s = class_exists('Lang') ? (string)Lang::short() : sub_default_lang();
    return $s !== '' ? $s : sub_default_lang();
}

function sub_base(string $lang): string
{
    return $lang === sub_default_lang() ? '' : '/' . $lang;
}

/** 发件人显示名:按语言取站点名 */
function sub_from_name(string $lang): string
{
    $name = '';
    if (class_exists('Poly') && class_exists('Lang')) {
        $saved = (string)Lang::short();
        try {
            Lang::switch(Poly::pack($lang));
            $name = trim((string)site_label());
            Lang::switch(Poly::pack($saved));
        } catch (Throwable $e) { $name = ''; }
    }
    if ($name === '') { $name = function_exists('site_label') ? (string)site_label() : 'Newsletter'; }
    return $name;
}

/** SMTP 配置(复用后台「邮件通知」) */
function sub_mail_conf(): array
{
    return [
        'host' => (string)get_option('mail.host', ''),
        'port' => (int)get_option('mail.port', 465),
        'ssl'  => (string)get_option('mail.ssl', '1') === '1',
        'user' => (string)get_option('mail.user', ''),
        'pass' => function_exists('yc_opt') ? (string)yc_opt('mail.pass', '') : (string)get_option('mail.pass', ''),
        'from' => (string)get_option('mail.from', ''),
    ];
}

/** 自包含 SMTP 发送(SSL / STARTTLS),From 支持显示名 */
function sub_smtp_send(string $to, string $subject, string $body, string $fromName = ''): bool
{
    $c = sub_mail_conf();
    if ($c['host'] === '' || $c['from'] === '') { return false; }
    $host = $c['host']; $port = $c['port']; $ssl = $c['ssl'];
    $remote = ($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { return false; }
    stream_set_timeout($fp, 20);
    $read = static function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 515)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') { break; }
        }
        return $out;
    };
    $cmd = static function (string $s) use ($fp, $read): string {
        fwrite($fp, $s . "\r\n");
        return $read();
    };
    $ok = static fn(string $r): bool => (bool)preg_match('/^(2|3)\d\d/', trim($r));
    if (!$ok($read())) { fclose($fp); return false; }
    $hostName = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $hostName = preg_replace('/:\d+$/', '', $hostName) ?: 'localhost';
    $ehlo = $cmd('EHLO ' . $hostName);
    if (!$ssl && str_contains($ehlo, 'STARTTLS')) {
        $cmd('STARTTLS');
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; }
        $ehlo = $cmd('EHLO ' . $hostName);
    }
    if ($c['user'] !== '') {
        $cmd('AUTH LOGIN');
        $cmd(base64_encode($c['user']));
        $r = $cmd(base64_encode($c['pass']));
        if (!$ok($r)) { fclose($fp); return false; }
    }
    if (!$ok($cmd('MAIL FROM:<' . $c['from'] . '>'))) { fclose($fp); return false; }
    if (!$ok($cmd('RCPT TO:<' . $to . '>'))) { fclose($fp); return false; }
    if (!$ok($cmd('DATA'))) { fclose($fp); return false; }
    $fromHeader = $fromName !== ''
        ? mb_encode_mimeheader($fromName, 'UTF-8', 'B') . ' <' . $c['from'] . '>'
        : $c['from'];
    $subject = str_replace(["\r", "\n"], '', $subject);
    $headers = 'From: ' . $fromHeader . "\r\n"
        . 'To: ' . $to . "\r\n"
        . 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8', 'B') . "\r\n"
        . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
    $data = str_replace("\n.", "\n..", str_replace("\r\n", "\n", $body));
    fwrite($fp, $headers . $data . "\r\n.\r\n");
    $okSend = $ok($read());
    $cmd('QUIT');
    fclose($fp);
    return $okSend;
}

function sub_send(string $to, string $subject, string $body, string $lang): bool
{
    return sub_smtp_send($to, $subject, $body, sub_from_name($lang));
}

/* ==================== 前台路由 ==================== */

function sub_front(): void
{
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $segs = array_values(array_filter(explode('/', trim($path, '/')), static fn($s) => $s !== ''));
    $lang = sub_lang();
    $enabled = sub_langs();
    if ($segs && in_array($segs[0], $enabled, true) && $segs[0] !== sub_default_lang()) { $lang = $segs[0]; array_shift($segs); }
    /* 本插件的钩子早于 index.php 的语言识别 → 自己把界面语言切到当前语言，
       否则 /en/subscribe 会用默认语言渲染顶栏/页脚（与 index.php 第 48 行同一处理） */
    if (class_exists('Lang') && class_exists('Poly')) {
        try { Lang::switch(Poly::pack($lang)); } catch (Throwable $e) { /* 非多语站忽略 */ }
    }
    $first = $segs[0] ?? '';
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($first === 'subscribe.html') { header('Location: ' . sub_base($lang) . '/subscribe', true, 301); exit; }
    if ($first !== 'subscribe') { return; }
    if (count($segs) === 1 && $method === 'GET') { sub_render_page($lang); return; }
    if (count($segs) === 1 && $method === 'POST') { sub_handle_post($lang); return; }
    if (count($segs) === 2 && $segs[1] === 'confirm') { sub_handle_confirm($lang); return; }
    if (count($segs) === 2 && $segs[1] === 'unsub') { sub_handle_unsub($lang); return; }
}

function sub_go(string $lang, string $qs): never
{
    header('Location: ' . sub_base($lang) . '/subscribe' . $qs, true, 302);
    exit;
}

/**
 * 取后台「订阅」独立页（slug=subscribe）的文案：按语言优先取译文；
 * 剔除页内嵌的原始 HTML（表单/脚本/提示块）——那些由插件自己渲染，避免重复与转义。
 */
function sub_page_copy(string $lang): array
{
    $out = ['title' => '', 'html' => ''];
    try {
        $row = Db::row('SELECT id, title, content FROM ' . Db::t('contents')
            . " WHERE slug='subscribe' AND type='page' AND deleted_at IS NULL ORDER BY id LIMIT 1");
        if (!$row) { return $out; }
        $title = (string)$row['title'];
        $md = (string)$row['content'];
        if (class_exists('Poly') && Poly::on()) {
            $tl = Db::row('SELECT title, content FROM ' . Db::t('poly_contents') . ' WHERE group_id=? AND lang=? ORDER BY id LIMIT 1',
                [(int)$row['id'], $lang]);
            if ($tl) {
                if (trim((string)$tl['title']) !== '') { $title = (string)$tl['title']; }
                if (trim((string)$tl['content']) !== '') { $md = (string)$tl['content']; }
            }
        }
        $md = (string)preg_replace('#<form\b.*?</form>#is', '', $md);
        $md = (string)preg_replace('#<script\b.*?</script>#is', '', $md);
        $md = (string)preg_replace('#<p\b[^>]*sub-note[^>]*>.*?</p>#is', '', $md);
        $md = (string)preg_replace('#<div\b[^>]*id="sub-msg"[^>]*>.*?</div>#is', '', $md);
        $md = trim(strip_tags($md));
        if ($md !== '') {
            $md = (string)preg_replace('/^\s*#{1,6}\s+[^\n]*\n+/u', '', $md, 1); // 标题由页面头显示
            $md = (string)preg_replace('/\n{3,}/', "\n\n", $md);
        }
        $out['title'] = trim($title);
        $out['html'] = $md === '' ? '' : Markdown::render($md);
    } catch (Throwable $e) { /* 退回内置文案 */ }
    return $out;
}

/** 订阅表单（用主题自带 .sub-form 组件；无主题样式时由兜底外壳提供同名样式） */
function sub_form_html(string $lang, string $base, string $cta): string
{
    return '<form class="sub-form" method="post" action="' . e($base . '/subscribe') . '">'
        . '<input type="email" name="email" required maxlength="120" placeholder="you@example.com" aria-label="Email">'
        . '<input type="hidden" name="lang" value="' . e($lang) . '">'
        . '<input class="hp" type="text" name="company" tabindex="-1" autocomplete="off" aria-hidden="true">'
        . '<button type="submit">' . e($cta) . '</button></form>';
}

/** 结果提示块（读取 ?ok=/?err= 并显示在 .sub-msg 里） */
function sub_msg_html(string $lang): string
{
    return '<div id="sub-msg" class="sub-msg" hidden></div>'
        . '<script>(function(){var p=new URLSearchParams(location.search);var q=p.get("ok")||p.get("err");if(!q)return;'
        . 'var e=document.getElementById("sub-msg");var m={pending:["' . sub_t('ok_pending', $lang) . '"],confirmed:["' . sub_t('ok_confirmed', $lang) . '"],'
        . 'unsub:["' . sub_t('ok_unsub', $lang) . '"],email:["' . sub_t('err_email', $lang) . '"],spam:["' . sub_t('err_spam', $lang) . '"],'
        . 'mail:["' . sub_t('err_mail', $lang) . '"]}[q];e.hidden=false;e.textContent=m?m[0]:q;})();</script>';
}

/** 右栏：与站点其它页同一套（主题 partials/sidebar.php + Front::sidebar() 数据，尊重页面布局开关） */
function sub_sidebar_html(): string
{
    try {
        $tp = (string)($GLOBALS['YC_SB_TYPE'] ?? 'post');
        $raw = json_decode((string)get_option('theme.sidebar', ''), true);
        $cur = is_array($raw) ? ($raw[$tp] ?? null) : null;
        $show = ($cur === null)
            ? ((string)theme_opt('show_sidebar', '1') !== '0')
            : ((string)($cur['show'] ?? '1') !== '0');
        if (!$show) { return ''; }
        $isDefLang = !(class_exists('Poly') && Poly::on() && Lang::short() !== Poly::defaultLang());
        if ($isDefLang) {
            $sb = Front::sidebar();
            return View::render('partials/sidebar', [
                'latest' => $sb['latest'] ?? [],
                'cats'   => $sb['cats'] ?? [],
                'tags'   => $sb['tags'] ?? [],
                'months' => $sb['months'] ?? [],
            ]);
        }
        return View::render('partials/sidebar-lang', ['posts' => Poly::publishedList(Lang::short(), (int)theme_opt('latest_n', 8))]);
    } catch (Throwable $e) { return ''; }
}

function sub_render_page(string $lang): void
{
    $en = $lang === 'en';
    $base = sub_base($lang);
    $name = sub_from_name($lang);
    $cta = $en ? 'Subscribe' : '订阅';
    $lead = $en ? 'One story a week. No noise. No agenda.' : '每周一则故事。无噪音，无立场。';
    $note = $en ? 'Unsubscribe anytime with one click.' : '可随时一键退订。';

    $copy = sub_page_copy($lang);
    $h1 = $copy['title'] !== '' ? $copy['title'] : $cta;
    $body = trim((string)$copy['html']) !== '' ? (string)$copy['html'] : '<p>' . e($lead) . '</p>';
    $title = $h1 . ' · ' . $name;

    /* 站点原生外壳：复用主题 layout（同宽容器/顶栏/页脚），页面结构对齐站点其它页（page-head + 正文 + 表单） */
    $inner = '<style>.sub-page .page-head{margin:0 0 2px}'
        . '.sub-page .sub-body{margin:0 0 16px}'
        . '.sub-page .sub-actions{max-width:560px}</style>'
        . '<div class="sub-page">'
        . '<div class="page-head"><h1>' . e($h1) . '</h1></div>'
        . '<div class="article-content sub-body">' . $body . '</div>'
        . '<div class="sub-actions">' . sub_form_html($lang, $base, $cta)
        . '<p class="sub-note">' . e($note) . '</p>' . sub_msg_html($lang) . '</div>'
        . '</div>';

    if (class_exists('View')) {
        try {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $GLOBALS['YC_CANONICAL'] = $scheme . '://' . (string)($_SERVER['HTTP_HOST'] ?? '') . $base . '/subscribe';
            header('Content-Type: text/html; charset=utf-8');
            echo View::render('layout', [
                'title' => $title,
                'main' => $inner,
                'side' => sub_sidebar_html(),
                'tocSide' => '',
                'sbSticky' => ((string)get_option('theme.sidebar_mode', 'flow') === 'sticky'),
                'htmlLang' => $en ? 'en' : 'zh-CN',
            ]);
            exit;
        } catch (Throwable $e) { /* 主题没有 layout.php（自定义主题）→ 用下面的自包含外壳 */ }
    }

    /* 兜底：自包含外壳（不依赖主题，样式取站点同款配色） */
    header('Content-Type: text/html; charset=utf-8');
    $iconL = '/themes/forest/icon-light.svg';
    $iconD = '/themes/forest/icon-dark.svg';
    echo '<!doctype html><html lang="' . ($en ? 'en' : 'zh-CN') . '"><head><meta charset="utf-8">'
      . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . '</title>'
      . '<style>body{margin:0;background:#fff;color:#1f2328;font:15px/1.7 -apple-system,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}'
      . 'html.dark body{background:#0d1117;color:#e6edf3}.wrap{max-width:1280px;margin:0 auto;padding:0 24px}'
      . '.hd{display:flex;align-items:center;gap:8px;padding:18px 0;border-bottom:1px solid #d0d7de}'
      . '.hd img{width:24px;height:24px}.hd b{font-size:16px}'
      . '.box{margin:28px 0 60px}h1{font-size:24px;margin:0 0 10px}'
      . '.page-head{padding:8px 0;border:0}'
      . '.article-content p{margin:0 0 1.1em}'
      . '.sub-form{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:14px 0 6px;max-width:520px}'
      . '.sub-form input[type=email]{flex:1 1 240px;min-width:200px;border:1px solid #d0d7de;border-radius:6px;padding:7px 12px;font:inherit;background:#fff;color:inherit}'
      . '.sub-form button{background:#1f883d;border:1px solid #1f883d;color:#fff;border-radius:6px;padding:7px 20px;font:inherit;font-weight:600;cursor:pointer}'
      . '.sub-form .hp{position:absolute;left:-9999px;width:1px;height:1px;opacity:0}'
      . '.sub-note{color:#6e7781;font-size:13px;margin:0 0 6px}'
      . '.sub-msg{border:1px solid #d0d7de;border-left:3px solid #1f883d;background:#f6f8fa;border-radius:0 6px 6px 0;padding:10px 14px;margin-top:12px;max-width:520px}'
      . '.ft{border-top:1px solid #d0d7de;padding:16px 0;color:#57606a;font-size:12px}'
      . '.only-dark{display:none}html.dark .only-light{display:none}html.dark .only-dark{display:inline-block}</style></head><body>'
      . '<div class="hd wrap"><img class="only-light" src="' . $iconL . '" alt=""><img class="only-dark" src="' . $iconD . '" alt="">'
      . '<b>' . e($name) . '</b></div>'
      . '<div class="wrap box">' . $inner . '</div>'
      . '<div class="ft wrap"><span>© ' . date('Y') . ' ' . e($name) . '</span></div>'
      . '</body></html>';
    exit;
}

function sub_handle_post(string $lang): void
{
    sub_ensure_table();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $want = (string)($_POST['lang'] ?? $lang);
    $lang = in_array($want, sub_langs(), true) ? $want : sub_default_lang();
    if (trim((string)($_POST['company'] ?? '')) !== '') { sub_go($lang, '?err=spam'); }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) { sub_go($lang, '?err=email'); }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $n = (int)Db::one('SELECT COUNT(*) FROM ' . sub_table() . ' WHERE ip=? AND created_at > (NOW() - INTERVAL 10 MINUTE)', [$ip]);
    if ($n >= 3) { sub_go($lang, '?err=spam'); }

    $row = Db::row('SELECT id,status,token FROM ' . sub_table() . ' WHERE email=? LIMIT 1', [$email]);
    if ($row && (string)$row['status'] === 'confirmed') { sub_go($lang, '?ok=confirmed'); }
    $token = $row ? (string)$row['token'] : bin2hex(random_bytes(16));
    if ($row) {
        Db::run('UPDATE ' . sub_table() . ' SET token=?, status=?, lang=?, ip=?, created_at=NOW() WHERE id=?',
            [$token, 'pending', $lang, $ip, (int)$row['id']]);
    } else {
        Db::run('INSERT INTO ' . sub_table() . ' (email,token,status,lang,ip,created_at) VALUES (?,?,?,?,?,NOW())',
            [$email, $token, 'pending', $lang, $ip]);
    }
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $link = 'https://' . $host . sub_base($lang) . '/subscribe/confirm?t=' . $token;
    $body = str_replace(['%LINK%', '%SITE%'], [$link, sub_from_name($lang)], sub_t('body_confirm', $lang));
    $subject = sub_t('subject_confirm', $lang) . ' · ' . sub_from_name($lang);
    if (!sub_send($email, $subject, $body, $lang)) { sub_go($lang, '?err=mail'); }
    sub_go($lang, '?ok=pending');
}

function sub_handle_confirm(string $lang): void
{
    sub_ensure_table();
    $t = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
    if ($t === '') { sub_go($lang, '?err=email'); }
    $row = Db::row('SELECT id,lang FROM ' . sub_table() . ' WHERE token=? LIMIT 1', [$t]);
    if (!$row) { sub_go($lang, '?err=email'); }
    Db::run('UPDATE ' . sub_table() . ' SET status=?, confirmed_at=NOW() WHERE id=?', ['confirmed', (int)$row['id']]);
    sub_go((string)$row['lang'] !== '' ? (string)$row['lang'] : $lang, '?ok=confirmed');
}

function sub_handle_unsub(string $lang): void
{
    sub_ensure_table();
    $t = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
    if ($t === '') { sub_go($lang, '?err=email'); }
    $row = Db::row('SELECT id,lang FROM ' . sub_table() . ' WHERE token=? LIMIT 1', [$t]);
    if (!$row) { sub_go($lang, '?err=email'); }
    Db::run('UPDATE ' . sub_table() . ' SET status=? WHERE id=?', ['unsub', (int)$row['id']]);
    sub_go((string)$row['lang'] !== '' ? (string)$row['lang'] : $lang, '?ok=unsub');
}

/* ==================== 周刊发送(后台 & CLI 共用) ==================== */

function sub_latest_items(string $lang, int $n = 3): array
{
    $C = Db::t('contents');
    $isDefault = $lang === sub_default_lang();
    if (!$isDefault && class_exists('Poly') && Poly::on()) {
        return Db::rows(
            'SELECT p.title, p.slug, c.published_at FROM ' . Db::t('poly_contents') . ' p JOIN ' . $C . ' c ON c.id=p.group_id '
            . 'WHERE p.lang=? AND p.status=\'publish\' AND c.type=\'post\' AND c.status=\'publish\' AND c.deleted_at IS NULL '
            . 'ORDER BY c.published_at DESC, c.id DESC LIMIT ' . (int)$n,
            [$lang]
        );
    }
    return Db::rows(
        'SELECT title, slug, published_at FROM ' . $C . " WHERE type='post' AND status='publish' AND deleted_at IS NULL "
        . 'ORDER BY published_at DESC, id DESC LIMIT ' . (int)$n
    );
}

function sub_issue_body(array $items, string $lang, string $token): string
{
    $host = 'https://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $base = sub_base($lang);
    $out = $lang === 'en' ? "This issue:\n\n" : "本期内容:\n\n";
    foreach ($items as $it) {
        $out .= '· ' . (string)$it['title'] . "\n  " . $host . $base . '/' . rawurlencode((string)$it['slug']) . ".html\n\n";
    }
    $out .= $lang === 'en'
        ? "Unsubscribe: " . $host . $base . '/subscribe/unsub?t=' . $token . "\n"
        : "退订: " . $host . $base . '/subscribe/unsub?t=' . $token . "\n";
    return $out;
}

/** 发送周刊;返回 ['sent'=>int,'fail'=>int,'skipped'=>int,'log'=>[]] */
function sub_send_weekly(bool $dry = false, string $test = '', int $limit = 3): array
{
    sub_ensure_table();
    $res = ['sent' => 0, 'fail' => 0, 'skipped' => 0, 'log' => []];
    if ($test !== '') {
        $lang = sub_default_lang();
        $items = sub_latest_items($lang, $limit);
        $body = sub_issue_body($items, $lang, 'testtoken');
        if ($dry) { $res['log'][] = "[dry] test → {$test}"; return $res; }
        $ok = sub_send($test, sub_t('subject_issue', $lang) . ' · ' . sub_from_name($lang), $body, $lang);
        $ok ? $res['sent']++ : $res['fail']++;
        $res['log'][] = ($ok ? 'OK test → ' : 'FAIL test → ') . $test;
        return $res;
    }
    $subs = Db::rows('SELECT id,email,lang,token FROM ' . sub_table() . " WHERE status='confirmed' ORDER BY id ASC");
    foreach ($subs as $s) {
        $lang = in_array((string)$s['lang'], sub_langs(), true) ? (string)$s['lang'] : sub_default_lang();
        $items = sub_latest_items($lang, $limit);
        if (!$items) { $res['skipped']++; $res['log'][] = 'skip (no items) ' . $s['email']; continue; }
        if ($dry) { $res['log'][] = "[dry] would send → {$s['email']} ({$lang})"; continue; }
        $body = sub_issue_body($items, $lang, (string)$s['token']);
        if (sub_send((string)$s['email'], sub_t('subject_issue', $lang) . ' · ' . sub_from_name($lang), $body, $lang)) {
            Db::run('UPDATE ' . sub_table() . ' SET last_sent_at=NOW() WHERE id=?', [(int)$s['id']]);
            $res['sent']++; $res['log'][] = 'sent ' . $s['email'];
        } else { $res['fail']++; $res['log'][] = 'FAIL ' . $s['email']; }
    }
    return $res;
}
