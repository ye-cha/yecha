<?php
declare(strict_types=1);
/**
 * Mall · M7 会员中心（个人中心加强）
 * —— 以 trait 形式挂进 Mall 类，避免大改 lib.php。
 * 结构对齐 51puer 会员中心：会员中心(欢迎页/个人资料/账户安全/收货地址/账户余额/提现记录/我的优惠券/我的虚拟码/我的积分/我的足迹)
 *                        交易中心(我的订单/退款退货及维修/商品评价晒单/商品收藏)
 * 用户：复用博客用户中心（ye_users + Auth），会员扩展数据落 ye_mall_member 等表。
 */
trait MallMember
{
    /* ================= 侧栏 / 外壳 ================= */

    private static function memberMenu(): array
    {
        return [
            ['t' => '会员中心', 'items' => [
                ['k' => '',          'n' => '欢迎页',         'h' => '/mall/user/'],
                ['k' => 'profile',   'n' => '个人资料',       'h' => '/mall/user/profile'],
                ['k' => 'security',  'n' => '账户安全',       'h' => '/mall/user/security'],
                ['k' => 'address',   'n' => '收货地址',       'h' => '/mall/user/address'],
                ['k' => 'balance',   'n' => '账户余额',       'h' => '/mall/user/balance'],
                ['k' => 'withdraw',  'n' => '提现记录',       'h' => '/mall/user/withdraw'],
                ['k' => 'coupon',    'n' => '我的优惠券',     'h' => '/mall/user/coupon'],
                ['k' => 'vcode',     'n' => '我的虚拟码',     'h' => '/mall/user/vcode'],
                ['k' => 'points',    'n' => '我的积分',       'h' => '/mall/user/points'],
                ['k' => 'footprint', 'n' => '我的足迹',       'h' => '/mall/user/footprint'],
                ['k' => 'level',     'n' => '会员等级',       'h' => '/mall/user/level'],
                ['k' => 'message',   'n' => '站内消息',       'h' => '/mall/user/message'],
            ]],
            ['t' => '交易中心', 'items' => [
                ['k' => 'order',     'n' => '我的订单',           'h' => '/mall/user/order'],
                ['k' => 'aftersale', 'n' => '退款/退货及维修',    'h' => '/mall/user/aftersale'],
                ['k' => 'review',    'n' => '商品评价/晒单',      'h' => '/mall/user/review'],
                ['k' => 'favorite',  'n' => '商品收藏',           'h' => '/mall/user/favorite'],
            ]],
        ];
    }

    private static function memberSide(string $active): string
    {
        $html = '<aside class="mm-side">';
        foreach (self::memberMenu() as $grp) {
            $html .= '<h3>' . e((string)$grp['t']) . '</h3><nav>';
            foreach ($grp['items'] as $it) {
                $html .= '<a' . ($it['k'] === $active ? ' class="on"' : '') . ' href="' . e((string)$it['h']) . '">' . e((string)$it['n']) . '</a>';
            }
            $html .= '</nav>';
        }
        return $html . '</aside>';
    }

    /** 会员中心统一外壳（面包屑 + 侧栏 + 主区；不含 head/footer） */
    private static function memberWrap(string $active, string $inner, string $crumb = ''): string
    {
        return '<div class="mall-crumb"><div class="mall-w"><a href="/mall">商城</a> <i>&gt;</i> <a href="/mall/user/">会员中心</a>'
            . ($crumb !== '' ? ' <i>&gt;</i> <span>' . e($crumb) . '</span>' : '')
            . '</div></div>'
            . self::flash()
            . '<div class="mall-panel"><div class="mall-w"><div class="mall-member">'
            . self::memberSide($active)
            . '<div class="mm-main">' . $inner . '</div>'
            . '</div></div></div>';
    }

    /* ================= 会员 / 钱包 / 积分 基础 ================= */

    /** 会员行（不存在则建档） */
    private static function memberRow(int $uid): array
    {
        $m = Db::row('SELECT * FROM ' . self::t('member') . ' WHERE uid=? LIMIT 1', [$uid]);
        if ($m === null) {
            Db::insert('mall_member', ['uid' => $uid]);
            $m = Db::row('SELECT * FROM ' . self::t('member') . ' WHERE uid=? LIMIT 1', [$uid]);
        }
        return $m ?? ['uid' => $uid, 'balance' => '0.00', 'points' => 0, 'mobile' => '', 'gender' => 0,
            'birthday' => null, 'real_name' => '', 'level' => 1, 'total_recharge' => '0.00',
            'total_withdraw' => '0.00', 'total_consume' => '0.00'];
    }

    /** 余额变动（member + 流水；负数为支出，余额不足抛异常）。type 1充值 2消费 3退款 4提现 5调整 */
    private static function walletApply(int $uid, int $type, float $amount, string $remark): void
    {
        self::memberRow($uid);
        $amount = round($amount, 2);
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            if ($amount >= 0) {
                if ($type === 1) {
                    Db::run('UPDATE ' . self::t('member') . ' SET balance=balance+?, total_recharge=total_recharge+? WHERE uid=?', [$amount, $amount, $uid]);
                } else {
                    Db::run('UPDATE ' . self::t('member') . ' SET balance=balance+? WHERE uid=?', [$amount, $uid]);
                }
            } else {
                $need = abs($amount);
                $st = Db::run('UPDATE ' . self::t('member') . ' SET balance=balance-? WHERE uid=? AND balance>=?', [$need, $uid, $need]);
                if ($st->rowCount() === 0) { throw new RuntimeException('余额不足'); }
                if ($type === 2) {
                    Db::run('UPDATE ' . self::t('member') . ' SET total_consume=total_consume+? WHERE uid=?', [$need, $uid]);
                }
            }
            $bal = (float)Db::one('SELECT balance FROM ' . self::t('member') . ' WHERE uid=?', [$uid]);
            Db::insert('mall_balance_log', ['uid' => $uid, 'type' => $type, 'amount' => $amount, 'balance_after' => $bal, 'remark' => $remark]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** 积分变动（member + 流水） */
    private static function pointsApply(int $uid, int $points, string $remark): void
    {
        if ($points === 0) { return; }
        self::memberRow($uid);
        if ($points < 0) {
            $st = Db::run('UPDATE ' . self::t('member') . ' SET points=points+? WHERE uid=? AND points>=?', [$points, $uid, -$points]);
            if ($st->rowCount() === 0) { return; }
        } else {
            Db::run('UPDATE ' . self::t('member') . ' SET points=points+? WHERE uid=?', [$points, $uid]);
        }
        $after = (int)Db::one('SELECT points FROM ' . self::t('member') . ' WHERE uid=?', [$uid]);
        Db::insert('mall_points_log', ['uid' => $uid, 'type' => $points > 0 ? 1 : 2, 'points' => $points, 'points_after' => $after, 'remark' => $remark]);
    }

    /** 支付成功后的会员侧副作用：赠积分 + 生成提货码（幂等） */
    private static function onPaid(array $o, string $driver, string $tradeNo): void
    {
        $uid = (int)$o['uid'];
        if ($uid <= 0) { return; }
        $pts = max(1, (int)floor((float)$o['pay_price']));
        try { self::pointsApply($uid, $pts, '订单 ' . $o['order_no'] . ' 支付赠积分'); } catch (\Throwable $e) {}
        try {
            if (Db::one('SELECT id FROM ' . self::t('virtual_code') . ' WHERE order_no=? LIMIT 1', [(string)$o['order_no']]) !== null) { return; }
            Db::insert('mall_virtual_code', [
                'uid' => $uid, 'order_id' => (int)$o['id'], 'order_no' => (string)$o['order_no'],
                'goods_id' => 0, 'title' => '订单提货码', 'code' => 'TK' . strtoupper(bin2hex(random_bytes(5))),
                'type' => 3, 'status' => 0,
            ]);
        } catch (\Throwable $e) {}
    }

    /* ================= 路由：/mall/user[/...] ================= */

    private static function userCenter(array $path, array $query): array
    {
        $cats = self::categories();
        $head = self::css() . self::topbar() . self::header() . self::navBar($cats);
        $uid = self::uid();
        $sub = (string)($path[1] ?? '');
        if ($uid <= 0) {
            $html = $head . '<div class="mall-panel"><div class="mall-w">'
                . self::loginGuide('/mall/user/' . ($sub !== '' ? $sub : ''), '登录后进入会员中心')
                . '</div></div>' . self::footer();
            return ['html' => $html, 'title' => '会员中心 - 商城'];
        }
        $titles = [
            '' => '会员中心', 'profile' => '个人资料', 'security' => '账户安全', 'address' => '收货地址',
            'balance' => '账户余额', 'withdraw' => '提现记录', 'coupon' => '我的优惠券', 'vcode' => '我的虚拟码',
            'points' => '我的积分', 'footprint' => '我的足迹', 'order' => '我的订单',
            'aftersale' => '退款/退货及维修', 'review' => '商品评价/晒单', 'favorite' => '商品收藏',
            'logistics' => '物流查询',
            'signin' => '每日签到',
            // M13 会员等级折扣 / M12 站内消息
            'level' => '会员等级', 'message' => '站内消息',
        ];
        if (!isset($titles[$sub])) { $sub = ''; }
        switch ($sub) {
            case 'profile':   $inner = self::mmProfile($uid); break;
            case 'security':  $inner = self::mmSecurity($uid); break;
            case 'address':   $inner = self::mmAddress($uid, $query); break;
            case 'balance':   $inner = self::mmBalance($uid); break;
            case 'withdraw':  $inner = self::mmWithdraw($uid); break;
            case 'coupon':    $inner = self::mmCoupon($uid); break;
            case 'vcode':     $inner = self::mmVcode($uid); break;
            case 'points':    $inner = self::mmPoints($uid); break;
            case 'signin':    $inner = self::mmSignin($uid); break;
            case 'footprint': $inner = self::mmFootprint($uid); break;
            case 'order':     $inner = self::mmOrder($uid, $query); break;
            case 'aftersale': $inner = self::mmAftersale($uid); break;
            case 'review':    $inner = self::mmReview($uid); break;
            case 'favorite':  $inner = self::mmFavorite($uid); break;
            case 'logistics': $inner = self::mmLogistics($uid); break;
            // M13 / M12：会员等级页、站内消息箱
            case 'level':     $inner = self::mmLevel($uid); break;
            case 'message':   $inner = self::mmMessage($uid); break;
            default:          $inner = self::mmWelcome($uid); break;
        }
        $html = $head . self::memberWrap($sub, $inner, $titles[$sub]) . self::footer();
        return ['html' => $html, 'title' => $titles[$sub] . ' - 商城'];
    }

    /* ================= 页面：欢迎页 ================= */

    private static function mmWelcome(int $uid): string
    {
        $u = Auth::user();
        $m = self::memberRow($uid);
        $name = (string)($u['display_name'] ?? $u['username'] ?? '');
        $cnt = static function (int $st) use ($uid): int {
            return (int)Db::one('SELECT COUNT(*) FROM ' . self::t('order') . ' WHERE uid=? AND status=?', [$uid, $st]);
        };
        $cpn = (int)Db::one('SELECT COUNT(*) FROM ' . self::t('user_coupon') . ' uc JOIN ' . self::t('coupon') . ' c ON c.id=uc.coupon_id'
            . ' WHERE uc.uid=? AND uc.status=0 AND (c.end_time IS NULL OR c.end_time>=?)', [$uid, date('Y-m-d H:i:s')]);
        $fav = (int)Db::one('SELECT COUNT(*) FROM ' . self::t('favorite') . ' WHERE uid=?', [$uid]);
        $fp = (int)Db::one('SELECT COUNT(*) FROM ' . self::t('footprint') . ' WHERE uid=?', [$uid]);

        $html = '<h3 class="mm-title">欢迎页</h3>';
        $html .= '<div class="mm-hello">'
            . '<div class="av">' . ($u !== null && (string)$u['avatar'] !== ''
                ? '<img src="' . e((string)$u['avatar']) . '" alt="">'
                : '<span>' . e(mb_substr($name !== '' ? $name : 'U', 0, 1)) . '</span>') . '</div>'
            . '<div class="tx"><b>您好，' . e($name) . '</b>'
            . '<p>会员等级 Lv.' . (int)$m['level'] . ' · 上次登录 ' . e((string)($u['last_login_at'] ?? '—')) . '</p></div>'
            . '<div class="wal"><span>账户余额 <b>¥' . e(number_format((float)$m['balance'], 2, '.', '')) . '</b></span>'
            . '<span>积分 <b>' . (int)$m['points'] . '</b></span></div></div>';

        $stats = [
            ['待付款', $cnt(0), '/mall/user/order?st=0'],
            ['待发货', $cnt(1), '/mall/user/order?st=1'],
            ['待收货', $cnt(2), '/mall/user/order?st=2'],
            ['已完成', $cnt(3), '/mall/user/order?st=3'],
            ['可用券', $cpn, '/mall/user/coupon'],
            ['收藏', $fav, '/mall/user/favorite'],
            ['足迹', $fp, '/mall/user/footprint'],
        ];
        $html .= '<div class="mm-stats">';
        foreach ($stats as $s) {
            $html .= '<a class="mm-stat" href="' . e($s[2]) . '"><b>' . $s[1] . '</b><span>' . e($s[0]) . '</span></a>';
        }
        $html .= '</div>';

        $rows = Db::rows('SELECT order_no, status, pay_price, created_at FROM ' . self::t('order') . ' WHERE uid=? ORDER BY id DESC LIMIT 5', [$uid]);
        $html .= '<div class="mm-sec"><h4>最近订单</h4>';
        if ($rows === []) {
            $html .= '<p class="mm-dim">还没有订单，<a href="/mall">去逛逛商城</a>。</p>';
        } else {
            $html .= '<table class="mm-tbl"><tr><th>订单号</th><th>状态</th><th>金额</th><th>下单时间</th><th></th></tr>';
            foreach ($rows as $o) {
                $html .= '<tr><td>' . e((string)$o['order_no']) . '</td>'
                    . '<td><span class="mm-st s' . (int)$o['status'] . '">' . e(self::orderStatus((int)$o['status'])) . '</span></td>'
                    . '<td class="c-red">¥' . e(number_format((float)$o['pay_price'], 2, '.', '')) . '</td>'
                    . '<td class="mm-dim">' . e((string)$o['created_at']) . '</td>'
                    . '<td><a class="mini" href="/mall/order/' . e((string)$o['order_no']) . '">详情</a></td></tr>';
            }
            $html .= '</table>';
        }
        return $html . '</div>';
    }

    /* ================= 页面：个人资料 / 账户安全 ================= */

    private static function mmProfile(int $uid): string
    {
        $u = Auth::user();
        $m = self::memberRow($uid);
        $html = '<h3 class="mm-title">个人资料</h3>';
        $html .= '<form class="mm-form" method="post" action="/mall/user/profile">' . Csrf::field()
            . '<input type="hidden" name="act" value="member_profile">'
            . '<label>用户名<span class="ro">' . e((string)$u['username']) . '</span><i>用户名不可修改</i></label>'
            . '<label>昵称<input type="text" name="display_name" value="' . e((string)$u['display_name']) . '" maxlength="100" required></label>'
            . '<label>真实姓名<input type="text" name="real_name" value="' . e((string)$m['real_name']) . '" maxlength="32"></label>'
            // ★ 手机号不可在此直改（原来可自由填、无校验）→ 改到「账户安全」走验证码绑定
            . '<label>手机号<span class="ro">' . (trim((string)$m['mobile']) !== '' ? e((string)$m['mobile']) : '未绑定') . '</span>'
            . '<i>如需绑定或更换，请到「账户安全」页通过短信验证码操作</i></label>'
            . '<label>性别<select name="gender">'
            . '<option value="0"' . ((int)$m['gender'] === 0 ? ' selected' : '') . '>保密</option>'
            . '<option value="1"' . ((int)$m['gender'] === 1 ? ' selected' : '') . '>男</option>'
            . '<option value="2"' . ((int)$m['gender'] === 2 ? ' selected' : '') . '>女</option></select></label>'
            . '<label>生日<input type="date" name="birthday" value="' . e((string)($m['birthday'] ?? '')) . '"></label>'
            . '<label>头像地址<input type="text" name="avatar" value="' . e((string)$u['avatar']) . '" maxlength="255" placeholder="图片 URL"></label>'
            . '<label>个人主页<input type="text" name="website" value="' . e((string)$u['website']) . '" maxlength="255" placeholder="https://"></label>'
            . '<label class="col">个人简介<textarea name="bio" rows="3" maxlength="500" placeholder="介绍一下自己">' . e((string)$u['bio']) . '</textarea></label>'
            . '<div class="mm-actions"><button class="mall-btn-buy">保存资料</button></div>'
            . '</form>';
        return $html;
    }

    private static function mmSecurity(int $uid): string
    {
        $u = Auth::user();
        $m = self::memberRow($uid);
        $masked = (string)$m['mobile'] !== '' ? preg_replace('/(\d{3})\d{4}(\d{4})/', '$1****$2', (string)$m['mobile']) : '';
        $html = '<h3 class="mm-title">账户安全</h3>';
        $html .= '<div class="mm-sec"><h4>账号信息</h4><table class="mm-tbl kv">'
            . '<tr><th>登录账号</th><td>' . e((string)$u['username']) . '</td></tr>'
            . '<tr><th>登录邮箱</th><td>' . e((string)$u['email']) . '</td></tr>'
            . '<tr><th>账号角色</th><td>' . e((string)$u['role']) . '</td></tr>'
            . '<tr><th>绑定手机</th><td>' . ($masked !== '' ? e((string)$masked) : '<span class="mm-dim">未绑定</span>') . '</td></tr>'
            . '<tr><th>上次登录</th><td>' . e((string)($u['last_login_at'] ?? '—')) . '</td></tr>'
            . '<tr><th>上次 IP</th><td>' . e((string)($u['last_login_ip'] ?? '—')) . '</td></tr>'
            . '</table></div>';
        $html .= '<div class="mm-sec"><h4>修改密码</h4>'
            . '<form class="mm-form" method="post" action="/mall/user/security">' . Csrf::field()
            . '<input type="hidden" name="act" value="member_password">'
            . '<label>当前密码<input type="password" name="old_password" required autocomplete="current-password"></label>'
            . '<label>新密码<input type="password" name="new_password" required minlength="6" autocomplete="new-password" placeholder="至少 6 位"></label>'
            . '<label>确认新密码<input type="password" name="confirm_password" required minlength="6" autocomplete="new-password"></label>'
            . '<div class="mm-actions"><button class="mall-btn-buy">修改密码</button></div></form></div>';
        $html .= '<div class="mm-sec"><h4>修改邮箱</h4>'
            . '<form class="mm-form" method="post" action="/mall/user/security">' . Csrf::field()
            . '<input type="hidden" name="act" value="member_email">'
            . '<label>新邮箱<input type="email" name="email" value="' . e((string)$u['email']) . '" required maxlength="190"></label>'
            . '<div class="mm-actions"><button class="mall-btn-plain">更新邮箱</button></div></form></div>';
            // ★ 绑定手机号（需短信验证码校验；换绑同样走此流程）
            $smsOn = PasswordReset::smsReady();
            $html .= '<div class="mm-sec"><h4>' . ($masked !== '' ? '更换绑定手机' : '绑定手机号') . '</h4>'
                . ($smsOn
                    ? '<p class="mm-dim">绑定后可用于<b>短信找回密码</b>与订单通知。需通过短信验证码校验。</p>'
                      . '<form class="mm-form" method="post" action="/mall/user/security">' . Csrf::field()
                      . '<input type="hidden" name="act" value="member_mobile">'
                      . '<label>手机号<input type="text" name="mobile" maxlength="11" inputmode="numeric" required placeholder="11 位手机号"></label>'
                      . '<label>短信验证码<input type="text" name="code" maxlength="6" inputmode="numeric" required placeholder="先点获取验证码"></label>'
                      . '<div class="mm-actions"><button class="mall-btn-buy">' . ($masked !== '' ? '更换绑定' : '绑定手机号') . '</button></div></form>'
                    : '<p class="mm-dim">站点尚未配置短信服务，暂时无法自助绑定。请联系管理员。</p>')
                . '</div>';
        return $html;
    }

    /* ================= 页面：收货地址（会员中心内） ================= */

    private static function mmAddress(int $uid, array $query): string
    {
        $editId = (int)($query['edit'] ?? 0);
        $edit = $editId > 0
            ? Db::row('SELECT * FROM ' . self::t('user_address') . ' WHERE id=? AND uid=? AND is_delete_time=0 LIMIT 1', [$editId, $uid])
            : null;
        $html = '<h3 class="mm-title">收货地址</h3><div class="mall-addr-grid">';
        $html .= '<div class="mall-addr-form"><h3>' . ($edit !== null ? '编辑地址 #' . (int)$edit['id'] : '＋ 新增收货地址') . '</h3>'
            . '<form method="post" action="/mall/address/">' . Csrf::field()
            . '<input type="hidden" name="act" value="address_save">'
            . '<input type="hidden" name="id" value="' . ($edit !== null ? (int)$edit['id'] : 0) . '">'
            . '<input type="hidden" name="back" value="/mall/user/address">'
            . '<label>收货人<input type="text" name="name" value="' . e((string)($edit['name'] ?? '')) . '" required maxlength="32"></label>'
            . '<label>手机号<input type="text" name="mobile" value="' . e((string)($edit['mobile'] ?? '')) . '" required maxlength="20" placeholder="11 位手机号"></label>'
            . '<div class="row3"><label>省份<input type="text" name="province" value="' . e((string)($edit['province'] ?? '')) . '" required maxlength="32" placeholder="如 云南省"></label>'
            . '<label>城市<input type="text" name="city" value="' . e((string)($edit['city'] ?? '')) . '" maxlength="32" placeholder="如 西双版纳州"></label>'
            . '<label>区县<input type="text" name="county" value="' . e((string)($edit['county'] ?? '')) . '" maxlength="32" placeholder="如 勐海县"></label></div>'
            . '<label>详细地址<input type="text" name="address_detail" value="' . e((string)($edit['address_detail'] ?? '')) . '" required maxlength="255" placeholder="街道、门牌号"></label>'
            . '<label class="ck"><input type="checkbox" name="is_default" value="1"' . ((int)($edit['is_default'] ?? 0) === 1 ? ' checked' : '') . '>设为默认地址</label>'
            . '<button class="mall-btn-buy">保存地址</button> '
            . ($edit !== null ? '<a class="mall-btn-plain" href="/mall/user/address">取消编辑</a>' : '')
            . '</form></div>';
        $list = Db::rows('SELECT * FROM ' . self::t('user_address') . ' WHERE uid=? AND is_delete_time=0 ORDER BY is_default DESC, id DESC', [$uid]);
        $html .= '<div class="mall-addr-list"><h3>已保存地址（' . count($list) . '）</h3>';
        if ($list === []) {
            $html .= '<p class="dim">还没有收货地址，在左侧添加一个吧。</p>';
        }
        foreach ($list as $a) {
            $html .= '<div class="mall-addr-item' . ((int)$a['is_default'] === 1 ? ' def' : '') . '">'
                . '<div class="t">' . e((string)$a['name']) . ' <b>' . e((string)$a['mobile']) . '</b>'
                . ((int)$a['is_default'] === 1 ? '<span class="tag">默认</span>' : '') . '</div>'
                . '<div class="d">' . e((string)($a['province'] . ' ' . $a['city'] . ' ' . $a['county'] . ' ' . $a['address_detail'])) . '</div>'
                . '<div class="op"><a class="mini" href="/mall/user/address?edit=' . (int)$a['id'] . '">编辑</a>'
                . '<form class="inline" method="post" action="/mall/address/">' . Csrf::field()
                . '<input type="hidden" name="act" value="address_default"><input type="hidden" name="id" value="' . (int)$a['id'] . '">'
                . '<input type="hidden" name="back" value="/mall/user/address"><button class="mini">设为默认</button></form>'
                . '<form class="inline" method="post" action="/mall/address/">' . Csrf::field()
                . '<input type="hidden" name="act" value="address_del"><input type="hidden" name="id" value="' . (int)$a['id'] . '">'
                . '<input type="hidden" name="back" value="/mall/user/address"><button class="mini danger">删除</button></form>'
                . '</div></div>';
        }
        return $html . '</div></div>';
    }

    /* ================= 页面：账户余额 / 提现记录 / 积分 ================= */

    private static function mmBalance(int $uid): string
    {
        $m = self::memberRow($uid);
        $logs = Db::rows('SELECT * FROM ' . self::t('balance_log') . ' WHERE uid=? ORDER BY id DESC LIMIT 50', [$uid]);
        $html = '<h3 class="mm-title">账户余额</h3>';
        $html .= '<div class="mm-wallet"><div class="b"><span>可用余额</span><b>¥' . e(number_format((float)$m['balance'], 2, '.', '')) . '</b></div>'
            . '<div class="k"><span>累计充值 <b>¥' . e(number_format((float)$m['total_recharge'], 2, '.', '')) . '</b></span>'
            . '<span>累计提现 <b>¥' . e(number_format((float)$m['total_withdraw'], 2, '.', '')) . '</b></span>'
            . '<span>累计消费 <b>¥' . e(number_format((float)$m['total_consume'], 2, '.', '')) . '</b></span></div></div>';
        $html .= '<div class="mm-sec"><h4>在线充值（模拟）</h4>'
            . '<form class="mm-form mm-inline" method="post" action="/mall/user/balance">' . Csrf::field()
            . '<input type="hidden" name="act" value="member_recharge">'
            . '<label>充值金额<input type="number" name="amount" value="100" min="1" max="100000" step="0.01" required></label>'
            . '<div class="mm-actions"><button class="mall-btn-buy">立即充值</button></div></form>'
            . '<p class="mm-dim">测试环境为模拟充值，直接计入余额并写流水；接入真实支付网关后替换为支付回调。</p></div>';
        $typeName = [1 => '充值', 2 => '消费', 3 => '退款', 4 => '提现', 5 => '调整'];
        $html .= '<div class="mm-sec"><h4>余额明细</h4>';
        if ($logs === []) {
            return $html . '<p class="mm-dim">暂无余额变动记录。</p></div>';
        }
        $html .= '<table class="mm-tbl"><tr><th>时间</th><th>类型</th><th>金额</th><th>余额</th><th>备注</th></tr>';
        foreach ($logs as $l) {
            $amt = (float)$l['amount'];
            $html .= '<tr><td class="mm-dim">' . e((string)$l['created_at']) . '</td>'
                . '<td>' . e((string)($typeName[(int)$l['type']] ?? '其他')) . '</td>'
                . '<td class="' . ($amt >= 0 ? 'c-red' : 'c-green') . '">' . ($amt >= 0 ? '+' : '') . e(number_format($amt, 2, '.', '')) . '</td>'
                . '<td>¥' . e(number_format((float)$l['balance_after'], 2, '.', '')) . '</td>'
                . '<td class="mm-dim">' . e((string)$l['remark']) . '</td></tr>';
        }
        return $html . '</table></div>';
    }

    private static function mmWithdraw(int $uid): string
    {
        $m = self::memberRow($uid);
        $rows = Db::rows('SELECT * FROM ' . self::t('withdraw') . ' WHERE uid=? ORDER BY id DESC LIMIT 50', [$uid]);
        $stName = [0 => '待审核', 1 => '已通过', 2 => '已拒绝', 3 => '已打款'];
        $acName = ['alipay' => '支付宝', 'wechat' => '微信', 'bank' => '银行卡'];
        $html = '<h3 class="mm-title">提现记录</h3>';
        $html .= '<div class="mm-wallet"><div class="b"><span>可提现余额</span><b>¥' . e(number_format((float)$m['balance'], 2, '.', '')) . '</b></div></div>';
        $html .= '<div class="mm-sec"><h4>申请提现</h4>'
            . '<form class="mm-form" method="post" action="/mall/user/withdraw">' . Csrf::field()
            . '<input type="hidden" name="act" value="member_withdraw">'
            . '<label>提现金额<input type="number" name="amount" min="1" step="0.01" placeholder="不超过可提现余额" required></label>'
            . '<label>收款方式<select name="account_type"><option value="alipay">支付宝</option><option value="wechat">微信</option><option value="bank">银行卡</option></select></label>'
            . '<label>收款姓名<input type="text" name="account_name" maxlength="64" placeholder="实名姓名"></label>'
            . '<label>收款账号<input type="text" name="account_no" maxlength="64" placeholder="支付宝/微信账号或银行卡号" required></label>'
            . '<div class="mm-actions"><button class="mall-btn-buy">提交申请</button></div></form>'
            . '<p class="mm-dim">提现申请提交后由管理员审核并打款；当前为演示流程，不产生真实资金流转。</p></div>';
        $html .= '<div class="mm-sec"><h4>提现明细</h4>';
        if ($rows === []) {
            return $html . '<p class="mm-dim">暂无提现记录。</p></div>';
        }
        $html .= '<table class="mm-tbl"><tr><th>提现单号</th><th>金额</th><th>手续费</th><th>实付</th><th>收款方式</th><th>状态</th><th>申请时间</th></tr>';
        foreach ($rows as $w) {
            $html .= '<tr><td>' . e((string)$w['withdraw_no']) . '</td>'
                . '<td>¥' . e(number_format((float)$w['amount'], 2, '.', '')) . '</td>'
                . '<td class="mm-dim">¥' . e(number_format((float)$w['fee'], 2, '.', '')) . '</td>'
                . '<td class="c-red">¥' . e(number_format((float)$w['real_amount'], 2, '.', '')) . '</td>'
                . '<td>' . e((string)($acName[(string)$w['account_type']] ?? (string)$w['account_type'])) . '</td>'
                . '<td><span class="mm-st w' . (int)$w['status'] . '">' . e((string)($stName[(int)$w['status']] ?? '—')) . '</span></td>'
                . '<td class="mm-dim">' . e((string)$w['created_at']) . '</td></tr>';
        }
        return $html . '</table></div>';
    }

    private static function mmPoints(int $uid): string
    {
        $m = self::memberRow($uid);
        $logs = Db::rows('SELECT * FROM ' . self::t('points_log') . ' WHERE uid=? ORDER BY id DESC LIMIT 50', [$uid]);
        $html = '<h3 class="mm-title">我的积分</h3>';
        $html .= '<div class="mm-wallet"><div class="b pts"><span>可用积分</span><b>' . (int)$m['points'] . '</b></div>'
            . '<div class="k"><span>下单每消费 1 元得 1 积分</span><span>' . (int)get_option('mall.points_rate', 100) . ' 积分 ≈ ¥1</span></div></div>';
        $html .= '<div class="mm-sec"><h4>积分明细</h4>';
        if ($logs === []) {
            return $html . '<p class="mm-dim">暂无积分记录（下单支付后自动赠送）。</p></div>';
        }
        $html .= '<table class="mm-tbl"><tr><th>时间</th><th>类型</th><th>积分</th><th>余额</th><th>备注</th></tr>';
        foreach ($logs as $l) {
            $p = (int)$l['points'];
            $html .= '<tr><td class="mm-dim">' . e((string)$l['created_at']) . '</td>'
                . '<td>' . ((int)$l['type'] === 1 ? '获得' : '消耗') . '</td>'
                . '<td class="' . ($p >= 0 ? 'c-red' : 'c-green') . '">' . ($p >= 0 ? '+' : '') . $p . '</td>'
                . '<td>' . (int)$l['points_after'] . '</td>'
                . '<td class="mm-dim">' . e((string)$l['remark']) . '</td></tr>';
        }
        return $html . '</table></div>';
    }

    /* ================= M10 页面：每日签到 ================= */

    private static function mmSignin(int $uid): string
    {
        $m = self::memberRow($uid);
        $today = date('Y-m-d');
        $signedToday = Db::one('SELECT id FROM ' . self::t('signin') . ' WHERE uid=? AND day=? LIMIT 1', [$uid, $today]) !== null;
        $ym = date('Y-m');
        $monthDays = (int)date('t');
        $signedDays = [];
        $rows = Db::rows('SELECT day FROM ' . self::t('signin') . ' WHERE uid=? AND day LIKE ?', [$uid, $ym . '-%']);
        foreach ($rows as $r) { $signedDays[substr((string)$r['day'], 8, 2)] = true; }
        $base = (int)get_option('mall.signin_points', 5);
        $bonus = (int)get_option('mall.signin_bonus', 10);
        $rate = (float)get_option('mall.points_rate', 100);

        $html = '<h3 class="mm-title">每日签到</h3>';
        $html .= '<div class="mm-wallet"><div class="b pts"><span>可用积分</span><b>' . (int)$m['points'] . '</b></div>'
            . '<div class="k"><span>连续签到 ' . (int)$m['signin_days'] . ' 天</span><span>每次签到得 ' . $base . ' 积分，每连签 7 天额外 +' . $bonus . '</span></div></div>';
        if ($signedToday) {
            $html .= '<div class="mm-signin-done">今日已签到 ✓ 明天再来，连续签到奖励更多积分</div>';
        } else {
            $html .= '<form method="post" action="/mall/user/" class="mm-signin-btnwrap">'
                . \Csrf::field() . '<input type="hidden" name="act" value="member_signin">'
                . '<button class="mall-btn-buy mm-signin-btn">立即签到 +' . $base . ' 积分</button></form>';
        }
        // 月历
        $wd = ['日', '一', '二', '三', '四', '五', '六'];
        $html .= '<div class="mm-cal"><div class="mm-cal-h">' . implode('', array_map(static fn(string $w): string => '<i>' . $w . '</i>', $wd)) . '</div>';
        $firstW = (int)date('w', strtotime($ym . '-01'));
        $html .= '<div class="mm-cal-grid">';
        for ($i = 0; $i < $firstW; $i++) { $html .= '<span class="e"></span>'; }
        for ($d = 1; $d <= $monthDays; $d++) {
            $dd = str_pad((string)$d, 2, '0');
            $on = isset($signedDays[$dd]);
            $html .= '<span class="' . ($on ? 'on' : 'd') . '">' . $d . ($on ? '<b>✓</b>' : '') . '</span>';
        }
        $html .= '</div></div>';
        $html .= '<p class="mm-dim">积分规则：' . (int)$rate . ' 积分 ≈ ¥1；签到所得积分可在结算时抵扣现金。</p>';
        return $html;
    }

    /* ================= 页面：我的虚拟码 / 我的足迹 / 商品收藏 ================= */

    private static function mmVcode(int $uid): string
    {
        $rows = Db::rows('SELECT * FROM ' . self::t('virtual_code') . ' WHERE uid=? ORDER BY id DESC LIMIT 100', [$uid]);
        $tName = [1 => '卡密', 2 => '券码', 3 => '提货码'];
        $sName = [0 => '未使用', 1 => '已使用', 2 => '已作废'];
        $html = '<h3 class="mm-title">我的虚拟码</h3>';
        $html .= '<p class="mm-dim">下单支付成功后自动生成提货码，发货/自提时出示核销。</p>';
        if ($rows === []) {
            return $html . '<p class="mm-dim">暂无虚拟码。</p>';
        }
        $html .= '<table class="mm-tbl"><tr><th>名称</th><th>类型</th><th>虚拟码</th><th>关联订单</th><th>状态</th><th>生成时间</th></tr>';
        foreach ($rows as $c) {
            $html .= '<tr><td>' . e((string)$c['title']) . '</td>'
                . '<td>' . e((string)($tName[(int)$c['type']] ?? '其他')) . '</td>'
                . '<td><code class="mm-code">' . e((string)$c['code']) . '</code></td>'
                . '<td class="mm-dim">' . e((string)$c['order_no']) . '</td>'
                . '<td><span class="mm-st v' . (int)$c['status'] . '">' . e((string)($sName[(int)$c['status']] ?? '—')) . '</span></td>'
                . '<td class="mm-dim">' . e((string)$c['created_at']) . '</td></tr>';
        }
        return $html . '</table>';
    }

    /** 物流查询：列出已发货订单的快递公司与轨迹（对齐 51puer /member/logistics.html） */
    private static function mmLogistics(int $uid): string
    {
        $rows = Db::rows('SELECT id, order_no, status, express_name, express_no, delivery_time, finish_time'
            . ' FROM ' . self::t('order')
            . ' WHERE uid=? AND express_no<>\'\' AND express_no IS NOT NULL'
            . ' ORDER BY id DESC LIMIT 50', [$uid]);
        if ($rows === []) {
            return '<div class="mm-empty">还没有已发货的订单。订单发货后可在这里查询物流。</div>';
        }
        $stTxt = [1 => '待发货', 2 => '待收货', 3 => '已完成', 4 => '已取消', 5 => '售后中'];
        $h = '<div class="mm-card"><h3>物流查询</h3>'
            . '<p class="mm-tip">共 ' . count($rows) . ' 个已发货订单。也可在订单详情页查看完整轨迹。</p>';
        foreach ($rows as $o) {
            $no = (string)$o['order_no'];
            $tr = Db::rows('SELECT status_text, content, occur_time FROM ' . self::t('delivery_trace')
                . ' WHERE order_no=? ORDER BY id DESC LIMIT 8', [$no]);
            $h .= '<div class="mm-logi">'
                . '<div class="mm-logi-hd">'
                . '<b>' . e((string)$o['express_name']) . '</b>'
                . '<span class="mm-logi-no">' . e((string)$o['express_no']) . '</span>'
                . '<em>' . e($stTxt[(int)$o['status']] ?? '') . '</em>'
                . '<a href="/order/' . e($no) . '">订单 ' . e(substr($no, -6)) . '</a>'
                . '</div>';
            if ($tr === []) {
                $h .= '<div class="mm-logi-body"><p class="dim">暂无轨迹记录。</p></div>';
            } else {
                $h .= '<ul class="mm-logi-list">';
                foreach ($tr as $t) {
                    $h .= '<li><b>' . e((string)$t['status_text']) . '</b>'
                        . '<span>' . e((string)$t['content']) . '</span>'
                        . '<i>' . e(substr((string)$t['occur_time'], 0, 16)) . '</i></li>';
                }
                $h .= '</ul>';
            }
            $h .= '</div>';
        }
        return $h . '</div>';
    }
    private static function mmFootprint(int $uid): string
    {
        $rows = Db::rows('SELECT f.viewed_at, g.id, g.title, g.cover, g.min_price, g.market_price, g.sales_count, g.virtual_sales'
            . ' FROM ' . self::t('footprint') . ' f JOIN ' . self::t('goods') . ' g ON g.id=f.goods_id AND g.status=1 AND g.is_delete_time=0'
            . ' WHERE f.uid=? ORDER BY f.viewed_at DESC LIMIT 60', [$uid]);
        $html = '<h3 class="mm-title">我的足迹</h3>';
        $html .= '<p class="mm-dim">最近浏览过的商品（最多 60 条）。</p>';
        if ($rows === []) {
            return $html . '<p class="mm-dim">还没有浏览记录，<a href="/mall">去逛逛商城</a>。</p>';
        }
        $html .= '<div class="mm-goods-grid">';
        foreach ($rows as $g) {
            $html .= self::goodsCard($g);
        }
        return $html . '</div>';
    }

    private static function mmFavorite(int $uid): string
    {
        $rows = Db::rows('SELECT f.created_at, g.id, g.title, g.cover, g.min_price, g.market_price, g.sales_count, g.virtual_sales'
            . ' FROM ' . self::t('favorite') . ' f JOIN ' . self::t('goods') . ' g ON g.id=f.goods_id AND g.status=1 AND g.is_delete_time=0'
            . ' WHERE f.uid=? ORDER BY f.id DESC LIMIT 60', [$uid]);
        $html = '<h3 class="mm-title">商品收藏</h3>';
        if ($rows === []) {
            return $html . '<p class="mm-dim">还没有收藏商品，在商品详情页点「收藏」即可加入。</p>';
        }
        $html .= '<div class="mm-goods-grid">';
        foreach ($rows as $g) {
            $html .= '<div class="mm-fav-item">' . self::goodsCard($g)
                . '<form method="post" action="/mall/user/favorite" class="mm-fav-del">' . Csrf::field()
                . '<input type="hidden" name="act" value="favorite_del"><input type="hidden" name="goods_id" value="' . (int)$g['id'] . '">'
                . '<button class="mini danger">取消收藏</button></form></div>';
        }
        return $html . '</div>';
    }

    /* ================= 页面：我的订单（会员中心内） ================= */

    private static function mmOrder(int $uid, array $query): string
    {
        $st = isset($query['st']) && $query['st'] !== '' ? (int)$query['st'] : -1;
        $where = 'uid=?';
        $args = [$uid];
        if ($st >= 0 && $st <= 5) { $where .= ' AND status=?'; $args[] = $st; }
        $page = max(1, (int)($query['page'] ?? 1));
        $per = 10;
        $total = (int)Db::one('SELECT COUNT(*) FROM ' . self::t('order') . ' WHERE ' . $where, $args);
        $rows = Db::rows('SELECT * FROM ' . self::t('order') . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $args);
        $items = [];
        if ($rows) {
            $ids = implode(',', array_map(static fn($r) => (int)$r['id'], $rows));
            foreach (Db::rows('SELECT order_id, title, spec_text, num, cover FROM ' . self::t('order_goods') . ' WHERE order_id IN (' . $ids . ')') as $og) {
                $items[(int)$og['order_id']][] = $og;
            }
        }
        $statusTabs = [['', '全部'], [0, '待付款'], [1, '待发货'], [2, '待收货'], [3, '已完成'], [4, '已取消']];
        $html = '<h3 class="mm-title">我的订单</h3><div class="mall-order-tabs">';
        foreach ($statusTabs as $t) {
            $on = ($t[0] === '' && $st < 0) || ((string)$t[0] === (string)$st && $t[0] !== '');
            $html .= '<a href="/mall/user/order' . ($t[0] === '' ? '' : '?st=' . $t[0]) . '"' . ($on ? ' class="on"' : '') . '>' . $t[1] . '</a>';
        }
        $html .= '</div>';
        if ($rows === []) {
            return $html . '<div class="mall-cart-empty">暂无订单，<a href="/mall">去下单</a></div>';
        }
        foreach ($rows as $o) {
            $lines = '';
            foreach ($items[(int)$o['id']] ?? [] as $og) {
                $lines .= '<div class="li"><span class="t">' . e((string)$og['title']) . '</span>'
                    . '<span class="spec">' . e((string)$og['spec_text']) . '</span>'
                    . '<span class="n">× ' . (int)$og['num'] . '</span></div>';
            }
            $html .= '<div class="mall-order-card">'
                . '<div class="hd"><span class="no">订单号 ' . e((string)$o['order_no']) . '</span>'
                . '<span class="st s' . (int)$o['status'] . '">' . e(self::orderStatus((int)$o['status'])) . '</span></div>'
                . '<div class="bd"><div class="its">' . $lines . '</div>'
                . '<div class="amt"><span class="p">¥' . e(number_format((float)$o['pay_price'], 2, '.', '')) . '</span>'
                . '<span class="d">' . e((string)$o['created_at']) . '</span></div></div>'
                . '<div class="ft"><a class="mini" href="/mall/order/' . e((string)$o['order_no']) . '">订单详情</a>' . self::orderActions($o) . '</div>'
                . '</div>';
        }
        $tp = (int)ceil($total / $per);
        if ($tp > 1) {
            $base = '/mall/user/order' . ($st >= 0 ? '?st=' . $st . '&' : '?');
            $html .= '<div class="mall-pager">';
            if ($page > 1) { $html .= '<a href="' . e($base . 'page=' . ($page - 1)) . '">&lt; 上一页</a>'; }
            $html .= '<span class="cur">' . $page . ' / ' . $tp . '</span>';
            if ($page < $tp) { $html .= '<a href="' . e($base . 'page=' . ($page + 1)) . '">下一页 &gt;</a>'; }
            $html .= '</div>';
        }
        return $html;
    }

    /* ================= 页面：我的优惠券（会员中心内） ================= */

    private static function mmCoupon(int $uid): string
    {
        $fmtEnd = static fn($t): string => $t !== null ? ' · ' . date('Y.m.d', strtotime((string)$t)) . ' 前有效' : '';
        $list = self::couponList();
        $html = '<h3 class="mm-title">我的优惠券</h3><div class="mall-cp-sec"><h3>可领优惠券</h3><div class="mall-cp-grid">';
        if ($list === []) {
            $html .= '<div class="mm-dim">暂无可领的优惠券，常回来看看。</div>';
        }
        foreach ($list as $c) {
            $got = (int)Db::one('SELECT COUNT(*) FROM ' . self::t('user_coupon') . ' WHERE coupon_id=? AND uid=?', [(int)$c['id'], $uid]);
            $full = $got >= max(1, (int)$c['per_user_limit']);
            $html .= '<div class="mall-cp"><div class="v"><i>¥</i><b>' . e(number_format((float)$c['amount'], 2, '.', '')) . '</b><span class="th">满 ' . e(number_format((float)$c['threshold'], 2, '.', '')) . ' 可用</span></div>'
                . '<div class="n">' . e((string)$c['name']) . '<span class="d">' . $fmtEnd($c['end_time']) . '</span></div>'
                . ($full
                    ? '<button class="mall-cp-btn dis" disabled>已领取</button>'
                    : '<form method="post" action="/mall/coupon/">' . Csrf::field()
                        . '<input type="hidden" name="act" value="coupon_get"><input type="hidden" name="coupon_id" value="' . (int)$c['id'] . '">'
                        . '<input type="hidden" name="back" value="/mall/user/coupon"><button class="mall-cp-btn">领取</button></form>')
                . '</div>';
        }
        $html .= '</div></div><div class="mall-cp-sec"><h3>我的券包</h3><div class="mall-cp-grid">';
        $mine = self::myCoupons($uid);
        if ($mine === []) {
            $html .= '<div class="mm-dim">还没有优惠券，从上面领一张吧。</div>';
        }
        foreach ($mine as $m) {
            $stCls = ['ok', 'use', 'exp'][(int)$m['status']] ?? '';
            $stTxt = ['未使用', '已使用', '已过期'][(int)$m['status']] ?? '';
            $html .= '<div class="mall-cp' . ((int)$m['status'] === 0 ? '' : ' used') . '"><div class="v"><i>¥</i><b>' . e(number_format((float)$m['amount'], 2, '.', '')) . '</b><span class="th">满 ' . e(number_format((float)$m['threshold'], 2, '.', '')) . ' 可用</span></div>'
                . '<div class="n">' . e((string)$m['name']) . '<span class="d">' . $fmtEnd($m['end_time']) . '</span></div>'
                . '<span class="mall-cp-st ' . $stCls . '">' . $stTxt . '</span></div>';
        }
        return $html . '</div></div>';
    }

    /* ================= 页面：退款/退货及维修 ================= */

    private static function mmAftersale(int $uid): string
    {
        $rows = Db::rows('SELECT a.*, o.order_no FROM ' . self::t('aftersale') . ' a LEFT JOIN ' . self::t('order') . ' o ON o.id=a.order_id'
            . ' WHERE a.uid=? ORDER BY a.id DESC LIMIT 50', [$uid]);
        $stName = [0 => '待审核', 1 => '已同意', 2 => '已拒绝', 3 => '已退货', 4 => '已退款'];
        $typeName = [1 => '仅退款', 2 => '退货退款'];
        $orders = Db::rows('SELECT order_no, status, pay_price FROM ' . self::t('order') . ' WHERE uid=? AND status IN (1,2,3) ORDER BY id DESC LIMIT 30', [$uid]);
        $html = '<h3 class="mm-title">退款/退货及维修</h3><div class="mm-sec"><h4>申请售后</h4>';
        if ($orders === []) {
            $html .= '<p class="mm-dim">暂无可申请售后的订单（已支付/待发货/待收货/已完成的订单可申请）。</p>';
        } else {
            $html .= '<form class="mm-form" method="post" action="/mall/user/aftersale">' . Csrf::field()
                . '<input type="hidden" name="act" value="member_aftersale">'
                . '<label>选择订单<select name="order_no" required>';
            foreach ($orders as $o) {
                $html .= '<option value="' . e((string)$o['order_no']) . '">' . e((string)$o['order_no']) . ' · ' . e(self::orderStatus((int)$o['status'])) . ' · ¥' . e(number_format((float)$o['pay_price'], 2, '.', '')) . '</option>';
            }
            $html .= '</select></label>'
                . '<label>售后类型<select name="type"><option value="1">仅退款</option><option value="2">退货退款</option></select></label>'
                . '<label>退款金额<input type="number" name="refund_amount" min="0.01" step="0.01" placeholder="不超过实付金额" required></label>'
                . '<label class="col">申请原因<textarea name="reason" rows="2" maxlength="255" placeholder="请描述问题，如商品破损/与描述不符…" required></textarea></label>'
                . '<div class="mm-actions"><button class="mall-btn-buy">提交申请</button></div></form>';
        }
        $html .= '</div><div class="mm-sec"><h4>售后记录</h4>';
        if ($rows === []) {
            return $html . '<p class="mm-dim">暂无售后记录。</p></div>';
        }
        $html .= '<table class="mm-tbl"><tr><th>售后单号</th><th>订单号</th><th>类型</th><th>退款金额</th><th>状态</th><th>申请时间</th></tr>';
        foreach ($rows as $a) {
            $html .= '<tr><td>' . e((string)$a['aftersale_no']) . '</td>'
                . '<td class="mm-dim">' . e((string)($a['order_no'] ?? '—')) . '</td>'
                . '<td>' . e((string)($typeName[(int)$a['type']] ?? '—')) . '</td>'
                . '<td class="c-red">¥' . e(number_format((float)$a['refund_amount'], 2, '.', '')) . '</td>'
                . '<td><span class="mm-st a' . (int)$a['status'] . '">' . e((string)($stName[(int)$a['status']] ?? '—')) . '</span></td>'
                . '<td class="mm-dim">' . e((string)$a['created_at']) . '</td></tr>';
        }
        return $html . '</table></div>';
    }

    /* ================= 页面：商品评价/晒单 ================= */

    private static function mmReview(int $uid): string
    {
        $pending = Db::rows('SELECT og.id, og.goods_id, og.title, og.cover, og.spec_text, o.order_no'
            . ' FROM ' . self::t('order_goods') . ' og JOIN ' . self::t('order') . ' o ON o.id=og.order_id'
            . ' WHERE o.uid=? AND o.status=3 AND NOT EXISTS (SELECT 1 FROM ' . self::t('review') . ' r WHERE r.order_goods_id=og.id)'
            . ' ORDER BY og.id DESC LIMIT 30', [$uid]);
        $done = Db::rows('SELECT r.*, og.title, og.cover FROM ' . self::t('review') . ' r'
            . ' LEFT JOIN ' . self::t('order_goods') . ' og ON og.id=r.order_goods_id'
            . ' WHERE r.uid=? ORDER BY r.id DESC LIMIT 50', [$uid]);
        $html = '<h3 class="mm-title">商品评价/晒单</h3><div class="mm-sec"><h4>待评价（' . count($pending) . '）</h4>';
        if ($pending === []) {
            $html .= '<p class="mm-dim">暂无待评价商品（订单完成后可评价）。</p>';
        }
        foreach ($pending as $p) {
            $html .= '<form class="mm-form mm-review" method="post" action="/mall/user/review">' . Csrf::field()
                . '<input type="hidden" name="act" value="member_review"><input type="hidden" name="og_id" value="' . (int)$p['id'] . '">'
                . '<div class="mm-rv-hd">' . ((string)$p['cover'] !== '' ? '<img src="' . e((string)$p['cover']) . '" alt="">' : '')
                . '<div><b>' . e((string)$p['title']) . '</b><span class="mm-dim">' . e((string)$p['spec_text']) . ' · 订单 ' . e((string)$p['order_no']) . '</span></div></div>'
                . '<label>评分<select name="score">'
                . '<option value="5">5 星 非常好</option><option value="4">4 星 好</option><option value="3">3 星 一般</option>'
                . '<option value="2">2 星 差</option><option value="1">1 星 很差</option></select></label>'
                . '<label class="col">评价内容<textarea name="content" rows="2" maxlength="1000" placeholder="说说这次购物体验…"></textarea></label>'
                . '<div class="mm-actions"><button class="mall-btn-buy">提交评价</button></div></form>';
        }
        $html .= '</div><div class="mm-sec"><h4>我的评价（' . count($done) . '）</h4>';
        if ($done === []) {
            return $html . '<p class="mm-dim">还没有发表过评价。</p></div>';
        }
        foreach ($done as $r) {
            $sc = max(1, min(5, (int)$r['score']));
            $html .= '<div class="mm-rv-item"><div class="hd"><b>' . e((string)($r['title'] ?? '商品')) . '</b>'
                . '<span class="mm-stars">' . str_repeat('★', $sc) . str_repeat('☆', 5 - $sc) . '</span>'
                . '<span class="mm-dim">' . e((string)$r['created_at']) . '</span></div>'
                . '<p>' . nl2br(e((string)$r['content'])) . '</p></div>';
        }
        return $html . '</div>';
    }

    /* ================= POST 动作（由 lib.php::handlePost 的 default 分支转入） ================= */

    private static function memberPost(string $act, int $uid): void
    {
        switch ($act) {
            case 'member_profile': {
                if ($uid <= 0) { self::go(ui_login('/mall/user/')); }
                $dn = trim((string)($_POST['display_name'] ?? ''));
                if ($dn === '') { self::go('/mall/user/profile', '昵称不能为空'); }
                Db::update('users', [
                    'display_name' => mb_substr($dn, 0, 100),
                    'bio' => mb_substr(trim((string)($_POST['bio'] ?? '')), 0, 500),
                    'avatar' => mb_substr(trim((string)($_POST['avatar'] ?? '')), 0, 255),
                    'website' => mb_substr(trim((string)($_POST['website'] ?? '')), 0, 255),
                ], 'id=?', [$uid]);
                self::memberRow($uid);
                $bday = trim((string)($_POST['birthday'] ?? ''));
                Db::update('mall_member', [
                    // mobile 不在此更新：必须走 /mall/user/security 的验证码绑定流程
                    'gender' => max(0, min(2, (int)($_POST['gender'] ?? 0))),
                    'birthday' => ($bday !== '' ? $bday : null),
                    'real_name' => mb_substr(trim((string)($_POST['real_name'] ?? '')), 0, 32),
                ], 'uid=?', [$uid]);
                self::go('/mall/user/profile', '个人资料已保存');
            }
            case 'member_password': {
                if ($uid <= 0) { self::go(ui_login('/mall/user/security')); }
                $old = (string)($_POST['old_password'] ?? '');
                $new = (string)($_POST['new_password'] ?? '');
                $cfm = (string)($_POST['confirm_password'] ?? '');
                if (!Auth::verifyPassword($uid, $old)) { self::go('/mall/user/security', '当前密码不正确'); }
                if (mb_strlen($new) < 6) { self::go('/mall/user/security', '新密码至少 6 位'); }
                if ($new !== $cfm) { self::go('/mall/user/security', '两次输入的新密码不一致'); }
                Auth::setPassword($uid, $new);
                self::go('/mall/user/security', '密码修改成功');
            }
            case 'member_mobile': {
                if ($uid <= 0) { self::go(ui_login('/mall/user/security')); }
                $mobile = trim((string)($_POST['mobile'] ?? ''));
                $code = trim((string)($_POST['code'] ?? ''));
                $r = PasswordReset::verifyBindCode($uid, $mobile, $code);
                self::go('/mall/user/security', $r['msg']);
            }
            case 'member_mobile_send': {
                if ($uid <= 0) { self::go(ui_login('/mall/user/security')); }
                $mobile = trim((string)($_POST['mobile'] ?? ''));
                $r = PasswordReset::requestBindCode($uid, $mobile);
                self::go('/mall/user/security', $r['msg']);
            }
            case 'member_email': {
                if ($uid <= 0) { self::go(ui_login('/mall/user/security')); }
                $em = trim((string)($_POST['email'] ?? ''));
                if (!filter_var($em, FILTER_VALIDATE_EMAIL)) { self::go('/mall/user/security', '邮箱格式不正确'); }
                if (Db::one('SELECT id FROM ' . Db::t('users') . ' WHERE email=? AND id<>? LIMIT 1', [$em, $uid]) !== null) {
                    self::go('/mall/user/security', '该邮箱已被占用');
                }
                Db::update('users', ['email' => $em], 'id=?', [$uid]);
                self::go('/mall/user/security', '邮箱已更新');
            }
            case 'member_recharge': {
                if ($uid <= 0) { self::go(ui_login('/mall/user/balance')); }
                $amt = round((float)($_POST['amount'] ?? 0), 2);
                if ($amt <= 0 || $amt > 100000) { self::go('/mall/user/balance', '充值金额不合法'); }
                self::walletApply($uid, 1, $amt, '在线充值（模拟）');
                self::go('/mall/user/balance', '充值成功，已到账 ¥' . number_format($amt, 2, '.', ''));
            }
            case 'member_withdraw': {
                if ($uid <= 0) { self::go(ui_login('/mall/user/withdraw')); }
                $m = self::memberRow($uid);
                $amt = round((float)($_POST['amount'] ?? 0), 2);
                if ($amt <= 0) { self::go('/mall/user/withdraw', '提现金额不合法'); }
                if ($amt > (float)$m['balance']) { self::go('/mall/user/withdraw', '提现金额不能超过可用余额'); }
                $atype = (string)($_POST['account_type'] ?? 'alipay');
                if (!in_array($atype, ['alipay', 'wechat', 'bank'], true)) { $atype = 'alipay'; }
                $ano = trim((string)($_POST['account_no'] ?? ''));
                if ($ano === '') { self::go('/mall/user/withdraw', '请填写收款账号'); }
                $fee = round($amt * 0.006, 2);
                $no = 'WD' . date('YmdHis') . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
                $pdo = Db::pdo();
                $pdo->beginTransaction();
                try {
                    $st = Db::run('UPDATE ' . self::t('member') . ' SET balance=balance-?, total_withdraw=total_withdraw+? WHERE uid=? AND balance>=?', [$amt, $amt, $uid, $amt]);
                    if ($st->rowCount() === 0) { throw new RuntimeException('余额不足'); }
                    $bal = (float)Db::one('SELECT balance FROM ' . self::t('member') . ' WHERE uid=?', [$uid]);
                    Db::insert('mall_withdraw', [
                        'withdraw_no' => $no, 'uid' => $uid, 'amount' => $amt, 'fee' => $fee,
                        'real_amount' => round($amt - $fee, 2), 'account_type' => $atype,
                        'account_name' => mb_substr(trim((string)($_POST['account_name'] ?? '')), 0, 64),
                        'account_no' => mb_substr($ano, 0, 64), 'status' => 0,
                    ]);
                    Db::insert('mall_balance_log', ['uid' => $uid, 'type' => 4, 'amount' => -$amt, 'balance_after' => $bal, 'remark' => '提现申请 ' . $no]);
                    $pdo->commit();
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    self::go('/mall/user/withdraw', '提现失败：' . $e->getMessage());
                }
                self::go('/mall/user/withdraw', '提现申请已提交，等待管理员审核');
            }
            case 'favorite_add':
            case 'favorite_del': {
                $back = (string)($_POST['back'] ?? '/mall/user/favorite');
                if (!str_starts_with($back, '/mall')) { $back = '/mall/user/favorite'; }
                if ($uid <= 0) {
                    if (self::isAjax()) { self::json(['ok' => false, 'login' => true, 'url' => ui_login($back)]); }
                    self::go(ui_login($back));
                }
                $gid = (int)($_POST['goods_id'] ?? 0);
                if ($gid > 0) {
                    if ($act === 'favorite_add') {
                        Db::run('INSERT IGNORE INTO ' . self::t('favorite') . ' (uid, goods_id) VALUES (?, ?)', [$uid, $gid]);
                    } else {
                        Db::run('DELETE FROM ' . self::t('favorite') . ' WHERE uid=? AND goods_id=?', [$uid, $gid]);
                    }
                }
                if (self::isAjax()) { self::json(['ok' => true, 'fav' => $act === 'favorite_add']); }
                self::go($back, $act === 'favorite_add' ? '已加入收藏' : '已取消收藏');
            }
            case 'member_review': {
                if ($uid <= 0) { self::go(ui_login('/mall/user/review')); }
                $ogId = (int)($_POST['og_id'] ?? 0);
                $og = Db::row('SELECT og.id, og.goods_id, o.id oid, o.uid ouid, o.status ostatus FROM ' . self::t('order_goods') . ' og'
                    . ' JOIN ' . self::t('order') . ' o ON o.id=og.order_id WHERE og.id=? AND o.uid=? LIMIT 1', [$ogId, $uid]);
                if ($og === null) { self::go('/mall/user/review', '订单商品不存在'); }
                if ((int)$og['ostatus'] !== 3) { self::go('/mall/user/review', '订单完成后才能评价'); }
                if (Db::one('SELECT id FROM ' . self::t('review') . ' WHERE order_goods_id=? LIMIT 1', [$ogId]) !== null) { self::go('/mall/user/review', '该商品已评价'); }
                $score = max(1, min(5, (int)($_POST['score'] ?? 5)));
                Db::insert('mall_review', [
                    'order_goods_id' => $ogId, 'order_id' => (int)$og['oid'], 'goods_id' => (int)$og['goods_id'],
                    'uid' => $uid, 'score' => $score,
                    'content' => mb_substr(trim((string)($_POST['content'] ?? '')), 0, 2000), 'is_show' => 1,
                ]);
                Db::run('UPDATE ' . self::t('goods') . ' SET comments=comments+1 WHERE id=?', [(int)$og['goods_id']]);
                self::go('/mall/user/review', '评价提交成功，感谢反馈');
            }
            case 'member_signin': {
                if ($uid <= 0) { self::go(ui_login('/mall/user/signin')); }
                $today = date('Y-m-d');
                if (Db::one('SELECT id FROM ' . self::t('signin') . ' WHERE uid=? AND day=? LIMIT 1', [$uid, $today]) !== null) {
                    self::go('/mall/user/signin', '今天已经签到啦');
                }
                $yesterday = date('Y-m-d', strtotime('-1 day'));
                $last = Db::row('SELECT * FROM ' . self::t('signin') . ' WHERE uid=? ORDER BY day DESC LIMIT 1', [$uid]);
                $consecutive = ($last !== null && (string)$last['day'] === $yesterday) ? (int)$last['consecutive'] + 1 : 1;
                $base = (int)get_option('mall.signin_points', 5);
                $bonus = (int)get_option('mall.signin_bonus', 10);
                $pts = $base + ($consecutive % 7 === 0 ? $bonus : 0);
                $pdo = Db::pdo();
                $pdo->beginTransaction();
                try {
                    Db::insert('mall_signin', ['uid' => $uid, 'day' => $today, 'consecutive' => $consecutive, 'points' => $pts]);
                    Db::run('UPDATE ' . self::t('member') . ' SET signin_days=?, signin_at=? WHERE uid=?', [$consecutive, $today, $uid]);
                    self::pointsApply($uid, $pts, '签到 第' . $consecutive . '天');
                    $pdo->commit();
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    self::go('/mall/user/signin', '签到失败：' . $e->getMessage());
                }
                self::go('/mall/user/signin', '签到成功，+' . $pts . ' 积分（连续 ' . $consecutive . ' 天）');
            }
            case 'member_msg_read': {
                if ($uid <= 0) { self::go(ui_login('/mall/user/message')); }
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    Db::update('mall_message', ['is_read' => 1], 'id=? AND uid=?', [$id, $uid]);
                }
                self::go('/mall/user/message', '已标记为已读');
            }
            case 'member_aftersale': {
                if ($uid <= 0) { self::go(ui_login('/mall/user/aftersale')); }
                $no = (string)($_POST['order_no'] ?? '');
                $o = Db::row('SELECT * FROM ' . self::t('order') . ' WHERE order_no=? AND uid=? LIMIT 1', [$no, $uid]);
                if ($o === null) { self::go('/mall/user/aftersale', '订单不存在'); }
                if (!in_array((int)$o['status'], [1, 2, 3], true)) { self::go('/mall/user/aftersale', '该订单当前状态不支持售后'); }
                $type = ((int)($_POST['type'] ?? 1) === 2) ? 2 : 1;
                $amt = round((float)($_POST['refund_amount'] ?? 0), 2);
                if ($amt <= 0 || $amt > (float)$o['pay_price']) {
                    self::go('/mall/user/aftersale', '退款金额不合法（不超过实付 ¥' . number_format((float)$o['pay_price'], 2, '.', '') . '）');
                }
                $reason = trim((string)($_POST['reason'] ?? ''));
                if ($reason === '') { self::go('/mall/user/aftersale', '请填写申请原因'); }
                $ano = 'AS' . date('YmdHis') . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
                Db::insert('mall_aftersale', [
                    'aftersale_no' => $ano, 'order_id' => (int)$o['id'], 'order_goods_id' => 0, 'uid' => $uid,
                    'type' => $type, 'reason' => mb_substr($reason, 0, 255), 'refund_amount' => $amt, 'status' => 0,
                ]);
                Db::insert('mall_order_log', ['order_id' => (int)$o['id'], 'order_no' => $no, 'action' => 'aftersale', 'detail' => '用户提交售后申请 ' . $ano, 'operator' => 'user']);
                self::go('/mall/user/aftersale', '售后申请已提交，客服会尽快处理');
            }
        }
    }
}
