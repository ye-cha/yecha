<?php
declare(strict_types=1);
/**
 * 微信小程序 JSON API（mall 插件）
 * - 路由入口：Mall::dispatch 的 case 'api' → MallApi::run($path, $query)
 * - 认证：微信登录 code → openid → 映射 uid → 返回 Bearer token；本地无 AppId 时走 dev 回退
 * - 覆盖：商品浏览 / 资讯浏览+评论 / 购物车 / 下单 / JSAPI 支付(沙箱回退) / 用户中心 / 地址 / 收藏
 * - 复用：Mall::t() 表名前缀、Db 数据层、MallPay\Wxpay(JSAPI)、Mall 的 payApplyNotify 思路(本类内 markPaid)
 * 纯本地、不上线生产；真实商户号填 mall.pay_wxpay_* 后即走真 JSAPI。
 */
require_once __DIR__ . '/../pay/Wxpay.php';

class MallApi
{
    /** 入口：解析请求 → 分发 → 输出 JSON 并 exit */
    public static function run(array $path, array $query): void
    {
        self::ensureTables();
        header('Content-Type: application/json; charset=utf-8');
        $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $seg = array_values(array_filter($path, fn($p) => $p !== ''));
        $res = self::route($method, $seg, $query);
        self::out($res['code'] ?? 0, $res['data'] ?? null, $res['msg'] ?? '');
    }

/* ============ AI 导购：商品卡批量查询（公开只读） ============ */

    /**
     * GET /mall/api/cards?ids=8830,3373
     * 返回：[{id,title,cover,price,market_price,url,tags}]
     * 约束：≤12 个 id；只返在售有货；每 IP 每分钟限流。
     */
    private static function cards(array $q): array
    {
        // —— 限流：每 IP 每分钟 30 次（防止被当商品 API 爬）——
        $ip = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        $limit = 30;
        try {
            // 用 ai 插件的调用日志表做限流计数（专为日志设计，不污染商城业务数据）。
            // ai 插件未启用时降级放行 —— 导购本就不可能在 ai 关闭时工作。
            if (class_exists('Plugins') && \Plugins::isOn('ai') && class_exists('Ai')) {
                $scene = mb_substr('cards:' . $ip, 0, 32);
                $n = (int)Db::one(
                    'SELECT COUNT(*) FROM ' . Db::t('ai_log')
                    . ' WHERE scene = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)',
                    [$scene]
                );
                if ($n >= $limit) { return ['code' => 429, 'msg' => '请求过于频繁，请稍后再试']; }
                Db::insert('ai_log', ['scene' => $scene, 'provider' => 'mall', 'model' => 'cards',
                    'in_tokens' => 0, 'out_tokens' => 0, 'cost_ms' => 0, 'ok' => 1, 'err' => '']);
            }
        } catch (Throwable $e) {
            // 限流不可用时降级放行（不阻塞导购主流程）
        }

        $raw = trim((string)($q['ids'] ?? ''));
        if ($raw === '') { return ['code' => 400, 'msg' => '缺少 ids 参数']; }
        $ids = [];
        foreach (explode(',', $raw) as $x) {
            $i = (int)trim($x);
            if ($i > 0 && !in_array($i, $ids, true)) { $ids[] = $i; }
            if (count($ids) >= 12) { break; }        // ★ 上限 12
        }
        if (!$ids) { return ['code' => 400, 'msg' => 'ids 无效']; }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = Db::rows(
            'SELECT id, title, subtitle, cover, min_price, market_price, total_stock, sales_count'
            . ' FROM ' . Db::t('mall_goods')
            . " WHERE id IN ({$ph}) AND status = 1 AND is_delete_time = 0"
            . " AND total_stock > 0 AND title NOT LIKE '%售罄%'",      // ★ 只返可售
            $ids
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'           => (int)$r['id'],
                'title'        => (string)$r['title'],
                'subtitle'     => (string)($r['subtitle'] ?? ''),
                'cover'        => (string)$r['cover'],
                'price'        => number_format((float)$r['min_price'], 2, '.', ''),
                'market_price' => (float)$r['market_price'] > 0
                                    ? number_format((float)$r['market_price'], 2, '.', '') : '',
                // 只给"有货/紧张"两态，不暴露精确库存
                'stock_state'  => (int)$r['total_stock'] <= 5 ? 'low' : 'in',
                'url'          => '/goods-' . (int)$r['id'] . '.html',
            ];
        }
        // 保持调用方传入的顺序（AI 的推荐顺序有意义）
        $idx = array_flip($ids);
        usort($out, fn($a, $b) => ($idx[$a['id']] ?? 99) <=> ($idx[$b['id']] ?? 99));
        return ['code' => 0, 'data' => ['list' => $out, 'requested' => count($ids), 'found' => count($out)]];
    }

    /* ============ 路由 ============ */

    private static function route(string $method, array $seg, array $query): array
    {
        $r = (string)($seg[1] ?? '');       // 资源
        $s = (string)($seg[2] ?? '');       // 子资源 / id
        $input = self::input();             // 解析后的请求体
        $q = array_merge($query, $input);    // GET + POST 合并

        switch ($r) {
            case 'login':
                return $method === 'POST' ? self::login($input) : self::no();
            case 'home':
                return self::home();
            case 'cards':
                // ★ AI 导购用：批量取商品卡（公开只读、限流、只返在售有货）
                //   见 _ai-shopping-guide-plan.md §5 阶段二
                return self::cards($q);
            case 'category':
                return self::categoryList();
            case 'goods':
                if ($s !== '') { return self::goodsDetail((int)$s); }
                return self::goodsList($q);
            case 'article':
                if ($s !== '') { return self::articleDetail((int)$s); }
                return self::articleList($q);
            case 'comment':
                return $method === 'POST' ? self::commentPost($input) : self::no();
            case 'cart':
                if ($method === 'GET') { return self::cartList(); }
                if ($method === 'POST') { return self::cartAdd($input); }
                if ($method === 'PUT') { return self::cartUpdate($input); }
                if ($method === 'DELETE') { return self::cartDel($input); }
                return self::no();
            case 'order':
                if ($s === 'pay') { return $method === 'POST' ? self::orderPay($input) : self::no(); }
                if ($s === 'sim') { return self::orderSimConfirm((string)($input['no'] ?? $query['no'] ?? '')); }
                if ($s === 'cancel') { return $method === 'POST' ? self::orderCancel($input) : self::no(); }
                if ($s === 'confirm') { return $method === 'POST' ? self::orderConfirm($input) : self::no(); }
                if ($s !== '') { return self::orderDetail((string)$s); }
                return $method === 'POST' ? self::orderCreate($input) : self::orderList($q);
            case 'user':
                if ($s === 'profile') { return $method === 'PUT' ? self::userProfile($input) : self::no(); }
                if ($s === 'address') {
                    if ($method === 'POST') { return self::addressSave($input); }
                    if ($method === 'DELETE') { return self::addressDel($input); }
                    if ($method === 'PUT') { return self::addressDefault($input); }
                    return self::addressList();
                }
                if ($s === 'favorite') {
                    if ($method === 'POST') { return self::favoriteAdd($input); }
                    if ($method === 'DELETE') { return self::favoriteDel($input); }
                    return self::favoriteList($q);
                }
                return self::userCenter();
            default:
                return ['code' => 404, 'msg' => '接口不存在: /mall/api/' . implode('/', $seg)];
        }
    }

    private static function no(): array
    {
        return ['code' => 405, 'msg' => '方法不允许'];
    }

    /* ============ 认证 ============ */

    /** 解析请求体：优先 JSON，回退表单 */
    private static function input(): array
    {
        $ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
        if (stripos($ct, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $d = json_decode($raw ?: '{}', true);
            return is_array($d) ? $d : [];
        }
        return $_POST;
    }

    /** 取当前 uid（Bearer / ?token= / POST token）；失败直接输出 401 并 exit */
    private static function uid(): int
    {
        $token = '';
        $ah = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(\S+)/i', $ah, $m)) { $token = $m[1]; }
        if ($token === '' && isset($_GET['token'])) { $token = (string)$_GET['token']; }
        if ($token === '' && isset($_POST['token'])) { $token = (string)$_POST['token']; }
        if ($token === '') { self::out(401, null, '请先登录'); }
        $row = Db::row('SELECT uid, expire_at FROM ' . Mall::t('api_token') . ' WHERE token=? LIMIT 1', [$token]);
        if ($row === null) { self::out(401, null, '登录已失效，请重新登录'); }
        if ((string)$row['expire_at'] !== '' && (string)$row['expire_at'] < date('Y-m-d H:i:s')) {
            self::out(401, null, '登录已过期，请重新登录');
        }
        return (int)$row['uid'];
    }

    /** 微信登录：code → openid → uid 映射 → token */
/**
     * dev 登录回退是否允许：必须**服务端显式开启**且请求来自本机/内网。
     * 默认关闭 —— 安全默认。生产绝不能开。
     */
    /**
     * 是否来自本机或私网（RFC1918 + 回环 + IPv6 ULA/链路本地）。
     * ★ 不能用字符串前缀 —— 原实现 ['10.','192.168.','172.16.',...] 漏了
     *   docker 网桥常见的 172.17~172.31，导致本该放行的本地调试被误拒。
     */
    private static function isLocalOrPrivateIp(string $ip): bool
    {
        if ($ip === '') { return false; }
        if ($ip === '::1' || str_starts_with(strtolower($ip), 'fc') || str_starts_with(strtolower($ip), 'fd')
            || str_starts_with(strtolower($ip), 'fe80:')) { return true; }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { return false; }
        $n = ip2long($ip);
        if ($n === false) { return false; }
        $in = static function (string $cidr) use ($n): bool {
            [$sub, $bits] = explode('/', $cidr);
            $sn = ip2long($sub);
            if ($sn === false) { return false; }
            $mask = $bits === 0 ? 0 : (-1 << (32 - (int)$bits));
            return (($n & $mask) === ($sn & $mask));
        };
        return $in('127.0.0.0/8') || $in('10.0.0.0/8') || $in('172.16.0.0/12') || $in('192.168.0.0/16');
    }

    private static function devAllowed(): bool
    {
        // 显式开关（后台/option，默认 0=关）
        if (trim((string)get_option('mall.api_allow_dev', '0')) !== '1') { return false; }
        return self::isLocalOrPrivateIp((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    private static function login(array $in): array
    {
        $code = trim((string)($in['code'] ?? ''));
        $openid = '';
        $unionid = '';

        // ★ 安全加固（修阻塞 2）：dev 回退必须由服务端显式开启，且仅限本机/内网。
        //   原实现 $code==='' 即视为 dev、openid 由固定字符串推导 —— 任何人可登录。
        // ★ 注意类型：JSON 里 "dev":1 解出来是**整数** 1，严格比较 === '1' 会 false。
        //   小程序 app.js 发的就是数字 1，所以必须做类型宽松判断。
        $devRaw = $in['dev'] ?? '';
        $devWanted = in_array((string)$devRaw, ['1', 'true', 'yes'], true) || $code === 'dev';
        $dev = $devWanted && self::devAllowed();

        if (!$dev) {
            $cfg = self::wxCfg();
            if (trim((string)($cfg['app_id'] ?? '')) === '' || trim((string)($cfg['secret'] ?? '')) === '') {
                // 原实现此处静默回退 dev → 生产上等于关门不上锁。改为明确报错。
                return ['code' => 400, 'msg' => '微信登录未配置（缺少 AppId/AppSecret），请联系管理员'];
            }
            if ($code === '') { return ['code' => 400, 'msg' => '缺少微信登录 code']; }
            $wx = new \MallPay\Wxpay($cfg);
            $r = $wx->openId($code);
            if ($r['ok']) { $openid = (string)$r['openid']; $unionid = (string)($r['unionid'] ?? ''); }
            else { return ['code' => 400, 'msg' => '微信登录失败：' . ($r['msg'] ?? '')]; }
        }
        if ($dev && $openid === '') { $openid = 'dev_' . md5($code === '' ? 'dev' : $code); }

        // openid → uid
        $map = Db::row('SELECT uid FROM ' . Mall::t('user_wx') . ' WHERE openid=? LIMIT 1', [$openid]);
        if ($map !== null) {
            $uid = (int)$map['uid'];
        } else {
            $uid = self::wxRegister($openid, $unionid);
        }
        $u = Db::row('SELECT id, display_name, username, avatar FROM ' . Db::t('users') . ' WHERE id=? LIMIT 1', [$uid]);
        $token = bin2hex(random_bytes(16));
        Db::insert('mall_api_token', [
            'token' => $token, 'uid' => $uid, 'openid' => $openid,
            'expire_at' => date('Y-m-d H:i:s', time() + 86400 * 30),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['code' => 0, 'data' => [
            'token' => $token,
            'uid' => $uid,
            'user' => [
                'id' => $uid,
                'display_name' => $u ? (string)($u['display_name'] ?? '') : '',
                'username' => $u ? (string)($u['username'] ?? '') : '',
                'avatar' => $u ? (string)($u['avatar'] ?? '') : '',
            ],
            'dev' => $dev,
        ], 'msg' => '登录成功'];
    }

    /** 微信用户首次注册（落 ye_users + 映射表） */
    private static function wxRegister(string $openid, string $unionid): int
    {
        $uname = 'wx_' . substr(md5($openid), 0, 12);
        $uid = Db::insert('users', [
            'username' => $uname,
            'email' => $uname . '@wx.local',
            'display_name' => '微信用户',
            // 随机密码（微信登录不依赖密码；沿用宿主 md5(明文.salt) 规则留空 salt）
            'password' => md5(bin2hex(random_bytes(8))),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Db::insert('mall_user_wx', [
            'openid' => $openid, 'unionid' => $unionid, 'uid' => $uid,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $uid;
    }

    /* ============ 首页 / 分类 ============ */

    private static function home(): array
    {
        $cats = self::categories(true);
        $rec = self::goodsQuery([], 6);
        $articles = self::articleRows(3, 0);
        $lis = [];
        foreach ($articles as $a) {
            $lis[] = [
                'id' => (int)$a['id'], 'title' => (string)$a['title'],
                'excerpt' => (string)$a['excerpt'], 'thumb' => self::firstImg((string)($a['content'] ?? '')),
                'published_at' => (string)($a['published_at'] ?? ''),
            ];
        }
        return ['code' => 0, 'data' => [
            'categories' => $cats,
            'recommend' => $rec,
            'articles' => $lis,
        ]];
    }

    private static function categoryList(): array
    {
        return ['code' => 0, 'data' => self::categories(false)];
    }

    private static function categories(bool $topOnly): array
    {
        $rows = Db::rows('SELECT id, pid, name, slug, icon FROM ' . Mall::t('goods_category')
            . " WHERE is_show=1 AND is_delete_time=0 ORDER BY sort ASC, id ASC");
        $out = [];
        foreach ($rows as $c) {
            if ($topOnly && (int)$c['pid'] !== 0) { continue; }
            $out[] = ['id' => (int)$c['id'], 'pid' => (int)$c['pid'], 'name' => (string)$c['name'],
                'slug' => (string)$c['slug'], 'icon' => (string)$c['icon']];
        }
        return $out;
    }

    /* ============ 商品 ============ */

    private static function goodsList(array $q): array
    {
        $cat = (int)($q['category_id'] ?? 0);
        $kw = trim((string)($q['keyword'] ?? ''));
        $page = max(1, (int)($q['page'] ?? 1));
        $limit = min(50, max(1, (int)($q['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;
        $where = 'g.status=1 AND g.is_delete_time=0';
        $params = [];
        if ($cat > 0) { $where .= ' AND g.category_id=?'; $params[] = $cat; }
        if ($kw !== '') { $where .= ' AND g.title LIKE ?'; $params[] = '%' . $kw . '%'; }
        $rows = Db::rows('SELECT g.id, g.title, g.subtitle, g.cover, g.min_price, g.market_price, g.total_stock, g.sales_count, g.is_recommend'
            . ' FROM ' . Mall::t('goods') . ' g WHERE ' . $where
            . ' ORDER BY g.is_recommend DESC, g.id DESC LIMIT ? OFFSET ?', array_merge($params, [$limit, $offset]));
        $total = (int)Db::one('SELECT COUNT(*) FROM ' . Mall::t('goods') . ' g WHERE ' . $where, $params);
        $list = [];
        foreach ($rows as $g) {
            $list[] = self::goodsCard($g);
        }
        return ['code' => 0, 'data' => ['list' => $list, 'page' => $page, 'limit' => $limit, 'total' => $total, 'has_more' => ($offset + count($list) < $total)]];
    }

    private static function goodsCard(array $g): array
    {
        return [
            'id' => (int)$g['id'],
            'title' => (string)($g['title'] ?? ''),
            'subtitle' => (string)($g['subtitle'] ?? ''),
            'cover' => (string)($g['cover'] ?? ''),
            'price' => self::f($g['min_price'] ?? 0),
            'market_price' => self::f($g['market_price'] ?? 0),
            'stock' => (int)($g['total_stock'] ?? 0),
            'sales' => (int)($g['sales_count'] ?? 0),
            'is_recommend' => (int)($g['is_recommend'] ?? 0),
        ];
    }

    private static function goodsDetail(int $id): array
    {
        $g = Db::row('SELECT * FROM ' . Mall::t('goods') . ' WHERE id=? AND is_delete_time=0 LIMIT 1', [$id]);
        if ($g === null) { return ['code' => 404, 'msg' => '商品不存在']; }
        $skus = Db::rows('SELECT id, spec_json, spec_text, price, market_price, stock, is_default FROM ' . Mall::t('goods_sku')
            . ' WHERE goods_id=? AND is_delete_time=0 ORDER BY is_default DESC, id ASC', [$id]);
        $skuOut = [];
        foreach ($skus as $s) {
            $skuOut[] = [
                'id' => (int)$s['id'], 'spec_text' => (string)$s['spec_text'],
                'price' => self::f($s['price']), 'market_price' => self::f($s['market_price']),
                'stock' => (int)$s['stock'], 'is_default' => (int)$s['is_default'],
            ];
        }
        $images = [];
        if (!empty($g['images'])) {
            $dec = json_decode((string)$g['images'], true);
            if (is_array($dec)) { $images = array_values(array_filter(array_map('strval', $dec))); }
        }
        if ($images === [] && (string)$g['cover'] !== '') { $images = [(string)$g['cover']]; }
        return ['code' => 0, 'data' => [
            'id' => (int)$g['id'],
            'title' => (string)$g['title'], 'subtitle' => (string)$g['subtitle'],
            'cover' => (string)$g['cover'], 'images' => $images,
            'detail' => (string)($g['detail'] ?? ''),
            'price' => self::f($g['min_price']), 'market_price' => self::f($g['market_price']),
            'stock' => (int)$g['total_stock'], 'sales' => (int)$g['sales_count'],
            'status' => (int)$g['status'], 'comments' => (int)$g['comments'],
            'skus' => $skuOut,
        ]];
    }

    /** 复用：商品列表查询（首页推荐用） */
    private static function goodsQuery(array $filter, int $limit): array
    {
        $where = 'g.status=1 AND g.is_delete_time=0';
        $params = [];
        if (!empty($filter['category_id'])) { $where .= ' AND g.category_id=?'; $params[] = (int)$filter['category_id']; }
        if (!empty($filter['recommend'])) { $where .= ' AND g.is_recommend=1'; }
        $rows = Db::rows('SELECT g.id, g.title, g.subtitle, g.cover, g.min_price, g.market_price, g.total_stock, g.sales_count, g.is_recommend'
            . ' FROM ' . Mall::t('goods') . ' g WHERE ' . $where
            . ' ORDER BY g.is_recommend DESC, g.id DESC LIMIT ?', array_merge($params, [$limit]));
        $out = [];
        foreach ($rows as $g) { $out[] = self::goodsCard($g); }
        return $out;
    }

    /* ============ 资讯 + 评论 ============ */

    private static function articleList(array $q): array
    {
        $cat = (int)($q['category_id'] ?? 0);
        $tag = trim((string)($q['tag'] ?? ''));
        $page = max(1, (int)($q['page'] ?? 1));
        $limit = min(50, max(1, (int)($q['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;
        $where = "type='post' AND status='publish' AND deleted_at IS NULL";
        $params = [];
        if ($tag !== '') {
            $where .= " AND (tags LIKE ? OR title LIKE ?)";
            $params[] = '%' . $tag . '%';
            $params[] = '%' . $tag . '%';
        }
        $rows = Db::rows('SELECT id, title, excerpt, content, published_at, author_id FROM ' . Db::t('contents')
            . ' WHERE ' . $where . ' ORDER BY COALESCE(published_at, created_at) DESC LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset]));
        $total = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('contents') . ' WHERE ' . $where, $params);
        $list = [];
        foreach ($rows as $a) {
            $list[] = [
                'id' => (int)$a['id'], 'title' => (string)$a['title'],
                'excerpt' => (string)$a['excerpt'], 'thumb' => self::firstImg((string)($a['content'] ?? '')),
                'published_at' => (string)($a['published_at'] ?? ''),
            ];
        }
        return ['code' => 0, 'data' => ['list' => $list, 'page' => $page, 'limit' => $limit, 'total' => $total, 'has_more' => ($offset + count($list) < $total)]];
    }

    private static function articleDetail(int $id): array
    {
        $a = Db::row('SELECT id, title, excerpt, content, published_at, author_id FROM ' . Db::t('contents')
            . " WHERE id=? AND type='post' AND status='publish' AND deleted_at IS NULL LIMIT 1", [$id]);
        if ($a === null) { return ['code' => 404, 'msg' => '资讯不存在']; }
        $author = '';
        if ((int)$a['author_id'] > 0) {
            $u = Db::row('SELECT display_name, username FROM ' . Db::t('users') . ' WHERE id=? LIMIT 1', [(int)$a['author_id']]);
            if ($u) { $author = (string)($u['display_name'] ?? $u['username'] ?? ''); }
        }
        $comments = Db::rows('SELECT id, author_id, author_name, body, created_at FROM ' . Db::t('comments')
            . " WHERE content_id=? AND status='approved' AND kind='normal' ORDER BY id ASC LIMIT 200", [$id]);
        $cl = [];
        foreach ($comments as $c) {
            $name = (string)$c['author_name'];
            if ($name === '' && (int)$c['author_id'] > 0) {
                $u = Db::row('SELECT display_name FROM ' . Db::t('users') . ' WHERE id=? LIMIT 1', [(int)$c['author_id']]);
                $name = $u ? (string)($u['display_name'] ?? '') : '游客';
            }
            $cl[] = ['id' => (int)$c['id'], 'author' => $name ?: '游客', 'body' => (string)$c['body'], 'created_at' => (string)$c['created_at']];
        }
        return ['code' => 0, 'data' => [
            'id' => (int)$a['id'], 'title' => (string)$a['title'], 'excerpt' => (string)$a['excerpt'],
            'content' => (string)$a['content'], 'author' => $author, 'published_at' => (string)($a['published_at'] ?? ''),
            'comments' => $cl, 'comment_count' => count($cl),
        ]];
    }

    private static function commentPost(array $in): array
    {
        $uid = self::uid();
        $cid = (int)($in['content_id'] ?? 0);
        $post = Db::row('SELECT id FROM ' . Db::t('contents') . " WHERE id=? AND type='post' AND status='publish' AND deleted_at IS NULL LIMIT 1", [$cid]);
        if ($post === null) { return ['code' => 404, 'msg' => '资讯不存在']; }
        $body = trim((string)($in['body'] ?? ''));
        if (mb_strlen($body) < 2) { return ['code' => 400, 'msg' => '评论内容太短']; }
        $u = Db::row('SELECT display_name, username FROM ' . Db::t('users') . ' WHERE id=? LIMIT 1', [$uid]);
        $name = $u ? (string)($u['display_name'] ?? $u['username'] ?? '') : '微信用户';
        $moderation = (string)get_option('comment.moderation', '1') === '1';
        Db::insert('comments', [
            'content_id' => $cid, 'author_id' => $uid, 'author_name' => $name,
            'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            'body' => mb_substr($body, 0, 2000), 'kind' => 'normal', 'parent_id' => null,
            'status' => $moderation ? 'pending' : 'approved',
        ]);
        Cache::flush('pages'); Cache::flush('blocks');
        return ['code' => 0, 'msg' => $moderation ? '评论已提交，审核后显示' : '评论成功', 'data' => ['pending' => $moderation]];
    }

    /* ============ 购物车 ============ */

    private static function cartList(): array
    {
        $uid = self::uid();
        $rows = Db::rows('SELECT c.id cart_id, c.num, c.sku_id, c.goods_id, g.title, g.cover, g.status g_status, g.is_delete_time g_del,'
            . ' s.spec_text, s.price, s.market_price, s.stock s_stock, s.is_delete_time s_del'
            . ' FROM ' . Mall::t('cart') . ' c JOIN ' . Mall::t('goods') . ' g ON g.id=c.goods_id JOIN ' . Mall::t('goods_sku') . ' s ON s.id=c.sku_id'
            . ' WHERE c.uid=? ORDER BY c.updated_at DESC, c.id DESC', [$uid]);
        $list = []; $total = 0.0;
        foreach ($rows as $r) {
            $ok = ((int)$r['g_status'] === 1 && (int)$r['g_del'] === 0 && (int)$r['s_del'] === 0 && (int)$r['s_stock'] > 0);
            $price = self::f($r['price']);
            $num = (int)$r['num'];
            $total += $price * $num;
            $list[] = [
                'cart_id' => (int)$r['cart_id'], 'sku_id' => (int)$r['sku_id'], 'goods_id' => (int)$r['goods_id'],
                'title' => (string)$r['title'], 'cover' => (string)$r['cover'], 'spec_text' => (string)$r['spec_text'],
                'price' => $price, 'market_price' => self::f($r['market_price']), 'num' => $num, 'stock' => (int)$r['s_stock'],
                'ok' => $ok,
            ];
        }
        return ['code' => 0, 'data' => ['list' => $list, 'total' => round($total, 2), 'count' => count($list)]];
    }

    private static function cartAdd(array $in): array
    {
        $uid = self::uid();
        $skuId = (int)($in['sku_id'] ?? 0);
        $sku = Db::row('SELECT s.id, s.goods_id, s.stock FROM ' . Mall::t('goods_sku') . ' s JOIN ' . Mall::t('goods') . ' g ON g.id=s.goods_id'
            . ' WHERE s.id=? AND s.is_delete_time=0 AND g.status=1 AND g.is_delete_time=0 LIMIT 1', [$skuId]);
        if ($sku === null) { return ['code' => 400, 'msg' => '该商品暂不可购买']; }
        $cap = max(0, (int)$sku['stock']);
        if ($cap <= 0) { return ['code' => 400, 'msg' => '该规格暂时售罄']; }
        // ★ 数量上限 = min(999, 库存)，与网页端 lib.php::cartAdd 及本文件 cartUpdate 一致。
        //   旧代码首次加购只夹到 999、不用 $cap（$target = $exist===null ? $num : ...），
        //   会出现「购物车 999 件 / 库存 96」——下单时才报库存不足
        //   （不会超卖，因为下单有 stock<num 校验，但用户看到的数量是错的）。
        $want = max(1, min(999, (int)($in['num'] ?? 1)));
        $exist = Db::row('SELECT id, num FROM ' . Mall::t('cart') . ' WHERE uid=? AND sku_id=? LIMIT 1', [$uid, $skuId]);
        $had = $exist === null ? 0 : (int)$exist['num'];
        $target = min($had + $want, $cap);
        if ($target <= 0) { return ['code' => 400, 'msg' => '库存不足']; }
        if ($exist === null) {
            Db::insert('mall_cart', ['uid' => $uid, 'goods_id' => (int)$sku['goods_id'], 'sku_id' => $skuId, 'num' => $target]);
        } else {
            Db::update('mall_cart', ['num' => $target], 'id=?', [(int)$exist['id']]);
        }
        $msg = ($had + $want > $target)
            ? ('库存仅剩 ' . $cap . ' 件，购物车数量已按上限调整为 ' . $target)
            : '已加入购物车';
        return ['code' => 0, 'msg' => $msg];
    }

    private static function cartUpdate(array $in): array
    {
        $uid = self::uid();
        $cartId = (int)($in['cart_id'] ?? 0);
        $num = (int)($in['num'] ?? 0);
        $row = Db::row('SELECT c.id, s.stock FROM ' . Mall::t('cart') . ' c JOIN ' . Mall::t('goods_sku') . ' s ON s.id=c.sku_id'
            . ' WHERE c.id=? AND c.uid=? LIMIT 1', [$cartId, $uid]);
        if ($row === null) { return ['code' => 404, 'msg' => '购物车项不存在']; }
        if ($num <= 0) { Db::run('DELETE FROM ' . Mall::t('cart') . ' WHERE id=? AND uid=?', [$cartId, $uid]); return ['code' => 0, 'msg' => '已删除']; }
        $cap = max(1, min(999, (int)$row['stock']));
        Db::update('mall_cart', ['num' => max(1, min($cap, $num))], 'id=?', [$cartId]);
        return ['code' => 0, 'msg' => '已更新'];
    }

    private static function cartDel(array $in): array
    {
        $uid = self::uid();
        $cartId = (int)($in['cart_id'] ?? 0);
        Db::run('DELETE FROM ' . Mall::t('cart') . ' WHERE id=? AND uid=?', [$cartId, $uid]);
        return ['code' => 0, 'msg' => '已删除'];
    }

    /* ============ 下单 ============ */

    private static function orderCreate(array $in): array
    {
        $uid = self::uid();
        // 商品来源：items[{sku_id,num}] 或 buy_now(sku_id,num) 或 购物车
        $items = [];
        if (!empty($in['items']) && is_array($in['items'])) {
            foreach ($in['items'] as $it) {
                $items[] = ['sku_id' => (int)($it['sku_id'] ?? 0), 'num' => (int)($it['num'] ?? 1)];
            }
        } elseif (!empty($in['sku_id'])) {
            $items[] = ['sku_id' => (int)$in['sku_id'], 'num' => max(1, (int)($in['num'] ?? 1))];
        } else {
            $items = self::cartRows($uid);
        }
        if ($items === []) { return ['code' => 400, 'msg' => '没有可结算的商品']; }
        // 校验 SKU 有效 + 库存
        $valid = [];
        foreach ($items as $it) {
            $skuId = (int)$it['sku_id'];
            $num = max(1, (int)$it['num']);
            $sku = Db::row('SELECT s.id, s.goods_id, s.price, s.market_price, s.spec_text, s.stock, g.title, g.cover, g.status g_status, g.is_delete_time g_del, s.is_delete_time s_del'
                . ' FROM ' . Mall::t('goods_sku') . ' s JOIN ' . Mall::t('goods') . ' g ON g.id=s.goods_id'
                . ' WHERE s.id=? LIMIT 1', [$skuId]);
            if ($sku === null || (int)$sku['g_status'] !== 1 || (int)$sku['g_del'] !== 0 || (int)$sku['s_del'] !== 0 || (int)$sku['stock'] < $num) {
                return ['code' => 400, 'msg' => '部分商品不可购买或库存不足'];
            }
            $valid[] = ['sku_id' => $skuId, 'goods_id' => (int)$sku['goods_id'], 'num' => $num,
                'price' => self::f($sku['price']), 'title' => (string)$sku['title'], 'cover' => (string)$sku['cover'],
                'spec_text' => (string)$sku['spec_text']];
        }
        $addrId = (int)($in['address_id'] ?? 0);
        $addr = Db::row('SELECT * FROM ' . Mall::t('user_address') . ' WHERE id=? AND uid=? AND is_delete_time=0 LIMIT 1', [$addrId, $uid]);
        if ($addr === null) { return ['code' => 400, 'msg' => '请选择收货地址']; }
        $remark = mb_substr(trim((string)($in['remark'] ?? '')), 0, 255);

        $total = 0.0;
        foreach ($valid as $r) { $total += $r['price'] * $r['num']; }
        $freight = self::freightFee($total);
        $orderNo = date('YmdHis') . str_pad((string)($uid % 10000), 4, '0', STR_PAD_LEFT) . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $addressJson = json_encode([
            'name' => $addr['name'], 'mobile' => $addr['mobile'],
            'province' => $addr['province'], 'city' => $addr['city'], 'county' => $addr['county'],
            'address_detail' => $addr['address_detail'],
        ], JSON_UNESCAPED_UNICODE);

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($valid as $r) {
                $st = Db::run('UPDATE ' . Mall::t('goods_sku') . ' SET stock=stock-? WHERE id=? AND stock>=?', [$r['num'], $r['sku_id'], $r['num']]);
                if ($st->rowCount() === 0) { throw new RuntimeException('「' . $r['title'] . '」库存不足'); }
            }
            $orderId = Db::insert('mall_order', [
                'order_no' => $orderNo, 'uid' => $uid, 'status' => 0,
                'total_goods_price' => round($total, 2), 'freight_price' => $freight,
                'discount_price' => 0.00, 'pay_price' => round($total + $freight, 2),
                'address_json' => $addressJson, 'user_coupon_id' => 0, 'remark' => $remark,
            ]);
            foreach ($valid as $r) {
                Db::insert('mall_order_goods', [
                    'order_id' => $orderId, 'goods_id' => $r['goods_id'], 'sku_id' => $r['sku_id'],
                    'title' => $r['title'], 'spec_text' => $r['spec_text'], 'cover' => $r['cover'],
                    'price' => $r['price'], 'num' => $r['num'], 'total_price' => round($r['price'] * $r['num'], 2),
                ]);
                Db::run('UPDATE ' . Mall::t('goods') . ' SET sales_count=sales_count+? WHERE id=?', [$r['num'], $r['goods_id']]);
            }
            // 购物车模式：清掉已下单行
            if (empty($in['sku_id']) && empty($in['items'])) {
                foreach ($valid as $r) { Db::run('DELETE FROM ' . Mall::t('cart') . ' WHERE uid=? AND sku_id=?', [$uid, $r['sku_id']]); }
            }
            Db::insert('mall_order_log', ['order_id' => $orderId, 'order_no' => $orderNo, 'action' => 'create', 'detail' => '小程序下单', 'operator' => 'user']);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return ['code' => 400, 'msg' => '下单失败：' . $e->getMessage()];
        }
        return ['code' => 0, 'msg' => '下单成功', 'data' => ['order_no' => $orderNo, 'pay_price' => round($total + $freight, 2)]];
    }

    private static function orderList(array $q): array
    {
        $uid = self::uid();
        $status = $q['status'] ?? '';
        $where = 'o.uid=?';
        $params = [$uid];
        if ($status !== '' && $status !== 'all') { $where .= ' AND o.status=?'; $params[] = (int)$status; }
        $rows = Db::rows('SELECT o.id, o.order_no, o.status, o.pay_price, o.freight_price, o.total_goods_price, o.pay_type, o.created_at, o.pay_time'
            . ' FROM ' . Mall::t('order') . ' o WHERE ' . $where . ' ORDER BY o.id DESC LIMIT 50', $params);
        $list = [];
        foreach ($rows as $o) {
            $goods = Db::rows('SELECT title, cover, spec_text, price, num FROM ' . Mall::t('order_goods') . ' WHERE order_id=?', [(int)$o['id']]);
            $list[] = [
                'order_no' => (string)$o['order_no'], 'status' => (int)$o['status'],
                'status_text' => self::orderStatus((int)$o['status']),
                'pay_price' => self::f($o['pay_price']), 'pay_type' => (string)$o['pay_type'],
                'created_at' => (string)$o['created_at'], 'pay_time' => (string)($o['pay_time'] ?? ''),
                'goods' => array_map(fn($g) => [
                    'title' => (string)$g['title'], 'cover' => (string)$g['cover'],
                    'spec_text' => (string)$g['spec_text'], 'price' => self::f($g['price']), 'num' => (int)$g['num'],
                ], $goods),
            ];
        }
        return ['code' => 0, 'data' => ['list' => $list]];
    }

    private static function orderDetail(string $no): array
    {
        $uid = self::uid();
        $o = Db::row('SELECT * FROM ' . Mall::t('order') . ' WHERE order_no=? AND uid=? LIMIT 1', [$no, $uid]);
        if ($o === null) { return ['code' => 404, 'msg' => '订单不存在']; }
        $goods = Db::rows('SELECT title, cover, spec_text, price, num, total_price FROM ' . Mall::t('order_goods') . ' WHERE order_id=?', [(int)$o['id']]);
        $addr = json_decode((string)($o['address_json'] ?? ''), true) ?: [];
        return ['code' => 0, 'data' => [
            'order_no' => (string)$o['order_no'], 'status' => (int)$o['status'], 'status_text' => self::orderStatus((int)$o['status']),
            'pay_price' => self::f($o['pay_price']), 'total_goods_price' => self::f($o['total_goods_price']),
            'freight_price' => self::f($o['freight_price']), 'discount_price' => self::f($o['discount_price']),
            'pay_type' => (string)$o['pay_type'], 'remark' => (string)$o['remark'],
            'created_at' => (string)$o['created_at'], 'pay_time' => (string)($o['pay_time'] ?? ''),
            'address' => $addr,
            'goods' => array_map(fn($g) => [
                'title' => (string)$g['title'], 'cover' => (string)$g['cover'],
                'spec_text' => (string)$g['spec_text'], 'price' => self::f($g['price']), 'num' => (int)$g['num'],
                'total_price' => self::f($g['total_price']),
            ], $goods),
        ]];
    }

    private static function orderPay(array $in): array
    {
        $uid = self::uid();
        $no = trim((string)($in['order_no'] ?? ''));
        $o = Db::row('SELECT * FROM ' . Mall::t('order') . ' WHERE order_no=? AND uid=? LIMIT 1', [$no, $uid]);
        if ($o === null) { return ['code' => 404, 'msg' => '订单不存在']; }
        if ((int)$o['status'] !== 0) { return ['code' => 400, 'msg' => '订单状态不可支付']; }
        $cfg = self::wxCfg();
        $openidRow = Db::row('SELECT openid FROM ' . Mall::t('user_wx') . ' WHERE uid=? LIMIT 1', [$uid]);
        $openid = $openidRow ? (string)$openidRow['openid'] : '';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $notify = 'https://' . $host . '/mall/pay/notify/wxpay';
        $order = ['order_no' => $no, 'pay_price' => (float)$o['pay_price'], 'subject' => '夜叉商城订单' . $no, 'body' => ''];
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if ((new \MallPay\Wxpay($cfg))->configured() && $openid !== '' && strpos($openid, 'dev_') !== 0) {
            $wx = new \MallPay\Wxpay($cfg);
            $r = $wx->jsapiUnified($order, $notify, $ip, $openid);
            if (!$r['ok']) { return ['code' => 400, 'msg' => '微信下单失败：' . ($r['msg'] ?? '')]; }
            return ['code' => 0, 'data' => ['sandbox' => false, 'pay_params' => $r['pay_params']]];
        }
        // 沙箱回退：无商户号或 dev 登录 → 返回模拟支付入口
        return ['code' => 0, 'data' => ['sandbox' => true, 'sim_url' => '/mall/api/order/sim?no=' . rawurlencode($no) . '&token=' . self::curToken()]];
    }

    /** 沙箱模拟支付确认（dev/无商户号时用）；小程序走 Authorization 头鉴权 */
    private static function orderSimConfirm(string $no): array
    {
        $uid = self::uid();
        $o = Db::row('SELECT id, order_no, status, pay_price, uid FROM ' . Mall::t('order') . ' WHERE order_no=? AND uid=? LIMIT 1', [$no, $uid]);
        if ($o === null) { return ['code' => 404, 'msg' => '订单不存在']; }
        if ((int)$o['status'] !== 0) { return ['code' => 0, 'msg' => '订单已支付', 'data' => ['order_no' => $no]]; }
        self::markPaid($no, 'wxpay', 'SIM' . date('YmdHis') . random_int(1000, 9999), (float)$o['pay_price']);
        return ['code' => 0, 'msg' => '支付成功（沙箱模拟）', 'data' => ['order_no' => $no]];
    }

    private static function orderCancel(array $in): array
    {
        $uid = self::uid();
        $no = trim((string)($in['order_no'] ?? ''));
        $o = Db::row('SELECT id, status FROM ' . Mall::t('order') . ' WHERE order_no=? AND uid=? LIMIT 1', [$no, $uid]);
        if ($o === null) { return ['code' => 404, 'msg' => '订单不存在']; }
        if (!in_array((int)$o['status'], [0, 1], true)) { return ['code' => 400, 'msg' => '当前状态不可取消']; }
        $pdo = Db::pdo(); $pdo->beginTransaction();
        try {
            // 恢复库存
            $goods = Db::rows('SELECT sku_id, num FROM ' . Mall::t('order_goods') . ' WHERE order_id=?', [(int)$o['id']]);
            foreach ($goods as $g) { Db::run('UPDATE ' . Mall::t('goods_sku') . ' SET stock=stock+? WHERE id=?', [(int)$g['num'], (int)$g['sku_id']]); }
            Db::update('mall_order', ['status' => 4, 'cancel_time' => date('Y-m-d H:i:s'), 'cancel_reason' => '用户取消'], 'id=?', [(int)$o['id']]);
            Db::insert('mall_order_log', ['order_id' => (int)$o['id'], 'order_no' => $no, 'action' => 'cancel', 'detail' => '用户取消', 'operator' => 'user']);
            $pdo->commit();
        } catch (\Throwable $e) { $pdo->rollBack(); return ['code' => 400, 'msg' => '取消失败：' . $e->getMessage()]; }
        return ['code' => 0, 'msg' => '已取消'];
    }

    private static function orderConfirm(array $in): array
    {
        $uid = self::uid();
        $no = trim((string)($in['order_no'] ?? ''));
        $o = Db::row('SELECT id, status FROM ' . Mall::t('order') . ' WHERE order_no=? AND uid=? LIMIT 1', [$no, $uid]);
        if ($o === null) { return ['code' => 404, 'msg' => '订单不存在']; }
        if ((int)$o['status'] !== 2) { return ['code' => 400, 'msg' => '仅待收货订单可确认']; }
        Db::update('mall_order', ['status' => 3, 'confirm_time' => date('Y-m-d H:i:s')], 'id=?', [(int)$o['id']]);
        Db::insert('mall_order_log', ['order_id' => (int)$o['id'], 'order_no' => $no, 'action' => 'confirm', 'detail' => '用户确认收货', 'operator' => 'user']);
        return ['code' => 0, 'msg' => '已确认收货'];
    }

    /** 幂等标记已支付（复用 payApplyNotify 思路） */
    private static function markPaid(string $no, string $type, string $tradeNo, float $amount): void
    {
        $o = Db::row('SELECT id, status, uid FROM ' . Mall::t('order') . ' WHERE order_no=? LIMIT 1', [$no]);
        if ($o === null || (int)$o['status'] !== 0) { return; }
        $pdo = Db::pdo(); $pdo->beginTransaction();
        try {
            Db::run('UPDATE ' . Mall::t('order') . ' SET status=1, pay_type=?, pay_time=? WHERE id=? AND status=0',
                [$type, date('Y-m-d H:i:s'), (int)$o['id']]);
            Db::insert('mall_payment_log', [
                'out_trade_no' => $no, 'order_no' => $no, 'pay_type' => $type,
                'trade_no' => $tradeNo, 'amount' => round($amount, 2), 'created_at' => date('Y-m-d H:i:s'),
            ]);
            Db::insert('mall_order_log', ['order_id' => (int)$o['id'], 'order_no' => $no, 'action' => 'pay', 'detail' => '支付成功', 'operator' => 'user']);
            $pdo->commit();
        } catch (\Throwable $e) { $pdo->rollBack(); }
    }

    /* ============ 用户中心 / 资料 / 地址 / 收藏 ============ */

    private static function userCenter(): array
    {
        $uid = self::uid();
        $u = Db::row('SELECT id, display_name, username, avatar FROM ' . Db::t('users') . ' WHERE id=? LIMIT 1', [$uid]);
        $counts = [];
        foreach ([0, 1, 2, 3] as $st) {
            $counts[$st] = (int)Db::one('SELECT COUNT(*) FROM ' . Mall::t('order') . ' WHERE uid=? AND status=?', [$uid, $st]);
        }
        $fav = (int)Db::one('SELECT COUNT(*) FROM ' . Mall::t('favorite') . ' WHERE uid=?', [$uid]);
        $addr = (int)Db::one('SELECT COUNT(*) FROM ' . Mall::t('user_address') . ' WHERE uid=? AND is_delete_time=0', [$uid]);
        return ['code' => 0, 'data' => [
            'user' => [
                'id' => $uid, 'display_name' => $u ? (string)($u['display_name'] ?? '') : '',
                'username' => $u ? (string)($u['username'] ?? '') : '', 'avatar' => $u ? (string)($u['avatar'] ?? '') : '',
            ],
            'order_counts' => ['unpaid' => $counts[0], 'undelivered' => $counts[1], 'unreceived' => $counts[2], 'done' => $counts[3]],
            'favorite_count' => $fav, 'address_count' => $addr,
        ]];
    }

    private static function userProfile(array $in): array
    {
        $uid = self::uid();
        $set = [];
        if (isset($in['display_name'])) { $set['display_name'] = mb_substr(trim((string)$in['display_name']), 0, 40); }
        if (isset($in['avatar'])) { $set['avatar'] = trim((string)$in['avatar']); }
        if ($set === []) { return ['code' => 400, 'msg' => '无更新字段']; }
        Db::update('users', $set, 'id=?', [$uid]);
        return ['code' => 0, 'msg' => '已保存'];
    }

    private static function addressList(): array
    {
        $uid = self::uid();
        $rows = Db::rows('SELECT id, name, mobile, province, city, county, address_detail, is_default FROM ' . Mall::t('user_address')
            . ' WHERE uid=? AND is_delete_time=0 ORDER BY is_default DESC, id DESC', [$uid]);
        $list = [];
        foreach ($rows as $r) {
            $list[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'mobile' => (string)$r['mobile'],
                'province' => (string)$r['province'], 'city' => (string)$r['city'], 'county' => (string)$r['county'],
                'address_detail' => (string)$r['address_detail'], 'is_default' => (int)$r['is_default']];
        }
        return ['code' => 0, 'data' => ['list' => $list]];
    }

    private static function addressSave(array $in): array
    {
        $uid = self::uid();
        $id = (int)($in['id'] ?? 0);
        $data = [
            'name' => mb_substr(trim((string)($in['name'] ?? '')), 0, 40),
            'mobile' => mb_substr(trim((string)($in['mobile'] ?? '')), 0, 20),
            'province' => (string)($in['province'] ?? ''), 'city' => (string)($in['city'] ?? ''),
            'county' => (string)($in['county'] ?? ''), 'address_detail' => (string)($in['address_detail'] ?? ''),
            'is_default' => (int)($in['is_default'] ?? 0),
        ];
        if ($data['name'] === '' || $data['mobile'] === '') { return ['code' => 400, 'msg' => '请填写姓名与手机号']; }
        if ($id > 0) {
            $exist = Db::row('SELECT id FROM ' . Mall::t('user_address') . ' WHERE id=? AND uid=? LIMIT 1', [$id, $uid]);
            if ($exist === null) { return ['code' => 404, 'msg' => '地址不存在']; }
            Db::update('mall_user_address', $data, 'id=? AND uid=?', [$id, $uid]);
        } else {
            $data['uid'] = $uid;
            $id = Db::insert('mall_user_address', $data);
        }
        if ($data['is_default'] === 1) {
            Db::run('UPDATE ' . Mall::t('user_address') . ' SET is_default=0 WHERE uid=? AND id<>?', [$uid, $id]);
            Db::update('mall_user_address', ['is_default' => 1], 'id=? AND uid=?', [$id, $uid]);
        }
        return ['code' => 0, 'msg' => '已保存', 'data' => ['id' => $id]];
    }

    private static function addressDel(array $in): array
    {
        $uid = self::uid();
        $id = (int)($in['id'] ?? 0);
        Db::run('UPDATE ' . Mall::t('user_address') . ' SET is_delete_time=? WHERE id=? AND uid=?', [time(), $id, $uid]);
        return ['code' => 0, 'msg' => '已删除'];
    }

    private static function addressDefault(array $in): array
    {
        $uid = self::uid();
        $id = (int)($in['id'] ?? 0);
        Db::run('UPDATE ' . Mall::t('user_address') . ' SET is_default=0 WHERE uid=?', [$uid]);
        Db::update('mall_user_address', ['is_default' => 1], 'id=? AND uid=?', [$id, $uid]);
        return ['code' => 0, 'msg' => '已设为默认'];
    }

    private static function favoriteList(array $q): array
    {
        $uid = self::uid();
        $rows = Db::rows('SELECT f.id, f.goods_id, g.title, g.cover, g.min_price, g.market_price'
            . ' FROM ' . Mall::t('favorite') . ' f JOIN ' . Mall::t('goods') . ' g ON g.id=f.goods_id'
            . ' WHERE f.uid=? AND g.is_delete_time=0 ORDER BY f.id DESC LIMIT 50', [$uid]);
        $list = [];
        foreach ($rows as $r) {
            $list[] = ['fav_id' => (int)$r['id'], 'goods_id' => (int)$r['goods_id'], 'title' => (string)$r['title'],
                'cover' => (string)$r['cover'], 'price' => self::f($r['min_price']), 'market_price' => self::f($r['market_price'])];
        }
        return ['code' => 0, 'data' => ['list' => $list]];
    }

    private static function favoriteAdd(array $in): array
    {
        $uid = self::uid();
        $gid = (int)($in['goods_id'] ?? 0);
        $g = Db::row('SELECT id FROM ' . Mall::t('goods') . ' WHERE id=? AND is_delete_time=0 LIMIT 1', [$gid]);
        if ($g === null) { return ['code' => 404, 'msg' => '商品不存在']; }
        if (Db::one('SELECT id FROM ' . Mall::t('favorite') . ' WHERE uid=? AND goods_id=? LIMIT 1', [$uid, $gid]) > 0) {
            return ['code' => 0, 'msg' => '已收藏'];
        }
        Db::insert('mall_favorite', ['uid' => $uid, 'goods_id' => $gid, 'created_at' => date('Y-m-d H:i:s')]);
        return ['code' => 0, 'msg' => '已收藏'];
    }

    private static function favoriteDel(array $in): array
    {
        $uid = self::uid();
        $gid = (int)($in['goods_id'] ?? 0);
        Db::run('DELETE FROM ' . Mall::t('favorite') . ' WHERE uid=? AND goods_id=?', [$uid, $gid]);
        return ['code' => 0, 'msg' => '已取消收藏'];
    }

    /* ============ 工具 ============ */

    private static function cartRows(int $uid): array
    {
        $rows = Db::rows('SELECT sku_id, num FROM ' . Mall::t('cart') . ' WHERE uid=?', [$uid]);
        $out = [];
        foreach ($rows as $r) { $out[] = ['sku_id' => (int)$r['sku_id'], 'num' => (int)$r['num']]; }
        return $out;
    }

    private static function freightFee(float $total): float
    {
        $fee = (float)get_option('mall.freight_fee', '10');
        $free = (float)get_option('mall.free_from', '51');
        if ($free > 0 && $total >= $free) { return 0.0; }
        return $fee;
    }

    private static function wxCfg(): array
    {
        return [
            'app_id' => get_option('mall.pay_wxpay_app_id', ''),
            'mch_id' => get_option('mall.pay_wxpay_mch_id', ''),
            'key' => get_option('mall.pay_wxpay_key', ''),
            'secret' => get_option('mall.pay_wxpay_secret', ''),
            'sandbox' => get_option('mall.pay_wxpay_sandbox', '0') === '1',
            'sandbox_key' => get_option('mall.pay_wxpay_sandbox_key', ''),
        ];
    }

    private static function curToken(): string
    {
        $ah = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(\S+)/i', $ah, $m)) { return $m[1]; }
        return (string)($_GET['token'] ?? $_POST['token'] ?? '');
    }

    private static function orderStatus(int $st): string
    {
        return ['0' => '待付款', '1' => '待发货', '2' => '待收货', '3' => '已完成', '4' => '已取消', '5' => '售后中'][(string)$st] ?? '未知';
    }

    private static function f($v): float
    {
        return round((float)$v, 2);
    }

    private static function firstImg(string $html): string
    {
        if (preg_match('/<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) { return $m[1]; }
        return '';
    }

    private static function articleRows(int $limit, int $offset): array
    {
        return Db::rows('SELECT id, title, excerpt, content, published_at FROM ' . Db::t('contents')
            . " WHERE type='post' AND status='publish' AND deleted_at IS NULL ORDER BY COALESCE(published_at, created_at) DESC LIMIT ? OFFSET ?",
            [$limit, $offset]);
    }

    private static function out(int $code, $data = null, string $msg = ''): void
    {
        echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** 幂等建表（本地 dev 直跑；生产可走迁移 010） */
    private static function ensureTables(): void
    {
        $sql = [
            'CREATE TABLE IF NOT EXISTS ' . Mall::t('user_wx') . ' (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `openid` VARCHAR(64) NOT NULL DEFAULT \'\',
                `unionid` VARCHAR(64) NOT NULL DEFAULT \'\',
                `uid` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`), UNIQUE KEY `openid` (`openid`), KEY `uid` (`uid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS ' . Mall::t('api_token') . ' (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `token` VARCHAR(64) NOT NULL DEFAULT \'\',
                `uid` INT UNSIGNED NOT NULL DEFAULT 0,
                `openid` VARCHAR(64) NOT NULL DEFAULT \'\',
                `expire_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`), UNIQUE KEY `token` (`token`), KEY `uid` (`uid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
        foreach ($sql as $s) { Db::run($s); }
    }
}
