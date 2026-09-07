<?php
declare(strict_types=1);
/**
 * Uc：前台用户中心（E2）
 * 登录/注册/退出、收藏、私有划线+注释、我的笔记、普通评论与公开段评（走审核）
 * 动作端点全部 POST + CSRF；页面渲染返回主区 HTML（由 index.php 套用站点布局）。
 */
final class Uc
{
    /* ================= 动作端点（POST；已登录严格 CSRF，游客仅普通评论/回复+同源校验） ================= */
    public static function act(string $act): never
    {
        // 游客评论走全页缓存页面时，token 会随缓存过期 → 游客评论放开严格 CSRF，
        // 改为同源校验（Origin/Referer）+ 蜜罐 + IP 频率限制 + 先审后发兜底（与直接发帖同风险）。
        $guestComment = $act === 'comment' && (string)($_POST['kind'] ?? '') !== 'segment' && !Auth::check();
        if ($guestComment) {
            self::assertSameOrigin();
        } else {
            Csrf::check();
            if (!Auth::check()) {
                self::needLogin();
            }
        }
        $redirect = self::safeRedirect((string)($_POST['redirect'] ?? '/user'));
        switch ($act) {
            case 'favorite':
                self::toggleFavorite();
                break;
            case 'notify-read':
                Db::update('notifications', ['is_read' => 1], 'user_id=?', [Auth::id()]);
                $redirect = '/user/notifications';
                break;
            case 'mark':
                self::addMark();
                break;
            case 'annotation':
                self::addAnnotation();
                break;
            case 'mark-del':
                Db::deleteBy('marks', 'id=? AND user_id=?', [(int)($_POST['id'] ?? 0), Auth::id()]);
                Db::deleteBy('annotations', 'mark_id=? AND user_id=?', [(int)($_POST['id'] ?? 0), Auth::id()]);
                break;
            case 'annotation-del':
                Db::deleteBy('annotations', 'id=? AND user_id=?', [(int)($_POST['id'] ?? 0), Auth::id()]);
                break;
            case 'comment':
                self::addComment();
                break;
            case 'logout':
                Auth::logout();
                redirect('/');
                break;
            case 'password':
                $uid = Auth::id();
                $old = (string)($_POST['old_password'] ?? '');
                $new = (string)($_POST['new_password'] ?? '');
                $confirm = (string)($_POST['confirm_password'] ?? '');
                if (!Auth::verifyPassword($uid, $old)) {
                    $redirect = '/user?pw=badold';
                } elseif (mb_strlen($new) < 6) {
                    $redirect = '/user?pw=short';
                } elseif ($new !== $confirm) {
                    $redirect = '/user?pw=mismatch';
                } else {
                    Auth::setPassword($uid, $new);
                    $redirect = '/user?pw=ok';
                }
                break;
        }
        header('Location: ' . $redirect);
        exit;
    }

    /* ---------- 收藏 ---------- */
    private static function toggleFavorite(): void
    {
        $cid = (int)($_POST['content_id'] ?? 0);
        $uid = Auth::id();
        if ($cid <= 0) { return; }
        $exists = Db::one('SELECT id FROM ' . Db::t('favorites') . ' WHERE user_id=? AND content_id=? LIMIT 1', [$uid, $cid]);
        if ($exists === null) {
            Db::insert('favorites', ['user_id' => $uid, 'content_id' => $cid]);
        } else {
            Db::deleteBy('favorites', 'id=?', [(int)$exists]);
        }
        // 收藏不影响访客页缓存（私有），仅清自己无妨
    }

    /* ---------- 私有划线 ---------- */
    private static function addMark(): void
    {
        $uid = Auth::id();
        $cid = (int)($_POST['content_id'] ?? 0);
        $quote = trim((string)($_POST['quote'] ?? ''));
        $anchor = trim((string)($_POST['anchor_ref'] ?? ''));
        $color = preg_match('/^#?[a-fA-F0-9]{6}$/', (string)($_POST['color'] ?? '')) ? (string)$_POST['color'] : '#ffe08a';
        if ($cid <= 0 || $quote === '' || mb_strlen($quote) > 200) { return; }
        $dup = Db::one('SELECT id FROM ' . Db::t('marks') . ' WHERE user_id=? AND content_id=? AND anchor_ref=? AND quote=? LIMIT 1',
            [$uid, $cid, $anchor, $quote]);
        if ($dup !== null) { return; }
        $markId = Db::insert('marks', [
            'user_id' => $uid, 'content_id' => $cid,
            'anchor_kind' => 'paragraph', 'anchor_ref' => $anchor, 'quote' => $quote, 'color' => $color,
        ]);
        $note = trim((string)($_POST['note'] ?? ''));
        if ($note !== '') {
            Db::insert('annotations', [
                'user_id' => $uid, 'content_id' => $cid, 'mark_id' => $markId,
                'anchor_kind' => 'paragraph', 'anchor_ref' => $anchor, 'quote' => $quote, 'body' => $note,
            ]);
            Db::update('marks', ['note_count' => 1], 'id=?', [$markId]);
        }
    }

    /* ---------- 私有注释（可挂在划线或段落） ---------- */
    private static function addAnnotation(): void
    {
        $uid = Auth::id();
        $cid = (int)($_POST['content_id'] ?? 0);
        $body = trim((string)($_POST['body'] ?? ''));
        if ($cid <= 0 || $body === '') { return; }
        Db::insert('annotations', [
            'user_id' => $uid, 'content_id' => $cid,
            'mark_id' => ((int)($_POST['mark_id'] ?? 0)) ?: null,
            'anchor_kind' => 'paragraph',
            'anchor_ref' => trim((string)($_POST['anchor_ref'] ?? '')),
            'quote' => trim((string)($_POST['quote'] ?? '')),
            'body' => $body,
        ]);
        $markId = (int)($_POST['mark_id'] ?? 0);
        if ($markId > 0) {
            Db::update('marks', ['note_count' => (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('annotations') . ' WHERE mark_id=? AND user_id=?', [$markId, $uid])], 'id=? AND user_id=?', [$markId, $uid]);
        }
    }

    /* ---------- 评论 / 段评（先审后发 + 蜜罐 + 频率限制） ---------- */
    private static function addComment(): void
    {
        // 蜜罐：填了即垃圾
        if (trim((string)($_POST['company'] ?? '')) !== '') {
            Db::insert('comments', ['content_id' => (int)($_POST['content_id'] ?? 0), 'author_id' => null, 'author_name' => 'bot', 'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'body' => 'spam honeypot', 'status' => 'spam']);
            return;
        }
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if ((int)Cache::incr('comment_rate', 'min:' . $ip, 60) > 3 || (int)Cache::incr('comment_rate', 'hour:' . $ip, 3600) > 10) {
            $_SESSION['flash'] = __('uc.cm_fast');
            header('Location: ' . self::safeRedirect((string)($_POST['redirect'] ?? '/')));
            exit;
        }
        $cid = (int)($_POST['content_id'] ?? 0);
        $kind = ($_POST['kind'] ?? '') === 'segment' ? 'segment' : 'normal';
        $body = trim((string)($_POST['body'] ?? ''));
        if ($cid <= 0 || $body === '') { return; }
        // 回复：parent_id 必须属于同一文章且已存在
        $parentId = 0;
        $parent = (int)($_POST['parent_id'] ?? 0);
        if ($parent > 0) {
            $prow = Db::row('SELECT id FROM ' . Db::t('comments') . ' WHERE id=? AND content_id=? LIMIT 1', [$parent, $cid]);
            if ($prow !== null) { $parentId = $parent; }
        }
        $u = Auth::user();
        $uid = Auth::id();
        $loggedIn = is_array($u) && !empty($u['username']);
        $name = $loggedIn ? ((string)($u['display_name'] ?? '') !== '' ? (string)$u['display_name'] : (string)$u['username']) : '';
        $email = $loggedIn ? (string)($u['email'] ?? '') : '';
        if (!$loggedIn) {
            // 游客：昵称必填、邮箱选填（供头像/回复署名）；长度与格式收敛
            $name = mb_substr(trim((string)($_POST['author_name'] ?? '')), 0, 40);
            $emailRaw = mb_substr(trim((string)($_POST['author_email'] ?? '')), 0, 190);
            $email = $emailRaw !== '' && filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? $emailRaw : '';
            if ($name === '') {
                $_SESSION['flash'] = __('uc.name_req');
                header('Location: ' . self::safeRedirect((string)($_POST['redirect'] ?? '/')));
                exit;
            }
        }
        // P-4：关键词/链接数/名单 命中 → 直接入库为垃圾（不进前台，也不打扰提交者）
        if (!self::commentClean($body, $name, $email)) {
            Db::insert('comments', [
                'content_id' => $cid, 'author_id' => $uid ?: null, 'author_name' => $name,
                'author_email' => $email, 'ip' => $ip,
                'body' => mb_substr($body, 0, 5000), 'kind' => $kind,
                'parent_id' => $parentId > 0 ? $parentId : null,
                'status' => 'spam',
            ]);
            Cache::flush('pages');
            Cache::flush('blocks');
            return;
        }
        $moderation = (string)get_option('comment.moderation', '1') === '1';
        Db::insert('comments', [
            'content_id' => $cid, 'author_id' => $uid ?: null, 'author_name' => $name,
            'author_email' => $email, 'ip' => $ip,
            'body' => $body, 'kind' => $kind,
            'parent_id' => $parentId > 0 ? $parentId : null,
            'anchor_kind' => $kind === 'segment' ? 'paragraph' : null,
            'anchor_ref' => $kind === 'segment' ? (string)($_POST['anchor_ref'] ?? '') : null,
            'anchor_quote' => $kind === 'segment' ? mb_substr(trim((string)($_POST['anchor_quote'] ?? '')), 0, 200) : null,
            'status' => $moderation ? 'pending' : 'approved',
        ]);
        if (!$moderation || $kind === 'segment') {
            // 先发后审关闭或段评 → 可能立即上屏，刷缓存防新评论在整页缓存中不可见
            Cache::flush('pages');
            Cache::flush('blocks');
        }
        $_SESSION['flash'] = $moderation ? __('uc.cm_mod') : __('uc.cm_ok');
    }

    /* ================= 认证 POST（login / register） ================= */
    public static function authPost(string $kind): never
    {
        Csrf::check();
        $back = self::safeRedirect((string)($_POST['redirect'] ?? ($kind === 'login' ? '/user' : '/user')));
        if ($kind === 'login') {
            $res = Auth::attempt((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
            if ($res['ok']) {
                header('Location: ' . $back);
                exit;
            }
            $_SESSION['flash'] = $res['error'];
            header('Location: ' . ui_login($back));
            exit;
        }
        $res = Auth::register((string)($_POST['username'] ?? ''), (string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($res['ok']) {
            header('Location: ' . $back);
            exit;
        }
        // 注册节流：仅失败计次（防爆破；成功注册不拦截）
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if ((int)Cache::incr('regrate', 'ip:' . $ip, 600) > 5) {
            $_SESSION['flash'] = __('auth.throttle');
        } else {
            $_SESSION['flash'] = $res['error'];
        }
        header('Location: ' . ui_register($back));
        exit;
    }

    public static function needLogin(): never
    {
        $cur = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: ' . ui_login($cur));
        exit;
    }

    /** 游客 POST 的同源校验：Origin 或 Referer 必须与当前 Host 一致（比较域名，忽略端口；无则拒绝） */
    private static function assertSameOrigin(): void
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $hostDomain = strtolower((string)parse_url('http://' . $host, PHP_URL_HOST)); // 去端口
        if ($hostDomain === '') { self::needLogin(); }
        $match = function (string $url) use ($hostDomain): bool {
            $h = strtolower((string)parse_url($url, PHP_URL_HOST));
            return $h !== '' && $h === $hostDomain;
        };
        $check = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($check !== '') {
            if (!$match($check)) {
                http_response_code(403);
                exit('来源校验失败，请返回重试');
            }
            return;
        }
        $ref = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($ref !== '') {
            if (!$match($ref)) {
                http_response_code(403);
                exit('来源校验失败，请返回重试');
            }
            return;
        }
        // 无 Origin/Referer（如部分爬虫/旧客户端）→ 拒绝，鼓励从页面表单提交
        http_response_code(403);
        exit('请从页面评论表单提交');
    }

    /** 只允许站内相对路径回跳（防开放重定向） */
    public static function safeRedirect(string $to): string
    {
        $to = trim($to);
        if ($to === '' || $to === '/' || !str_starts_with($to, '/') || str_starts_with($to, '//')) {
            return '/user';
        }
        return $to;
    }

    /* ---------- 通知：评论 @提及（站内信 + 可选邮件） ---------- */
    public static function notifyMentions(int $commentId): void
    {
        $row = Db::row('SELECT * FROM ' . Db::t('comments') . ' WHERE id=?', [$commentId]);
        if ($row === null) { return; }
        $users = Db::rows('SELECT id, username, display_name FROM ' . Db::t('users'));
        foreach ($users as $u) {
            if ((int)$u['id'] === (int)($row['author_id'] ?? 0)) { continue; }
            foreach ([$u['username'], $u['display_name']] as $nm) {
                $nm = trim((string)$nm);
                if ($nm === '') { continue; }
                if (preg_match('/(^|[\s,，。！？!?（）()])@' . preg_quote($nm, '/') . '(?=$|[\s,，。！？!?（）()])/u', (string)$row['body'])) {
                    $dup = Db::one('SELECT id FROM ' . Db::t('notifications') . " WHERE user_id=? AND comment_id=? AND type='mention' LIMIT 1", [(int)$u['id'], $commentId]);
                    if ($dup === null) {
                        Db::insert('notifications', [
                            'user_id' => (int)$u['id'], 'type' => 'mention',
                            'comment_id' => $commentId, 'content_id' => (int)($row['content_id'] ?? 0),
                            'body' => mb_substr((string)$row['body'], 0, 300), 'is_read' => 0,
                        ]);
                    }
                    break;
                }
            }
        }
        self::mailNotify($commentId);
    }

    private static function mailNotify(int $commentId): void
    {
        if ((string)get_option('mail.enabled', '0') !== '1') { return; }
        $to = trim((string)get_option('mail.notify_to', ''));
        $host = trim((string)get_option('mail.host', ''));
        if ($to === '' || $host === '') { return; }
        $c = Db::row('SELECT * FROM ' . Db::t('comments') . ' WHERE id=?', [$commentId]);
        if ($c === null) { return; }
        $art = Db::row('SELECT title FROM ' . Db::t('contents') . ' WHERE id=?', [(int)$c['content_id']]);
        $subject = '新评论/提及：' . ($art['title'] ?? '文章');
        $body = $c['author_name'] . ' 说：' . (string)$c['body'] . "\n\nhttps://localhost:8082";
        $res = Mail::send(
            $host, (int)get_option('mail.port', '465'), (string)get_option('mail.ssl', '1') === '1',
            (string)get_option('mail.user', ''), yc_opt('mail.pass', ''), // 支持密文存储
            (string)get_option('mail.from', $to), $to, $subject, $body
        );
        if ($res !== true) { slog('mail send failed: ' . (is_string($res) ? $res : 'unknown')); }
    }

    public static function unreadCount(): int
    {
        return (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('notifications') . ' WHERE user_id=? AND is_read=0', [Auth::id()]);
    }

    public static function notificationsPage(): string
    {
        $rows = Db::rows(
            'SELECT n.*, c.title, c.slug FROM ' . Db::t('notifications') . ' n '
            . 'LEFT JOIN ' . Db::t('contents') . ' c ON c.id=n.content_id '
            . 'WHERE n.user_id=? ORDER BY n.id DESC LIMIT 40',
            [Auth::id()]
        );
        $lp = ui_lp();
        $html = '<div class="uc-card"><h1>' . e(__('uc.notif_title')) . ' <small class="hint">' . e(sprintf(__('uc.notif_sub'), self::unreadCount())) . '</small></h1>'
            . '<form method="post" action="' . ui_user('notify-read') . '">' . Csrf::field() . '<button class="btn ghost" type="submit">' . e(__('uc.notif_read')) . '</button></form>';
        if (!$rows) {
            $html .= '<div class="uc-empty">' . e(__('uc.notif_empty')) . '</div>';
        } else {
            $html .= '<div class="post-list">';
            foreach ($rows as $n) {
                $unread = (int)$n['is_read'] === 0 ? '<span class="dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--accent);margin-right:6px;"></span>' : '';
                $link = $n['slug'] ? '<a href="' . $lp . '/' . e((string)$n['slug']) . '.html#comments">' . e(__('uc.notif_view')) . '</a>' : '';
                $html .= '<div class="note-item">' . $unread . '<b>' . e(__('uc.notif_mention')) . '</b> · ' . e(substr((string)$n['created_at'], 0, 16)) . '<br>'
                    . e(mb_substr((string)$n['body'], 0, 160)) . ' ' . $link . '</div>';
            }
            $html .= '</div>';
        }
        return $html . '</div>';
    }

    /** P-4 检查：关键词黑名单 / 链接数上限 / 昵称·邮箱名单 */
    private static function commentClean(string $body, string $name, string $email): bool
    {
        $black = (string)get_option('comment.blacklist', '');
        foreach (preg_split('/\r\n|\r|\n/', $black) ?: [] as $kw) {
            $kw = trim($kw);
            if ($kw !== '' && (mb_stripos($body, $kw) !== false || mb_stripos($name, $kw) !== false)) {
                return false;
            }
        }
        $max = max(0, (int)get_option('comment.max_links', '3'));
        if ($max > 0) {
            $links = preg_match_all('~(https?://|www\.|(\w+\.)+(com|net|org|cn|cc|top|xyz|info|vip|site)\b)~i', $body);
            if ($links !== false && $links > $max) { return false; }
        }
        $banned = (string)get_option('comment.banned', '');
        foreach (preg_split('/\r\n|\r|\n/', $banned) ?: [] as $bn) {
            $bn = trim($bn);
            if ($bn !== '' && (mb_stripos($name, $bn) !== false || mb_stripos($email, $bn) !== false)) {
                return false;
            }
        }
        return true;
    }

    /* ================= 页面渲染（返回主区 HTML） ================= */
    public static function loginPage(): string
    {
        $f = $_SESSION['flash'] ?? '';
        unset($_SESSION['flash']);
        $back = htmlspecialchars((string)($_GET['redirect'] ?? ui_user()));
        $lp = ui_lp();
        return '<div class="uc-card"><h1>' . e(__('uc.login')) . '</h1><p class="hint">' . e(__('uc.login_hint')) . '</p>'
            . ($f !== '' ? '<div class="uc-err">' . e($f) . '</div>' : '')
            . '<form method="post" action="' . $lp . '/login"><input type="hidden" name="redirect" value="' . $back . '">' . Csrf::field()
            . '<label>' . e(__('uc.account')) . '</label><input name="username" required autofocus>'
            . '<label>' . e(__('uc.password')) . '</label><input type="password" name="password" required>'
            . '<button type="submit">' . e(__('uc.login_btn')) . '</button></form>'
            . '<p class="hint"><a href="' . ui_register($back) . '">' . e(__('uc.no_account')) . '</a></p></div>';
    }

    public static function registerPage(): string
    {
        $f = $_SESSION['flash'] ?? '';
        unset($_SESSION['flash']);
        $back = htmlspecialchars((string)($_GET['redirect'] ?? ui_user()));
        $lp = ui_lp();
        return '<div class="uc-card"><h1>' . e(__('uc.reg')) . '</h1><p class="hint">' . e(__('uc.reg_hint')) . '</p>'
            . ($f !== '' ? '<div class="uc-err">' . e($f) . '</div>' : '')
            . '<form method="post" action="' . $lp . '/register"><input type="hidden" name="redirect" value="' . $back . '">' . Csrf::field()
            . '<label>' . e(__('uc.username')) . '</label><input name="username" required autofocus>'
            . '<label>' . e(__('uc.email')) . '</label><input type="email" name="email" required>'
            . '<label>' . e(__('uc.pass6')) . '</label><input type="password" name="password" required>'
            . '<button type="submit">' . e(__('uc.reg_btn')) . '</button></form>'
            . '<p class="hint"><a href="' . ui_login($back) . '">' . e(__('uc.have_account')) . '</a></p></div>';
    }

    public static function profilePage(): string
    {
        $u = Auth::user();
        $fav = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('favorites') . ' WHERE user_id=?', [Auth::id()]);
        $marks = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('marks') . ' WHERE user_id=?', [Auth::id()]);
        $notes = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('annotations') . ' WHERE user_id=?', [Auth::id()]);
        $cm = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('comments') . ' WHERE author_id=?', [Auth::id()]);
        $unread = self::unreadCount();
        $role = ($u['role'] ?? '') === 'admin' ? __('uc.role_admin') : __('uc.role_reader');
        $uName = e((string)($u['display_name'] ?: $u['username']));
        $t = fn(int $n, string $k) => '<div><b>' . $n . '</b>' . e(__($k)) . '</div>';
        $pwMsg = '';
        $pwSt = (string)($_GET['pw'] ?? '');
        if ($pwSt === 'ok') { $pwMsg = '<p style="color:#2e6b34;">密码已更新，请下次登录使用新密码。</p>'; }
        elseif ($pwSt === 'badold') { $pwMsg = '<p style="color:#9c3f1b;">旧密码不正确。</p>'; }
        elseif ($pwSt === 'short') { $pwMsg = '<p style="color:#9c3f1b;">新密码至少 6 位。</p>'; }
        elseif ($pwSt === 'mismatch') { $pwMsg = '<p style="color:#9c3f1b;">两次输入的新密码不一致。</p>'; }
        $pwCard = '<div class="uc-card"><h2>修改密码</h2>' . $pwMsg
            . '<form method="post" action="' . ui_user('password') . '" style="max-width:420px;">'
            . '<input type="hidden" name="redirect" value="' . e(ui_user()) . '">' . Csrf::field()
            . '<label class="pf">旧密码</label><input type="password" name="old_password" required autocomplete="current-password">'
            . '<label class="pf">新密码（至少 6 位）</label><input type="password" name="new_password" required minlength="6" autocomplete="new-password">'
            . '<label class="pf">确认新密码</label><input type="password" name="confirm_password" required minlength="6">'
            . '<div style="margin-top:10px;"><button class="btn" type="submit">更新密码</button></div></form></div>';
        return '<div class="uc-card"><h1>' . e(__('uc.profile')) . '</h1>'
            . '<p><b>' . $uName . '</b> <span class="tag">' . e($role) . '</span> · ' . e((string)$u['email']) . '</p>'
            . '<div class="uc-stats">' . $t($fav, 'uc.saved') . $t($marks, 'uc.highlights') . $t($notes, 'uc.notes') . $t($cm, 'uc.comments') . '</div>'
            . '<p class="hint">' . e(__('uc.joined')) . e(substr((string)$u['created_at'], 0, 10)) . e(__('uc.last_login')) . e(substr((string)$u['last_login_at'], 0, 16)) . '</p>'
            . '<p><a href="' . ui_user('favorites') . '">' . e(__('uc.my_fav')) . '</a> · <a href="' . ui_user('notes') . '">' . e(__('uc.my_notes')) . '</a> · '
            . '<a href="' . ui_user('notifications') . '">' . e(sprintf(__('uc.my_notif'), $unread)) . '</a></p></div>' . $pwCard;
    }

    public static function favoritesPage(): string
    {
        $rows = Db::rows(
            'SELECT f.id fid, c.id, c.title, c.slug, c.views, c.published_at, c.excerpt FROM ' . Db::t('favorites') . ' f '
            . 'JOIN ' . Db::t('contents') . ' c ON c.id=f.content_id AND c.status=\'publish\' AND c.deleted_at IS NULL '
            . 'WHERE f.user_id=? ORDER BY f.id DESC LIMIT 200', [Auth::id()]
        );
        $lp = ui_lp();
        $html = '<div class="uc-card" id="favBox"><h1>' . e(__('uc.fav_title')) . ' <small class="hint">' . e(sprintf(__('uc.fav_sub'), count($rows))) . '</small></h1>';
        if (!$rows) {
            $html .= '<div class="uc-empty">' . e(__('uc.fav_empty')) . '</div>';
        } else {
            $html .= '<div class="post-list">';
            foreach ($rows as $r) {
                $html .= '<article class="post-item"><h2><a href="' . $lp . '/' . e((string)$r['slug']) . '.html">' . e($r['title']) . '</a></h2>'
                    . '<p class="post-excerpt">' . e(Str::excerpt((string)$r['excerpt'], 90)) . '</p>'
                    . '<div class="post-meta"><span>' . e(substr((string)($r['published_at'] ?? ''), 0, 10)) . '</span><span>' . e(__('meta.reads')) . Str::num((int)$r['views']) . '</span></div></article>';
            }
            $html .= '</div>';
        }
        return $html . '</div>';
    }

    public static function notesPage(): string
    {
        $uid = Auth::id();
        $marks = Db::rows(
            'SELECT m.*, c.title, c.slug FROM ' . Db::t('marks') . ' m JOIN ' . Db::t('contents') . ' c ON c.id=m.content_id '
            . 'WHERE m.user_id=? ORDER BY m.id DESC LIMIT 300', [$uid]
        );
        $notes = Db::rows(
            'SELECT n.*, c.title, c.slug FROM ' . Db::t('annotations') . ' n JOIN ' . Db::t('contents') . ' c ON c.id=n.content_id '
            . 'WHERE n.user_id=? AND n.mark_id IS NULL ORDER BY n.id DESC LIMIT 300', [$uid]
        );
        // 批量取挂在划线下的注释（替代逐 mark 查库，消除 N+1）
        $markIds = array_map('intval', array_column($marks, 'id'));
        $markAnns = [];
        if ($markIds) {
            $in = implode(',', array_fill(0, count($markIds), '?'));
            foreach (Db::rows('SELECT * FROM ' . Db::t('annotations') . " WHERE user_id=? AND mark_id IN ($in) ORDER BY id ASC", array_merge([$uid], $markIds)) as $a) {
                $markAnns[(int)$a['mark_id']][] = $a;
            }
        }
        $byPost = [];
        foreach ($marks as $m) {
            $key = (int)$m['content_id'];
            $byPost[$key] ??= ['title' => $m['title'], 'slug' => $m['slug'], 'marks' => [], 'free' => []];
            $byPost[$key]['marks'][] = $m;
        }
        foreach ($notes as $n) {
            $key = (int)$n['content_id'];
            $byPost[$key] ??= ['title' => $n['title'], 'slug' => $n['slug'], 'marks' => [], 'free' => []];
            $byPost[$key]['free'][] = $n;
        }
        $lp = ui_lp();
        $html = '<div class="uc-card"><h1>' . e(__('uc.notes_title')) . ' <small class="hint">' . e(__('uc.notes_sub')) . '</small></h1>';
        if (!$byPost) {
            $html .= '<div class="uc-empty">' . e(__('uc.notes_empty')) . '</div>';
        }
        foreach ($byPost as $key => $g) {
            $html .= '<div class="note-group"><h2><a href="' . $lp . '/' . e((string)$g['slug']) . '.html">' . e($g['title']) . '</a></h2>';
            foreach ($g['marks'] as $m) {
                $html .= '<div class="note-item"><span class="note-mark" style="background:' . e($m['color']) . '">' . e(mb_substr((string)$m['quote'], 0, 80)) . '</span>'
                    . ' <span class="hint">' . e(sprintf(__('uc.para'), (string)$m['anchor_ref'])) . '</span>'
                    . '<form class="inline" method="post" action="' . ui_user('mark-del') . '"><input type="hidden" name="id" value="' . (int)$m['id'] . '">' . Csrf::field()
                    . '<button class="btn-link" type="submit">' . e(__('uc.del_mark')) . '</button></form>';
                foreach ($markAnns[(int)$m['id']] ?? [] as $a) {
                    $html .= '<div class="note-ann">📝 ' . e($a['body']) . '</div>';
                }
                $html .= '</div>';
            }
            foreach ($g['free'] as $n) {
                $html .= '<div class="note-item">📝 ' . e($n['body'])
                    . '<form class="inline" method="post" action="' . ui_user('annotation-del') . '"><input type="hidden" name="id" value="' . (int)$n['id'] . '">' . Csrf::field()
                    . '<button class="btn-link" type="submit">' . e(__('uc.del')) . '</button></form></div>';
            }
            $html .= '</div>';
        }
        return $html . '</div>';
    }
}
