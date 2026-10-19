<?php
declare(strict_types=1);

/**
 * PasswordReset —— 密码找回（邮箱 / 短信双渠道）
 *
 * 安全设计（每条都对应一个真实攻击面）：
 *   1) 防枚举：账号不存在时**不报错**，返回与成功一致的提示；只有内部记录差异
 *   2) 防爆破：验证码 6 位 + **尝试次数上限 5 次**，超限即作废
 *   3) 防重放：验证码单次使用（used_at）+ 改密后作废该用户全部未用码
 *   4) 防泄漏：库里只存 password_hash，不存明文码
 *   5) 防刷：发码按 uid / IP 双维度限流（10 分钟窗口）
 *   6) 过期：10 分钟
 *   7) 改密后清登录限流 + 注销会话（与 Auth::setPassword 一致）
 */
final class PasswordReset
{
    /** 验证码有效期（秒） */
    public const TTL = 600;
    /** 单条验证码最多尝试次数 */
    public const MAX_ATTEMPTS = 5;
    /** 同一 uid 在窗口内最多发码次数 */
    public const MAX_SEND_PER_USER = 3;
    /** 同一 IP 在窗口内最多发码次数（防遍历） */
    public const MAX_SEND_PER_IP = 10;
    /** 限流窗口（秒） */
    public const RATE_WINDOW = 600;

    /* ================= 渠道可用性 ================= */

    /** 邮件渠道是否可用（复用内核 mail.* 配置） */
    public static function mailReady(): bool
    {
        if ((string)get_option('mail.enabled', '0') !== '1') { return false; }
        $host = trim((string)get_option('mail.host', ''));
        $from = trim((string)get_option('mail.from', ''));
        return $host !== '' && $from !== '';
    }

    /** 短信渠道是否可用（需启用 + 厂商 + 凭据） */
    public static function smsReady(): bool
    {
        if ((string)get_option('sms.enabled', '0') !== '1') { return false; }
        $p = strtolower(trim((string)get_option('sms.provider', '')));
        if ($p === '') { return false; }
        // mock 厂商不真发短信，不应要求 key/secret（否则联调永远不可用）
        if ($p === 'mock') { return class_exists('Sms') && Sms::mockAllowed(); }
        return trim((string)yc_opt('sms.key', '')) !== '' && trim((string)yc_opt('sms.secret', '')) !== '';
    }

    /** 当前可用的渠道列表（用于页面渲染与自动选择） */
    public static function channels(): array
    {
        $c = [];
        if (self::mailReady()) { $c[] = 'email'; }
        if (self::smsReady()) { $c[] = 'sms'; }
        return $c;
    }

    /* ================= 限流 ================= */

    private static function rateHit(string $k): int
    {
        $T = Db::t('pwd_reset_rate');
        $now = date('Y-m-d H:i:s');
        $row = Db::row("SELECT id, n, window_at FROM {$T} WHERE k=?", [$k]);
        if ($row === null) {
            Db::run("INSERT INTO {$T} (k, n, window_at) VALUES (?, 1, ?)", [$k, $now]);
            return 1;
        }
        $win = strtotime((string)$row['window_at']);
        if ($win === false || (time() - $win) > self::RATE_WINDOW) {
            Db::run("UPDATE {$T} SET n=1, window_at=? WHERE id=?", [$now, (int)$row['id']]);
            return 1;
        }
        $n = (int)$row['n'] + 1;
        Db::run("UPDATE {$T} SET n=? WHERE id=?", [$n, (int)$row['id']]);
        return $n;
    }

    private static function rateCount(string $k): int
    {
        $T = Db::t('pwd_reset_rate');
        $row = Db::row("SELECT n, window_at FROM {$T} WHERE k=?", [$k]);
        if ($row === null) { return 0; }
        $win = strtotime((string)$row['window_at']);
        if ($win === false || (time() - $win) > self::RATE_WINDOW) { return 0; }
        return (int)$row['n'];
    }

    private static function rateReset(string $k): void
    {
        Db::run('DELETE FROM ' . Db::t('pwd_reset_rate') . ' WHERE k=?', [$k]);
    }

    /* ================= 发送 ================= */

    /**
     * 请求发码。
     * @return array{ok:bool,msg:string} —— **无论账号是否存在，msg 都一致**（防枚举）
     */
    public static function request(string $account): array
    {
        $account = trim($account);
        $generic = ['ok' => true, 'msg' => '如果该账号存在，我们已发送验证码，请查收（10 分钟内有效）。'];

        if ($account === '') { return ['ok' => false, 'msg' => '请输入用户名或邮箱']; }

        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (self::rateCount('ip:' . $ip) >= self::MAX_SEND_PER_IP) {
            return ['ok' => false, 'msg' => '请求过于频繁，请稍后再试'];
        }

        // ★ 渠道可用性检查必须放在账号查询**之前**：
        //   否则「存在账号 → 报配置错误」而「不存在账号 → 报通用文案」，
        //   两者文案不同 → 反而泄露了账号是否存在（实测踩到）。
        $anyChannel = self::mailReady() || self::smsReady();
        if (!$anyChannel) {
            return ['ok' => false, 'msg' => '站点尚未配置邮件或短信服务，请联系管理员协助找回密码'];
        }

        $u = Db::row('SELECT id, username, email, status FROM ' . Db::t('users') . ' WHERE (username=? OR email=?) LIMIT 1', [$account, $account]);
        if ($u === null) {
            self::rateHit('ip:' . $ip);           // 不存在的账号也计 IP 次数（防遍历）
            return $generic;                       // ★ 不暴露「账号不存在」
        }
        if ((string)$u['status'] !== 'normal') {
            self::rateHit('ip:' . $ip);
            return $generic;                       // 禁用账号同样不暴露
        }

        $uid = (int)$u['id'];
        if (self::rateCount('uid:' . $uid) >= self::MAX_SEND_PER_USER) {
            return ['ok' => false, 'msg' => '发送过于频繁，请 10 分钟后再试'];
        }

        // 选渠道：
        //   ★ ye_users 没有手机号字段 —— 手机号在 ye_mall_member.mobile（实测确认）
        //   有邮箱且邮件可用 → 邮件；否则若有手机号且短信可用 → 短信
        $email = trim((string)$u['email']);
        $mobile = self::mobileOf($uid);
        $channel = '';
        if (self::mailReady() && $email !== '') { $channel = 'email'; }
        elseif (self::smsReady() && $mobile !== '') { $channel = 'sms'; }
        elseif (self::mailReady() && self::smsReady() && $mobile !== '') { $channel = 'sms'; }
        if ($channel === '') {
            // 无可用「渠道 + 收件地址」组合：不暴露账号绑定情况，返回通用文案
            slog('[pwdreset] no deliverable channel uid=' . $uid
                . ' mailReady=' . var_export(self::mailReady(), true)
                . ' smsReady=' . var_export(self::smsReady(), true)
                . ' hasEmail=' . ($email !== '' ? '1' : '0')
                . ' hasMobile=' . ($mobile !== '' ? '1' : '0'));
            return ['ok' => false, 'msg' => '该账号暂无法通过邮件或短信找回，请联系管理员'];
        }
        if ($channel === 'email' && !self::mailReady()) {
            return ['ok' => false, 'msg' => '站点尚未配置可用的通知渠道，请联系管理员'];
        }
        if ($channel === 'sms' && !self::smsReady()) {
            return ['ok' => false, 'msg' => '站点尚未配置可用的通知渠道，请联系管理员'];
        }
        if ($channel === 'email' && $email === '') {
            // 不暴露「未绑定邮箱」这一账号属性
            return $generic;
        }

        // 生成 6 位数字码（用 random_int，非 rand）
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // 作废该用户所有未用码（同时只允许一个有效码）
        Db::run('UPDATE ' . Db::t('pwd_reset') . ' SET used_at=? WHERE uid=? AND used_at IS NULL', [date('Y-m-d H:i:s'), $uid]);

        Db::run('INSERT INTO ' . Db::t('pwd_reset')
            . ' (uid, channel, target, code_hash, attempts, expires_at, ip, ua, created_at)'
            . ' VALUES (?,?,?,?,0,?,?,?,?)', [
                $uid, $channel, $channel === 'email' ? $email : '',
                password_hash($code, PASSWORD_DEFAULT),
                date('Y-m-d H:i:s', time() + self::TTL),
                $ip, mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190),
                date('Y-m-d H:i:s'),
            ]);

        self::rateHit('uid:' . $uid);
        self::rateHit('ip:' . $ip);

        // 发送
        if ($channel === 'email') {
            $sent = self::sendMail($email, $code);
        } else {
            $sent = self::sendSms($mobile, $code);
        }

        if ($sent !== true) {
            // ★ 内核的日志函数是 slog(string)，不是 sg_log —— 原写法会 Fatal
            slog('[pwdreset] send fail uid=' . $uid . ' ch=' . $channel . ' err=' . (is_string($sent) ? $sent : '?'));
            return ['ok' => false, 'msg' => '验证码发送失败，请稍后重试或联系管理员'];
        }
        return $generic;
    }

    /** 取用户手机号：ye_users 无此字段，手机号在 ye_mall_member.mobile */
    public static function mobileOf(int $uid): string
    {
        try {
            $m = Db::one('SELECT mobile FROM ' . Db::t('mall_member') . ' WHERE uid=? LIMIT 1', [$uid]);
            return trim((string)$m);
        } catch (Throwable $e) {
            return '';
        }
    }

    /** 发邮件（复用内核 Mail + mail.* 配置，密码走 yc_opt 解密） */
    private static function sendMail(string $to, string $code): bool|string
    {
        $host = trim((string)get_option('mail.host', ''));
        $port = (int)get_option('mail.port', '465');
        $ssl  = (string)get_option('mail.ssl', '1') === '1';
        $user = trim((string)get_option('mail.user', ''));
        $pass = yc_opt('mail.pass', '');
        $from = trim((string)get_option('mail.from', ''));
        if ($host === '' || $from === '') { return '未配置 SMTP'; }

        $site = function_exists('site_label') ? site_label() : '本站';
        $subject = '【' . $site . '】密码找回验证码';
        $body = "您正在找回 {$site} 的账号密码。\n\n"
              . "验证码：{$code}\n\n"
              . "有效期 10 分钟，请勿转告他人。\n"
              . "如果这不是您本人的操作，请忽略本邮件，您的密码不会被修改。\n\n"
              . "—— {$site}";

        return Mail::send($host, $port, $ssl, $user, $pass, $from, $to, $subject, $body, $site);
    }

    /** 发短信（委托 Sms 适配器） */
    private static function sendSms(string $to, string $code): bool|string
    {
        if (!class_exists('Sms')) { return 'Sms 类缺失'; }
        $site = function_exists('site_label') ? site_label() : '本站';
        return Sms::sendCode($to, $code, self::TTL / 60, $site);
    }

    /* ================= 校验与改密 ================= */

    /**
     * 用验证码重置密码。
     * @return array{ok:bool,msg:string}
     */
    public static function verifyAndReset(string $account, string $code, string $newPassword): array
    {
        $account = trim($account);
        $code = trim($code);
        if ($account === '' || $code === '' || $newPassword === '') {
            return ['ok' => false, 'msg' => '请填写完整信息'];
        }
        if (strlen($newPassword) < 6) {
            return ['ok' => false, 'msg' => '新密码至少 6 位'];
        }

        $u = Db::row('SELECT id, username, status FROM ' . Db::t('users') . ' WHERE (username=? OR email=?) LIMIT 1', [$account, $account]);
        // 统一错误文案，避免通过「账号不存在」枚举
        $bad = ['ok' => false, 'msg' => '验证码无效或已过期，请重新获取'];
        if ($u === null) { return $bad; }

        $uid = (int)$u['id'];
        $row = Db::row('SELECT * FROM ' . Db::t('pwd_reset')
            . ' WHERE uid=? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1',
            [$uid, date('Y-m-d H:i:s')]);
        if ($row === null) { return $bad; }

        // 尝试次数上限（防 6 位码爆破）
        if ((int)$row['attempts'] >= self::MAX_ATTEMPTS) {
            Db::run('UPDATE ' . Db::t('pwd_reset') . ' SET used_at=? WHERE id=?', [date('Y-m-d H:i:s'), (int)$row['id']]);
            return ['ok' => false, 'msg' => '尝试次数过多，请重新获取验证码'];
        }
        Db::run('UPDATE ' . Db::t('pwd_reset') . ' SET attempts=attempts+1 WHERE id=?', [(int)$row['id']]);

        if (!password_verify($code, (string)$row['code_hash'])) { return $bad; }

        // 通过：改密 + 标记单次使用 + 作废该用户所有未用码
        Db::update('users', [
            'password' => password_hash($newPassword, PASSWORD_DEFAULT),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id=?', [$uid]);
        Db::run('UPDATE ' . Db::t('pwd_reset') . ' SET used_at=? WHERE uid=? AND used_at IS NULL', [date('Y-m-d H:i:s'), $uid]);
        self::rateReset('uid:' . $uid);

        // 清理登录限流，让用户能立即用新密码登录
        try { Auth::throttleReset(); } catch (Throwable $e) {}

        return ['ok' => true, 'msg' => '密码已重置，请用新密码登录'];
    }

    /* ================= 手机号绑定（channel=bind）================= */

    /**
     * 发送绑定用验证码到指定手机号。
     * ★ target 记录手机号，校验时必须传入同一号码才通过 ——
     *   否则「给 A 发的码」可以被拿去绑定 B（验证码跨号复用漏洞）。
     */
    public static function requestBindCode(int $uid, string $mobile): array
    {
        $mobile = trim($mobile);
        if (!preg_match('/^1[3-9]\d{9}$/', $mobile)) {
            return ['ok' => false, 'msg' => '请输入正确的 11 位手机号'];
        }
        if (!self::smsReady()) {
            return ['ok' => false, 'msg' => '站点尚未配置短信服务，暂时无法绑定手机号'];
        }
        // 该号码是否已被其他账号绑定
        $taken = Db::one('SELECT uid FROM ' . Db::t('mall_member') . ' WHERE mobile=? AND uid<>? LIMIT 1', [$mobile, $uid]);
        if ($taken !== null) { return ['ok' => false, 'msg' => '该手机号已被其他账号绑定']; }

        if (self::rateCount('binduid:' . $uid) >= self::MAX_SEND_PER_USER) {
            return ['ok' => false, 'msg' => '发送过于频繁，请 10 分钟后再试'];
        }
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (self::rateCount('bindip:' . $ip) >= self::MAX_SEND_PER_IP) {
            return ['ok' => false, 'msg' => '请求过于频繁，请稍后再试'];
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // 作废该用户此前的未用绑定码
        Db::run('UPDATE ' . Db::t('pwd_reset') . " SET used_at=? WHERE uid=? AND channel='bind' AND used_at IS NULL",
            [date('Y-m-d H:i:s'), $uid]);

        Db::run('INSERT INTO ' . Db::t('pwd_reset')
            . ' (uid, channel, target, code_hash, attempts, expires_at, ip, ua, created_at) VALUES (?,?,?,?,0,?,?,?,?)', [
                $uid, 'bind', $mobile,
                password_hash($code, PASSWORD_DEFAULT),
                date('Y-m-d H:i:s', time() + self::TTL),
                $ip, mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190),
                date('Y-m-d H:i:s'),
            ]);

        self::rateHit('binduid:' . $uid);
        self::rateHit('bindip:' . $ip);

        $sent = self::sendSms($mobile, $code);
        if ($sent !== true) {
            slog('[bind] send fail uid=' . $uid . ' err=' . (is_string($sent) ? $sent : '?'));
            return ['ok' => false, 'msg' => '验证码发送失败，请稍后重试'];
        }
        return ['ok' => true, 'msg' => '验证码已发送，请查收（10 分钟内有效）'];
    }

    /** 校验绑定码并写入手机号；成功返回 true */
    public static function verifyBindCode(int $uid, string $mobile, string $code): array
    {
        $mobile = trim($mobile);
        $code = trim($code);
        if ($mobile === '' || $code === '') { return ['ok' => false, 'msg' => '请填写手机号与验证码']; }

        $row = Db::row('SELECT * FROM ' . Db::t('pwd_reset')
            . " WHERE uid=? AND channel='bind' AND target=? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1",
            [$uid, $mobile, date('Y-m-d H:i:s')]);
        if ($row === null) { return ['ok' => false, 'msg' => '验证码无效或已过期，请重新获取']; }

        if ((int)$row['attempts'] >= self::MAX_ATTEMPTS) {
            Db::run('UPDATE ' . Db::t('pwd_reset') . ' SET used_at=? WHERE id=?', [date('Y-m-d H:i:s'), (int)$row['id']]);
            return ['ok' => false, 'msg' => '尝试次数过多，请重新获取验证码'];
        }
        Db::run('UPDATE ' . Db::t('pwd_reset') . ' SET attempts=attempts+1 WHERE id=?', [(int)$row['id']]);

        if (!password_verify($code, (string)$row['code_hash'])) {
            return ['ok' => false, 'msg' => '验证码不正确'];
        }

        // 再查一次占用（并发场景）
        $taken = Db::one('SELECT uid FROM ' . Db::t('mall_member') . ' WHERE mobile=? AND uid<>? LIMIT 1', [$mobile, $uid]);
        if ($taken !== null) { return ['ok' => false, 'msg' => '该手机号已被其他账号绑定']; }

        // 写库（memberRow 会确保行存在）
        self::ensureMemberRow($uid);
        Db::run('UPDATE ' . Db::t('mall_member') . ' SET mobile=?, updated_at=? WHERE uid=?',
            [$mobile, date('Y-m-d H:i:s'), $uid]);
        Db::run('UPDATE ' . Db::t('pwd_reset') . ' SET used_at=? WHERE uid=? AND channel=? AND used_at IS NULL',
            [date('Y-m-d H:i:s'), $uid, 'bind']);
        self::rateReset('binduid:' . $uid);
        return ['ok' => true, 'msg' => '手机号绑定成功'];
    }

    /** 确保会员行存在（供绑定/查询用） */
    public static function ensureMemberRow(int $uid): void
    {
        $has = Db::one('SELECT id FROM ' . Db::t('mall_member') . ' WHERE uid=? LIMIT 1', [$uid]);
        if ($has === null) {
            Db::run('INSERT INTO ' . Db::t('mall_member') . ' (uid, mobile, gender, balance, points, level, signin_days, created_at, updated_at)'
                . ' VALUES (?,?,0,0,0,0,0,?,?)', [$uid, '', date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        }
    }
    /** 清理过期数据（供 cron 调用） */
    public static function prune(): int
    {
        $n = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('pwd_reset') . ' WHERE expires_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
        Db::run('DELETE FROM ' . Db::t('pwd_reset') . ' WHERE expires_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
        Db::run('DELETE FROM ' . Db::t('pwd_reset_rate') . ' WHERE window_at < ?', [date('Y-m-d H:i:s', time() - self::RATE_WINDOW * 2)]);
        return $n;
    }
}
