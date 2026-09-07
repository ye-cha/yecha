<?php
declare(strict_types=1);
// 友链插件数据访问（公共：后台/功能块/独立页共用）
if (!function_exists('yc_links_enabled_groups')) {
    function yc_links_enabled_groups(string $kind = ''): array
    {
        $where = '';
        $params = [];
        if ($kind === 'nav' || $kind === 'friend') {
            $where = ' WHERE kind IN (' . ($kind === 'nav' ? "'nav','mixed'" : "'friend','mixed'") . ')';
        }
        $rows = Db::rows('SELECT * FROM ' . Db::t('link_groups') . $where . ' ORDER BY sort ASC, id ASC');
        return array_values(array_filter($rows, fn($g) => (int)($g['enabled'] ?? 1) === 1));
    }

    function yc_links_group_links(int $gid): array
    {
        return Db::rows('SELECT * FROM ' . Db::t('links') . " WHERE group_id=? AND status='show' ORDER BY sort ASC, id ASC", [$gid]);
    }
}

/* ---------- 阶段1「看得见的信任」：出站点击统计（按访客会话自然日去重） ---------- */
if (!function_exists('yc_links_click_sid')) {
    function yc_links_click_sid(): string
    {
        $s = (string)session_id();
        if ($s !== '') { return md5($s); }
        return md5(($_SERVER['REMOTE_ADDR'] ?? 'x') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? 'x'));
    }

    /** 记录一次出站/串门点击；同访客同日同链接只计 1（INSERT IGNORE）。返回是否新计。 */
    function yc_links_click_track(int $linkId): bool
    {
        $t = Db::t('link_clicks');
        if (!Db::rows('SHOW TABLES LIKE "' . $t . '"')) { return false; }
        $sid = yc_links_click_sid();
        Db::run("INSERT IGNORE INTO {$t} (day, link_id, sid) VALUES (CURDATE(), ?, ?)", [$linkId, $sid]);
        return true;
    }

    /** 单个链接近 N 天被带走（去重会话）次数 */
    function yc_links_click_total(int $linkId, int $days = 30): int
    {
        $t = Db::t('link_clicks');
        if (!Db::rows('SHOW TABLES LIKE "' . $t . '"')) { return 0; }
        return (int)Db::one("SELECT COUNT(*) FROM {$t} WHERE link_id=? AND day >= DATE_SUB(CURDATE(), INTERVAL ? DAY)", [$linkId, max(1, min(365, $days))]);
    }

    /** 一批链接近 N 天计数 map：id => n（空数组返回空 map） */
    function yc_links_click_map(array $ids, int $days = 30): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_filter($ids, fn($v) => $v > 0);
        if (!$ids) { return []; }
        $t = Db::t('link_clicks');
        if (!Db::rows('SHOW TABLES LIKE "' . $t . '"')) { return []; }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = Db::rows("SELECT link_id, COUNT(*) n FROM {$t} WHERE day >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND link_id IN ({$ph}) GROUP BY link_id", array_merge([max(1, min(365, $days))], $ids));
        $map = [];
        foreach ($rows as $r) { $map[(int)$r['link_id']] = (int)$r['n']; }
        return $map;
    }

    /** 近 N 天每日计数：['Y-m-d' => n]（补 0） */
    function yc_links_click_days(int $linkId, int $days = 30): array
    {
        $t = Db::t('link_clicks');
        $rows = [];
        if (Db::rows('SHOW TABLES LIKE "' . $t . '"')) {
            $rows = Db::rows("SELECT day, COUNT(*) n FROM {$t} WHERE link_id=? AND day >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY day", [$linkId, max(1, min(365, $days))]);
        }
        $map = [];
        foreach ($rows as $r) { $map[(string)$r['day']] = (int)$r['n']; }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} day"));
            $out[$d] = $map[$d] ?? 0;
        }
        return $out;
    }
}

/* ---------- 阶段1：机器体检（RSS 可达/可解析/最近更新 + 站点存活） ----------
 * 仅用于后台「体检」按钮与 CLI 探测工具（cron），不在公开提交时抓取，降低 SSRF 面。 */
if (!function_exists('yc_links_is_private_ip')) {
    function yc_links_is_private_ip(string $ip): bool
    {
        $ip = trim($ip);
        if (str_contains($ip, ':')) { // IPv6：环回/链路本地/ULA 视为内网
            $low = strtolower($ip);
            return $low === '::1' || str_starts_with($low, 'fe80:') || str_starts_with($low, 'fc') || str_starts_with($low, 'fd') || str_starts_with($low, '::ffff:127.');
        }
        $n = ip2long($ip);
        if ($n === false) { return true; }
        $n = (float)sprintf('%u', $n);
        return ($n >= 0 && $n <= 16777215)            // 0.0.0.0/8
            || ($n >= 167772160 && $n <= 184549375)   // 10.0.0.0/8
            || ($n >= 2130706432 && $n <= 2147483647) // 127.0.0.0/8
            || ($n >= 2851995648 && $n <= 2852061183) // 169.254.0.0/16
            || ($n >= 2886729728 && $n <= 2887778303) // 172.16.0.0/12
            || ($n >= 3232235520 && $n <= 3232301055) // 192.168.0.0/16
            || ($n >= 3758096384 && $n <= 4026531839); // 224.0.0.0/4 组播
    }

    /** 探测目标必须是公网 http(s)：域名解析结果任一为内网即拒绝（本地开发可设 YC_LINKS_PROBE_ALLOW_PRIVATE=1 放行） */
    function yc_links_host_public(string $host): bool
    {
        if (getenv('YC_LINKS_PROBE_ALLOW_PRIVATE') === '1') { return true; }
        $host = trim(strtolower($host));
        $host = preg_replace('/^\[|\]$/', '', $host);
        if ($host === '' || $host === 'localhost') { return false; }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return !yc_links_is_private_ip($host);
        }
        $ips = @gethostbynamel($host);
        if (!is_array($ips) || !$ips) { return false; }
        foreach ($ips as $ip) {
            if (yc_links_is_private_ip($ip)) { return false; }
        }
        return true;
    }

    /** 低层抓取：限 http/https、限时、限重定向到公网；返回 [ok,code,body,err,final] */
    function yc_links_http_get(string $url, int $timeout = 8, int $maxBody = 524288): array
    {
        $timeout = max(3, min(20, $timeout));
        for ($hop = 0; $hop < 5; $hop++) {
            $p = parse_url($url);
            if (!$p || !isset($p['scheme'], $p['host']) || !in_array(strtolower((string)$p['scheme']), ['http', 'https'], true)) {
                return ['ok' => false, 'code' => 0, 'body' => '', 'err' => '非 http(s) 地址'];
            }
            if (!yc_links_host_public((string)$p['host'])) {
                return ['ok' => false, 'code' => 0, 'body' => '', 'err' => '目标指向内网/非公网地址，已拒绝探测'];
            }
            $res = _yc_links_curl($url, $timeout, $maxBody);
            if (!$res['ok']) { return $res; }
            if ($res['code'] >= 300 && $res['code'] < 400 && isset($res['location']) && $res['location'] !== '') {
                $url = $res['location'];
                continue;
            }
            unset($res['location']);
            return $res;
        }
        return ['ok' => false, 'code' => 0, 'body' => '', 'err' => '重定向过多'];
    }

    function _yc_links_curl(string $url, int $timeout, int $maxBody): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT => $timeout + 6,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT => 'YeCha-LinksProbe/1.2 (+https://yecha.org)',
                CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, */*'],
                CURLOPT_NOBODY => false,
            ]);
            $body = (string)curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = (string)curl_error($ch);
            $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);
            if (strlen($body) > $maxBody) { $body = substr($body, 0, $maxBody); }
            return ['ok' => $err === '', 'code' => $code, 'body' => $body, 'err' => $err !== '' ? $err : '', 'location' => $loc];
        }
        // 无 curl 兜底（仅首跳校验；重定向交给流包装默认行为）
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'follow_location' => 1, 'max_redirects' => 3,
            'ignore_errors' => true, 'user_agent' => 'YeCha-LinksProbe/1.2 (+https://yecha.org)',
            'header' => "Accept: application/rss+xml, application/xml, text/xml, */*\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) { return ['ok' => false, 'code' => 0, 'body' => '', 'err' => '抓取失败（网络/超时）']; }
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$h, $mm)) { $code = (int)$mm[1]; }
        }
        return ['ok' => true, 'code' => $code, 'body' => substr($body, 0, $maxBody), 'err' => ''];
    }

    /** 解析 RSS/Atom 正文，取最新条目时间戳；解析失败返回 null */
    function yc_links_rss_latest_ts(string $xmlBody): ?int
    {
        $xmlBody = trim($xmlBody);
        if ($xmlBody === '' || !str_contains($xmlBody, '<')) { return null; }
        $prev = libxml_use_internal_errors(true);
        $sx = @simplexml_load_string($xmlBody);
        libxml_use_internal_errors($prev);
        if ($sx === false) { return null; }
        $times = [];
        $items = isset($sx->channel->item) ? $sx->channel->item : $sx->entry;
        if ($items) {
            foreach ($items as $it) {
                foreach (['pubDate', 'published', 'updated', 'dc:date'] as $tag) {
                    $v = (string)($it->{$tag} ?? '');
                    if ($v !== '') { $ts = strtotime($v); if ($ts > 0) { $times[] = $ts; } break; }
                }
            }
        }
        if ($times) { return (int)max($times); }
        return null;
    }

    /** 对一个链接执行体检并写回（站点存活 + RSS 可达/解析/最近更新）。 */
    function yc_links_probe_link(array $link): array
    {
        $id = (int)($link['id'] ?? 0);
        $url = trim((string)($link['url'] ?? ''));
        $rss = trim((string)($link['rss'] ?? ''));
        $state = '';
        $note = '';
        $lastPost = null;
        $now = date('Y-m-d H:i:s');
        if ($url !== '') {
            $site = yc_links_http_get($url, 8);
            if (!$site['ok']) {
                $state = 'down';
                $note = '站点探测失败：' . mb_substr((string)$site['err'], 0, 120);
            } elseif ($site['code'] >= 400) {
                $state = 'down';
                $note = '站点返回 HTTP ' . (int)$site['code'];
            }
        }
        if ($state === '') {
            if ($rss === '') {
                $state = 'no_rss';
                $note = '站点可达，未填 RSS（无法显示“最近更新”）';
            } else {
                $feed = yc_links_http_get($rss, 10);
                if (!$feed['ok']) {
                    $state = 'err';
                    $note = 'RSS 抓取失败：' . mb_substr((string)$feed['err'], 0, 120);
                } elseif ($feed['code'] >= 400 || trim($feed['body']) === '') {
                    $state = 'err';
                    $note = 'RSS 返回 HTTP ' . (int)$feed['code'] . ' 或内容为空';
                } else {
                    $ts = yc_links_rss_latest_ts($feed['body']);
                    if ($ts === null) {
                        $state = 'err';
                        $note = 'RSS 无法解析或没有文章条目';
                    } else {
                        $state = 'ok';
                        $note = 'RSS 正常';
                        $lastPost = date('Y-m-d H:i:s', $ts);
                    }
                }
            }
        }
        $upd = ['probe_at' => $now, 'probe_state' => $state, 'probe_note' => mb_substr($note, 0, 250)];
        if ($lastPost !== null) { $upd['last_post_at'] = $lastPost; }
        if ($state === 'ok') { $upd['last_ok_at'] = $now; }
        if ($id > 0) {
            Db::update('links', $upd, 'id=?', [$id]);
        }
        return ['id' => $id, 'state' => $state, 'note' => $note, 'last_post_at' => $lastPost];
    }
}

/* ---------- 阶段2「温柔聚合圈」：RSS 元数据解析/入表 + 阶段3 互认 via 参数 ---------- */
if (!function_exists('yc_links_abs_url')) {
    function yc_links_abs_url(string $url, string $base): string
    {
        $p = parse_url($url);
        if (isset($p['scheme'], $p['host'])) { return $url; }
        $bp = parse_url($base);
        $host = ($bp['scheme'] ?? 'http') . '://' . ($bp['host'] ?? '');
        if (str_starts_with($url, '/')) { return $host . $url; }
        $dir = isset($bp['path']) ? preg_replace('#/[^/]*$#', '/', (string)$bp['path']) : '/';
        return rtrim($host . $dir, '/') . '/' . ltrim($url, '/');
    }

    /** 解析 RSS2/Atom，仅取元数据；返回 [title,url,summary,ts] 列表（正文绝不缓存） */
    function yc_links_feed_entries(string $body): array
    {
        $body = trim($body);
        if ($body === '' || !str_contains($body, '<')) { return []; }
        $prev = libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($body);
        libxml_use_internal_errors($prev);
        if ($xml === false) { return []; }
        $out = [];
        if (strtolower((string)$xml->getName()) === 'feed') { // Atom
            $ns = 'http://www.w3.org/2005/Atom';
            foreach ($xml->entry as $en) {
                $title = trim((string)$en->title);
                if ($title === '') { continue; }
                $url = '';
                foreach ($en->link as $lk) {
                    $rel = (string)($lk['rel'] ?? 'alternate');
                    if ($rel === '' || $rel === 'alternate') { $url = trim((string)$lk['href']); break; }
                }
                if ($url === '') { continue; }
                $sum = (string)($en->summary ?? '');
                if ($sum === '') { $sum = (string)($en->content ?? ''); }
                $ts = 0;
                foreach (['published', 'updated'] as $tag) {
                    $v = trim((string)$en->{$tag});
                    if ($v !== '') { $ts = (int)strtotime($v); if ($ts > 0) { break; } }
                }
                $out[] = ['title' => $title, 'url' => $url, 'summary' => $sum, 'ts' => $ts];
            }
            return $out;
        }
        // RSS2
        foreach ($xml->channel->item as $it) {
            $title = trim((string)$it->title);
            $url = trim((string)$it->link);
            if ($title === '' || $url === '') { continue; }
            $sum = trim((string)($it->description ?? ''));
            $ts = 0;
            $pd = trim((string)($it->pubDate ?? ''));
            if ($pd !== '') { $ts = (int)strtotime($pd); }
            $out[] = ['title' => $title, 'url' => $url, 'summary' => $sum, 'ts' => $ts];
        }
        return $out;
    }

    function yc_links_clean_summary(string $raw, int $len = 400): string
    {
        $raw = trim((string)strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ' ', (string)$raw)));
        $raw = preg_replace('/\s+/u', ' ', $raw) ?? $raw;
        return mb_substr(trim($raw), 0, $len);
    }

    /** 出站带来源参数：via=<host>（互认协议：对方可识别「谁把你带过去的」） */
    function yc_links_via_host(): string
    {
        $env = trim((string)getenv('YC_LINKS_VIA'));
        if ($env !== '') { return strtolower(preg_replace('/[^a-z0-9.\-]/', '', $env)) ?: 'yecha.org'; }
        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? 'yecha.org')));
        $host = preg_replace('/:\d+$/', '', $host);
        return $host !== '' ? $host : 'yecha.org';
    }

    function yc_links_append_via(string $url): string
    {
        $p = parse_url($url);
        if (!$p || !isset($p['scheme'], $p['host'])) { return $url; }
        $via = 'via=' . urlencode(yc_links_via_host());
        $query = isset($p['query']) && $p['query'] !== '' ? $p['query'] . '&' . $via : $via;
        $frag = isset($p['fragment']) && $p['fragment'] !== '' ? '#' . $p['fragment'] : '';
        $out = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) && $p['port'] ? ':' . $p['port'] : '')
            . ($p['path'] ?? '') . '?' . $query . $frag;
        return $out;
    }

    /** 单链接 RSS 抓取入表：返回 [new, seen]；不写正文、只存元数据 */
    function yc_links_fetch_link(array $link, int $backDays = 120, int $maxPerFeed = 20): array
    {
        $rss = trim((string)($link['rss'] ?? ''));
        if ($rss === '' || (string)($link['status'] ?? '') !== 'show') { return ['new' => 0, 'seen' => 0]; }
        $feed = yc_links_http_get($rss, 10);
        if (!$feed['ok'] || $feed['code'] >= 400) { return ['new' => 0, 'seen' => 0]; }
        $entries = yc_links_feed_entries($feed['body']);
        if (!$entries) { return ['new' => 0, 'seen' => 0]; }
        $new = 0;
        $seen = 0;
        $cutoff = time() - $backDays * 86400;
        $base = $rss;
        foreach ($entries as $en) {
            if ($en['ts'] > 0 && $en['ts'] < $cutoff) { continue; }
            $url = yc_links_abs_url($en['url'], $base);
            if (strlen($url) > 500) { continue; }
            $seen++;
            $pub = $en['ts'] > 0 ? date('Y-m-d H:i:s', (int)$en['ts']) : null;
            $t = Db::t('circle_posts');
            $hit = Db::one('SELECT id FROM ' . $t . ' WHERE url=? LIMIT 1', [$url]);
            if ($hit !== null) { continue; }
            Db::run('INSERT IGNORE INTO ' . $t . ' (link_id, url, title, summary, published_at, fetched_at) VALUES (?,?,?,?,?,NOW())', [
                (int)$link['id'], $url, mb_substr(trim((string)$en['title']), 0, 300),
                yc_links_clean_summary((string)$en['summary']), $pub,
            ]);
            $new++;
            if ($new >= $maxPerFeed) { break; }
        }
        return ['new' => $new, 'seen' => $seen];
    }
}

/* ---------- 1.4.0 清遗留：邮件/精选/订阅/公平随机 ---------- */
if (!function_exists('yc_links_send_mail')) {
    /** 站点邮件封装：读全局 mail.* 选项；未开启直接返回跳过（不尝试连接）。 */
    function yc_links_send_mail(string $to, string $subject, string $body): array
    {
        if ((string)get_option('mail.enabled', '0') !== '1') {
            return ['ok' => false, 'err' => 'skip: mail.enabled=0'];
        }
        $host = (string)get_option('mail.host', '');
        $port = (int)get_option('mail.port', 465);
        $ssl = (string)get_option('mail.ssl', '1') === '1';
        $user = (string)get_option('mail.user', '');
        $pass = (string)get_option('mail.pass', '');
        $from = (string)get_option('mail.from', '');
        if ($host === '' || $from === '' || $to === '') { return ['ok' => false, 'err' => 'skip: smtp 未配置']; }
        $r = \Mail::send($host, $port, $ssl, $user, $pass, $from, $to, $subject, $body);
        return is_bool($r) && $r ? ['ok' => true, 'err' => ''] : ['ok' => false, 'err' => is_string($r) ? $r : 'send failed'];
    }

    /** 精选置顶开关 */
    function yc_links_featured_toggle(int $postId, bool $on): void
    {
        $t = Db::t('circle_posts');
        if ($on) {
            Db::run("UPDATE {$t} SET featured=1, featured_at=NOW() WHERE id=?", [$postId]);
        } else {
            Db::run("UPDATE {$t} SET featured=0, featured_at=NULL WHERE id=?", [$postId]);
        }
    }

    /** 订阅月度精选；返回错误串或 ''（成功）。同一邮箱只一条。 */
    function yc_links_sub_add(string $email): string
    {
        $email = trim(strtolower($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { return '邮箱格式不正确'; }
        $t = Db::t('circle_subs');
        if (!Db::rows('SHOW TABLES LIKE "' . $t . '"')) { return '订阅功能暂不可用'; }
        $token = md5($email . '|' . random_bytes(8));
        Db::run('INSERT INTO ' . $t . " (email, type, token, created_at) VALUES (?, 'monthly', ?, NOW()) ON DUPLICATE KEY UPDATE token=VALUES(token)", [$email, $token]);
        return '';
    }

    function yc_links_sub_del(string $email, string $token): bool
    {
        $t = Db::t('circle_subs');
        if (!Db::rows('SHOW TABLES LIKE "' . $t . '"')) { return false; }
        $email = trim(strtolower($email));
        $hit = Db::one('SELECT email FROM ' . $t . ' WHERE email=? AND token=? AND type=? LIMIT 1', [$email, $token, 'monthly']);
        if ($hit === null) { return false; }
        Db::deleteBy('circle_subs', 'email=? AND token=? AND type=?', [$email, $token, 'monthly']);
        return true;
    }

    /** 公平随机：近30天被带走越少权重越高（至少 1 次真实曝光） */
    function yc_links_random_link(): ?array
    {
        $rows = Db::rows('SELECT * FROM ' . Db::t('links') . " WHERE status='show' ORDER BY id ASC");
        if (!$rows) { return null; }
        $ids = array_column($rows, 'id');
        $map = yc_links_click_map(array_map('intval', $ids), 30);
        $weights = [];
        $min = PHP_INT_MAX;
        $max = 0;
        foreach ($rows as $r) {
            $n = $map[(int)$r['id']] ?? 0;
            if ($n < $min) { $min = $n; }
            if ($n > $max) { $max = $n; }
            $weights[(int)$r['id']] = $n;
        }
        // weight = maxClicks+1 - n（0 点击的拿最高权重）
        $base = $max + 1;
        $pool = [];
        foreach ($rows as $r) {
            $w = max(1, $base - ($weights[(int)$r['id']] ?? 0));
            for ($i = 0; $i < $w; $i++) { $pool[] = $r; }
        }
        return $pool ? $pool[array_rand($pool)] : $rows[0];
    }

    /** 存活关怀：站点 down/err 且距离上次提醒 >3 天时邮件站主一次；恢复正常则清标记 */
    function yc_links_uptime_alert(array $l): void
    {
        $state = (string)($l['probe_state'] ?? '');
        $id = (int)$l['id'];
        if ($state === 'down') {
            $key = 'links.downmail:' . $id;
            $last = (string)get_option($key, '');
            if ($last === '' || strtotime($last) < time() - 3 * 86400) {
                $to = trim((string)($l['email'] ?? ''));
                $name = (string)($l['name'] ?? '');
                if ($to !== '') {
                    $r = yc_links_send_mail($to, '【YeCha 收录圈】你的博客似乎无法访问', "你好 {$name}：\n\n我们连续检查到你的博客暂时无法访问。请确认域名解析/主机状态。\n若已恢复无需操作；若希望暂停收录可回复本站说明；超过一段时间仍不可达，我们会先把你的站点标注出来，再视情况移除——不会悄悄消失。\n\n—— YeCha 博客收录圈");
                    if ($r['ok']) { set_option($key, date('Y-m-d H:i:s')); }
                }
            }
        } else {
            set_option('links.downmail:' . $id, ''); // 恢复正常：清掉提醒时间戳（下次再 down 会重新提醒）
        }
    }
}

/* ---------- 1.4.1：使用的博客程序（尽量详细的分组清单） ---------- */
if (!function_exists('yc_links_engines')) {
    /** 分组 -> [id => 显示名]；id 存库 */
    function yc_links_engines(): array
    {
        return [
            '动态博客程序' => [
                'wordpress' => 'WordPress',
                'typecho'   => 'Typecho',
                'zblog'     => 'Z-Blog（PHP）',
                'halo'      => 'Halo',
                'emlog'     => 'Emlog',
                'ghost'     => 'Ghost',
            ],
            '静态站点生成' => [
                'hugo'       => 'Hugo',
                'hexo'       => 'Hexo',
                'jekyll'     => 'Jekyll',
                'vitepress'  => 'VitePress',
                'vuepress'   => 'VuePress',
                'astro'      => 'Astro',
                'notionnext' => 'NotionNext',
            ],
            '自研 / 其它' => [
                'custom' => '自研程序（自己开发）',
                'none'   => '手写 HTML / 未使用程序',
                'other'  => '其它 / 不确定',
            ],
        ];
    }

    function yc_links_engine_label(string $id): string
    {
        if ($id === '') { return ''; }
        foreach (yc_links_engines() as $group) {
            if (isset($group[$id])) { return $group[$id]; }
        }
        return $id;
    }

    /** 选项 HTML（optgroup），$cur 当前值 */
    function yc_links_engine_options(string $cur = ''): string
    {
        $o = '<option value=""' . ($cur === '' ? ' selected' : '') . '>— 请选择博客程序（尽量准确）—</option>';
        foreach (yc_links_engines() as $group => $items) {
            $o .= '<optgroup label="' . e($group) . '">';
            foreach ($items as $id => $label) {
                $o .= '<option value="' . e($id) . '"' . ($cur === $id ? ' selected' : '') . '>' . e($label) . '</option>';
            }
            $o .= '</optgroup>';
        }
        return $o;
    }
}
