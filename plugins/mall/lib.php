<?php
declare(strict_types=1);
/**
 * Mall：商城插件核心（M1 骨架）
 * - 路由：内核 case 'mall' 分发多级路径到 Mall::dispatch()；/mall 单段经 plugin_page 兜底同入口。
 * - 用户：复用博客用户中心（ye_users + Auth），不建独立会员表。
 * - 风格：51puer 传统电商（mall-style-guide.md）——灰白底 / 砖红导航 / 方角 / 1200px；CSS 内联输出（同 forum 自绘路线）。
 */
require_once __DIR__ . '/pay/Alipay.php';
require_once __DIR__ . '/pay/Wxpay.php';
require_once __DIR__ . '/Member.php';
require_once __DIR__ . '/Promo.php';      // M11 促销玩法：秒杀 / 拼团 / 砍价 / 组合优惠
require_once __DIR__ . '/Level.php';      // M13 会员等级折扣
require_once __DIR__ . '/Logistics.php';  // M12 履约通知：物流轨迹 / 自动取消 / 站内消息
final class Mall
{
    use MallMember; // M7 会员中心（个人中心加强）：侧栏 / 14 个页面 / 会员 POST 动作
    use MallPromo;  // M11 促销玩法（单价管线 + 活动页 + 后台 Tab）
    use MallLevel;  // M13 会员等级折扣（参与单价管线）
    use MallLogistics; // M12 履约通知

    public const PER_PAGE = 20;

    /** 表名：mall_goods → ye_mall_goods（跟随宿主前缀机制） */
    public static function t(string $name): string
    {
        return Db::t('mall_' . $name);
    }

    /* ================= URL 助手（51puer 风格根 slug，与 51puer.com 一致） ================= */
    private static function goodsUrl(int $id): string { return '/goods-' . $id . '.html'; }
    private static function listUrl(?int $catId, string $qs = ''): string
    {
        $base = '/list.html' . ($catId !== null ? '?category_id=' . (int)$catId : '');
        if ($qs !== '') { $base .= (strpos($base, '?') === false ? '?' : '&') . ltrim($qs, '&?'); }
        return $base;
    }
    private static function searchUrl(string $kw): string { return '/goods/lists.html?keyword=' . rawurlencode($kw); }
    private static function articleUrl(int $id): string { return '/article-' . $id . '.html'; }
    private static function articleListUrl(): string { return '/article/lists.html'; }
    private static function articleCatUrl(string $slug): string
    {
        // 与老 51puer 一致：/article/lists.html?class_id={原栏目id}（slug 形如 n{oldId}）
        if (preg_match('/^n(\d+)$/', $slug, $m)) { return '/article/lists.html?class_id=' . $m[1]; }
        return '/article/category/' . rawurlencode($slug) . '.html';
    }
    private static function articleTagUrl(string $slug): string { return '/article/tag/' . rawurlencode($slug) . '.html'; }
    private static function newsUrl(int $page = 1): string { return '/article/lists.html' . ($page > 1 ? '?page=' . (int)$page : ''); }
    private static function tagFilterUrl(string $slug, string $qs = ''): string
    {
        $base = '/list.html?tag=' . rawurlencode($slug);
        if ($qs !== '') { $base .= '&' . ltrim($qs, '&?'); }
        return $base;
    }
    private static function cartUrl(): string { return '/cart'; }
    private static function checkoutUrl(string $qs = ''): string { return '/checkout' . ($qs !== '' ? '?' . ltrim($qs, '?') : ''); }
    private static function orderUrl(?string $no = null): string { return $no !== null ? '/order/' . rawurlencode($no) : '/order'; }
    private static function payUrl(string $no): string { return '/pay/' . rawurlencode($no); }
    private static function couponUrl(): string { return '/coupon'; }
    private static function addressUrl(): string { return '/address'; }
    private static function brandUrl(string $slug): string { return '/brand/' . rawurlencode($slug) . '/'; }
    private static function promoUrl(string $kind, ?int $id = null): string { return '/promo/' . rawurlencode($kind) . ($id !== null ? '/' . $id : ''); }
    private static function cronUrl(string $token): string { return '/cron?token=' . rawurlencode($token); }

    /* ================= 路由分发 ================= */

    /** /mall[/...] → ['html'=>..,'title'=>..]（通栏，无博客侧栏） */
    public static function dispatch(array $path, array $query): array
    {
        $head = (string)($path[0] ?? '');
        // M12 履约定时任务：/mall/cron?token=xxx（纯文本应答，内部 exit）
        if ($head === 'cron') { self::cronEndpoint(); }
        // SEO：内容页 canonical（主题 layout 消费 YC_CANONICAL；交易页不声明）。51puer 根 slug
        //   ★ 列表页的类目在 **query**（/list.html?category_id=N，51puer 格式），
        //     不是在 path 段里 —— 只判 $path[1] 会让所有分类页 canonical 都退到 "/"，
        //     等于告诉搜索引擎「分类页都是首页副本」，分类页会被摘出索引。
        if (in_array($head, ['', 'list', 'goods'], true)) {
            $canon = '/';
            if ($head === 'goods' && isset($path[1])) {
                $canon = '/goods-' . (int)preg_replace('/\D/', '', (string)$path[1]) . '.html';
            } elseif ($head === 'list') {
                $cid = 0;
                $qcid = (int)($_GET['category_id'] ?? 0);
                if ($qcid > 0) {
                    $cid = $qcid;                                   // 51puer 格式
                } elseif (isset($path[1])) {
                    $slug = (string)$path[1];                       // 旧式 /list/{cat}/
                    $cid = (int)Db::one('SELECT id FROM ' . self::t('goods_category') . ' WHERE slug=? AND is_delete_time=0', [$slug]);
                }
                if ($cid > 0) {
                    $canon = '/list.html?category_id=' . $cid;
                } elseif (($kw = trim((string)($_GET['keyword'] ?? $_GET['q'] ?? ''))) !== '') {
                    $canon = '/goods/lists.html?keyword=' . rawurlencode($kw);   // 搜索页自带 canonical
                } else {
                    $canon = '/list.html';
                }
            }
            $GLOBALS['YC_CANONICAL'] = yc_theme_scheme() . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $canon;
        }
        // 分类列表页也给 description（列表页无描述时搜索引擎只能自造摘要）
        if ($head === 'list') {
            $cn = '';
            $qcid2 = (int)($_GET['category_id'] ?? 0);
            if ($qcid2 > 0) {
                $cn = (string)Db::one('SELECT name FROM ' . self::t('goods_category') . ' WHERE id=? AND is_delete_time=0', [$qcid2]);
            }
            $GLOBALS['YC_META']['description'] = $cn !== ''
                ? ($cn . '：' . site_label() . '在售' . $cn . '商品，产地直供、正品保障、支持15天无理由退换。')
                : (site_label() . '全部在售商品，云南普洱茶生熟饼砖沱与茶具，产地直供、正品保障。');
            $GLOBALS['YC_OG']['description'] = $GLOBALS['YC_META']['description'];
        }
        // ================= ★ 统一 SEO 层（51普洱茶网） =================
        // 背景：原来各页 title 是硬编码的「xxx - 商城」，且只有列表页有 description，
        //   分类/品牌/标签/搜索页缺 canonical 与 keywords —— 这些页会被搜索引擎当重复内容。
        // 这里在 dispatch 开头一次性生成，页面内部不再各自拼 title 后缀。
        self::seo($head, $path);
        // 前台 POST 动作统一入口（加购/改量/地址/下单/支付…）；商城前台自管 CSRF（宿主仅对 admin POST 统一检查）
        // 小程序 API 自带 JSON 路由与鉴权，不进前台 handlePost（否则会被 default 重定向到 /mall）
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $head !== 'api') {
            self::handlePost($head);
        }
        switch ($head) {
            case '':
                $res = self::home();
                break;
            case 'list':
                $res = self::listPage($path, $query);
                break;
            case 'goods':
                $res = self::goodsPage($path);
                break;
            case 'cart':
                $res = self::cartPage();
                break;
            case 'address':
                $res = self::addressPage($query);
                break;
            case 'coupon':
                $res = self::couponPage($query);
                break;
            case 'help':
                $res = self::helpPage($path);
                break;
            case 'checkout':
                $res = self::checkoutPage($query);
                break;
            case 'order':
                $res = self::orderPage($path, $query);
                break;
            case 'pay':
                // /mall/pay/notify/{driver} = 渠道异步回调（无 CSRF、无会话；验签在驱动内）
                $sub = (string)($path[1] ?? '');
                if ($sub === 'notify') {
                    self::payNotify((string)($path[2] ?? 'mock')); // 内部 echo + exit
                    return ['html' => '', 'title' => 'pay notify'];
                }
                if ($sub === 'sim') {
                    $res = self::paySimPage((string)($path[2] ?? ''), (string)($path[3] ?? ''));
                    break;
                }
                if ($sub === 'qr') {
                    $res = self::payQrPage((string)($path[2] ?? ''), (string)($path[3] ?? ''));
                    break;
                }
                if ($sub === 'wxjs') {
                    $res = self::payWxJsPage((string)($path[2] ?? ''));
                    break;
                }
                if ($sub === 'wxoauth') {
                    self::wxOauthPage(); // 内部 header + exit
                    return ['html' => '', 'title' => 'wechat oauth'];
                }
                $res = self::payPage($path, $query);
                break;
            case 'user':
                // 会员中心（个人中心加强）：/mall/user/{page}
                $res = self::userCenter($path, $query);
                break;
            case 'brand':
                // 品牌聚合页：/mall/brand/{slug}/
                $res = self::brandPage($path);
                break;
            case 'tag':
                // 标签页：/tag/{slug}/（等同按标签筛选的商品列表，根目录）
                $res = self::tagPage($path);
                break;
            case 'article':
                // 资讯：/article/lists.html（列表）| /article-{id}.html（详情）
                $res = self::articlePage($path);
                break;
            case 'search':
                // 商品搜索：/goods/lists.html?keyword=（51puer 风格）；listPage 原生支持 q
                $q = trim((string)($query['keyword'] ?? ''));
                $res = self::listPage([], ['q' => $q] + $query);
                break;

                case 'topic':

                    // 专题（对标 51puer /topic）：根路径进来，转给 promoPage 的多级路由

                    //   /topic → ['promo','topic']；/topic/{id}.html → ['promo','topic','{id}']

                    $res = self::promoPage(array_merge(['promo'], $path), $query);

                    break;
            case 'promo':
                // 促销活动：/mall/promo/{seckill|groupon|bargain|bundle}[/{id}]
                $res = self::promoPage($path, $query);
                break;
            case 'api':
                // 微信小程序 JSON API：/mall/api/{resource}[/{id}]（自带路由+鉴权，输出 JSON 后 exit）
                require_once __DIR__ . '/api/Api.php';
                \MallApi::run($path, $query);
                return ['html' => '', 'title' => 'api'];
            default:
                $res = self::notFound();
                break;
        }
        // ★ 交易/账户页 noindex：内核的 render_page 只消费 canonical/description/keywords，
        //   不消费 robots，所以这里直接输出 meta 标签（避免被索引成低价值页稀释权重）。
        $robots = (string)($GLOBALS['YC_META']['robots'] ?? '');
        $robotsTag = $robots !== '' ? '<meta name="robots" content="' . e($robots) . '">' : '';

        // .mall-root：样式作用域 + 暗色模式免疫（见 mall.css 头注）
        $res['html'] = '<div class="mall-root">' . $robotsTag . $res['html'] . '</div>';

        // ★ 标题规范化（统一在此处做，避免散落几十处硬编码）
        //   原来各页 title 是「xxx - 商城」或「品牌 - 商城」，死板且缺站点名。
        //   这里统一为「主标题 - 站点名」，并去掉重复的「商城」兜底后缀。
        if (isset($res['title'])) {
            $res['title'] = self::seoTitle(self::normTitle((string)$res['title']));
        }

        return $res;
    }

    /* ================= 页面：商城首页 ================= */

    /**
     * 首页英雄区（替代 280px 纯红渐变占位）
     *
     * 设计依据（~/taste 校正）：
     *   ① 原实现是无图时的占位（.mall-slide-ph），280px 纯色渐变占了首屏 1/3，
     *      红色在"铺面积"而不是当"标点"——眼睛没有落点。
     *   ② 老吕方向：暖红色系。暖度走红色与灰阶，红只做强调。
     *   ③ 现在 ye_mall_ad 无数据，所以不做"假轮播"，改成能用的英雄区：
     *      左侧=品牌主张 + 分类快捷入口；右侧=热销单品（真实数据）。
     *   ④ 若将来配了 home_slide 广告图，自动回退成图片轮播（保留原能力）。
     */
    private static function hero(array $cats): string
    {
        // 配了广告图 → 保持图片轮播（原能力不丢）
        $slide = Db::rows('SELECT name, image, url FROM ' . self::t('ad')
            . " WHERE position='home_slide' AND is_show=1 AND is_delete_time=0 ORDER BY sort ASC, id ASC LIMIT 5");
        $hasImg = false;
        foreach ($slide as $ad) {
            if (trim((string)$ad['image']) !== '') { $hasImg = true; break; }
        }
        if ($hasImg) {
            $h = '<div class="mall-slide-wrap"><div class="mall-slide">';
            foreach ($slide as $ad) {
                $img = (string)$ad['image'];
                if ($img === '') { continue; }
                $h .= '<a class="mall-slide-item" href="' . e((string)($ad['url'] ?: '#')) . '">'
                    . '<img src="' . e($img) . '" alt="' . e((string)$ad['name']) . '"></a>';
            }
            return $h . '</div></div>';
        }

        // 无广告图 → 功能型英雄区
        $topCats = array_slice(array_values(array_filter($cats, fn($c) => (int)$c['pid'] === 0)), 0, 7);

        // 右侧热销：真实销量最高的 3 款（可售）
        $hot = Db::rows('SELECT id, title, cover, min_price, sales_count FROM ' . self::t('goods')
            . ' WHERE status=1 AND is_delete_time=0 AND total_stock>0 AND title NOT LIKE "%售罄%"'
            . ' ORDER BY sales_count DESC, id DESC LIMIT 3');

        $total = (int)Db::one('SELECT COUNT(*) FROM ' . self::t('goods')
            . ' WHERE status=1 AND is_delete_time=0 AND total_stock>0 AND title NOT LIKE "%售罄%"');

        $h = '<div class="mall-hero"><div class="mall-w"><div class="mall-hero-in">';

        // 左：主张 + 分类入口 + 搜索
        $h .= '<div class="mall-hero-l">'
            . '<h1>云南普洱茶 · 产地直发</h1>'
            . '<p class="mall-hero-sub">生茶熟茶、古树山头、红茶白茶，'
            . '<b>' . number_format($total) . '</b> 款在售，满 51 元包邮 · 15 天无理由退换</p>';

        if ($topCats) {
            $h .= '<div class="mall-hero-cats">';
            foreach ($topCats as $c) {
                $h .= '<a href="' . e(self::listUrl((int)$c['id'])) . '">' . e((string)$c['name']) . '</a>';
            }
            $h .= '<a class="more" href="/list.html">全部商品 &gt;</a>';
            $h .= '</div>';
        }

        $h .= '<form class="mall-hero-search" action="/list.html" method="get" role="search">'
            . '<input type="text" name="q" placeholder="搜茶名 / 山头 / 年份，如「易武古树」" autocomplete="off">'
            . '<button type="submit">搜索</button></form>';

        $h .= '<p class="mall-hero-note">不确定买哪款？点右下角「帮你选茶」，说说预算和口味。</p>';
        $h .= '</div>';

        // 右：热销单品
        if ($hot) {
            $h .= '<div class="mall-hero-r"><div class="mall-hero-rt">热销榜</div>';
            foreach ($hot as $g) {
                $h .= '<a class="mall-hero-item" href="/goods-' . (int)$g['id'] . '.html">'
                    . '<img src="' . e((string)$g['cover']) . '" alt="" loading="lazy">'
                    . '<div class="tx"><b>' . e(mb_substr((string)$g['title'], 0, 26)) . '</b>'
                    . '<span class="mall-hero-price">¥' . e(number_format((float)$g['min_price'], 2, '.', '')) . '</span>'
                    . '<em>已售 ' . (int)$g['sales_count'] . '</em></div></a>';
            }
            $h .= '</div>';
        }

        $h .= '</div></div></div>';
        return $h;
    }

    private static function home(): array
    {
        $cats = self::categories();
        $topCats = array_values(array_filter($cats, fn($c) => (int)$c['pid'] === 0));

        $html = self::css() . self::topbar() . self::header() . self::navBar($cats);
        // 英雄区：有广告图→图片轮播；无图→功能型英雄区（品牌主张+分类入口+热销）
        $html .= self::hero($cats);

        // 新品上市楼层（全量最新）
        $news = self::goodsQuery([], 8);
        $html .= self::floor('新品上市', self::listUrl(null), $news);

        // 分类楼层：每个顶层类目一档（更多 → /list.html?category_id=）
        foreach ($topCats as $c) {
            $goods = self::goodsQuery(['category_id' => (int)$c['id']], 8);
            if ($goods) {
                $html .= self::floor((string)$c['name'], self::listUrl((int)$c['id']), $goods);
            }
        }

        // 最新资讯楼层（博客文章，渲染为 51puer 资讯块；链接 /article-{id}.html）
        $html .= self::homeArticles();

        $html .= self::footer();
        // 首页标题：品牌打头 + 核心词（"商城" 对 SEO 无价值，且首页是品牌落地页）
        return ['html' => $html, 'title' => self::siteName() . ' - 云南普洱茶产地直供_生茶熟茶_古树茶'];
    }

    /** 首页分类楼层（标题 + 更多 + 商品栅格） */
    private static function floor(string $title, string $more, array $goods): string
    {
        $h = '<div class="mall-floor"><div class="mall-floor-hd"><h2>' . e($title) . '</h2>'
            . '<a href="' . e($more) . '">更多 &gt;</a></div><div class="mall-goods-grid">';
        if ($goods) {
            foreach ($goods as $g) { $h .= self::goodsCard($g); }
        } else {
            $h .= self::emptyGrid('商品上架中，敬请期待');
        }
        return $h . '</div></div>';
    }

    /** 首页最新资讯块（取博客 contents 文章） */
    private static function homeArticles(): string
    {
        $rows = Db::rows('SELECT id, title, excerpt, content FROM ' . Db::t('contents')
            . " WHERE type='post' AND status='publish' AND deleted_at IS NULL ORDER BY COALESCE(published_at, created_at) DESC LIMIT 6");
        if (!$rows) { return ''; }
        $lis = '';
        foreach ($rows as $a) {
            $img = self::firstImg((string)($a['content'] ?? ''));
            $thumb = $img !== '' ? '<div class="mall-news-img"><img src="' . e($img) . '" alt=""></div>'
                                  : '<div class="mall-news-img"><div class="mall-news-ph">资讯</div></div>';
            $lis .= '<li><a href="' . e(self::articleUrl((int)$a['id'])) . '">'
                . $thumb
                . '<div class="mall-news-t">' . e((string)$a['title']) . '</div>'
                . '<div class="mall-news-d">' . e(Str::excerpt((string)($a['excerpt'] ?? ''), 36)) . '</div>'
                . '</a></li>';
        }
        return '<div class="mall-floor mall-floor-news"><div class="mall-floor-hd"><h2>最新资讯</h2>'
            . '<a href="' . e(self::articleListUrl()) . '">更多 &gt;</a></div>'
            . '<ul class="mall-news-list">' . $lis . '</ul></div>';
    }

    /* ================= 页面：资讯（博客 contents，51puer 风格 /article-*） ================= */

    /** /article/lists.html → 资讯列表；/article/category/{slug}.html → 分类；/article/tag/{slug}.html → 标签；/article-{id}.html → 详情 */
    private static function articlePage(array $path): array
    {
        $sub = (string)($path[1] ?? '');
        if ($sub === 'category' && isset($path[2])) {
            return self::articleCategory((string)$path[2]);
        }
        if ($sub === 'tag' && isset($path[2])) {
            return self::articleTag((string)$path[2]);
        }
        if ($sub === 'comment') {
            return self::articleList(); // 评论 POST 由 handlePost 处理，GET 直接回列表
        }
        $last = (string)($path[1] ?? $path[0] ?? '');
        if (preg_match('/^(\d+)\.html$/', $last, $m)) {
            return self::articleDetail((int)$m[1]);
        }
        // 51puer 风格栏目标：/article/lists.html?class_id={原栏目id}
        $clsId = (int)($_GET['class_id'] ?? 0);
        if ($clsId > 0) {
            return self::articleCategory('n' . $clsId);
        }
        return self::articleList();
    }

    private static function articleList(): array
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per = 12;
        $total = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('contents')
            . " WHERE type='post' AND status='publish' AND deleted_at IS NULL");
        $rows = Db::rows('SELECT id, title, excerpt, content, ' . self::publishAt() . ' AS dt FROM ' . Db::t('contents')
            . " WHERE type='post' AND status='publish' AND deleted_at IS NULL ORDER BY dt DESC LIMIT $per OFFSET " . (($page - 1) * $per));
        $lis = '';
        foreach ($rows as $a) {
            $lis .= self::newsItemHtml($a);
        }
        $pager = '';
        $tp = max(1, (int)ceil($total / $per));
        if ($tp > 1) {
            $pager = '<div class="mall-pager">';
            for ($i = 1; $i <= $tp; $i++) {
                $on = $i === $page ? ' class="on"' : '';
                $pager .= '<a' . $on . ' href="/article/lists.html?page=' . $i . '">' . $i . '</a>';
            }
            $pager .= '</div>';
        }
        $html = self::css() . self::topbar() . self::header() . self::navBar(self::categories())
            . '<div class="mall-article-wrap"><div class="mall-article-main">'
            . '<div class="mall-article-list"><div class="mall-floor-hd"><h2>茶业资讯</h2></div>'
            . '<ul class="mall-news-list mall-news-list-lg">' . $lis . '</ul>' . $pager . '</div>'
            . '</div>' . self::newsSidebar() . '</div>'
            . self::footer();
        return ['html' => $html, 'title' => '茶业资讯 · ' . site_label()];
    }

    /** 资讯列表单项（首页/列表/分类/标签页复用） */
    private static function newsItemHtml(array $a): string
    {
        $img = self::firstImg((string)($a['content'] ?? ''));
        $thumb = $img !== '' ? '<div class="mall-news-img"><img src="' . e($img) . '" alt=""></div>'
                              : '<div class="mall-news-img"><div class="mall-news-ph">资讯</div></div>';
        $dt = (string)($a['dt'] ?? $a['published_at'] ?? $a['created_at']);
        return '<li><a href="' . e(self::articleUrl((int)$a['id'])) . '">'
            . $thumb
            . '<div class="mall-news-t">' . e((string)$a['title']) . '</div>'
            . '<div class="mall-news-meta">' . e(lang_date(strtotime($dt))) . '</div>'
            . '<div class="mall-news-d">' . e(Str::excerpt((string)($a['excerpt'] ?? ''), 48)) . '</div>'
            . '</a></li>';
    }

    /** 分类子树 id 集合（含自身），用于分类页展开子栏目文章 */
    private static function catTreeIds(int $rootId): array
    {
        $all = Db::rows('SELECT id, parent FROM ' . Db::t('metas') . " WHERE type='category'");
        $children = [];
        foreach ($all as $c) { $children[(int)$c['parent']][] = (int)$c['id']; }
        $ids = [(int)$rootId]; $stack = [$rootId];
        while ($stack) {
            $cur = array_pop($stack);
            foreach ($children[$cur] ?? [] as $ch) {
                if (!in_array($ch, $ids, true)) { $ids[] = $ch; $stack[] = $ch; }
            }
        }
        return $ids;
    }

    /** 资讯侧栏：分类树 + 热门标签 + 最新 + 热门 */
    private static function newsSidebar(): string
    {
        $allCats = Db::rows('SELECT id, name, slug, parent, `count` FROM ' . Db::t('metas') . " WHERE type='category' AND slug<>'news' ORDER BY `order` ASC, id ASC");
        $top = []; $children = [];
        foreach ($allCats as $c) {
            if ((int)$c['parent'] === 0) { $top[] = $c; } else { $children[(int)$c['parent']][] = $c; }
        }
        $catHtml = '<div class="mall-side-block"><h3>资讯分类</h3><ul class="mall-side-cats">';
        foreach ($top as $c) {
            $catHtml .= '<li><a href="' . e(self::articleCatUrl((string)$c['slug'])) . '">' . e((string)$c['name']) . ' <span>' . (int)$c['count'] . '</span></a>';
            if (isset($children[(int)$c['id']])) {
                $catHtml .= '<ul class="mall-side-cats-sub">';
                foreach ($children[(int)$c['id']] as $ch) {
                    $catHtml .= '<li><a href="' . e(self::articleCatUrl((string)$ch['slug'])) . '">' . e((string)$ch['name']) . ' <span>' . (int)$ch['count'] . '</span></a></li>';
                }
                $catHtml .= '</ul>';
            }
            $catHtml .= '</li>';
        }
        $catHtml .= '</ul></div>';

        $tags = Db::rows('SELECT name, slug, `count` FROM ' . Db::t('metas') . " WHERE type='tag' AND `count`>0 ORDER BY `count` DESC LIMIT 24");
        $tagHtml = '<div class="mall-side-block"><h3>热门标签</h3><div class="mall-tag-cloud">';
        foreach ($tags as $t) {
            $tagHtml .= '<a class="mall-tag" href="' . e(self::articleTagUrl((string)$t['slug'])) . '">' . e((string)$t['name']) . '</a>';
        }
        $tagHtml .= '</div></div>';

        $recent = Db::rows('SELECT id, title FROM ' . Db::t('contents') . " WHERE type='post' AND status='publish' AND deleted_at IS NULL ORDER BY " . self::publishAt() . ' DESC LIMIT 8');
        $recHtml = '<div class="mall-side-block"><h3>最新资讯</h3><ul class="mall-side-news">';
        foreach ($recent as $r) { $recHtml .= '<li><a href="' . e(self::articleUrl((int)$r['id'])) . '">' . e((string)$r['title']) . '</a></li>'; }
        $recHtml .= '</ul></div>';

        $hot = Db::rows('SELECT id, title FROM ' . Db::t('contents') . " WHERE type='post' AND status='publish' AND deleted_at IS NULL ORDER BY views DESC LIMIT 8");
        $hotHtml = '<div class="mall-side-block"><h3>热门资讯</h3><ul class="mall-side-news">';
        foreach ($hot as $r) { $hotHtml .= '<li><a href="' . e(self::articleUrl((int)$r['id'])) . '">' . e((string)$r['title']) . '</a></li>'; }
        $hotHtml .= '</ul></div>';

        return '<aside class="mall-article-side">' . $catHtml . $tagHtml . $recHtml . $hotHtml . '</aside>';
    }

    /** 资讯分类页：/article/category/{slug}.html（展开子栏目） */
    private static function articleCategory(string $slug): array
    {
        $meta = Db::row('SELECT * FROM ' . Db::t('metas') . " WHERE type='category' AND slug=? LIMIT 1", [$slug]);
        if ($meta === null) { return self::notFound(); }
        $ids = self::catTreeIds((int)$meta['id']);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per = 12;
        $total = (int)Db::one('SELECT COUNT(DISTINCT r.content_id) FROM ' . Db::t('relationships') . ' r JOIN ' . Db::t('contents') . " c ON c.id=r.content_id WHERE r.meta_id IN ($in) AND c.type='post' AND c.status='publish' AND c.deleted_at IS NULL", $ids);
        $rows = Db::rows('SELECT c.id, c.title, c.excerpt, c.content, ' . self::publishAt() . ' AS dt FROM ' . Db::t('relationships') . ' r JOIN ' . Db::t('contents') . " c ON c.id=r.content_id WHERE r.meta_id IN ($in) AND c.type='post' AND c.status='publish' AND c.deleted_at IS NULL GROUP BY c.id ORDER BY dt DESC LIMIT $per OFFSET " . (($page - 1) * $per), $ids);
        $lis = ''; foreach ($rows as $a) { $lis .= self::newsItemHtml($a); }
        $pager = self::newsPager($total, $per, $page, self::articleCatUrl($slug));
        $html = self::css() . self::topbar() . self::header() . self::navBar(self::categories())
            . '<div class="mall-article-wrap"><div class="mall-article-main">'
            . '<div class="mall-article-list"><div class="mall-crumbs"><a href="' . e(self::articleListUrl()) . '">资讯</a> &gt; ' . e((string)$meta['name']) . '</div>'
            . '<div class="mall-floor-hd"><h2>' . e((string)$meta['name']) . '</h2></div>'
            . '<ul class="mall-news-list mall-news-list-lg">' . $lis . '</ul>' . $pager . '</div>'
            . '</div>' . self::newsSidebar() . '</div>'
            . self::footer();
        return ['html' => $html, 'title' => (string)$meta['name'] . ' · 资讯 · ' . site_label()];
    }

    /** 资讯标签页：/article/tag/{slug}.html */
    private static function articleTag(string $slug): array
    {
        $meta = Db::row('SELECT * FROM ' . Db::t('metas') . " WHERE type='tag' AND slug=? LIMIT 1", [$slug]);
        if ($meta === null) { return self::notFound(); }
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per = 12;
        $total = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('relationships') . ' r JOIN ' . Db::t('contents') . " c ON c.id=r.content_id WHERE r.meta_id=? AND c.type='post' AND c.status='publish' AND c.deleted_at IS NULL", [(int)$meta['id']]);
        $rows = Db::rows('SELECT c.id, c.title, c.excerpt, c.content, ' . self::publishAt() . ' AS dt FROM ' . Db::t('relationships') . ' r JOIN ' . Db::t('contents') . " c ON c.id=r.content_id WHERE r.meta_id=? AND c.type='post' AND c.status='publish' AND c.deleted_at IS NULL GROUP BY c.id ORDER BY dt DESC LIMIT $per OFFSET " . (($page - 1) * $per), [(int)$meta['id']]);
        $lis = ''; foreach ($rows as $a) { $lis .= self::newsItemHtml($a); }
        $pager = self::newsPager($total, $per, $page, self::articleTagUrl($slug));
        $html = self::css() . self::topbar() . self::header() . self::navBar(self::categories())
            . '<div class="mall-article-wrap"><div class="mall-article-main">'
            . '<div class="mall-article-list"><div class="mall-crumbs"><a href="' . e(self::articleListUrl()) . '">资讯</a> &gt; 标签：' . e((string)$meta['name']) . '</div>'
            . '<div class="mall-floor-hd"><h2>标签：' . e((string)$meta['name']) . '</h2></div>'
            . '<ul class="mall-news-list mall-news-list-lg">' . $lis . '</ul>' . $pager . '</div>'
            . '</div>' . self::newsSidebar() . '</div>'
            . self::footer();
        return ['html' => $html, 'title' => '标签：' . (string)$meta['name'] . ' · 资讯 · ' . site_label()];
    }

    /** 资讯分页器 */
    private static function newsPager(int $total, int $per, int $page, string $base): string
    {
        $tp = max(1, (int)ceil($total / $per));
        if ($tp <= 1) { return ''; }
        $p = '<div class="mall-pager">';
        for ($i = 1; $i <= $tp; $i++) {
            $on = $i === $page ? ' class="on"' : '';
            $u = $base . (strpos($base, '?') === false ? '?page=' : '&page=') . $i;
            $p .= '<a' . $on . ' href="' . e($u) . '">' . $i . '</a>';
        }
        return $p . '</div>';
    }

    /** 资讯评论区（列表 + 发表表单），落 ye_comments */
    private static function articleComments(int $cid): string
    {
        $all = Db::rows('SELECT * FROM ' . Db::t('comments') . " WHERE content_id=? AND status='approved' AND kind='normal' ORDER BY id ASC", [$cid]);
        $html = '<div class="mall-comments" id="comments"><h3>评论（' . count($all) . '）</h3>';
        if (!$all) {
            $html .= '<p class="mall-comments-empty">暂无评论，快来抢沙发～</p>';
        } else {
            $byParent = [];
            foreach ($all as $c) { $byParent[(int)($c['parent_id'] ?? 0)][] = $c; }
            $html .= self::commentTree($byParent, 0, 0);
        }
        $uid = self::uid();
        $html .= '<form class="mall-comment-form" method="post" action="' . e(self::articleUrl($cid)) . '#comments">'
            . Csrf::field()
            . '<input type="hidden" name="act" value="comment_post">'
            . '<input type="hidden" name="content_id" value="' . (int)$cid . '">'
            . '<input type="hidden" name="parent_id" id="cmt-parent" value="0">'
            . '<div class="mall-comment-frow">';
        if ($uid <= 0) {
            $html .= '<input class="mall-input" name="author_name" placeholder="昵称（必填）" maxlength="40">'
                . '<input class="mall-input" name="author_email" placeholder="邮箱（选填）" maxlength="190">';
        } else {
            $html .= '<div class="mall-comment-user">您已登录，将以账号身份发表</div>';
        }
        $html .= '</div>'
            . '<textarea class="mall-input mall-comment-area" name="body" rows="4" placeholder="说点什么…" required></textarea>'
            . '<div class="mall-comment-bot"><span class="mall-hp">请勿填写：<input type="text" name="company" tabindex="-1" autocomplete="off"></span>'
            . '<button type="submit" class="mall-btn-buy">发表评论</button></div>'
            . '</form></div>';
        return $html;
    }

    private static function commentTree(array $byParent, int $pid, int $depth): string
    {
        if (!isset($byParent[$pid])) { return ''; }
        $html = $depth === 0 ? '<ul class="mall-comment-list">' : '';
        foreach ($byParent[$pid] as $c) {
            $name = e((string)($c['author_name'] ?: '匿名'));
            $date = e(lang_date(strtotime((string)$c['created_at'])));
            $body = nl2br(e((string)$c['body']));
            $html .= '<li class="mall-comment" id="cmt-' . (int)$c['id'] . '">'
                . '<div class="mall-comment-meta"><b>' . $name . '</b> <span>' . $date . '</span>'
                . ' <a href="javascript:;" class="mall-comment-reply" data-id="' . (int)$c['id'] . '">回复</a></div>'
                . '<div class="mall-comment-body">' . $body . '</div>'
                . self::commentTree($byParent, (int)$c['id'], $depth + 1)
                . '</li>';
        }
        if ($depth === 0) { $html .= '</ul>'; }
        return $html;
    }

    private static function articleDetail(int $id): array
    {
        $a = Db::row('SELECT * FROM ' . Db::t('contents') . ' WHERE id=? AND type=\'post\' AND status=\'publish\' AND deleted_at IS NULL LIMIT 1', [$id]);
        if ($a === null) {
            return self::notFound();
        }
        $coverHtml = '';
        $cf = (string)($a['content_format'] ?? 'markdown');
        // 51puer 导来的资讯是 HTML（content_format='html'）→ 安全输出；博客原生 markdown 走原渲染
        $body = $cf === 'html' ? self::sanitizeHtml((string)($a['content'] ?? '')) : Markdown::render((string)($a['content'] ?? ''));
        // 分类 / 标签
        $terms = Db::rows('SELECT m.type, m.name, m.slug FROM ' . Db::t('relationships') . ' r JOIN ' . Db::t('metas') . ' m ON m.id=r.meta_id WHERE r.content_id=? AND m.type IN (\'category\',\'tag\')', [$id]);
        $cats = []; $tags = [];
        foreach ($terms as $t) { if ($t['type'] === 'category') { $cats[] = $t; } else { $tags[] = $t; } }
        $catHtml = '';
        if ($cats) {
            $catHtml = '<div class="mall-article-cats">分类：';
            foreach ($cats as $c) { $catHtml .= '<a href="' . e(self::articleCatUrl((string)$c['slug'])) . '">' . e((string)$c['name']) . '</a>'; }
            $catHtml .= '</div>';
        }
        $tagHtml = '';
        if ($tags) {
            $tagHtml = '<div class="mall-article-tags">标签：';
            foreach ($tags as $t) { $tagHtml .= '<a class="mall-tag-item" href="' . e(self::articleTagUrl((string)$t['slug'])) . '">' . e((string)$t['name']) . '</a>'; }
            $tagHtml .= '</div>';
        }
        $rel = Db::rows('SELECT id, title FROM ' . Db::t('contents')
            . " WHERE type='post' AND status='publish' AND deleted_at IS NULL AND id<>? ORDER BY COALESCE(published_at, created_at) DESC LIMIT 6", [$id]);
        $relHtml = '';
        if ($rel) {
            $relHtml = '<div class="mall-article-rel"><h3>相关资讯</h3><ul>';
            foreach ($rel as $r) {
                $relHtml .= '<li><a href="' . e(self::articleUrl((int)$r['id'])) . '">' . e((string)$r['title']) . '</a></li>';
            }
            $relHtml .= '</ul></div>';
        }
        $comments = self::articleComments($id);
        $replyJs = '<script>document.querySelectorAll(\'.mall-comment-reply\').forEach(function(a){a.onclick=function(){var id=a.getAttribute(\'data-id\');var f=document.querySelector(\'.mall-comment-form\');if(!f)return;f.querySelector(\'#cmt-parent\').value=id;f.querySelector(\'.mall-comment-area\').focus();f.scrollIntoView({behavior:\'smooth\',block:\'center\'});};});</script>';
        $html = self::css() . self::topbar() . self::header() . self::navBar(self::categories())
            . '<div class="mall-article-wrap"><div class="mall-article-main">'
            . '<div class="mall-article"><div class="mall-article-hd"><h1>' . e((string)$a['title']) . '</h1>'
            . '<div class="mall-article-meta">发布于 ' . e(lang_date(strtotime((string)($a['published_at'] ?: $a['created_at'])))) . '</div></div>'
            . $catHtml . $tagHtml
            . '<div class="mall-article-body">' . $body . '</div>'
            . $relHtml
            . $comments
            . '</div></div>'
            . self::newsSidebar()
            . '</div>' . $replyJs
            . self::footer();
        $GLOBALS['YC_META'] = ['description' => Str::excerpt(Str::cleanMd((string)($a['excerpt'] ?: $a['content'])), 150), 'keywords' => (string)($a['keywords'] ?? '')];
        return ['html' => $html, 'title' => (string)$a['title'] . ' · ' . site_label()];
    }

    /** 博客 contents 发布时间列（兼容 published_at 为空） */
    private static function publishAt(): string
    {
        return 'COALESCE(published_at, created_at)';
    }

    /** 从 HTML 正文取第一张图片的 src（用于资讯列表/首页缩略图） */
    private static function firstImg(string $html): string
    {
        if (preg_match('/<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            $u = trim($m[1]);
            if ($u !== '' && strncmp($u, 'file://', 7) !== 0) {
                return $u;
            }
        }
        return '';
    }

    /**
     * 51puer 导来的资讯是富文本 HTML，渲染前做最小安全清洗：
     * 去掉 script/style/iframe/object/embed、on* 事件属性、javascript: 协议，
     * 并清掉 Word 复制残留的 style/class/lang 属性（保留结构标签与 img 的 width/height/src/alt）。
     */
    private static function sanitizeHtml(string $h): string
    {
        $h = preg_replace('#<script\b.*?</script>#is', '', $h);
        $h = preg_replace('#<style\b.*?</style>#is', '', $h);
        $h = preg_replace('#<iframe\b.*?</iframe>#is', '', $h);
        $h = preg_replace('#<object\b.*?</object>#is', '', $h);
        $h = preg_replace('#<embed\b[^>]*/?>#i', '', $h);
        $h = preg_replace('#\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\')#i', '', $h);
        $h = preg_replace('#\s+(?:href|src)\s*=\s*("javascript:[^"]*"|\'javascript:[^\']*\')#i', ' src="#"', $h);
        $h = preg_replace('#</?o:p\s*/?>#i', '', $h);
        $h = preg_replace('#\s+(?:style|class|lang|face|size|vspace|hspace|clear|type)\s*=\s*("[^"]*"|\'[^\']*\')#i', '', $h);
        return $h;
    }

    /* ================= 页面：商品列表 ================= */

    // 聚合属性 facet：在给定 scope 内统计 attrs 的 key→value 频次，过滤噪声后按优先级排序
    // $denyKeys：类目轴属性（如 生熟，生/熟 已是独立类目 c1/c47），不进入 facet
    // $denyValues：类目名泄漏进属性值/标签的文本（如 形态=礼品茶、标签 普洱/茶具），剔除
    private static function computeFacets(string $fromWhere, array $args, array $denyKeys = [], array $denyValues = []): array
    {
        $rows = Db::rows('SELECT g.attrs' . $fromWhere, $args);
        $keys = []; $vals = [];
        foreach ($rows as $r) {
            $a = $r['attrs'] ? json_decode((string)$r['attrs'], true) : null;
            if (!is_array($a)) { continue; }
            foreach ($a as $k => $v) {
                if (in_array($k, $denyKeys, true)) { continue; }   // 类目轴属性不进入 facet
                if (!is_scalar($v) || $v === '' || $v === null) { continue; }
                $v = (string)$v;
                if (isset($denyValues[$v])) { continue; }           // 类目名泄漏的属性值剔除
                if (!isset($keys[$k])) { $keys[$k] = 0; $vals[$k] = []; }
                $keys[$k]++;
                $vals[$k][$v] = ($vals[$k][$v] ?? 0) + 1;
            }
        }
        $order = ['形态','生熟','年份','产区','树型','净含量','季节','材质','类型','容量','用途'];
        $rank = array_flip($order);
        $sorted = [];
        foreach ($keys as $k => $tot) {
            $vs = $vals[$k];
            arsort($vs);
            if (count($vs) < 2 || $tot < 3) { continue; } // 噪声过滤
            if ($k === '品牌') { continue; }               // 品牌已有独立筛选
            $sorted[$k] = ['key' => $k, 'total' => $tot, 'rank' => $rank[$k] ?? 99, 'values' => $vs];
        }
        uasort($sorted, function ($a, $b) { return $a['rank'] <=> $b['rank'] ?: $b['total'] <=> $a['total']; });
        return $sorted;
    }

    // 生成列表页 query string；opt 支持增删属性/品牌/价格/标签筛选，保留其余
    private static function mallListQs(array $cur, array $opt = []): string
    {
        $q = [];
        if (!empty($cur['q'])) { $q['q'] = $cur['q']; }
        $brand = $cur['brand'] ?? null; $min = $cur['min'] ?? null; $max = $cur['max'] ?? null; $tag = $cur['tag'] ?? null;
        if (!empty($opt['dropBrand'])) { $brand = null; }
        if (!empty($opt['dropPrice'])) { $min = null; $max = null; }
        if (!empty($opt['dropTag'])) { $tag = null; }
        if ($brand !== null) { $q['brand'] = $brand; }
        if ($min !== null) { $q['min'] = $min; }
        if ($max !== null) { $q['max'] = $max; }
        if ($tag !== null) { $q['tag'] = $tag; }
        $fa = $cur['fa'] ?? [];
        if (!empty($opt['dropFaKey']) && isset($fa[$opt['dropFaKey']])) { unset($fa[$opt['dropFaKey']]); }
        if (!empty($opt['dropFa'])) {
            [$k, $v] = $opt['dropFa'];
            if (isset($fa[$k])) {
                $fa[$k] = array_values(array_filter($fa[$k], function ($x) use ($v) { return $x !== $v; }));
                if ($fa[$k] === []) { unset($fa[$k]); }
            }
        }
        if (!empty($opt['addFa'])) {
            [$k, $v] = $opt['addFa'];
            $fa[$k] = array_values(array_unique(array_merge($fa[$k] ?? [], [$v])));
        }
        foreach ($fa as $k => $vs) { foreach ($vs as $v) { $q['fa'][] = $k . '::' . $v; } }
        return $q !== [] ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '';
    }

    private static function listPage(array $path, array $query): array
    {
        $cats = self::categories();
        // 父子菜单逻辑：类目名集合（用于剔除泄漏进属性/标签的类目概念）
        // 类目轴属性（生熟）已是独立类目（c1=熟茶 / c47=生茶），不可再作筛选维度；
        // 任何等于类目名的属性值/标签（如 形态=礼品茶、标签 普洱/生茶/茶具/礼品茶）一律隐藏。
        $catNameSet = [];
        foreach ($cats as $c) { $catNameSet[trim((string)$c['name'])] = true; }
        foreach (['普洱', '生茶', '熟茶', '普洱茶', '茶具', '礼品茶', '红茶', '白茶', '普洱茶熟茶', '普洱茶生茶'] as $a) { $catNameSet[$a] = true; }
        $denyKeys = ['生熟'];
        $denyValues = $catNameSet;
        $catSlug = trim((string)($path[1] ?? ''));
        $catId = (int)($query['category_id'] ?? 0); // 51puer 风格：/list.html?category_id=N
        $q = trim((string)($query['q'] ?? ''));
        if ($q !== '') { // 搜索词统计（UNIQUE keyword 前缀 32，直接入库自增）
            $kw = mb_substr($q, 0, 32);
            Db::run('INSERT INTO ' . self::t('search_keyword') . ' (keyword, hit_count, last_at) VALUES (?, 1, NOW())'
                . ' ON DUPLICATE KEY UPDATE hit_count=hit_count+1, last_at=NOW()', [$kw]);
        }
        $cat = null;
        if ($catSlug !== '') {
            $cat = Db::row('SELECT * FROM ' . self::t('goods_category') . ' WHERE slug=? AND is_show=1 AND is_delete_time=0 LIMIT 1', [$catSlug]);
            if ($cat === null) { return self::notFound(); }
        } elseif ($catId > 0) {
            $cat = Db::row('SELECT * FROM ' . self::t('goods_category') . ' WHERE id=? AND is_show=1 AND is_delete_time=0 LIMIT 1', [$catId]);
        }
        // M8 筛选：标签 / 品牌 / 价格
        $tagSlug = trim((string)($query['tag'] ?? ''));
        $tag = null;
        if ($tagSlug !== '') {
            $tag = Db::row('SELECT * FROM ' . self::t('tag') . ' WHERE slug=? AND is_show=1 AND is_delete_time=0 LIMIT 1', [$tagSlug]);
            if ($tag === null) { return self::notFound(); }
        }
        $brandId = (int)($query['brand'] ?? 0);
        $brand = $brandId > 0 ? Db::row('SELECT id, name FROM ' . self::t('brand') . ' WHERE id=? AND is_show=1 AND is_delete_time=0 LIMIT 1', [$brandId]) : null;
        $minP = (float)($query['min'] ?? 0);
        $maxP = (float)($query['max'] ?? 0);
        // ★ 年份筛选（虚拟属性：年份只存在于商品标题，如「2007年/357克/饼」）
        //   51puer 的年份档是「单年 + 2001-2005年 + 2000年及以前」，所以这里要支持区间。
        $year = trim((string)($query['year'] ?? ''));
        // ★ 工艺(生熟) / 外观(形态)：来自 attrs JSON（1402 条全有），按 JSON 字段筛选
        $craft = trim((string)($query['craft'] ?? ''));
        $shape = trim((string)($query['shape'] ?? ''));
        $page = max(1, (int)($query['page'] ?? 1));
        // 排序（仿 51puer 顶栏：综合/销量/价格）
        $sort = trim((string)($query['sort'] ?? 'default'));
        if (!in_array($sort, ['default', 'sales', 'price_asc', 'price_desc'], true)) { $sort = 'default'; }
        $orderBy = 'g.is_recommend DESC, g.id DESC';
        if ($sort === 'sales') { $orderBy = 'g.sales_count DESC, g.id DESC'; }
        elseif ($sort === 'price_asc') { $orderBy = 'g.min_price ASC, g.id DESC'; }
        elseif ($sort === 'price_desc') { $orderBy = 'g.min_price DESC, g.id DESC'; }
        // 属性逐级筛选：fa[]=key::value（同键 OR，跨键 AND）
        $faRaw = $query['fa'] ?? [];
        if (!is_array($faRaw)) { $faRaw = ($faRaw !== null && $faRaw !== '') ? [$faRaw] : []; }
        $activeFa = []; // key => [values]
        foreach ($faRaw as $kv) {
            if (!is_string($kv) || strpos($kv, '::') === false) { continue; }
            [$fk, $fv] = explode('::', $kv, 2);
            $fk = trim($fk); $fv = trim($fv);
            if ($fk !== '' && $fv !== '') { $activeFa[$fk][] = $fv; }
        }
        // ---- 基础 scope（不含属性筛选）：用于聚合 facet + 主列表 ----
        $scopeCond = ['g.status=1', 'g.is_delete_time=0'];
        $scopeArgs = [];
        // ★ 父分类聚合：选中父分类时包含其子分类商品
        //   （普洱茶(97) 下有 生茶(47)/熟茶(1)，选「普洱茶」应能看到全部 844 款）
        if ($cat !== null) {
            $scopeCond[] = 'g.category_id IN (SELECT id FROM ' . self::t('goods_category')
                . ' WHERE id=? OR pid=?)';
            $scopeArgs[] = (int)$cat['id'];
            $scopeArgs[] = (int)$cat['id'];
        }
        if ($q !== '') { $scopeCond[] = '(g.title LIKE ? OR g.subtitle LIKE ?)'; $scopeArgs[] = '%' . $q . '%'; $scopeArgs[] = '%' . $q . '%'; }
        if ($brand !== null) { $scopeCond[] = 'g.brand_id=?'; $scopeArgs[] = (int)$brand['id']; }
        if ($minP > 0) { $scopeCond[] = 'g.min_price>=?'; $scopeArgs[] = $minP; }
        if ($maxP > 0) { $scopeCond[] = 'g.min_price<=?'; $scopeArgs[] = $maxP; }
        // 年份 → title LIKE（虚拟属性）
        if ($year !== '') {
            if ($year === '2000年及以前') {
                // 2000 年及以前：用 OR 覆盖 1990~2000
                $ors = [];
                for ($yy = 2000; $yy >= 1990; $yy--) { $ors[] = 'g.title LIKE ?'; $scopeArgs[] = '%' . $yy . '年%'; }
                $scopeCond[] = '(' . implode(' OR ', $ors) . ')';
            } elseif (strpos($year, '-') !== false) {
                [$ya, $yb] = array_map('intval', explode('-', str_replace('年', '', $year)));
                $ors = [];
                for ($yy = $ya; $yy <= $yb; $yy++) { $ors[] = 'g.title LIKE ?'; $scopeArgs[] = '%' . $yy . '年%'; }
                $scopeCond[] = '(' . implode(' OR ', $ors) . ')';
            } else {
                $scopeCond[] = 'g.title LIKE ?';
                $scopeArgs[] = '%' . (int)$year . '年%';
            }

            // 工艺 / 外观 → attrs JSON。
            //   ★ 不能用 JSON_EXTRACT：MySQL 5.7 的 JSON path **不支持中文键**，
            //     JSON_EXTRACT(attrs,'$.生熟') 会直接报 Invalid JSON path expression。
            //   attrs 序列化格式固定为 {"生熟": "生茶"}（冒号后有一个空格），用 LIKE 精确匹配。
            foreach ([['craft', $craft, '生熟'], ['shape', $shape, '形态']] as $one) {
                if ($one[1] === '') { continue; }
                $scopeCond[] = 'g.attrs LIKE ?';
                $scopeArgs[] = '%"' . $one[2] . '": "' . $one[1] . '"%';
            }
        }
        $scopeFrom = ' FROM ' . self::t('goods') . ' g';
        if ($tag !== null) {
            $scopeFrom .= ' JOIN ' . self::t('goods_tag') . ' gt ON gt.goods_id=g.id';
            $scopeCond[] = 'gt.tag_id=?';
            $scopeArgs[] = (int)$tag['id'];
        }
        $scopeWhere = ' WHERE ' . implode(' AND ', $scopeCond);

        // ---- 属性筛选（逐级选择）：同键 OR，跨键 AND ----
        $attrCond = [];
        $attrArgs = [];
        foreach ($activeFa as $fk => $vals) {
            if (!preg_match('/^[\x{4e00}-\x{9fa5}A-Za-z0-9_]+$/u', $fk)) { continue; } // 防 JSON path 注入
            $ors = [];
            foreach ($vals as $fv) {
                $ors[] = 'JSON_CONTAINS(g.attrs, JSON_QUOTE(?), \'$."' . $fk . '"\')';
                $attrArgs[] = $fv;
            }
            if ($ors !== []) { $attrCond[] = '(' . implode(' OR ', $ors) . ')'; }
        }

        // ---- 聚合 facet（在 scope 内，不含属性筛选，便于叠加选择）----
        $facets = self::computeFacets($scopeFrom . $scopeWhere, $scopeArgs, $denyKeys, $denyValues);

        // ---- 主列表 / 总数：scope + 属性筛选 ----
        $cond = array_merge($scopeCond, $attrCond);
        $args = array_merge($scopeArgs, $attrArgs);
        $from = $scopeFrom;
        $where = ' WHERE ' . implode(' AND ', $cond);
        $goods = Db::rows('SELECT g.id, g.title, g.subtitle, g.cover, g.min_price, g.market_price, g.total_stock, g.sales_count, g.virtual_sales' . $from . $where . ' ORDER BY ' . $orderBy . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $args);
        $total = (int)Db::one('SELECT COUNT(*) ' . $from . $where, $args);
        $totalPages = max(1, (int)ceil($total / self::PER_PAGE));

        // 分页 base：路径 + 当前生效筛选（tag/brand/min/max/q/fa 全部保持）
        $fq = [];
        if ($tag !== null) { $fq['tag'] = $tag['slug']; }
        if ($brand !== null) { $fq['brand'] = (int)$brand['id']; }
        if ($minP > 0) { $fq['min'] = $minP; }
        if ($maxP > 0) { $fq['max'] = $maxP; }
        if ($q !== '') { $fq['q'] = $q; }
        foreach ($activeFa as $fk => $vals) { foreach ($vals as $fv) { $fq['fa'][] = $fk . '::' . $fv; } }
        $qs = $fq !== [] ? '?' . http_build_query($fq, '', '&', PHP_QUERY_RFC3986) : '';
        $base = '/list.html' . ($cat !== null ? rawurlencode((string)$cat['slug']) . '/' : '') . $qs;
        $parts = [];
        if ($cat !== null) { $parts[] = (string)$cat['name']; }
        if ($brand !== null) { $parts[] = (string)$brand['name']; }
        if ($tag !== null) { $parts[] = '标签·' . (string)$tag['name']; }
        if ($minP > 0 || $maxP > 0) { $parts[] = '¥' . ($minP > 0 ? $minP : '0') . '~' . ($maxP > 0 ? $maxP : '∞'); }
        foreach ($activeFa as $fk => $vals) { foreach ($vals as $fv) { $parts[] = $fk . '：' . $fv; } }
        if ($q !== '') { $parts[] = '搜索：' . $q; }
        $headName = $parts !== [] ? implode(' / ', $parts) : '全部商品';
        $title = ($parts !== [] ? implode(' ', $parts) . ' - ' : '全部商品 - ') . '商城';

        // 当前筛选态（供 facet / chips 生成链接复用）
        $cur = [
            'cat'   => $cat !== null ? $cat['slug'] : null,
            'q'     => $q !== '' ? $q : null,
            'brand' => $brand !== null ? (int)$brand['id'] : null,
            'min'   => $minP > 0 ? $minP : null,
            'max'   => $maxP > 0 ? $maxP : null,
            'tag'   => $tag !== null ? $tag['slug'] : null,
            'fa'    => $activeFa,
        ];
        $basePath = '/list.html' . ($cat !== null ? rawurlencode((string)$cat['slug']) . '/' : '');

        $html = self::css() . self::topbar() . self::header() . self::navBar($cats, (int)($cat['id'] ?? 0));
        $html .= '<div class="mall-crumb"><div class="mall-w"><a href="/mall">商城</a>';
        if ($cat !== null) { $html .= ' <i>&gt;</i> <span>' . e((string)$cat['name']) . '</span>'; }
        else { $html .= ' <i>&gt;</i> <span>' . e($headName) . '</span>'; }
        $html .= '</div></div>';

        // 筛选条（仿 51puer：顶栏排序 + 维度行 label+值链接；类目轴属性/类目名已剔除，保证父子菜单逻辑）
        // ★ 价格档位对齐 51puer（实测其面板为 6 档，命名用「~」不带空格）
        $pricePresets = [
            ['100元以内', 0, 100], ['101~300元', 100, 300], ['301~500元', 300, 500],
            ['501~1000元', 500, 1000], ['1001元~2000元', 1000, 2000], ['2000元以上', 2000, 0],
        ];
        $priceLinks = '';
        foreach ($pricePresets as $p) {
            $pq = $fq; $pq['min'] = $p[1]; $pq['max'] = $p[2];
            $qp = http_build_query($pq, '', '&', PHP_QUERY_RFC3986);
            $on = ($minP === (float)$p[1] && $maxP === (float)$p[2]) ? ' class="on"' : '';
            $priceLinks .= '<li><a' . $on . ' href="' . e($basePath . '?' . $qp) . '">' . e($p[0]) . '</a></li>';
        }
        // ★ 年份档（对齐 51puer：单年 + 2001-2005年 + 2000年及以前）
        //   年份是虚拟属性（只存在于 title，如「2007年/357克/饼」），链接带 year= 参数
        $yearPresets = ['2021年','2020年','2019年','2018年','2017年','2016年','2015年','2014年','2013年',
                        '2012年','2011年','2010年','2009年','2008年','2007年','2006年','2001-2005年','2000年及以前'];
        $yearLinks = '';
        foreach ($yearPresets as $yp) {
            $yq = $fq; $yq['year'] = $yp;
            $qp = http_build_query($yq, '', '&', PHP_QUERY_RFC3986);
            $on = (isset($year) && $year === $yp) ? ' class="on"' : '';
            $yearLinks .= '<li><a' . $on . ' href="' . e($basePath . '?' . $qp) . '">' . e($yp) . '</a></li>';
        }

        $sortTabs = ['default' => '综合', 'sales' => '销量', 'price_asc' => '价格 ↑', 'price_desc' => '价格 ↓'];
        $sortLinks = '';
        foreach ($sortTabs as $sk => $sl) {
            $sq = $fq; $sq['sort'] = $sk;
            $on = ($sort === $sk) ? ' class="on"' : '';
            $sortLinks .= '<a' . $on . ' href="' . e($basePath . '?' . http_build_query($sq, '', '&', PHP_QUERY_RFC3986)) . '">' . e($sl) . '</a>';
        }
        $allTags = Db::rows('SELECT id,name,slug,goods_count FROM ' . self::t('tag') . ' WHERE is_show=1 AND is_delete_time=0 AND goods_count>0 ORDER BY sort ASC, goods_count DESC LIMIT 30');
        $allTags = array_values(array_filter($allTags, function ($t) use ($catNameSet) { return !isset($catNameSet[trim((string)$t['name'])]); })); // 剔除类目名标签
        $tagCloud = '';
        foreach ($allTags as $t) {
            $on = ($tag !== null && (int)$tag['id'] === (int)$t['id']) ? ' class="on"' : '';
            $tagCloud .= '<li><a' . $on . ' href="/list.html?tag=' . rawurlencode((string)$t['slug']) . '">' . e((string)$t['name']) . '</a></li>';
        }
        // 属性 facet 行（逐级选择，无 JS）
        // ★ 51puer 结构对齐：年份/生熟/形态 这三个键接上可筛选参数（其余保留展示）
        $facetRows = '';
        // 可筛选的 facet 键 → URL 参数名
        $clickable = ['年份' => 'year', '生熟' => 'craft', '形态' => 'shape'];
        // 已提到常驻位的维度不再进「更多筛选」，避免重复出现
        $promoted = ['年份' => 1, '生熟' => 1, '形态' => 1];
        foreach ($facets as $fk => $f) {
            if (isset($promoted[$fk])) { continue; }   // 已在常驻行
            $cells = '';
            $useParam = $clickable[$fk] ?? '';
            foreach ($f['values'] as $fv => $cnt) {
                if ($useParam !== '') {
                    // 可筛选：走 URL 参数（年份=title LIKE，生熟/形态=attrs）
                    $on = (isset($cur[$useParam]) && (string)$cur[$useParam] === (string)$fv);
                    $q2 = $cur; if ($on) { unset($q2[$useParam]); } else { $q2[$useParam] = $fv; }
                    $href = $basePath . '?' . http_build_query($q2, '', '&', PHP_QUERY_RFC3986);
                } else {
                    $on = isset($activeFa[$fk]) && in_array($fv, $activeFa[$fk], true);
                    $href = $basePath . self::mallListQs($cur, $on ? ['dropFa' => [$fk, $fv]] : ['addFa' => [$fk, $fv]]);
                }
                $cells .= '<li><a' . ($on ? ' class="on"' : '') . ' href="' . e($href) . '">' . e($fv)
                    . ($cnt > 0 ? ' <em>' . (int)$cnt . '</em>' : '') . '</a></li>';
            }
            $facetRows .= '<div class="mall-filter-row"><span class="mall-filter-k">' . e($f['key'])
                . '</span><ul class="mall-filter-v mall-filter-attr">' . $cells . '</ul></div>';
        }
        // 已选 chips（逐项可移除，保留其余筛选）
        $chipParts = '';
        if ($tag !== null) { $chipParts .= '<a class="mall-chip" href="' . e($basePath . self::mallListQs($cur, ['dropTag' => 1])) . '">标签·' . e((string)$tag['name']) . ' ✕</a>'; }
        if ($brand !== null) { $chipParts .= '<a class="mall-chip" href="' . e($basePath . self::mallListQs($cur, ['dropBrand' => 1])) . '">品牌·' . e((string)$brand['name']) . ' ✕</a>'; }
        if ($minP > 0 || $maxP > 0) { $chipParts .= '<a class="mall-chip" href="' . e($basePath . self::mallListQs($cur, ['dropPrice' => 1])) . '">¥' . ($minP > 0 ? $minP : '0') . '~' . ($maxP > 0 ? $maxP : '∞') . ' ✕</a>'; }
        foreach ($activeFa as $fk => $vals) {
            foreach ($vals as $fv) {
                $chipParts .= '<a class="mall-chip" href="' . e($basePath . self::mallListQs($cur, ['dropFa' => [$fk, $fv]])) . '">' . e($fk) . '：' . e($fv) . ' ✕</a>';
            }
        }
        $chips = $chipParts !== '' ? '<div class="mall-filter-chips"><span class="dim">已选：</span>' . $chipParts . '</div>' : '';
        // ★ 筛选区重构（D3）：
        //   原实现把 12~14 行 facet 全铺开，占掉首屏 ~380px，进来几乎看不到商品；
        //   且每行交替底色形成条纹噪声。
        //   现在：排序 + 价格 + 标签常驻（高频），其余收进无 JS 的 <details> 折叠。
        // 生熟 / 外观 两个高频维度提到常驻位（原来是 facet，藏在「更多筛选」里）
        $mkRow = static function (string $label, string $param, array $presets, string $cur, string $base, array $q) {
            $lis = '';
            foreach ($presets as $val) {
                $qq = $q;
                $on = ((string)$cur === (string)$val);
                if ($on) { unset($qq[$param]); } else { $qq[$param] = $val; }
                $href = $base . '?' . http_build_query($qq, '', '&', PHP_QUERY_RFC3986);
                $lis .= '<li><a' . ($on ? ' class="on"' : '') . ' href="' . e($href) . '">' . e($val) . '</a></li>';
            }
            return '<div class="mall-filter-row"><span class="mall-filter-k">' . e($label)
                . '</span><ul class="mall-filter-v">' . $lis . '</ul></div>';
        };
        $craftRow = $mkRow('生熟', 'craft', ['生茶', '熟茶', '生熟混合装'], (string)$craft, $basePath, $fq);
        $shapeRow = $mkRow('外观', 'shape', ['饼茶', '沱茶', '砖茶', '散茶', '小沱茶', '礼品茶'], (string)$shape, $basePath, $fq);

        // 标签降级为「一行提示词」：不占用筛选导航，只做发现入口
        $tagHint = '';
        if ($tagCloud !== '') {
            $tagHint = '<div class="mall-filter-row mall-filter-taghint">'
                . '<span class="mall-filter-k">热门</span>'
                . '<ul class="mall-filter-v mall-filter-tags-inline">' . $tagCloud . '</ul></div>';
        }

        $facetRows = $facetRows !== ''
            ? '<details class="mall-filter-more"><summary>更多筛选'
              . '<span class="mall-filter-cnt">' . count($facets) . ' 项</span></summary>'
              . '<div class="mall-filter-more-in">' . $facetRows . '</div></details>'
            : '';
        // ★ 筛选区顺序（对齐 51puer）：生熟 → 外观 → 年份 → 价格
        //   选了「普洱茶」后，生熟是第一决策维度（生茶/熟茶口味差异最大），必须排在最前。
        //   标签不再占一整行导航 → 降级为「一行提示词」（tagHint），不抢筛选的注意力。
        $html .= '<div class="mall-filter"><div class="mall-w">'
            . '<div class="mall-filter-top">' . $sortLinks . '</div>'
            . $craftRow      // 生熟（点击筛选）
            . $shapeRow      // 外观（点击筛选）
            . '<div class="mall-filter-row"><span class="mall-filter-k">年份</span><ul class="mall-filter-v mall-filter-years">' . $yearLinks . '</ul></div>'
            . '<div class="mall-filter-row"><span class="mall-filter-k">价格</span><ul class="mall-filter-v">' . $priceLinks . '</ul></div>'
            . $tagHint       // 一行 tag 提示词
            . $facetRows     // 其余 facet（产区/树型/净含量…）收进「更多筛选」
            . $chips
            . '</div></div>';

        $html .= '<div class="mall-list-main"><div class="mall-w">';
        // 左侧分类树（51puer 列表页版式）
        $side = '<div class="mall-side"><div class="mall-side-hd">商品分类</div><ul class="mall-side-cats">';
        foreach ($cats as $c) {
            $on = ($cat !== null && (int)$cat['id'] === (int)$c['id']) ? ' class="on"' : '';
            $side .= '<li><a' . $on . ' href="/list.html' . rawurlencode((string)$c['slug']) . '/">' . e((string)$c['name']) . '</a></li>';
        }
        $side .= '<li><a' . ($cat === null ? ' class="on"' : '') . ' href="/list.html">全部商品</a></li></ul></div>';
        $html .= $side;
        // 右侧栅格 + 分页
        $html .= '<div class="mall-list-body"><div class="mall-goods-grid mall-grid-4">';
        if ($goods) {
            foreach ($goods as $g) { $html .= self::goodsCard($g); }
        } else {
            // ★ 搜索无结果时的 AI 兜底：不只是「没找到」，而是给出下一步
            if ($q !== '') {
                $html .= '<div class="mall-empty">'
                    . '<p>没有找到与「<b>' . e($q) . '</b>」相关的商品。</p>'
                    . '<p class="dim">可以试试：换个说法（如「生茶」→「普洱生茶」）、减少条件，或者让 AI 帮你选。</p>'
                    . '<p style="margin-top:12px">'
                    . '<a class="mall-btn-buy mg-ask-search" href="javascript:;" '
                    . 'data-q="' . e($q) . '" style="display:inline-block;padding:9px 18px">让「帮你选茶」帮我找</a>'
                    . ' <a href="/list.html" style="margin-left:14px">浏览全部商品</a></p>'
                    . '</div>';
            } else {
                $html .= self::emptyGrid('该分类暂无商品');
            }
        }
        $html .= '</div>';
        if ($totalPages > 1) {
            $pgBase = $base . (str_contains($base, '?') ? '&' : '?') . 'page=';
            $html .= '<div class="mall-pager">';
            if ($page > 1) { $html .= '<a href="' . e($pgBase . ($page - 1)) . '">&lt; 上一页</a>'; }
            $html .= '<span class="cur">' . $page . ' / ' . $totalPages . '</span>';
            if ($page < $totalPages) { $html .= '<a href="' . e($pgBase . ($page + 1)) . '">下一页 &gt;</a>'; }
            $html .= '</div>';
        }
        $html .= '</div></div>';
        $html .= self::footer();
        return ['html' => $html, 'title' => $title];
    }

    /* ================= 页面：品牌聚合 ================= */

    private static function brandPage(array $path): array
    {
        $slug = trim((string)($path[1] ?? ''));
        // 无 slug → 品牌总览页（与 tagPage 的空 slug 行为一致，修裸 /brand 404）
        if ($slug === '') { return self::brandsIndex(); }
        $b = Db::row('SELECT * FROM ' . self::t('brand') . ' WHERE slug=? AND is_show=1 AND is_delete_time=0 LIMIT 1', [$slug]);
        if ($b === null) { return self::notFound(); }
        $cats = self::categories();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $total = (int)Db::one('SELECT COUNT(*) FROM ' . self::t('goods') . ' WHERE brand_id=? AND status=1 AND is_delete_time=0', [(int)$b['id']]);
        $goods = Db::rows('SELECT id,title,subtitle,cover,min_price,market_price,total_stock,sales_count,virtual_sales FROM ' . self::t('goods')
            . ' WHERE brand_id=? AND status=1 AND is_delete_time=0 ORDER BY is_recommend DESC, id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), [(int)$b['id']]);
        $tp = max(1, (int)ceil($total / self::PER_PAGE));
        $html = self::css() . self::topbar() . self::header() . self::navBar($cats);
        $html .= '<div class="mall-crumb"><div class="mall-w"><a href="/mall">商城</a> <i>&gt;</i> <span>品牌：' . e((string)$b['name']) . '</span></div></div>';
        $html .= '<div class="mall-brand-hd"><div class="mall-w">'
            . ((string)$b['logo'] !== '' ? '<img src="' . e((string)$b['logo']) . '" alt="' . e((string)$b['name']) . '">' : '')
            . '<div><h1>' . e((string)$b['name']) . '</h1><p class="dim">共 ' . $total . ' 件商品</p></div></div></div>';
        $html .= '<div class="mall-list-main"><div class="mall-w"><div class="mall-list-body"><div class="mall-goods-grid mall-grid-4">';
        if ($goods) { foreach ($goods as $g) { $html .= self::goodsCard($g); } }
        else { $html .= self::emptyGrid('该品牌暂无在售商品'); }
        $html .= '</div>';
        if ($tp > 1) {
            $pb = '/brand/' . rawurlencode((string)$b['slug']) . '/?page=';
            $html .= '<div class="mall-pager">';
            if ($page > 1) { $html .= '<a href="' . e($pb . ($page - 1)) . '">&lt; 上一页</a>'; }
            $html .= '<span class="cur">' . $page . ' / ' . $tp . '</span>';
            if ($page < $tp) { $html .= '<a href="' . e($pb . ($page + 1)) . '">下一页 &gt;</a>'; }
            $html .= '</div>';
        }
        $html .= '</div></div></div>';
        $html .= self::footer();
        return ['html' => $html, 'title' => (string)$b['name'] . ' - 商城'];
    }

    /* ================= 页面：标签 ================= */

    private static function tagPage(array $path): array
    {
        $slug = trim((string)($path[1] ?? ''));
        if ($slug === '') {
            return self::tagsIndex();
        }
        $t = Db::row('SELECT * FROM ' . self::t('tag') . ' WHERE slug=? AND is_show=1 AND is_delete_time=0 LIMIT 1', [$slug]);
        if ($t === null) { return self::notFound(); }
        $cats = self::categories();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $total = (int)Db::one('SELECT COUNT(*) FROM ' . self::t('goods') . ' g JOIN ' . self::t('goods_tag') . ' gt ON gt.goods_id=g.id WHERE gt.tag_id=? AND g.status=1 AND g.is_delete_time=0', [(int)$t['id']]);
        $goods = Db::rows('SELECT g.id,g.title,g.subtitle,g.cover,g.min_price,g.market_price,g.total_stock,g.sales_count,g.virtual_sales FROM ' . self::t('goods') . ' g JOIN ' . self::t('goods_tag') . ' gt ON gt.goods_id=g.id'
            . ' WHERE gt.tag_id=? AND g.status=1 AND g.is_delete_time=0 ORDER BY g.is_recommend DESC, g.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), [(int)$t['id']]);
        $tp = max(1, (int)ceil($total / self::PER_PAGE));
        $html = self::css() . self::topbar() . self::header() . self::navBar($cats);
        $html .= '<div class="mall-crumb"><div class="mall-w"><a href="/mall">商城</a> <i>&gt;</i> <span>标签：' . e((string)$t['name']) . '</span></div></div>';
        $html .= '<div class="mall-tag-hd"><div class="mall-w"><h1>标签 · ' . e((string)$t['name']) . '</h1><p class="dim">共 ' . $total . ' 件相关商品</p></div></div>';
        $html .= '<div class="mall-list-main"><div class="mall-w"><div class="mall-list-body"><div class="mall-goods-grid mall-grid-4">';
        if ($goods) { foreach ($goods as $g) { $html .= self::goodsCard($g); } }
        else { $html .= self::emptyGrid('该标签暂无在售商品'); }
        $html .= '</div>';
        if ($tp > 1) {
            $pb = '/tag/' . rawurlencode((string)$t['slug']) . '/?page=';
            $html .= '<div class="mall-pager">';
            if ($page > 1) { $html .= '<a href="' . e($pb . ($page - 1)) . '">&lt; 上一页</a>'; }
            $html .= '<span class="cur">' . $page . ' / ' . $tp . '</span>';
            if ($page < $tp) { $html .= '<a href="' . e($pb . ($page + 1)) . '">下一页 &gt;</a>'; }
            $html .= '</div>';
        }
        $html .= '</div></div></div>';
        $html .= self::footer();
        return ['html' => $html, 'title' => '标签·' . (string)$t['name'] . ' - 商城'];
    }

    /* ================= 页面：标签总览（/mall/tag/） ================= */

    /** 品牌总览（裸 /brand 或 /mall/brand 无 slug 时）：列出全部品牌 */
    private static function brandsIndex(): array
    {
        $cats = self::categories();
        $brands = Db::rows('SELECT b.id, b.name, b.slug, b.logo,'
            . ' (SELECT COUNT(*) FROM ' . self::t('goods') . ' g'
            . '   WHERE g.brand_id=b.id AND g.status=1 AND g.is_delete_time=0) AS goods_count'
            . ' FROM ' . self::t('brand') . ' b'
            . ' WHERE b.is_show=1 AND b.is_delete_time=0 ORDER BY goods_count DESC, b.sort ASC, b.id ASC');
        $html = self::css() . self::topbar() . self::header() . self::navBar($cats);
        $html .= '<div class="mall-crumb"><div class="mall-w"><a href="/mall">商城</a> <i>&gt;</i> <span>品牌</span></div></div>';
        $html .= '<div class="mall-tag-hd"><div class="mall-w"><h1>全部品牌</h1><p class="dim">共 '
            . count($brands) . ' 个品牌，点击查看该品牌全部商品</p></div></div>';
        $html .= '<div class="mall-list-main"><div class="mall-w"><div class="mall-brand-grid">';
        foreach ($brands as $b) {
            $html .= '<a class="mall-brand-item" href="' . e(self::brandUrl((string)$b['slug'])) . '">'
                . ((string)$b['logo'] !== ''
                    ? '<img src="' . e((string)$b['logo']) . '" alt="' . e((string)$b['name']) . '">'
                    : '<span class="ph">' . e(mb_substr((string)$b['name'], 0, 1)) . '</span>')
                . '<b>' . e((string)$b['name']) . '</b>'
                . '<em>' . (int)$b['goods_count'] . ' 件商品</em></a>';
        }
        if ($brands === []) { $html .= self::emptyGrid('暂无品牌'); }
        $html .= '</div></div></div>';
        $html .= self::footer();
        return ['html' => $html, 'title' => '普洱茶品牌大全'];
    }
    private static function tagsIndex(): array
    {
        $cats = self::categories();
        $tags = Db::rows('SELECT name, slug, goods_count FROM ' . self::t('tag')
            . ' WHERE is_show=1 AND is_delete_time=0 AND goods_count>0 ORDER BY goods_count DESC, sort ASC');
        $counts = array_column($tags, 'goods_count');
        $max = $counts ? (int)max($counts) : 1;
        $min = $counts ? (int)min($counts) : 1;
        $span = max(1, $max - $min);
        $html = self::css() . self::topbar() . self::header() . self::navBar($cats);
        $html .= '<div class="mall-crumb"><div class="mall-w"><a href="/mall">商城</a> <i>&gt;</i> <span>商品标签</span></div></div>';
        $html .= '<div class="mall-tag-hd"><div class="mall-w"><h1>全部商品标签</h1><p class="dim">共 ' . count($tags) . ' 个标签，点击查看对应商品</p></div></div>';
        $html .= '<div class="mall-list-main"><div class="mall-w"><div class="mall-tag-index">';
        foreach ($tags as $t) {
            $gc = (int)$t['goods_count'];
            $fs = 14 + (int)round(($gc - $min) / $span * 16); // 14~30px
            $html .= '<a class="mall-tag-item" style="font-size:' . $fs . 'px" href="/tag/'
                . rawurlencode((string)$t['slug']) . '/">' . e((string)$t['name'])
                . ' <span class="dim">(' . $gc . ')</span></a>';
        }
        $html .= '</div></div></div>';
        $html .= self::footer();
        return ['html' => $html, 'title' => '普洱茶标签大全'];
    }

    /* ================= 页面：商品详情 ================= */

    /**
     * 页面级 AI 导购上下文：由具体页面设置、footer() 透传给浮窗。
     * 为什么不改 footer() 签名：它有 40 个调用点，逐个改风险大。
     */
    private static array $pageCtx = [];

    private static function goodsPage(array $path): array
    {
        $raw = (string)($path[1] ?? '');
        $id = (int)$raw;
        if ($id <= 0 || !str_ends_with(strtolower($raw), '.html')) { return self::notFound(); }
        $g = Db::row('SELECT * FROM ' . self::t('goods') . ' WHERE id=? AND status=1 AND is_delete_time=0 LIMIT 1', [$id]);
        // ★ MallAiGuide 上下文（商品页）：让浮窗知道「用户在看哪款茶」
        //   实测原状：data-cfg 的 hint/ctx 全空 → AI 只能答通用问题，无法回答「这款适合送人吗」
        if (\Plugins::isOn('ai')) {
            $__price = number_format((float)$g['min_price'], 2, '.', '');
            $__cat = '';
            foreach (self::categories() as $__c) { if ((int)$__c['id'] === (int)$g['category_id']) { $__cat = (string)$__c['name']; break; } }
            self::$pageCtx = [
                'hint'  => '用户正在查看商品【' . (string)$g['title'] . '】'
            . ($__cat !== '' ? '（分类：' . $__cat . '）' : '')
            . '，售价 ¥' . $__price . '。回答时优先围绕这款商品，必要时再推荐同类替代。',
                'quick' => ['这款适合送人吗', '和同类比有什么区别', '新手能喝吗'],
                'ctx'   => ['goods_id' => (int)$g['id']],
            ];
        }
        if ($g === null) { return self::notFound(); }
        // 浏览足迹埋点（M7）：登录用户每次查看详情记录/刷新时间
        if (($uid = self::uid()) > 0) {
            Db::run('INSERT INTO ' . self::t('footprint') . ' (uid, goods_id) VALUES (?, ?)'
                . ' ON DUPLICATE KEY UPDATE viewed_at = NOW()', [$uid, $id]);
        }
        // 收藏态（M7）：登录用户是否已收藏本商品
        $faved = $uid > 0 && (bool)Db::one('SELECT 1 FROM ' . self::t('favorite') . ' WHERE uid=? AND goods_id=? LIMIT 1', [$uid, $id]);
        $cats = self::categories();
        $skus = Db::rows('SELECT * FROM ' . self::t('goods_sku') . ' WHERE goods_id=? AND is_delete_time=0 ORDER BY is_default DESC, price ASC', [$id]);
        $cat = null;
        foreach ($cats as $c) { if ((int)$c['id'] === (int)$g['category_id']) { $cat = $c; break; } }
        $brand = $g['brand_id'] ? Db::row('SELECT name, logo FROM ' . self::t('brand') . ' WHERE id=? LIMIT 1', [(int)$g['brand_id']]) : null;
        // 详情正文：51puer 映射来的为真实富文本 HTML（图片已本地化），直接净化输出；
        // 纯文本/旧 markdown 详情仍走 Parsedown（保留原格式）。
        $detail = trim((string)$g['detail']);
        if ($detail !== '') {
            if (strpos($detail, '<') !== false) {
                $detailHtml = '<div class="mall-detail-body">' . mallSanitizeHtml($detail) . '</div>';
            } else {
                if (!class_exists('Parsedown', false)) { require_once __DIR__ . '/Parsedown.php'; }
                static $__pd = null;
                if ($__pd === null) {
                    $__pd = new Parsedown();
                    $__pd->setBreaksEnabled(true);   // 单行换行 -> <br>，兼容旧纯文本详情
                    $__pd->setSafeMode(true);        // 转义残留原始 HTML，防 XSS
                    $__pd->setUrlsLinked(true);
                }
                $detailHtml = '<div class="mall-detail-body">' . $__pd->text($detail) . '</div>';
            }
        } else {
            $detailHtml = '<div class="mall-detail-body mall-detail-ph">商品详情完善中</div>';
        }

        $title = (string)(($g['seo_title'] ?: $g['title']));
        $html = self::css() . self::topbar() . self::header() . self::navBar($cats, (int)($cat['id'] ?? 0));
        $html .= '<div class="mall-crumb"><div class="mall-w"><a href="/mall">商城</a>';
        if ($cat !== null) { $html .= ' <i>&gt;</i> <a href="/list.html' . rawurlencode((string)$cat['slug']) . '/">' . e((string)$cat['name']) . '</a>'; }
        $html .= ' <i>&gt;</i> <span>' . e((string)$g['title']) . '</span></div></div>';
        // 主区：左图右信息
                // ===== 主区：左缩略图画廊 + 右信息 =====
        $cover = (string)$g['cover'];
        $gallery = $g['images'] ? json_decode((string)$g['images'], true) : [];
        if (!is_array($gallery)) { $gallery = []; }
        $html .= '<div class="mall-goods-main"><div class="mall-w">';
        // 画廊（缩略图）：主图 + 缩略图条
        $html .= '<div class="mall-goods-img"><div class="mall-gallery">';
        $html .= '<div class="mall-gallery-main">'
            . ($cover !== '' ? '<img id="mall-main-img" src="' . e($cover) . '" alt="' . e((string)$g['title']) . '">'
                              : '<div class="mall-img-ph">商品图</div>')
            . '</div>';
        if ($gallery) {
            $html .= '<div class="mall-gallery-thumbs">';
            $gi = 0;
            foreach ($gallery as $gp) {
                $html .= '<img class="mall-thumb' . ($gi === 0 ? ' active' : '') . '" data-full="' . e((string)$gp) . '" src="' . e(mallThumb((string)$gp)) . '" alt="' . e((string)$g['title']) . ' 图' . ($gi + 1) . '">';
                $gi++;
            }
            $html .= '</div>';
        }
        $html .= '</div></div>';
        // 右信息
        $html .= '<div class="mall-goods-info">'
            . '<h1>' . e((string)$g['title']) . '</h1>'
            . ($g['subtitle'] !== '' ? '<div class="mall-goods-sub">' . e((string)$g['subtitle']) . '</div>' : '')
            . '<div class="mall-brand">品牌：'
            . ($brand && (string)$brand['logo'] !== '' ? '<img src="' . e((string)$brand['logo']) . '" alt="' . e((string)$brand['name']) . '">' : '')
            . '<b>' . e((string)($brand ? $brand['name'] : $g['subtitle'])) . '</b></div>';
        // M8 商品标签
        $gtags = Db::rows('SELECT t.name, t.slug FROM ' . self::t('goods_tag') . ' gt JOIN ' . self::t('tag') . ' t ON t.id=gt.tag_id WHERE gt.goods_id=? AND t.is_show=1 AND t.is_delete_time=0 ORDER BY t.sort ASC, t.id ASC', [$id]);
        if ($gtags) {
            $html .= '<div class="mall-goods-tags"><span class="dim">标签：</span>';
            foreach ($gtags as $gt) {
                $html .= '<a class="mall-tag-pill" href="/list.html?tag=' . rawurlencode((string)$gt['slug']) . '">' . e((string)$gt['name']) . '</a>';
            }
            $html .= '</div>';
        }
        $html .= '<div class="mall-price-box"><span class="mall-price"><i>¥</i><b id="mall-price-now">' . e(number_format((float)$g['min_price'], 2, '.', '')) . '</b></span>'
            . ((float)$g['market_price'] > 0 ? '<span class="mall-market">¥' . e(number_format((float)$g['market_price'], 2, '.', '')) . '</span>' : '')
            . '</div>'
            . '<div class="mall-meta"><span>销量 ' . ((int)$g['sales_count'] + (int)$g['virtual_sales']) . '</span><span>库存 ' . max(0, (int)$g['total_stock']) . '</span></div>';
        // M11 促销活动 + M13 会员专属价（按默认可购规格展示）
        $defSku = null;
        foreach ($skus as $s) { if ((int)$s['stock'] > 0) { $defSku = $s; break; } }
        if ($defSku === null && $skus !== []) { $defSku = $skus[0]; }
        if ($defSku !== null) {
            $lvNote = self::memberPriceNote((float)$defSku['price'], self::memberLevelDiscount(self::uid()));
            if ($lvNote !== '') { $html .= '<div class="mall-promo">' . $lvNote . '</div>'; }
            $html .= self::goodsPromoBlock((int)$g['id'], (int)$defSku['id'], (float)$defSku['price']);
        }
        // SKU 选择器：先生成 HTML（同时判定是否有可购规格），再置入加购表单内，
        // 否则 radio 在 <form> 之外，提交时不会带上 sku_id，导致加购失败。
        $hasBuyable = false;
        $skuHtml = '';
        if ($skus) {
            $skuHtml = '<div class="mall-sku-pick"><span class="mall-sku-hd">规格</span><div class="mall-sku-radios">';
            $first = true;
            foreach ($skus as $s) {
                $stock = (int)$s['stock'];
                $off = $stock <= 0;
                if (!$off) { $hasBuyable = true; }
                $skuHtml .= '<label class="mall-sku-item' . ($off ? ' off' : '') . '">'
                    . '<input type="radio" name="sku_id" value="' . (int)$s['id'] . '"'
                    . ' data-price="' . e(number_format((float)$s['price'], 2, '.', '')) . '" data-stock="' . $stock . '"'
                    . ($first && !$off ? ' checked' : '') . ($off ? ' disabled' : '') . '>'
                    . '<span>' . e((string)($s['spec_text'] !== '' ? $s['spec_text'] : '默认规格')) . '</span>'
                    . '<i>¥' . e(number_format((float)$s['price'], 2, '.', '')) . '</i></label>';
                $first = false;
            }
            $skuHtml .= '</div></div>';
        }
        // 数量 + 加购表单（未登录提交时 302 登录页带 redirect 回本页）
        if ($hasBuyable || $skus === []) {
            $html .= '<form class="mall-buy" method="post" action="' . e(self::goodsUrl((int)$g['id'])) . '">'
                . Csrf::field()
                . '<input type="hidden" name="act" value="cart_add">'
                . '<input type="hidden" name="back" value="' . e(self::goodsUrl((int)$g['id'])) . '">'
                . $skuHtml
                . '<div class="mall-num"><label>数量</label>'
                . '<button type="button" class="mn-d" aria-label="减少数量">-</button>'
                . '<input type="number" name="num" value="1" min="1" max="999">'
                . '<button type="button" class="mn-a" aria-label="增加数量">+</button></div>'
                . '<div class="mall-goods-btns"><button type="submit" id="mall-add-cart" class="mall-btn-cart">加入购物车</button>'
                // ★ 「这款适合我吗」：打开 AI 浮窗并带上本商品上下文直接提问
                . '<button type="button" class="mall-btn-plain mg-ask-this" style="margin-left:10px">这款适合我吗</button>'
                . '<button type="submit" class="mall-btn-buy" formaction="/checkout/" name="act" value="buy_now">立即购买</button>'
                . self::favBtn($id, $faved)
                . '</div></form>';
        } else {
            $html .= '<div class="mall-goods-btns"><span class="mall-soldout">暂时售罄</span>'
                . '<a class="mall-btn-plain" href="/list.html">看看其他商品</a></div>';
        }
        $html .= '</div></div></div>';   // 关闭 mall-goods-info / mall-w / mall-goods-main

        // ===== 切换栏(商品详情/商品属性/累计评价/累计购买) + 右栏 + 推荐模块 =====
        $gid = (int)$g['id'];
        $brandId = (int)$g['brand_id'];
        $catId = (int)$g['category_id'];
        $html .= '<div class="mall-detail-layout"><div class="mall-w">';
        $html .= '<div class="mall-detail-main">';
        $html .= '<div class="mall-tabs" id="mall-tabs"><div class="mall-tab-bar">'
            . '<button type="button" class="mall-tab-btn active" data-tab="detail">商品详情</button>'
            . '<button type="button" class="mall-tab-btn" data-tab="attr">商品属性</button>'
            . '<button type="button" class="mall-tab-btn" data-tab="eval">累计评价' . ((int)$g['comments'] > 0 ? '(' . (int)$g['comments'] . ')' : '') . '</button>'
            . '<button type="button" class="mall-tab-btn" data-tab="buy">累计购买</button>'
            . '</div>';

        // 商品详情
        $html .= '<div class="mall-tab-panel active" data-panel="detail"><div class="mall-detail-hd">商品详情</div>' . $detailHtml . '</div>';

        // 商品属性（来自 goods.attrs；与 51puer 商品属性对齐：外观/生熟/年份/价格区间）
        $gattrs = $g['attrs'] ? json_decode((string)$g['attrs'], true) : null;
        if (is_array($gattrs) && $gattrs) {
            $arows = '';
            foreach ($gattrs as $ak => $av) {
                $arows .= '<tr><th>' . e((string)$ak) . '</th><td>' . e((string)$av) . '</td></tr>';
            }
            $html .= '<div class="mall-tab-panel" data-panel="attr"><div class="mall-detail-hd">商品属性</div>'
                . '<table class="mall-params"><tbody>' . $arows . '</tbody></table></div>';
        }

        // 累计评价（来自 ye_mall_review；无则占位）M8 含晒图 images
        $reviews = Db::rows('SELECT uid, score, content, images, created_at FROM ' . self::t('review') . ' WHERE goods_id=? AND is_show=1 ORDER BY id DESC LIMIT 10', [$gid]);
        $html .= '<div class="mall-tab-panel" data-panel="eval"><div class="mall-detail-hd">累计评价 (' . (int)$g['comments'] . ')</div>';
        if ($reviews) {
            $html .= '<ul class="mall-review-list">';
            foreach ($reviews as $rv) {
                $rvImgs = $rv['images'] ? json_decode((string)$rv['images'], true) : [];
                if (!is_array($rvImgs)) { $rvImgs = []; }
                $html .= '<li class="mall-review"><div class="mall-review-hd"><b>茶友#' . (int)$rv['uid'] . '</b>'
                    . '<span class="mall-review-score">' . str_repeat('★', (int)$rv['score']) . '</span>'
                    . '<span class="mall-review-date">' . e((string)$rv['created_at']) . '</span></div>'
                    . '<div class="mall-review-c">' . e((string)$rv['content']) . '</div>';
                if ($rvImgs) {
                    $html .= '<div class="mall-review-imgs">';
                    foreach ($rvImgs as $ri) { $html .= '<img src="' . e((string)$ri) . '" alt="晒图">'; }
                    $html .= '</div>';
                }
                $html .= '</li>';
            }
            $html .= '</ul>';
        } else {
            $html .= '<div class="mall-rec-ph">该商品暂无评价，欢迎购买后评价～</div>';
        }
        $html .= '</div>';

        // 累计购买（销量汇总）
        $totalBuy = (int)$g['sales_count'] + (int)$g['virtual_sales'];
        $html .= '<div class="mall-tab-panel" data-panel="buy"><div class="mall-detail-hd">累计购买</div>'
            . '<div class="mall-buy-sum">该商品累计售出 <b>' . e(number_format($totalBuy)) . '</b> 件'
            . '（真实销量 ' . (int)$g['sales_count'] . ' 件，展示销量 ' . (int)$g['virtual_sales'] . ' 件）。</div></div>';

        $html .= '</div>';   // 关闭 mall-tabs
        $html .= '</div>';   // 关闭 mall-detail-main

        // 右栏：看了又看（同品牌）+ 热销（销量榜）
        $relBrand = $brandId ? Db::rows('SELECT id,title,cover,min_price,market_price FROM ' . self::t('goods') . ' WHERE brand_id=? AND id<>? AND status=1 AND is_delete_time=0 ORDER BY is_recommend DESC, virtual_sales DESC LIMIT 4', [$brandId, $gid]) : [];
        if (!$relBrand) { $relBrand = Db::rows('SELECT id,title,cover,min_price,market_price FROM ' . self::t('goods') . ' WHERE category_id=? AND id<>? AND status=1 AND is_delete_time=0 ORDER BY is_recommend DESC, virtual_sales DESC LIMIT 4', [$catId, $gid]); }
        $hot = Db::rows('SELECT id,title,cover,min_price,market_price FROM ' . self::t('goods') . ' WHERE id<>? AND status=1 AND is_delete_time=0 ORDER BY (sales_count+virtual_sales) DESC, is_recommend DESC LIMIT 5', [$gid]);
        $html .= '<aside class="mall-aside">'
            . self::asideModule('看了又看', $relBrand, false)
            . self::asideModule('热销', $hot, true)
            . '</aside>';
        $html .= '</div></div>';   // 关闭 mall-w / mall-detail-layout

        // 底部：猜你喜欢 / 浏览历史
        $guess = Db::rows('SELECT id,title,cover,min_price,market_price FROM ' . self::t('goods') . ' WHERE category_id=? AND id<>? AND status=1 AND is_delete_time=0 ORDER BY is_recommend DESC, virtual_sales DESC LIMIT 6', [$catId, $gid]);
        if (!$guess) { $guess = $hot; }
        $html .= '<div class="mall-rec"><div class="mall-w">'
            . '<div class="mall-rec-bar"><button type="button" class="mall-rec-btn active" data-rec="guess">猜你喜欢</button>'
            . '<button type="button" class="mall-rec-btn" data-rec="history">浏览历史</button></div>'
            . '<div class="mall-rec-list" data-rec="guess">' . self::cardList($guess) . '</div>'
            . '<div class="mall-rec-list" data-rec="history" id="mall-history" style="display:none"><div class="mall-rec-ph">浏览过的商品会显示在这里（需浏览器本地记录）。</div></div>'
            . '</div></div>';

        $html .= self::footer();
        // JS 增强：缩略图切换主图 / 切换栏 / 猜你喜欢·浏览历史 / SKU 价格联动（无 JS 时页面完整可用）
        $curJson = json_encode([
            'id'    => $gid,
            'title' => $g['title'],
            'price' => number_format((float)$g['min_price'], 2, '.', ''),
            'cover' => mallThumb($cover),
        ], JSON_UNESCAPED_UNICODE);
        $html .= '<script>'
            . 'var __CUR__=' . $curJson . ';'
            . '(function () {'
            . '  var priceEl = document.getElementById("mall-price-now");'
            . '  var numIn = document.querySelector(".mall-num input[name=num]");'
            . '  function pick(r) {'
            . '    if (priceEl && r.getAttribute("data-price")) { priceEl.textContent = r.getAttribute("data-price"); }'
            . '    if (numIn) { var st = parseInt(r.getAttribute("data-stock") || "999", 10); numIn.max = String(Math.max(1, Math.min(999, st))); if (parseInt(numIn.value || "1", 10) > st) { numIn.value = String(Math.max(1, st)); } }'
            . '  }'
            . '  document.querySelectorAll(".mall-sku-item input[type=radio]").forEach(function (r) { r.addEventListener("change", function () { pick(r); }); });'
            . '  var sel = document.querySelector(".mall-sku-item input[type=radio]:checked"); if (sel) { pick(sel); }'
            . '  var d = document.querySelector(".mall-num .mn-d"), a = document.querySelector(".mall-num .mn-a");'
            . '  function step(k) { if (!numIn) return; var v = (parseInt(numIn.value || "1", 10) || 1) + k; var mx = parseInt(numIn.max || "999", 10); numIn.value = String(Math.max(1, Math.min(mx, v))); }'
            . '  if (d) { d.addEventListener("click", function () { step(-1); }); }'
            . '  if (a) { a.addEventListener("click", function () { step(1); }); }'
            . '  var mainImg = document.getElementById("mall-main-img");'
            . '  document.querySelectorAll(".mall-thumb").forEach(function (t) { t.addEventListener("click", function () { if (mainImg) { mainImg.src = t.getAttribute("data-full"); } document.querySelectorAll(".mall-thumb").forEach(function (x) { x.classList.remove("active"); }); t.classList.add("active"); }); });'
            . '  var tabs = document.getElementById("mall-tabs");'
            . '  if (tabs) { tabs.classList.add("js"); tabs.querySelectorAll(".mall-tab-btn").forEach(function (b) { b.addEventListener("click", function () { var name = b.getAttribute("data-tab"); tabs.querySelectorAll(".mall-tab-btn").forEach(function (x) { x.classList.remove("active"); }); b.classList.add("active"); tabs.querySelectorAll(".mall-tab-panel").forEach(function (p) { p.classList.toggle("active", p.getAttribute("data-panel") === name); }); }); }); }'
            . '  var rec = document.querySelector(".mall-rec");'
            . '  if (rec) { rec.querySelectorAll(".mall-rec-btn").forEach(function (b) { b.addEventListener("click", function () { var name = b.getAttribute("data-rec"); rec.querySelectorAll(".mall-rec-btn").forEach(function (x) { x.classList.remove("active"); }); b.classList.add("active"); rec.querySelectorAll(".mall-rec-list").forEach(function (l) { l.style.display = (l.getAttribute("data-rec") === name) ? "" : "none"; }); }); }); }'
            . '  var mallToastTimer=null;'
            . '  function mallToast(msg, type){ var t=document.getElementById("mall-toast"); if(!t){ t=document.createElement("div"); t.id="mall-toast"; t.className="mall-toast"; document.body.appendChild(t); } t.className="mall-toast "+(type||"ok"); t.textContent=msg; void t.offsetWidth; t.classList.add("show"); clearTimeout(mallToastTimer); mallToastTimer=setTimeout(function(){ t.classList.remove("show"); }, 1600); }'
            . '  function mallUpdateCart(count){ var btn=document.querySelector(".mall-cart-btn"); if(!btn) return; var badge=btn.querySelector(".mall-cart-badge"); if(count>0){ var txt=count>99?"99+":String(count); if(badge){ badge.textContent=txt; } else { badge=document.createElement("em"); badge.className="mall-cart-badge"; badge.textContent=txt; btn.appendChild(badge); } } else if(badge){ badge.parentNode.removeChild(badge); } }'
            . '  var addBtn=document.getElementById("mall-add-cart");'
            . '  if(addBtn){ addBtn.addEventListener("click", function(ev){ ev.preventDefault(); var f=addBtn.closest("form"); if(!f) return; var sku=document.querySelector("input[name=sku_id]:checked"); if(document.querySelector("input[name=sku_id]") && !sku){ mallToast("请先选择规格","err"); return; } var fd=new FormData(f); fd.set("act","cart_add"); fd.set("ajax","1"); addBtn.disabled=true; var orig=addBtn.textContent; addBtn.textContent="加入中…"; fetch(location.pathname, {method:"POST", body:fd, headers:{"X-Requested-With":"XMLHttpRequest"}}).then(function(r){ return r.json(); }).then(function(j){ addBtn.disabled=false; addBtn.textContent=orig; if(j && j.login){ location.href=j.url; return; } if(j && j.ok){ mallToast("已加入购物车","ok"); if(typeof j.count==="number"){ mallUpdateCart(j.count); } } else { mallToast("加入失败，请重试","err"); } }).catch(function(){ addBtn.disabled=false; addBtn.textContent=orig; mallToast("网络错误，请重试","err"); }); }); }'
            . '  try {'
            . '    var HKEY = "mall_history";'
            . '    var hist = JSON.parse(localStorage.getItem(HKEY) || "[]");'
            . '    hist = hist.filter(function (x) { return x.id !== __CUR__.id; });'
            . '    hist.unshift(__CUR__);'
            . '    if (hist.length > 12) { hist = hist.slice(0, 12); }'
            . '    localStorage.setItem(HKEY, JSON.stringify(hist));'
            . '    var box = document.getElementById("mall-history");'
            . '    if (box && hist.length) { var h = ""; hist.forEach(function (x) { h += "<a class=\\"mall-card\\" href=\\"/goods-" + x.id + ".html\\"><div class=\\"mall-card-img\\">" + (x.cover ? "<img src=\\"" + x.cover + "\\" alt=\\"" + x.title + "\\">" : "<div class=\\"mall-img-ph\\">商品图</div>") + "</div><div class=\\mall-card-title\\">" + x.title + "</div><div class=\\mall-card-price\\">¥" + x.price + "</div></a>"; }); box.innerHTML = h; }'
            . '  } catch (e) {}'
            . '})();'
            . '</script>';

        $desc = trim((string)($g['seo_desc'] ?: $g['subtitle'] ?: $g['title']));
        $GLOBALS['YC_META'] = ['description' => Str::excerpt($desc, 150), 'keywords' => (string)$g['seo_keywords']];
        $pageTitle = trim((string)($g['seo_title'] ?: $g['title'] . ' - 商城'));

        // ★ SEO：商品页分享卡 + 结构化数据
        //   商品被分享到微信/群聊时，og:image 决定有没有缩略图；
        //   JSON-LD Product/Offer 让搜索引擎能出价格与库存的富媒体结果。
        $canonPath = '/goods-' . (int)$g['id'] . '.html';
        $canonUrl = yc_theme_scheme() . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $canonPath;
        $coverImg = (string)($g['cover'] ?? '');
        if ($coverImg !== '' && !preg_match('#^https?://#i', $coverImg)) {
            $coverImg = yc_theme_scheme() . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $coverImg;
        }
        $GLOBALS['YC_CANONICAL'] = $canonUrl;
        $GLOBALS['YC_OG'] = [
            'title' => (string)$g['title'],
            'description' => Str::excerpt($desc, 150),
            'image' => $coverImg,
            'type' => 'product',
            'url' => $canonUrl,
        ];
        $minPrice = 0.0;
        foreach ($skus as $s) {
            $sp = (float)$s['price'];
            if ($minPrice <= 0 || ($sp > 0 && $sp < $minPrice)) { $minPrice = $sp; }
        }
        $ld = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => (string)$g['title'],
            'description' => Str::excerpt($desc, 300),
            'sku' => 'G' . (int)$g['id'],
            'url' => $canonUrl,
        ];
        if ($coverImg !== '') { $ld['image'] = [$coverImg]; }
        if ((string)($g['brand_name'] ?? '') !== '') { $ld['brand'] = ['@type' => 'Brand', 'name' => (string)$g['brand_name']]; }
        if (!isset($ld['brand']) && (int)($g['brand_id'] ?? 0) > 0) {
            // goodsPage 的商品查询是 SELECT *（无 JOIN），品牌名另查一次给 JSON-LD 用
            $bn = (string)Db::one('SELECT name FROM ' . self::t('brand') . ' WHERE id=? LIMIT 1', [(int)$g['brand_id']]);
            if ($bn !== '') { $ld['brand'] = ['@type' => 'Brand', 'name' => $bn]; }
        }
        if ($minPrice > 0) {
            $ld['offers'] = [
                '@type' => 'Offer',
                'price' => number_format($minPrice, 2, '.', ''),
                'priceCurrency' => 'CNY',
                'availability' => ((int)($g['total_stock'] ?? 0) > 0)
                    ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'url' => $canonUrl,
            ];
        }
        if ((int)($g['comments'] ?? 0) > 0) {
            $ld['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => '5',
                'reviewCount' => (int)$g['comments'],
            ];
        }
        $GLOBALS['YC_JSONLD'] = $ld;

        return ['html' => $html, 'title' => $pageTitle];
    }




    /* ================= 商品卡 / 推荐模块 辅助 ================= */

    private static function cardHtml(array $row): string
    {
        $price = is_numeric($row['min_price']) ? number_format((float)$row['min_price'], 2, '.', '') : '0.00';
        $market = is_numeric($row['market_price']) ? (float)$row['market_price'] : 0;
        $cover = (string)($row['cover'] ?? '');
        $h = '<a class="mall-card" href="' . e(self::goodsUrl((int)$row['id'])) . '">';
        $h .= '<div class="mall-card-img">' . ($cover !== '' ? '<img src="' . e(mallThumb($cover)) . '" alt="' . e((string)$row['title']) . '">' : '<div class="mall-img-ph">商品图</div>') . '</div>';
        $h .= '<div class="mall-card-title">' . e((string)$row['title']) . '</div>';
        $h .= '<div class="mall-card-price">¥' . e($price) . ($market > 0 ? ' <s>¥' . e(number_format($market, 2, '.', '')) . '</s>' : '') . '</div>';
        $h .= '</a>';
        return $h;
    }

    private static function cardList(array $rows): string
    {
        if (!$rows) { return '<div class="mall-rec-ph">暂无相关商品。</div>'; }
        $h = '';
        foreach ($rows as $r) { $h .= self::cardHtml($r); }
        return $h;
    }

    private static function asideModule(string $title, array $rows, bool $ranked): string
    {
        $h = '<div class="mall-aside-mod"><div class="mall-aside-hd">' . e($title) . '</div>';
        if (!$rows) {
            $h .= '<div class="mall-rec-ph">暂无</div>';
        } else {
            $h .= '<ul class="' . ($ranked ? 'mall-rank' : 'mall-aside-list') . '">';
            $i = 1;
            foreach ($rows as $r) {
                $cv = (string)($r['cover'] ?? '');
                $h .= '<li>';
                if ($ranked) { $h .= '<span class="mall-rank-no">' . $i . '</span>'; }
                $h .= '<a class="mall-aside-card" href="' . e(self::goodsUrl((int)$r['id'])) . '">'
                    . '<div class="mall-card-img">' . ($cv !== '' ? '<img src="' . e(mallThumb($cv)) . '" alt="' . e((string)$r['title']) . '">' : '<div class="mall-img-ph">图</div>') . '</div>'
                    . '<div class="mall-aside-info"><div class="mall-card-title">' . e((string)$r['title']) . '</div>'
                    . '<div class="mall-card-price">¥' . e(is_numeric($r['min_price']) ? number_format((float)$r['min_price'], 2, '.', '') : '0.00') . '</div></div>'
                    . '</a></li>';
                $i++;
            }
            $h .= '</ul>';
        }
        $h .= '</div>';
        return $h;
    }

    /* ================= 页面：占位（cart/checkout/order/pay） ================= */

    private static function stub(string $name, string $milestone): array
    {
        $cats = self::categories();
        $html = self::css() . self::topbar() . self::header() . self::navBar($cats);
        $html .= '<div class="mall-stub"><div class="mall-w"><div class="mall-stub-box">'
            . '<h2>' . e($name) . '</h2><p>' . e($name) . '功能将在 ' . e($milestone) . ' 里程碑上线。</p>'
            . '<a class="mall-btn-cart" href="/mall">返回商城首页</a>'
            . '</div></div></div>' . self::footer();
        return ['html' => $html, 'title' => $name . ' - 商城'];
    }

    private static function notFound(): array
    {
        $cats = self::categories();
        $html = self::css() . self::topbar() . self::header() . self::navBar($cats);
        $html .= '<div class="mall-stub"><div class="mall-w"><div class="mall-stub-box">'
            . '<h2>404</h2><p>页面不存在或商品已下架。</p>'
            . '<a class="mall-btn-cart" href="/mall">返回商城首页</a>'
            . '</div></div></div>' . self::footer();
        return ['html' => $html, 'title' => '404 - 商城'];
    }

    /* ================= 会话 / 跳转 / 提示 ================= */

    private static function uid(): int
    {
        $u = Auth::user();
        return $u === null ? 0 : (int)$u['id'];
    }

    /** PRG 跳转（可带一次性提示） */
    private static function go(string $to, string $msg = ''): void
    {
        if ($msg !== '') { $_SESSION['mall_flash'] = $msg; }
        header('Location: ' . $to);
        exit;
    }

    private static function flash(): string
    {
        $m = (string)($_SESSION['mall_flash'] ?? '');
        unset($_SESSION['mall_flash']);
        return $m !== '' ? '<div class="mall-flash"><div class="mall-w">' . e($m) . '</div></div>' : '';
    }

    /** JSON 输出（AJAX 动作用；不走整页跳转） */
    private static function json(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** 是否为 AJAX 请求（fetch 显式带 X-Requested-With，或带 ajax=1） */
    private static function isAjax(): bool
    {
        return (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
            || (($_POST['ajax'] ?? '') === '1');
    }

    /** 终端识别：移动端浏览器（手机/平板） */
    private static function isMobile(): bool
    {
        $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        return (bool)preg_match('/(micromessenger|mobile|android|iphone|ipod|ipad|windows phone|blackberry|webos|opera mini|huawei|honor|miui|redmi|oppo|vivo|samsung|harmonyos)/i', $ua)
            || (strpos($ua, 'mobile') !== false);
    }

    /** 终端识别：微信内置浏览器（走 JSAPI 公众号支付） */
    private static function isWechat(): bool
    {
        return stripos((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'micromessenger') !== false;
    }

    /** 未登录引导卡（SSR；随页附登录/注册回跳链接） */
    private static function loginGuide(string $back, string $label = '登录后操作'): string
    {
        return '<div class="mall-login-guide"><b>' . e($label) . '</b>'
            . '<p>登录后即可使用商城购物功能。</p>'
            . '<a class="mall-btn-buy" href="' . e(ui_login($back)) . '">去登录</a>'
            . '<a class="mall-btn-plain" href="' . e(ui_register($back)) . '">免费注册</a>'
            . '</div>';
    }

    /* ================= POST 动作分发（全部 Csrf::check + PRG） ================= */

    private static function handlePost(string $head): void
    {
        $act = (string)($_POST['act'] ?? '');
        if ($act === '') { return; } // 无动作 POST 交给页面 GET 渲染
        Csrf::check();
        $uid = self::uid();

        switch ($act) {
            case 'cart_add': {
                $back = (string)($_POST['back'] ?? '/mall');
                if (!str_starts_with($back, '/mall')) { $back = '/mall'; } // 防开放跳转
                $ajax = self::isAjax();
                if ($uid <= 0) {
                    if ($ajax) { self::json(['ok' => false, 'login' => true, 'url' => ui_login($back)]); }
                    self::go(ui_login($back));
                }
                self::cartAdd($uid, (int)($_POST['sku_id'] ?? 0), (int)($_POST['num'] ?? 1), $back);
                if ($ajax) { self::json(['ok' => true, 'count' => self::cartCount()]); }
                self::go('/cart', '已加入购物车');
            }
            case 'coupon_get': {
                if ($uid <= 0) { self::go(ui_login('/coupon')); }
                self::couponGet($uid, (int)($_POST['coupon_id'] ?? 0));
                self::go('/coupon/', '领取成功，下单时可用');
            }
            case 'cart_update': {
                if ($uid <= 0) { self::go(ui_login('/cart')); }
                self::cartUpdate($uid, (int)($_POST['cart_id'] ?? 0), (int)($_POST['num'] ?? 1));
                self::go('/cart/', '数量已更新');
            }
            case 'cart_del': {
                if ($uid <= 0) { self::go(ui_login('/cart')); }
                Db::run('DELETE FROM ' . self::t('cart') . ' WHERE uid=? AND id=?', [$uid, (int)($_POST['cart_id'] ?? 0)]);
                self::go('/cart/', '已删除该商品');
            }
            case 'address_save': {
                if ($uid <= 0) { self::go(ui_login('/address/')); }
                self::addressSave($uid);
                $back = (string)($_POST['back'] ?? '/address/');
                if (!str_starts_with($back, '/mall')) { $back = '/address/'; } // 结算页新增地址后回结算
                self::go($back, '地址已保存');
            }
            case 'address_del': {
                if ($uid <= 0) { self::go(ui_login('/address/')); }
                $did = (int)($_POST['id'] ?? 0);
                $row = Db::row('SELECT is_default FROM ' . self::t('user_address') . ' WHERE id=? AND uid=? AND is_delete_time=0 LIMIT 1', [$did, $uid]);
                Db::update('mall_user_address', ['is_delete_time' => time(), 'is_default' => 0], 'id=? AND uid=?', [$did, $uid]);
                // 删的是默认地址 → 剩余最新一条自动顶上，保证始终有默认
                if ($row !== null && (int)$row['is_default'] === 1) {
                    $next = Db::row('SELECT id FROM ' . self::t('user_address') . ' WHERE uid=? AND is_delete_time=0 ORDER BY id DESC LIMIT 1', [$uid]);
                    if ($next !== null) { Db::run('UPDATE ' . self::t('user_address') . ' SET is_default=1 WHERE id=?', [(int)$next['id']]); }
                }
                self::go('/address/', '地址已删除');
            }
            case 'address_default': {
                if ($uid <= 0) { self::go(ui_login('/address/')); }
                self::addressSetDefault($uid, (int)($_POST['id'] ?? 0));
                self::go('/address/', '已设为默认地址');
            }
            case 'buy_now': {
                // 立即购买：校验后转 GET 结算页（buy=sku_id:num）
                $back = (string)($_POST['back'] ?? '/mall');
                if (!str_starts_with($back, '/mall')) { $back = '/mall'; }
                if ($uid <= 0) { self::go(ui_login($back)); }
                $skuId = (int)($_POST['sku_id'] ?? 0);
                $num = max(1, min(999, (int)($_POST['num'] ?? 1)));
                $sku = Db::row('SELECT s.id, s.stock FROM ' . self::t('goods_sku') . ' s JOIN ' . self::t('goods') . ' g ON g.id=s.goods_id'
                    . ' WHERE s.id=? AND s.is_delete_time=0 AND g.status=1 AND g.is_delete_time=0 LIMIT 1', [$skuId]);
                if ($sku === null) { self::go($back, '该商品暂不可购买'); }
                if ((int)$sku['stock'] <= 0) { self::go($back, '该规格暂时售罄'); }
                $num = min($num, (int)$sku['stock']);
                self::go('/checkout/?buy=' . $skuId . ':' . $num);
            }
            case 'order_create': {
                if ($uid <= 0) { self::go(ui_login('/checkout/')); }
                self::orderCreate($uid);
            }
            case 'order_cancel': {
                if ($uid <= 0) { self::go(ui_login('/order')); }
                $no = (string)($_POST['order_no'] ?? '');
                $err = self::orderCancelDo($no, $uid);
                self::go('/order/' . rawurlencode($no), $err !== '' ? $err : '订单已取消，库存已恢复');
            }
            case 'order_confirm': {
                if ($uid <= 0) { self::go(ui_login('/order')); }
                self::orderConfirm($uid, (string)($_POST['order_no'] ?? ''));
                self::go('/order/' . rawurlencode((string)($_POST['order_no'] ?? '')), '已确认收货，感谢购买');
            }
            case 'pay_mock': {
                if ($uid <= 0) { self::go(ui_login('/order')); }
                $no = (string)($_POST['order_no'] ?? '');
                $o = Db::row('SELECT id, order_no, pay_price, status FROM ' . self::t('order') . ' WHERE order_no=? AND uid=? LIMIT 1', [$no, $uid]);
                if ($o === null) { self::go('/order', '订单不存在'); }
                if ((int)$o['status'] !== 0) { self::go('/order/' . rawurlencode($no), '订单状态已变化，无需支付'); }
                $tradeNo = 'MOCK' . date('YmdHis') . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $msg = self::payApplyNotify('mock', $no, $tradeNo, (float)$o['pay_price']);
                self::go('/order/' . rawurlencode($no), $msg === 'success' ? '支付成功' : '支付失败：' . $msg);
            }
            case 'pay_submit': {
                if ($uid <= 0) { self::go(ui_login('/order')); }
                $no = (string)($_POST['order_no'] ?? '');
                $chan = (string)($_POST['chan'] ?? 'mock');
                $o = Db::row('SELECT id, order_no, uid, status, pay_price FROM ' . self::t('order') . ' WHERE order_no=? AND uid=? LIMIT 1', [$no, $uid]);
                if ($o === null) { self::go('/order', '订单不存在'); }
                if ((int)$o['status'] !== 0) { self::go('/order/' . rawurlencode($no), '订单状态已变化，无需支付'); }
                // 模拟支付（测试）
                if ($chan === 'mock') {
                    $tradeNo = 'MOCK' . date('YmdHis') . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $msg = self::payApplyNotify('mock', $no, $tradeNo, (float)$o['pay_price']);
                    self::go('/order/' . rawurlencode($no), $msg === 'success' ? '支付成功' : '支付失败：' . $msg);
                }
                // 扫码收款：跳转到微信/支付宝收款码页
                if ($chan === 'qr') { self::go('/pay/qr/qr/' . rawurlencode($no)); }
                // 支付宝 / 微信：按终端分派 PC / 手机网站 / H5 / JSAPI；未配置且开启沙箱模拟 → 本地模拟收银台
                if ($chan === 'alipay' || $chan === 'wxpay') {
                    $cfg = self::payCfg($chan);
                    $driver = $chan === 'alipay' ? new \MallPay\Alipay($cfg) : new \MallPay\Wxpay($cfg);
                    $order = ['order_no' => $no, 'pay_price' => (float)$o['pay_price'], 'subject' => self::brandName() . '订单' . $no, 'body' => ''];
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443' ? 'https' : 'http';
                    $base = $scheme . '://' . $host;
                    $notify = $base . '/pay/notify/' . $chan;
                    $return = $base . '/order/' . rawurlencode($no);
                    if ($driver->configured()) {
                        if ($chan === 'alipay') {
                            // 移动端浏览器走手机网站支付，PC 走电脑网站支付
                            self::go(self::isMobile() ? $driver->wapPayUrl($order, $notify, $return) : $driver->pagePayUrl($order, $notify, $return));
                        }
                        // 微信：微信内 → JSAPI；移动浏览器 → H5；PC → NATIVE 扫码
                        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                        if (self::isWechat()) {
                            $openid = (string)($_SESSION['mall_wx_openid'] ?? '');
                            if ($openid === '' && trim((string)($cfg['app_id'] ?? '')) !== '' && trim((string)($cfg['secret'] ?? '')) !== '') {
                                self::go(self::wxOauthUrl($no)); // 跳转微信 OAuth 取 openid 后回 wxjs 页
                            }
                            if ($openid === '') { self::go('/pay/' . rawurlencode($no), '微信内支付需配置公众号 AppId/Secret'); }
                            $r = $driver->jsapiUnified($order, $notify, $ip, $openid);
                            if (!$r['ok']) { self::go('/pay/' . rawurlencode($no), '微信下单失败：' . ($r['msg'] ?? '')); }
                            $_SESSION['mall_wx_jsapi'] = $r['pay_params'];
                            self::go('/pay/wxjs/' . rawurlencode($no));
                        }
                        if (self::isMobile()) {
                            $r = $driver->h5Unified($order, $notify, $ip, $base . '/order/' . rawurlencode($no));
                            if (!$r['ok']) { self::go('/pay/' . rawurlencode($no), '微信H5下单失败：' . ($r['msg'] ?? '')); }
                            self::go($r['mweb_url'] . (strpos($r['mweb_url'], '?') !== false ? '&' : '?') . 'redirect_url=' . urlencode($return));
                        }
                        $r = $driver->nativeUnified($order, $notify, $ip);
                        if (!$r['ok']) { self::go('/pay/' . rawurlencode($no), '微信下单失败：' . ($r['msg'] ?? '')); }
                        $_SESSION['mall_wx_codeurl'] = $r['code_url'];
                        self::go('/pay/qr/wxpay/' . rawurlencode($no));
                    }
                    if (self::sandboxAllowed()) {
                        self::go('/pay/sim/' . $chan . '/' . rawurlencode($no));
                    }
                    self::go('/pay/' . rawurlencode($no), '该支付渠道未配置商户号');
                }
                self::go('/pay/' . rawurlencode($no), '未知支付渠道');
            }
            case 'pay_sim_confirm': {
                if ($uid <= 0) { self::go(ui_login('/order')); }
                $no = (string)($_POST['order_no'] ?? '');
                $chan = (string)($_POST['chan'] ?? 'mock');
                if ($chan !== 'alipay' && $chan !== 'wxpay') { $chan = 'mock'; }
                $o = Db::row('SELECT id, order_no, uid, status, pay_price FROM ' . self::t('order') . ' WHERE order_no=? AND uid=? LIMIT 1', [$no, $uid]);
                if ($o === null) { self::go('/order', '订单不存在'); }
                if ((int)$o['status'] !== 0) { self::go('/order/' . rawurlencode($no), '订单状态已变化，无需支付'); }
                $tradeNo = strtoupper($chan) . date('YmdHis') . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $msg = self::payApplyNotify($chan, $no, $tradeNo, (float)$o['pay_price']);
                $label = $chan === 'alipay' ? '支付宝' : '微信支付';
                self::go('/order/' . rawurlencode($no), $msg === 'success' ? ($label . '支付成功') : '支付失败：' . $msg);
            }
            case 'pay_qr_confirm': {
                if ($uid <= 0) { self::go(ui_login('/order')); }
                $no = (string)($_POST['order_no'] ?? '');
                $o = Db::row('SELECT id, order_no, uid, status, pay_price FROM ' . self::t('order') . ' WHERE order_no=? AND uid=? LIMIT 1', [$no, $uid]);
                if ($o === null) { self::go('/order', '订单不存在'); }
                if ((int)$o['status'] !== 0) { self::go('/order/' . rawurlencode($no), '订单状态已变化，无需支付'); }
                $tradeNo = 'QR' . date('YmdHis') . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $msg = self::payApplyNotify('qr', $no, $tradeNo, (float)$o['pay_price']);
                self::go('/order/' . rawurlencode($no), $msg === 'success' ? '支付成功（扫码收款，待商家核对）' : '支付失败：' . $msg);
            }
            // 会员中心（个人中心加强）POST 动作：profile/password/email/recharge/withdraw/favorite/review/aftersale/signin
            case 'member_profile':
            case 'member_password':
            case 'member_mobile':
            case 'member_mobile_send':
            case 'member_email':
            case 'member_recharge':
            case 'member_withdraw':
            case 'favorite_add':
            case 'favorite_del':
            case 'member_review':
            case 'member_aftersale':
            case 'member_signin':
            case 'member_msg_read':
                self::memberPost($act, $uid);
                break;
            // 促销玩法 POST 动作（M11）
            case 'promo_groupon_join':
            case 'promo_bargain_start':
            case 'promo_bargain_cut':
                self::promoPost($act, $uid);
                break;
            case 'comment_post': {
                $cid = (int)($_POST['content_id'] ?? 0);
                $post = Db::row('SELECT id FROM ' . Db::t('contents') . " WHERE id=? AND type='post' AND status='publish' AND deleted_at IS NULL LIMIT 1", [$cid]);
                if ($post === null) { self::go(self::articleListUrl()); }
                // 蜜罐：填了即垃圾
                if (trim((string)($_POST['company'] ?? '')) !== '') { self::go(self::articleUrl($cid)); }
                $body = trim((string)($_POST['body'] ?? ''));
                if (mb_strlen($body) < 2) { self::go(self::articleUrl($cid), '评论内容太短'); }
                $uid = self::uid();
                $name = ''; $email = '';
                if ($uid > 0) {
                    $u = Db::row('SELECT display_name, username FROM ' . Db::t('users') . ' WHERE id=? LIMIT 1', [$uid]);
                    $name = is_array($u) ? ((string)($u['display_name'] ?? '') !== '' ? (string)$u['display_name'] : (string)($u['username'] ?? '')) : '';
                } else {
                    $name = mb_substr(trim((string)($_POST['author_name'] ?? '')), 0, 40);
                    if ($name === '') { self::go(self::articleUrl($cid), '请填写昵称'); }
                    $emailRaw = trim((string)($_POST['author_email'] ?? ''));
                    $email = $emailRaw !== '' && filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? $emailRaw : '';
                }
                $parent = 0;
                $pid = (int)($_POST['parent_id'] ?? 0);
                if ($pid > 0) {
                    $prow = Db::row('SELECT id FROM ' . Db::t('comments') . ' WHERE id=? AND content_id=? LIMIT 1', [$pid, $cid]);
                    if ($prow !== null) { $parent = $pid; }
                }
                $moderation = (string)get_option('comment.moderation', '1') === '1';
                Db::insert('comments', [
                    'content_id' => $cid,
                    'author_id'   => $uid > 0 ? $uid : null,
                    'author_name' => $name,
                    'author_email'=> $email,
                    'ip'          => (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
                    'body'        => mb_substr($body, 0, 2000),
                    'kind'        => 'normal',
                    'parent_id'   => $parent > 0 ? $parent : null,
                    'status'      => $moderation ? 'pending' : 'approved',
                ]);
                Cache::flush('pages');
                Cache::flush('blocks');
                self::go(self::articleUrl($cid), $moderation ? '评论已提交，审核后显示' : '评论成功');
            }
        }
        self::go('/mall'); // 未知动作
    }

    /* ================= 模型：购物车 ================= */

    /** 加购：校验 SKU 有效 → 已有行合并（上限 = 库存），无则新建 */
    private static function cartAdd(int $uid, int $skuId, int $num, string $back): void
    {
        $sku = Db::row('SELECT s.id, s.goods_id, s.stock FROM ' . self::t('goods_sku') . ' s JOIN ' . self::t('goods') . ' g ON g.id=s.goods_id'
            . ' WHERE s.id=? AND s.is_delete_time=0 AND g.status=1 AND g.is_delete_time=0 LIMIT 1', [$skuId]);
        if ($sku === null) { self::go($back, '该商品暂不可购买'); }
        $cap = max(0, (int)$sku['stock']);
        if ($cap <= 0) { self::go($back, '该规格暂时售罄'); }
        // ★ 数量上限 = min(999, 库存)，与 cartUpdate 一致。
        //   旧代码首次加购只夹到 999、不用 $cap，会出现「购物车 999 件 / 库存 96」
        //   ——下单时才报库存不足（不会超卖，但用户看到的数量是错的）。
        $want = max(1, min(999, $num));
        $exist = Db::row('SELECT id, num FROM ' . self::t('cart') . ' WHERE uid=? AND sku_id=? LIMIT 1', [$uid, $skuId]);
        $had = $exist === null ? 0 : (int)$exist['num'];
        $target = min($had + $want, $cap);
        if ($target <= 0) { self::go($back, '库存不足'); }
        if ($exist === null) {
            Db::insert('mall_cart', ['uid' => $uid, 'goods_id' => (int)$sku['goods_id'], 'sku_id' => $skuId, 'num' => $target]);
        } else {
            Db::update('mall_cart', ['num' => $target], 'id=?', [(int)$exist['id']]);
        }
        // 被库存截断时告知用户（不静默改数量）
        if ($had + $want > $target) {
            self::go($back, '库存仅剩 ' . $cap . ' 件，购物车数量已按上限调整为 ' . $target);
        }
    }

    /** 改量：归属校验 + 1..min(999,库存) 截断；num<=0 视为删除 */
    private static function cartUpdate(int $uid, int $cartId, int $num): void
    {
        $row = Db::row('SELECT c.id, s.stock FROM ' . self::t('cart') . ' c JOIN ' . self::t('goods_sku') . ' s ON s.id=c.sku_id'
            . ' WHERE c.id=? AND c.uid=? LIMIT 1', [$cartId, $uid]);
        if ($row === null) { return; }
        if ($num <= 0) { Db::run('DELETE FROM ' . self::t('cart') . ' WHERE id=? AND uid=?', [$cartId, $uid]); return; }
        $cap = max(1, min(999, (int)$row['stock']));
        Db::update('mall_cart', ['num' => max(1, min($cap, $num))], 'id=?', [$cartId]);
    }

    /** 购物车行（JOIN 商品+SKU；ok=可正常结算） */
    private static function cartItems(int $uid): array
    {
        $rows = Db::rows('SELECT c.id cart_id, c.num, c.sku_id, c.goods_id,'
            . ' g.title, g.cover, g.status g_status, g.is_delete_time g_del,'
            . ' s.spec_text, s.price, s.market_price, s.stock s_stock, s.is_delete_time s_del'
            . ' FROM ' . self::t('cart') . ' c'
            . ' JOIN ' . self::t('goods') . ' g ON g.id=c.goods_id'
            . ' JOIN ' . self::t('goods_sku') . ' s ON s.id=c.sku_id'
            . ' WHERE c.uid=? ORDER BY c.updated_at DESC, c.id DESC', [$uid]);
        foreach ($rows as &$r) {
            $r['ok'] = ((int)$r['g_status'] === 1 && (int)$r['g_del'] === 0 && (int)$r['s_del'] === 0 && (int)$r['s_stock'] > 0);
        }
        unset($r);
        return $rows;
    }

    private static function cartCount(): int
    {
        $uid = self::uid();
        if ($uid <= 0) { return 0; }
        return (int)Db::one('SELECT COALESCE(SUM(num),0) FROM ' . self::t('cart') . ' WHERE uid=?', [$uid]);
    }

    /* ================= 页面：购物车 ================= */

    private static function cartPage(): array
    {
        $cats = self::categories();
        $html = self::css() . self::topbar() . self::header() . self::navBar($cats);
        $html .= '<div class="mall-crumb"><div class="mall-w"><a href="/mall">商城</a> <i>&gt;</i> <span>购物车</span></div></div>';
        $html .= self::flash();
        if (self::uid() <= 0) {
            $html .= '<div class="mall-panel"><div class="mall-w">' . self::loginGuide('/cart', '登录后查看购物车') . '</div></div>' . self::footer();
            return ['html' => $html, 'title' => '购物车 - 商城'];
        }
        $items = self::cartItems(self::uid());
        $valid = array_values(array_filter($items, fn($r) => $r['ok']));
        $total = 0.0;
        foreach ($valid as $r) { $total += (float)$r['price'] * (int)$r['num']; }

        $html .= '<div class="mall-panel"><div class="mall-w">';
        if ($items === []) {
            $html .= '<div class="mall-cart-empty">购物车是空的，<a href="/mall">去逛逛</a></div></div></div>' . self::footer();
            return ['html' => $html, 'title' => '购物车 - 商城'];
        }
        // 结算用 GET 表单（checkbox 经 form 属性关联，避免嵌套表单；无 JS 可用）
        $html .= '<form id="mall-checkout-fm" method="get" action="/checkout/"></form>';
        $html .= '<table class="mall-cart-tbl"><tr><th class="c-pick">选择</th><th class="c-goods">商品</th><th class="c-price">单价</th><th class="c-num">数量</th><th class="c-sum">小计</th><th class="c-op">操作</th></tr>';
        foreach ($items as $r) {
            $cover = (string)$r['cover'];
            $sum = (float)$r['price'] * (int)$r['num'];
            $img = $cover !== '' ? '<img src="' . e(mallThumb($cover)) . '" alt="">' : '<span class="mall-cart-nopic">无图</span>';
            $titleCell = '<a class="t" href="' . e(self::goodsUrl((int)$r['goods_id'])) . '">' . e((string)$r['title']) . '</a>'
                . '<span class="spec">' . e((string)($r['spec_text'] !== '' ? $r['spec_text'] : '默认规格')) . '</span>';
            if ($r['ok']) {
                $num = (int)$r['num'];
                $cap = max(1, min(999, (int)$r['s_stock']));
                $html .= '<tr><td class="c-pick"><input form="mall-checkout-fm" type="checkbox" name="sku[]" value="' . (int)$r['sku_id'] . '" checked></td>'
                    . '<td class="c-goods"><div class="gd">' . $img . '<div class="tx">' . $titleCell . '</div></div></td>'
                    . '<td class="c-price">¥' . e(number_format((float)$r['price'], 2, '.', '')) . '</td>'
                    . '<td class="c-num"><form class="mall-numform" method="post" action="/cart/">'
                    . Csrf::field()
                    . '<input type="hidden" name="act" value="cart_update"><input type="hidden" name="cart_id" value="' . (int)$r['cart_id'] . '">'
                    . '<button type="submit" name="num" value="' . max(1, $num - 1) . '"' . ($num <= 1 ? ' disabled' : '') . '>-</button>'
                    . '<input type="number" name="num" value="' . $num . '" min="1" max="' . $cap . '">'
                    . '<button type="submit" name="num" value="' . min($cap, $num + 1) . '"' . ($num >= $cap ? ' disabled' : '') . '>+</button>'
                    . '</form></td>'
                    . '<td class="c-sum">¥' . e(number_format($sum, 2, '.', '')) . '</td>'
                    . '<td class="c-op"><form class="inline" method="post" action="/cart/">'
                    . Csrf::field()
                    . '<input type="hidden" name="act" value="cart_del"><input type="hidden" name="cart_id" value="' . (int)$r['cart_id'] . '">'
                    . '<button class="mini danger">删除</button></form></td></tr>';
            } else {
                $html .= '<tr class="off"><td class="c-pick">—</td>'
                    . '<td class="c-goods"><div class="gd">' . $img . '<div class="tx">' . $titleCell . '<span class="invalid">已失效（下架/售罄）</span></div></div></td>'
                    . '<td class="c-price">¥' . e(number_format((float)$r['price'], 2, '.', '')) . '</td>'
                    . '<td class="c-num">' . (int)$r['num'] . '</td>'
                    . '<td class="c-sum">¥' . e(number_format($sum, 2, '.', '')) . '</td>'
                    . '<td class="c-op"><form class="inline" method="post" action="/cart/">'
                    . Csrf::field()
                    . '<input type="hidden" name="act" value="cart_del"><input type="hidden" name="cart_id" value="' . (int)$r['cart_id'] . '">'
                    . '<button class="mini danger">删除</button></form></td></tr>';
            }
        }
        $html .= '</table>';
        $html .= '<div class="mall-cart-bar"><div class="l">有效商品 <b>' . count($valid) . '</b> 种 · 失效 <b>' . (count($items) - count($valid)) . '</b> 种</div>'
            . '<div class="r"><span class="sum">合计：<i>¥</i><b>' . e(number_format($total, 2, '.', '')) . '</b></span>'
            . ($valid !== []
                ? '<button form="mall-checkout-fm" type="submit" class="mall-btn-buy">去结算</button>'
                : '<span class="mall-btn-buy dis">去结算</span>')
            . '</div></div>';
        $html .= '</div></div>';
        $html .= self::footer();
        return ['html' => $html, 'title' => '购物车 - 商城'];
    }

    /* ================= 模型 + 页面：收货地址 ================= */

    private static function addressSetDefault(int $uid, int $id): void
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::run('UPDATE ' . self::t('user_address') . ' SET is_default=0 WHERE uid=? AND is_default=1', [$uid]);
            Db::run('UPDATE ' . self::t('user_address') . ' SET is_default=1 WHERE id=? AND uid=? AND is_delete_time=0', [$id, $uid]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
        }
    }

    private static function addressSave(int $uid): void
    {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            'name' => mb_substr(trim((string)($_POST['name'] ?? '')), 0, 32),
            'mobile' => mb_substr(trim((string)($_POST['mobile'] ?? '')), 0, 20),
            'province' => mb_substr(trim((string)($_POST['province'] ?? '')), 0, 32),
            'city' => mb_substr(trim((string)($_POST['city'] ?? '')), 0, 32),
            'county' => mb_substr(trim((string)($_POST['county'] ?? '')), 0, 32),
            'address_detail' => mb_substr(trim((string)($_POST['address_detail'] ?? '')), 0, 255),
        ];
        if ($data['name'] === '' || $data['mobile'] === '' || $data['province'] === '' || $data['address_detail'] === '') {
            self::go('/address/', '收货人 / 手机号 / 省份 / 详细地址为必填项');
        }
        $isDef = (($_POST['is_default'] ?? '0') === '1');
        $cnt = (int)Db::one('SELECT COUNT(*) FROM ' . self::t('user_address') . ' WHERE uid=? AND is_delete_time=0', [$uid]);
        if ($id > 0) {
            $own = Db::row('SELECT id FROM ' . self::t('user_address') . ' WHERE id=? AND uid=? AND is_delete_time=0 LIMIT 1', [$id, $uid]);
            if ($own === null) { self::go('/address/', '地址不存在'); }
            Db::update('mall_user_address', $data, 'id=?', [$id]);
        } else {
            if ($cnt >= 20) { self::go('/address/', '最多保存 20 个收货地址'); }
            $data['uid'] = $uid;
            $id = Db::insert('mall_user_address', $data);
        }
        if ($isDef) {
            self::addressSetDefault($uid, $id);
        } elseif ($cnt === 0) {
            Db::run('UPDATE ' . self::t('user_address') . ' SET is_default=1 WHERE id=?', [$id]); // 首个地址自动设默认
        }
    }

    private static function addressPage(array $query): array
    {
        $cats = self::categories();
        $uid = self::uid();
        $html = self::css() . self::topbar() . self::header() . self::navBar($cats);
        $html .= '<div class="mall-crumb"><div class="mall-w"><a href="/mall">商城</a> <i>&gt;</i> <span>收货地址</span></div></div>';
        $html .= self::flash();
        if ($uid <= 0) {
            $html .= '<div class="mall-panel"><div class="mall-w">' . self::loginGuide('/address', '登录后管理收货地址') . '</div></div>' . self::footer();
            return ['html' => $html, 'title' => '收货地址 - 商城'];
        }
        $editId = (int)($query['edit'] ?? 0);
        $edit = $editId > 0
            ? Db::row('SELECT * FROM ' . self::t('user_address') . ' WHERE id=? AND uid=? AND is_delete_time=0 LIMIT 1', [$editId, $uid])
            : null;

        $html .= '<div class="mall-panel"><div class="mall-w"><div class="mall-addr-grid">';
        // 左：表单
        $html .= '<div class="mall-addr-form"><h3>' . ($edit !== null ? '编辑地址 #' . (int)$edit['id'] : '＋ 新增收货地址') . '</h3>'
            . '<form method="post" action="/address/">'
            . Csrf::field()
            . '<input type="hidden" name="act" value="address_save"><input type="hidden" name="id" value="' . ($edit !== null ? (int)$edit['id'] : 0) . '">'
            . '<label>收货人<input type="text" name="name" value="' . e((string)($edit['name'] ?? '')) . '" required maxlength="32"></label>'
            . '<label>手机号<input type="text" name="mobile" value="' . e((string)($edit['mobile'] ?? '')) . '" required maxlength="20" placeholder="11 位手机号"></label>'
            . '<div class="row3"><label>省份<input type="text" name="province" value="' . e((string)($edit['province'] ?? '')) . '" required maxlength="32" placeholder="如 云南省"></label>'
            . '<label>城市<input type="text" name="city" value="' . e((string)($edit['city'] ?? '')) . '" maxlength="32" placeholder="如 西双版纳州"></label>'
            . '<label>区县<input type="text" name="county" value="' . e((string)($edit['county'] ?? '')) . '" maxlength="32" placeholder="如 勐海县"></label></div>'
            . '<label>详细地址<input type="text" name="address_detail" value="' . e((string)($edit['address_detail'] ?? '')) . '" required maxlength="255" placeholder="街道、门牌号"></label>'
            . '<label class="ck"><input type="checkbox" name="is_default" value="1"' . ((int)($edit['is_default'] ?? 0) === 1 ? ' checked' : '') . '>设为默认地址</label>'
            . '<button class="mall-btn-buy">保存地址</button> '
            . ($edit !== null ? '<a class="mall-btn-plain" href="/address/">取消编辑</a>' : '')
            . '</form></div>';
        // 右：列表
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
                . '<div class="op"><a class="mini" href="/address/?edit=' . (int)$a['id'] . '">编辑</a>'
                . '<form class="inline" method="post" action="/address/">' . Csrf::field()
                . '<input type="hidden" name="act" value="address_default"><input type="hidden" name="id" value="' . (int)$a['id'] . '"><button class="mini">设为默认</button></form>'
                . '<form class="inline" method="post" action="/address/">' . Csrf::field()
                . '<input type="hidden" name="act" value="address_del"><input type="hidden" name="id" value="' . (int)$a['id'] . '"><button class="mini danger">删除</button></form>'
                . '</div></div>';
        }
        $html .= '</div></div></div></div>';
        $html .= self::footer();
        return ['html' => $html, 'title' => '收货地址 - 商城'];
    }

    /* ================= M4：交易 ================= */

    private static function orderStatus(int $s): string
    {
        return ['待付款', '待发货', '待收货', '已完成', '已取消', '售后中'][(int)$s] ?? '未知';
    }

    /** 运费：满 mall.free_from 包邮（0=从不包邮），否则收 mall.freight_fee */
    private static function freightFee(float $goodsTotal): float
    {
        $fee = (float)get_option('mall.freight_fee', 10);
        $freeFrom = (float)get_option('mall.free_from', 51);
        if ($freeFrom > 0 && $goodsTotal >= $freeFrom) { return 0.0; }
        return round(max(0, $fee), 2);
    }

    /**
     * 结算行解析（GET query / POST 参数均可）：
     * - buy=sku:num 立即购买
     * - sku[] 或 sku=1,2,3 购物车勾选（数量取购物车行）
     * 返回行含快照字段与 ok 标记；数量钳制到库存。
     */
    private static function checkoutItems(array $params): array
    {
        $uid = self::uid();
        $buyNums = [];
        $skuIds = [];
        $buy = trim((string)($params['buy'] ?? ''));
        if ($buy !== '' && preg_match('/^(\d+):(\d+)$/', $buy, $m)) {
            $skuIds[] = (int)$m[1];
            $buyNums[(int)$m[1]] = max(1, min(999, (int)$m[2]));
        } else {
            $raw = $params['sku'] ?? [];
            $raw = is_string($raw) ? explode(',', $raw) : (array)$raw;
            $skuIds = array_filter(array_map('intval', $raw), fn($n) => $n > 0);
        }
        $skuIds = array_values(array_unique($skuIds));
        if ($skuIds === []) { return []; }
        $rows = Db::rows('SELECT s.id sku_id, s.spec_text, s.price, s.stock,'
            . ' g.id goods_id, g.title, g.cover'
            . ' FROM ' . self::t('goods_sku') . ' s JOIN ' . self::t('goods') . ' g ON g.id=s.goods_id'
            . ' WHERE s.id IN (' . implode(',', $skuIds) . ') AND s.is_delete_time=0 AND g.status=1 AND g.is_delete_time=0');
        // M11/M13 单价管线：秒杀/拼团/砍价活动价 + 会员等级折扣（同一套规则同时作用于结算页与下单）
        $promoCtx = self::promoContext();
        foreach ($rows as &$r) {
            // 促销提示位（默认空，避免模板里出现未定义键）
            $r['promo_blocked'] = '';
            $r['promo_note'] = '';
            $num = $buyNums[(int)$r['sku_id']] ?? 0;
            if ($num <= 0) {
                $c = Db::row('SELECT num FROM ' . self::t('cart') . ' WHERE uid=? AND sku_id=? LIMIT 1', [$uid, (int)$r['sku_id']]);
                $num = $c === null ? 0 : (int)$c['num'];
            }
            $r['num'] = max(1, min((int)$r['stock'], max(1, $num)));
            $r['ok'] = (int)$r['stock'] > 0;
            $base = (float)$r['price'];
            $up = self::unitPrice((int)$r['goods_id'], (int)$r['sku_id'], $base, $uid, $promoCtx);

            // ★ 秒杀限购：unitPrice 在"已买满"时会把 type 清空（活动价不适用），
            //   因此**不能再靠 $up['type']==='seckill' 判断**（那是不成立的死条件，见 docs 记录）。
            //   改读 unitPrice 主动回传的 $up['limited']。
            if (!empty($up['limited'])) {
                $lim = $up['limited'];
                $r['promo_blocked'] = '限时秒杀「' . (string)$lim['title'] . '」每人限购 '
                    . (int)$lim['limit'] . ' 件，您已买满 —— 本单不再享受秒杀价';
                $r['promo_type'] = '';
                $r['promo_id'] = 0;
                $r['promo_label'] = '';
            } else {
                // 仍可享活动：限制单次可购数量为剩余额度
                if ($up['type'] === 'seckill' && $up['id'] > 0) {
                    $sk = Db::row('SELECT per_limit FROM ' . self::t('seckill') . ' WHERE id=? LIMIT 1', [$up['id']]);
                    $limit = $sk !== null ? (int)$sk['per_limit'] : 0;
                    if ($limit > 0) {
                        $left = $limit - self::promoBought($uid, 'seckill', $up['id']);
                        if ($left > 0 && $r['num'] > $left) {
                            $r['num'] = max(1, $left);
                            $r['promo_note'] = '该秒杀每人限购 ' . $limit . ' 件，本次最多可购 ' . $left . ' 件';
                        }
                    }
                }
                $r['promo_type'] = (string)$up['type'];
                $r['promo_id'] = (int)$up['id'];
                $r['promo_label'] = (string)$up['label'];
            }
            $r['origin'] = $base;
            $r['price'] = (float)$up['price'];
        }
        unset($r);
        return $rows;
    }

    /**
     * M11 促销上下文：活动链接带 GET 参数进入结算，提交走 POST，两者都要生效
     * @return array groupon_id / team_id / launch_id
     */
    private static function promoContext(): array
    {
        return [
            'groupon_id' => (int)($_POST['groupon_id'] ?? $_GET['groupon_id'] ?? 0),
            'team_id' => (int)($_POST['team_id'] ?? $_GET['team_id'] ?? 0),
            'launch_id' => (int)($_POST['launch_id'] ?? $_GET['launch_id'] ?? 0),
        ];
    }

    /** 结算参数透传串（PRG 回跳用） */
    private static function carryQuery(array $params): string
    {
        $buy = trim((string)($params['buy'] ?? ''));
        $raw = $params['sku'] ?? [];
        $raw = is_string($raw) ? explode(',', $raw) : (array)$raw;
        $sku = array_filter(array_map('intval', $raw), fn($n) => $n > 0);
        $q = $sku === [] ? '' : 'sku=' . implode(',', $sku);
        if ($buy !== '') { $q = 'buy=' . rawurlencode($buy); }
        $pc = self::promoContext();
        foreach (['groupon_id', 'team_id', 'launch_id'] as $k) {
            if ($pc[$k] > 0) { $q .= ($q === '' ? '' : '&') . $k . '=' . $pc[$k]; }
        }
        return $q;
    }

    /** SKU 库存变动后刷新商品 total_stock 冗余 */
    private static function refreshGoodsAgg(int $goodsId): void
    {
        $agg = Db::row('SELECT SUM(stock) st FROM ' . self::t('goods_sku') . ' WHERE goods_id=? AND is_delete_time=0', [$goodsId]);
        Db::update('mall_goods', ['total_stock' => (int)($agg['st'] ?? 0)], 'id=?', [$goodsId]);
    }

    /** 下单（事务：防超卖扣减 → 订单 → 快照 → 销量 → 清购物车 → 日志） */
    private static function orderCreate(int $uid): void
    {
        $items = self::checkoutItems($_POST);
        $valid = array_values(array_filter($items, fn($r) => $r['ok'] && $r['num'] > 0));
        if ($valid === []) { self::go('/cart/', '没有可结算的商品'); }
        $addrId = (int)($_POST['address_id'] ?? 0);
        $addr = Db::row('SELECT * FROM ' . self::t('user_address') . ' WHERE id=? AND uid=? AND is_delete_time=0 LIMIT 1', [$addrId, $uid]);
        if ($addr === null) { self::go('/checkout/?' . self::carryQuery($_POST), '请选择收货地址'); }
        $remark = mb_substr(trim((string)($_POST['remark'] ?? '')), 0, 255);
        // 发票信息（结算页可选填）：幂等加列后写入订单
        self::ensureInvoiceColumn();
        $invoiceInfo = self::invoiceText($_POST);

        // ★ 秒杀限购硬拦截（服务端强制，不依赖结算页的提示）。
        //   旧代码在已买满限购时只是静默把单价回退成原价 —— 用户点「立即购买」
        //   预期 ¥199、实付原价，且页面无任何提示。属可造成价格争议/投诉的行为，
        //   故此处直接拒绝下单，要求用户明确选择「按原价购买」。
        foreach ($valid as $r) {
            if (!empty($r['promo_blocked'])) {
                self::go('/checkout/?' . self::carryQuery($_POST),
                    (string)$r['promo_blocked'] . '。如仍要购买，请返回购物车重新结算（将按原价 ¥'
                    . number_format((float)$r['price'], 2, '.', '') . ' 计算）');
            }
        }

        $total = 0.0;
        foreach ($valid as $r) { $total += (float)$r['price'] * (int)$r['num']; }
        $freight = self::freightFee($total);
        // 优惠券复核（归属/未用/未过期/门槛）→ 面额封顶商品合计
        $couponUid = (int)($_POST['coupon_uid'] ?? 0);
        $discount = 0.0;
        $couponRow = null;
        if ($couponUid > 0) {
            $uc = Db::row('SELECT uc.id, uc.coupon_id, c.type, c.amount, c.threshold, c.discount_rate, c.max_discount, c.end_time FROM ' . self::t('user_coupon') . ' uc'
                . ' JOIN ' . self::t('coupon') . ' c ON c.id=uc.coupon_id'
                . ' WHERE uc.id=? AND uc.uid=? AND uc.status=0 LIMIT 1', [$couponUid, $uid]);
            $now = date('Y-m-d H:i:s');
            if ($uc === null || ($uc['end_time'] !== null && (string)$uc['end_time'] < $now) || (float)$uc['threshold'] > $total) {
                self::go('/checkout/?' . self::carryQuery($_POST), '所选优惠券不可用，已按不用券重试');
            }
            $discount = self::couponDiscount($uc, $total);
            $couponRow = $uc;
        }
        // M11 组合优惠（订单级：搭配套餐价 / 满件减），作用于券后、积分前的商品金额
        $bd = self::bundleDiscount($valid, round($total, 2));
        $bundleDiscount = (float)$bd['discount'];
        // 兜底：券 + 组合优惠不超过商品金额与运费之和，避免出现负应付
        $bundleDiscount = max(0.0, min($bundleDiscount, round($total + $freight - $discount, 2)));
        // 积分抵扣复核（不超过可用积分，且不超过应付的 mall.points_max_rate）
        $payable0 = round($total + $freight - $discount - $bundleDiscount, 2);
        $pointsUse = 0;
        $pointsDiscount = 0.0;
        $rawPoints = (int)($_POST['points_use'] ?? 0);
        if ($rawPoints > 0) {
            $up = self::usablePoints($uid, $payable0);
            if ($rawPoints > $up['max_use']) {
                self::go('/checkout/?' . self::carryQuery($_POST), '积分使用超出上限（最多 ' . $up['max_use'] . ' 积分，约抵 ¥' . number_format($up['max_discount'], 2, '.', '') . '）');
            }
            $pointsUse = $rawPoints;
            $pointsDiscount = self::pointsDiscount($pointsUse, $payable0);
        }
        $orderNo = date('YmdHis') . str_pad((string)($uid % 10000), 4, '0', STR_PAD_LEFT) . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $addressJson = json_encode([
            'name' => $addr['name'], 'mobile' => $addr['mobile'],
            'province' => $addr['province'], 'city' => $addr['city'], 'county' => $addr['county'],
            'address_detail' => $addr['address_detail'],
        ], JSON_UNESCAPED_UNICODE);

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            if ($couponRow !== null) { // 先锁券：并发下单同一张券只有一单成功
                $st = Db::run('UPDATE ' . self::t('user_coupon') . ' SET status=1, used_order_no=? WHERE id=? AND uid=? AND status=0', [$orderNo, (int)$couponRow['id'], $uid]);
                if ($st->rowCount() === 0) { throw new RuntimeException('优惠券已被使用'); }
            }
            foreach ($valid as $r) { // 防超卖：条件扣减，失败即回滚
                $st = Db::run('UPDATE ' . self::t('goods_sku') . ' SET stock=stock-? WHERE id=? AND stock>=?', [(int)$r['num'], (int)$r['sku_id'], (int)$r['num']]);
                if ($st->rowCount() === 0) { throw new RuntimeException('「' . $r['title'] . '」库存不足'); }
            }
            if ($pointsUse > 0) { // 积分抵扣：事务内扣减（pointsApply 内含余额校验）
                self::pointsApply($uid, -$pointsUse, '订单 ' . $orderNo . ' 积分抵扣');
            }
            $orderId = Db::insert('mall_order', [
                'order_no' => $orderNo, 'uid' => $uid, 'status' => 0,
                'total_goods_price' => $total, 'freight_price' => $freight,
                'discount_price' => round($discount + $bundleDiscount + $pointsDiscount, 2),
                'pay_price' => round($total + $freight - $discount - $bundleDiscount - $pointsDiscount, 2),
                'address_json' => $addressJson, 'user_coupon_id' => $couponRow !== null ? (int)$couponRow['id'] : 0,
                'points_used' => $pointsUse, 'points_discount' => $pointsDiscount, 'promo_discount' => $bundleDiscount,
            'invoice_info' => $invoiceInfo,
                'remark' => $remark,
            ]);
            foreach ($valid as $r) {
                Db::insert('mall_order_goods', [
                    'order_id' => $orderId, 'goods_id' => (int)$r['goods_id'], 'sku_id' => (int)$r['sku_id'],
                    'title' => (string)$r['title'], 'spec_text' => (string)$r['spec_text'], 'cover' => (string)$r['cover'],
                    'promo_type' => (string)($r['promo_type'] ?? ''), 'promo_id' => (int)($r['promo_id'] ?? 0),
                    'origin_price' => round((float)($r['origin'] ?? $r['price']), 2),
                    'price' => (float)$r['price'], 'num' => (int)$r['num'],
                    'total_price' => round((float)$r['price'] * (int)$r['num'], 2),
                ]);
                Db::run('UPDATE ' . self::t('goods') . ' SET sales_count=sales_count+? WHERE id=?', [(int)$r['num'], (int)$r['goods_id']]);
                self::refreshGoodsAgg((int)$r['goods_id']);
            }
            if (trim((string)($_POST['buy'] ?? '')) === '') { // 购物车模式：清掉已下单的行
                foreach ($valid as $r) {
                    Db::run('DELETE FROM ' . self::t('cart') . ' WHERE uid=? AND sku_id=?', [$uid, (int)$r['sku_id']]);
                }
            }
            Db::insert('mall_order_log', ['order_id' => $orderId, 'order_no' => $orderNo, 'action' => 'create', 'detail' => '下单成功', 'operator' => 'user']);
            // M11：活动库存/参团登记（事务内，失败随订单回滚）
            self::promoAfterOrder($orderId, $orderNo, $uid, $valid, [
                'groupon_id' => (int)($_POST['groupon_id'] ?? 0),
                'team_id' => (int)($_POST['team_id'] ?? 0),
            ]);
            // M12：下单站内消息
            self::msgSend($uid, 'order', '下单成功', '订单 ' . $orderNo . ' 已提交，请尽快完成付款。', '/order/' . rawurlencode($orderNo));
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            self::go('/checkout/?' . self::carryQuery($_POST), '下单失败：' . $e->getMessage());
        }
        self::go('/order/' . $orderNo, '下单成功');
    }

    /** 取消订单核心：校验状态 + 恢复库存/销量 + 写日志；返回错误文案（''=成功），不做跳转（前台/后台共用） */
    public static function orderCancelDo(string $orderNo, ?int $uid, bool $isAdmin = false): string
    {
        $o = $uid === null
            ? Db::row('SELECT id, order_no, status, user_coupon_id, points_used, uid FROM ' . self::t('order') . ' WHERE order_no=? LIMIT 1', [$orderNo])
            : Db::row('SELECT id, order_no, status, user_coupon_id, points_used, uid FROM ' . self::t('order') . ' WHERE order_no=? AND uid=? LIMIT 1', [$orderNo, $uid]);
        if ($o === null) { return '订单不存在'; }
        if (!in_array((int)$o['status'], [0, 1], true)) { return '当前状态不可取消'; }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            // 优惠券归还（仅待付款/待发货期间取消）
            if ((int)$o['user_coupon_id'] > 0) {
                Db::run('UPDATE ' . self::t('user_coupon') . ' SET status=0, used_order_no=\'\' WHERE id=? AND used_order_no=?', [(int)$o['user_coupon_id'], $orderNo]);
            }
            // 积分归还（取消时退回抵扣用的积分）
            if ((int)$o['points_used'] > 0) {
                self::pointsApply((int)$o['uid'], (int)$o['points_used'], '订单取消退回积分 ' . $orderNo);
            }
            $rows = Db::rows('SELECT goods_id, sku_id, num FROM ' . self::t('order_goods') . ' WHERE order_id=?', [(int)$o['id']]);
            foreach ($rows as $r) {
                Db::run('UPDATE ' . self::t('goods_sku') . ' SET stock=stock+? WHERE id=?', [(int)$r['num'], (int)$r['sku_id']]);
                Db::run('UPDATE ' . self::t('goods') . ' SET sales_count=GREATEST(sales_count-?,0) WHERE id=?', [(int)$r['num'], (int)$r['goods_id']]);
                self::refreshGoodsAgg((int)$r['goods_id']);
            }
            // M11：退回秒杀活动库存、退出未支付团
            self::promoAfterCancel($orderNo, (int)$o['uid']);
            // ★ 取消来源要分清：自动（cron 超时）会被误标成「管理员取消」，
            //   使对账/客服看不出真实原因。故新增 $byAuto（向后兼容：默认 false）。
            $byAuto = (func_num_args() >= 4) ? (bool)func_get_arg(3) : false;
            $reason = $byAuto ? '超时未付款自动取消' : ($isAdmin ? '管理员取消' : '用户取消');
            $operator = $byAuto ? 'cron' : ($isAdmin ? 'admin' : 'user');
            Db::update('mall_order', ['status' => 4, 'cancel_time' => date('Y-m-d H:i:s'), 'cancel_reason' => $reason], 'id=?', [(int)$o['id']]);
            Db::insert('mall_order_log', ['order_id' => (int)$o['id'], 'order_no' => $orderNo, 'action' => 'cancel', 'detail' => $reason . '订单', 'operator' => $operator]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return '取消失败：' . $e->getMessage();
        }
        return '';
    }

    /** 用户确认收货：待收货 → 已完成 */
    private static function orderConfirm(int $uid, string $orderNo): void
    {
        $o = Db::row('SELECT id, status FROM ' . self::t('order') . ' WHERE order_no=? AND uid=? LIMIT 1', [$orderNo, $uid]);
        if ($o === null || (int)$o['status'] !== 2) { self::go('/order/' . $orderNo, '当前状态不可确认收货'); }
        $now = date('Y-m-d H:i:s');
        Db::update('mall_order', ['status' => 3, 'confirm_time' => $now, 'finish_time' => $now], 'id=?', [(int)$o['id']]);
        Db::insert('mall_order_log', ['order_id' => (int)$o['id'], 'order_no' => $orderNo, 'action' => 'confirm', 'detail' => '用户确认收货', 'operator' => 'user']);
    }

    /* ================= 页面：确认订单 ================= */

    private static function checkoutPage(array $query): array
    {
        $cats = self::categories();
        $uid = self::uid();
        $html = self::css() . self::topbar() . self::header() . self::navBar($cats);
        $html .= '<div class="mall-crumb"><div class="mall-w"><a href="/mall">商城</a> <i>&gt;</i> <span>确认订单</span></div></div>';
        $html .= self::flash();
        if ($uid <= 0) {
            $html .= '<div class="mall-panel"><div class="mall-w">' . self::loginGuide('/cart', '登录后提交订单') . '</div></div>' . self::footer();
            return ['html' => $html, 'title' => '确认订单 - 商城'];
        }
        $items = self::checkoutItems($query);
        $valid = array_values(array_filter($items, fn($r) => $r['ok'] && $r['num'] > 0));
        if ($valid === []) {
            $html .= '<div class="mall-panel"><div class="mall-w"><div class="mall-cart-empty">没有可结算的商品，<a href="/cart">回购物车看看</a></div></div></div>' . self::footer();
            return ['html' => $html, 'title' => '确认订单 - 商城'];
        }
        $total = 0.0;
        foreach ($valid as $r) { $total += (float)$r['price'] * (int)$r['num']; }
        $freight = self::freightFee($total);
        $addrs = Db::rows('SELECT * FROM ' . self::t('user_address') . ' WHERE uid=? AND is_delete_time=0 ORDER BY is_default DESC, id DESC', [$uid]);
        $coupons = self::usableCoupons($uid, $total);
        $pts = self::usablePoints($uid, round($total + $freight, 2));
        $carry = self::carryQuery($query);

        $html .= '<div class="mall-panel"><div class="mall-w">';
        if ($addrs === []) {
            // 无地址：仅展示提示 + 内联新增（独立表单，提交后回跳结算页）；不渲染下单表单
            $html .= '<div class="mall-co-noaddr">还没有收货地址，填写后继续下单。</div>'
                . '<div class="mall-addr-form mall-addr-inline"><h4>新增收货地址</h4>'
                . '<form method="post" action="/address/">'
                . Csrf::field()
                . '<input type="hidden" name="act" value="address_save"><input type="hidden" name="id" value="0">'
                . '<input type="hidden" name="back" value="/checkout/?' . e($carry) . '">'
                . '<label>收货人<input type="text" name="name" required maxlength="32"></label>'
                . '<label>手机号<input type="text" name="mobile" required maxlength="20" placeholder="11 位手机号"></label>'
                . '<div class="row3"><label>省份<input type="text" name="province" required maxlength="32" placeholder="如 云南省"></label>'
                . '<label>城市<input type="text" name="city" maxlength="32"></label>'
                . '<label>区县<input type="text" name="county" maxlength="32"></label></div>'
                . '<label>详细地址<input type="text" name="address_detail" required maxlength="255"></label>'
                . '<label class="ck"><input type="checkbox" name="is_default" value="1" checked>设为默认地址</label>'
                . '<button class="mall-btn-buy">保存并返回</button></form></div>';
        } else {
            // 有地址：订单表单包裹 地址选择 + 优惠券 + 提交（控件必须在表单内，否则提交丢失 address_id/coupon_uid）
            $html .= '<form method="post" action="/checkout/" class="mall-co-submit">'
                . Csrf::field()
                . '<input type="hidden" name="act" value="order_create">';
            if (isset($query['buy']) && trim((string)$query['buy']) !== '') {
                $html .= '<input type="hidden" name="buy" value="' . e((string)$query['buy']) . '">';
            } else {
                foreach ($valid as $r) { $html .= '<input type="hidden" name="sku[]" value="' . (int)$r['sku_id'] . '">'; }
            }
            // M11：保留促销上下文（拼团/砍价），否则提交订单时活动价丢失
            $pcCtx = self::promoContext();
            foreach (['groupon_id', 'team_id', 'launch_id'] as $pk) {
                if ($pcCtx[$pk] > 0) { $html .= '<input type="hidden" name="' . $pk . '" value="' . $pcCtx[$pk] . '">'; }
            }
            // 收货地址
            $html .= '<div class="mall-co-sec"><h3>收货信息</h3><div class="mall-co-addrs"><div class="list">';
            foreach ($addrs as $i => $a) {
                $checked = ((int)$a['is_default'] === 1 || $i === 0 && count($addrs) === 1) ? ' checked' : '';
                $html .= '<label class="mall-co-addr' . ($checked !== '' ? ' def' : '') . '">'
                    . '<input type="radio" name="address_id" value="' . (int)$a['id'] . '"' . $checked . '>'
                    . '<span class="n">' . e((string)$a['name']) . ' <b>' . e((string)$a['mobile']) . '</b></span>'
                    . '<span class="d">' . e((string)($a['province'] . ' ' . $a['city'] . ' ' . $a['county'] . ' ' . $a['address_detail'])) . '</span>'
                    . '</label>';
            }
            $html .= '</div><a class="mall-addr-mgr" href="/address/">管理收货地址 →</a></div></div>';
            // 商品清单
            $html .= '<div class="mall-co-sec"><h3>商品清单</h3><table class="mall-co-tbl"><tr><th>商品</th><th>单价</th><th>数量</th><th>小计</th></tr>';
            foreach ($valid as $r) {
                $cover = (string)$r['cover'];
                $img = $cover !== '' ? '<img src="' . e(mallThumb($cover)) . '" alt="">' : '<span class="mall-cart-nopic">无图</span>';
                $html .= '<tr><td class="c-goods"><div class="gd">' . $img . '<div class="tx"><span class="t">' . e((string)$r['title']) . '</span>'
                    . '<span class="spec">' . e((string)($r['spec_text'] !== '' ? $r['spec_text'] : '默认规格')) . '</span></div></div></td>'
                    . '<td>¥' . e(number_format((float)$r['price'], 2, '.', ''))
                    . ((float)($r['origin'] ?? 0) > (float)$r['price'] ? ' <s class="dim">¥' . e(number_format((float)$r['origin'], 2, '.', '')) . '</s>' : '')
                    . ($r['promo_label'] !== '' ? '<div class="mall-co-promo">' . e((string)$r['promo_label']) . '</div>' : '')
                    // ★ 限购已满：明确告知价格已按原价，不再静默
                    . ($r['promo_blocked'] !== ''
                        ? '<div class="mall-co-promo" style="color:#b3541e;font-weight:600">'
                          . e((string)$r['promo_blocked']) . '</div>'
                        : '')
                    . ($r['promo_note'] !== ''
                        ? '<div class="mall-co-promo" style="color:#8a6d3b">' . e((string)$r['promo_note']) . '</div>'
                        : '')
                    . '</td>'
                    . '<td>' . (int)$r['num'] . '</td>'
                    . '<td class="c-sum">¥' . e(number_format((float)$r['price'] * (int)$r['num'], 2, '.', '')) . '</td></tr>';
            }
            $html .= '</table></div>';
            // 优惠券
            $html .= '<div class="mall-co-sec"><h3>优惠券</h3>';
            if ($coupons === []) {
                $html .= '<div class="mall-co-nocp">暂无可用优惠券 <a href="/coupon/">去领券中心</a></div>';
            } else {
                $html .= '<div class="mall-co-cps"><label class="cp on"><input type="radio" name="coupon_uid" value="0" checked><span>不使用优惠券</span></label>';
                foreach ($coupons as $cp) {
                    $disc = (float)($cp['discount_now'] ?? 0);
                    $isDisc = (int)$cp['type'] === 2;
                    $zhe = $isDisc ? '（' . e(rtrim(rtrim(number_format((float)$cp['discount_rate'] * 10, 1, '.', ''), '0'), '.')) . '折）' : '';
                    $html .= '<label class="cp"><input type="radio" name="coupon_uid" value="' . (int)$cp['ucid'] . '" data-amount="' . e(number_format($disc, 2, '.', '')) . '">'
                        . '<span class="v">-¥' . e(number_format($disc, 2, '.', '')) . '</span>'
                        . '<span class="n">' . e((string)$cp['name']) . $zhe . '（满 ¥' . e(number_format((float)$cp['threshold'], 2, '.', '')) . ' 可用）</span></label>';
                }
                $html .= '</div>';
            }
            $html .= '</div>';
            // 积分抵扣
            $html .= '<div class="mall-co-sec"><h3>积分抵扣</h3>';
            if ($pts['avail'] <= 0 || $pts['max_use'] <= 0) {
                $html .= '<div class="mall-co-nocp">暂无可用积分（当前 ' . $pts['avail'] . ' 积分，约抵 ¥' . number_format($pts['avail'] / (float)$pts['rate'], 2, '.', '') . '）</div>';
            } else {
                $html .= '<div class="mall-co-pts"><span class="n">可用 ' . $pts['avail'] . ' 积分（约抵 ¥' . number_format($pts['avail'] / (float)$pts['rate'], 2, '.', '') . '）</span>'
                    . '<label class="mall-co-pts-row">使用 <input type="number" name="points_use" class="mall-co-pts-in" min="0" max="' . $pts['max_use'] . '" value="0" data-rate="' . $pts['rate'] . '"> 积分'
                    . '<span class="hint">最多 ' . $pts['max_use'] . '，约抵 ¥' . number_format($pts['max_discount'], 2, '.', '') . '</span></label></div>';
            }
            $html .= '</div>';
            // M11 组合优惠（订单级）：展示已命中的套餐优惠，结算金额同步扣减
            $bdNow = self::bundleDiscount($valid, round($total, 2));
            $bdAmount = max(0.0, min((float)$bdNow['discount'], round($total + $freight, 2)));
            $html .= '<div class="mall-co-sec"><h3>组合优惠</h3>';
            if ($bdAmount > 0) {
                $html .= '<div class="mall-co-bundle"><span class="mall-promo-tag bdl">已享</span>'
                    . '<b>' . e((string)$bdNow['label']) . '</b><span class="c-red">-¥' . e(number_format($bdAmount, 2, '.', '')) . '</span>'
                    . '<span class="dim">（已自动计入应付金额）</span></div>';
            } else {
                $html .= '<div class="mall-co-nocp">本单未命中组合套餐，搭配购买更省 <a href="/promo/bundle/">看看套装 →</a></div>';
            }
            $html .= '</div>';
            // 金额 + 提交
            $payBase = round($total + $freight - $bdAmount, 2);
            // 发票信息（对齐 51puer 的发票能力；本地茶店多为个人客户，故做轻量版）
            $html .= '<div class="mall-co-inv">'
                . '<div class="rm2">发票<label class="ck"><input type="radio" name="invoice_type" value="0" checked>不需要</label>'
                . '<label class="ck"><input type="radio" name="invoice_type" value="1">普通发票</label>'
                . '<label class="ck"><input type="radio" name="invoice_type" value="2">专用发票</label></div>'
                . '<div class="mall-co-inv-fields">'
                . '<label>发票抬头<input type="text" name="invoice_title" maxlength="80" placeholder="个人或单位名称"></label>'
                . '<label>税号<input type="text" name="invoice_taxno" maxlength="40" placeholder="单位纳税人识别号（个人可留空）"></label>'
                . '</div></div>';
            $html .= '<label class="rm">订单备注（选填）<input type="text" name="remark" maxlength="255" placeholder="给卖家留言"></label>'
                . '<div class="bar"><div class="sums">'
                . '<span>商品合计 <b>¥' . e(number_format($total, 2, '.', '')) . '</b></span>'
                . '<span>运费 <b>' . ($freight > 0 ? '¥' . e(number_format($freight, 2, '.', '')) : '包邮') . '</b></span>'
                . '<span class="pay">应付 <i>¥</i><b data-base="' . e(number_format($payBase, 2, '.', '')) . '">' . e(number_format($payBase, 2, '.', '')) . '</b></span>'
                . '</div>'
                . '<button type="submit" class="mall-btn-buy">提交订单</button></div>'
                . '</form>';
        }
        $html .= '</div></div>';
        $html .= '</div></div>';
        $html .= self::footer();
        // 仅增强：选券后实时更新应付金额（无 JS 时以提交后订单页金额为准）
        $html .= <<<'HTML'
<script>
(function () {
  var payB = document.querySelector('.mall-co-submit .sums .pay b');
  if (!payB) return;
  var base = parseFloat(payB.getAttribute('data-base') || '0');
  function recalc() {
    var couponAmt = 0;
    var cr = document.querySelector('.mall-co-cps input[type=radio]:checked');
    if (cr && cr.value !== '0') { couponAmt = parseFloat(cr.getAttribute('data-amount') || '0'); }
    var ptsAmt = 0;
    var pi = document.querySelector('.mall-co-pts-in');
    if (pi) {
      var rate = parseFloat(pi.getAttribute('data-rate') || '100');
      var use = Math.max(0, Math.min(parseInt(pi.value || '0', 10), parseInt(pi.getAttribute('max') || '0', 10)));
      if (rate > 0) { ptsAmt = use / rate; }
    }
    payB.textContent = Math.max(0, base - couponAmt - ptsAmt).toFixed(2);
  }
  document.querySelectorAll('.mall-co-cps input[type=radio]').forEach(function (r) {
    r.addEventListener('change', recalc);
  });
  var pi = document.querySelector('.mall-co-pts-in');
  if (pi) { pi.addEventListener('input', recalc); }
  recalc();
})();
</script>
HTML;
        return ['html' => $html, 'title' => '确认订单 - 商城'];
    }

    /* ================= 页面：订单列表 / 详情 ================= */

    private static function orderPage(array $path, array $query): array
    {
        $no = (string)($path[1] ?? '');
        return $no !== '' ? self::orderDetail($no) : self::orderList($query);
    }

    private static function orderActions(array $o): string
    {
        $no = (string)$o['order_no'];
        $btn = static fn(string $cls, string $label, string $act, string $extra = ''): string
            => '<form class="inline" method="post" action="/order/' . e($no) . '">' . Csrf::field()
                . '<input type="hidden" name="act" value="' . $act . '"><input type="hidden" name="order_no" value="' . e($no) . '">'
                . '<button class="' . $cls . '"' . $extra . '>' . $label . '</button></form>';
        $s = (int)$o['status'];
        $h = '';
        if ($s === 0) {
            $h .= '<a class="mini p" href="/pay/' . e($no) . '">去支付</a>';
            $h .= $btn('mini danger', '取消订单', 'order_cancel', ' onclick="return confirm(\'取消该订单并恢复库存？\')"');
        }
        if ($s === 2) {
            $h .= $btn('mini p', '确认收货', 'order_confirm', ' onclick="return confirm(\'确认已收到货？\')"');
        }
        return $h;
    }

    private static function orderList(array $query): array
    {
        $cats = self::categories();
        $uid = self::uid();
        $html = self::css() . self::topbar() . self::header() . self::navBar($cats);
        $html .= '<div class="mall-crumb"><div class="mall-w"><a href="/mall">商城</a> <i>&gt;</i> <span>我的订单</span></div></div>';
        $html .= self::flash();
        if ($uid <= 0) {
            $html .= '<div class="mall-panel"><div class="mall-w">' . self::loginGuide('/order', '登录后查看订单') . '</div></div>' . self::footer();
            return ['html' => $html, 'title' => '我的订单 - 商城'];
        }
        $st = isset($query['st']) && $query['st'] !== '' ? (int)$query['st'] : -1;
        $where = 'uid=?';
        $args = [$uid];
        if ($st >= 0 && $st <= 5) { $where .= ' AND status=?'; $args[] = $st; }
        $page = max(1, (int)($query['page'] ?? 1));
        $per = 10;
        $total = (int)Db::one('SELECT COUNT(*) FROM ' . self::t('order') . ' WHERE ' . $where, $args);
        $rows = Db::rows('SELECT * FROM ' . self::t('order') . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $args);
        // 商品摘要
        $items = [];
        if ($rows) {
            $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
            foreach (Db::rows('SELECT order_id, title, spec_text, num, cover FROM ' . self::t('order_goods') . ' WHERE order_id IN (' . $ids . ')') as $og) {
                $items[(int)$og['order_id']][] = $og;
            }
        }
        $statusTabs = [['', '全部'], [0, '待付款'], [1, '待发货'], [2, '待收货'], [3, '已完成'], [4, '已取消']];
        $html .= '<div class="mall-panel"><div class="mall-w">';
        $html .= '<div class="mall-order-tabs">';
        foreach ($statusTabs as $t) {
            $on = ($t[0] === '' && $st < 0) || (string)$t[0] === (string)$st && $t[0] !== '';
            $html .= '<a href="/order/' . ($t[0] === '' ? '' : '?st=' . $t[0]) . '"' . ($on ? ' class="on"' : '') . '>' . $t[1] . '</a>';
        }
        $html .= '</div>';
        if ($rows === []) {
            $html .= '<div class="mall-cart-empty">暂无订单，<a href="/mall">去下单</a></div></div></div>' . self::footer();
            return ['html' => $html, 'title' => '我的订单 - 商城'];
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
                . '<div class="ft"><a class="mini" href="/order/' . e((string)$o['order_no']) . '">订单详情</a>' . self::orderActions($o) . '</div>'
                . '</div>';
        }
        $tp = (int)ceil($total / $per);
        if ($tp > 1) {
            $base = '/order/' . ($st >= 0 ? '?st=' . $st . '&' : '?');
            $html .= '<div class="mall-pager">';
            if ($page > 1) { $html .= '<a href="' . e($base . 'page=' . ($page - 1)) . '">&lt; 上一页</a>'; }
            $html .= '<span class="cur">' . $page . ' / ' . $tp . '</span>';
            if ($page < $tp) { $html .= '<a href="' . e($base . 'page=' . ($page + 1)) . '">下一页 &gt;</a>'; }
            $html .= '</div>';
        }
        $html .= '</div></div>';
        $html .= self::footer();
        return ['html' => $html, 'title' => '我的订单 - 商城'];
    }

    private static function orderDetail(string $no): array
    {
        $cats = self::categories();
        $uid = self::uid();
        $html = self::css() . self::topbar() . self::header() . self::navBar($cats);
        $html .= '<div class="mall-crumb"><div class="mall-w"><a href="/mall">商城</a> <i>&gt;</i> <a href="/order">我的订单</a> <i>&gt;</i> <span>' . e($no) . '</span></div></div>';
        $html .= self::flash();
        $o = $uid > 0 || Auth::isAdmin()
            ? Db::row('SELECT * FROM ' . self::t('order') . ' WHERE order_no=? LIMIT 1', [$no])
            : null;
        if ($o === null || ((int)$o['uid'] !== $uid && !Auth::isAdmin())) {
            $html .= '<div class="mall-panel"><div class="mall-w">' . self::loginGuide('/order', '登录后查看订单') . '</div></div>' . self::footer();
            return ['html' => $html, 'title' => '订单详情 - 商城'];
        }
        $goods = Db::rows('SELECT * FROM ' . self::t('order_goods') . ' WHERE order_id=?', [(int)$o['id']]);
        $logs = Db::rows('SELECT * FROM ' . self::t('order_log') . ' WHERE order_id=? ORDER BY id DESC', [(int)$o['id']]);
        $addr = (array)json_decode((string)$o['address_json'], true);

        $html .= '<div class="mall-panel"><div class="mall-w"><div class="mall-order-dt">';
        // 状态头
        $html .= '<div class="od-hd"><div class="l"><div class="st s' . (int)$o['status'] . '">' . e(self::orderStatus((int)$o['status'])) . '</div>'
            . '<div class="no">订单号 ' . e((string)$o['order_no']) . ' · ' . e((string)$o['created_at']) . ' 下单</div></div>'
            . '<div class="r">' . self::orderActions($o) . '</div></div>';
        // 收货地址 + 快递
        $html .= '<div class="od-addr"><b>收货信息</b><p>' . e((string)($addr['name'] ?? '')) . ' ' . e((string)($addr['mobile'] ?? '')) . '</p>'
            . '<p>' . e((string)($addr['province'] ?? '') . ($addr['city'] ?? '') . ($addr['county'] ?? '') . ' ' . ($addr['address_detail'] ?? '')) . '</p>'
            . ((string)$o['express_name'] !== '' ? '<p class="ex">快递：' . e((string)$o['express_name']) . ' ' . e((string)$o['express_no']) . '</p>' : '')
            . ((string)$o['remark'] !== '' ? '<p class="rm">备注：' . e((string)$o['remark']) . '</p>' : '')
            . ((string)($o['invoice_info'] ?? '') !== '' ? '<p class="rm">发票：' . e((string)$o['invoice_info']) . '</p>' : '')
            . '</div>';
        // 商品
        $html .= '<table class="mall-co-tbl"><tr><th>商品</th><th>单价</th><th>数量</th><th>小计</th></tr>';
        foreach ($goods as $g) {
            $cover = (string)$g['cover'];
            $img = $cover !== '' ? '<img src="' . e(mallThumb($cover)) . '" alt="">' : '<span class="mall-cart-nopic">无图</span>';
            $html .= '<tr><td class="c-goods"><div class="gd">' . $img . '<div class="tx"><span class="t">' . e((string)$g['title']) . '</span>'
                . '<span class="spec">' . e((string)$g['spec_text']) . '</span></div></div></td>'
                . '<td>¥' . e(number_format((float)$g['price'], 2, '.', ''))
                . ((float)($g['origin_price'] ?? 0) > (float)$g['price'] ? ' <s class="dim">¥' . e(number_format((float)$g['origin_price'], 2, '.', '')) . '</s>' : '')
                . (($g['promo_type'] ?? '') !== '' ? '<div class="mall-co-promo">' . e(self::promoLabel((string)$g['promo_type'])) . '</div>' : '')
                . '</td><td>' . (int)$g['num'] . '</td>'
                . '<td class="c-sum">¥' . e(number_format((float)$g['total_price'], 2, '.', '')) . '</td></tr>';
        }
        $html .= '</table>';
        // 金额
        $html .= '<div class="od-sums"><span>商品合计 <b>¥' . e(number_format((float)$o['total_goods_price'], 2, '.', '')) . '</b></span>'
            . '<span>运费 <b>' . ((float)$o['freight_price'] > 0 ? '¥' . e(number_format((float)$o['freight_price'], 2, '.', '')) : '包邮') . '</b></span>'
            . ((float)$o['discount_price'] > 0 ? '<span>优惠 <b>-¥' . e(number_format((float)$o['discount_price'], 2, '.', '')) . '</b></span>' : '')
            . ((float)$o['points_discount'] > 0 ? '<span>积分抵扣 <b>-¥' . e(number_format((float)$o['points_discount'], 2, '.', '')) . '</b>（消耗 ' . (int)$o['points_used'] . ' 积分）</span>' : '')
            . ((float)($o['promo_discount'] ?? 0) > 0 ? '<span>促销优惠 <b>-¥' . e(number_format((float)$o['promo_discount'], 2, '.', '')) . '</b></span>' : '')
            . '<span class="pay">实付 <i>¥</i><b>' . e(number_format((float)$o['pay_price'], 2, '.', '')) . '</b></span></div>';
        // M12：物流信息 / 轨迹
        $html .= self::logisticsBox($o);
        // 日志
        $html .= '<div class="od-logs"><b>订单动态</b>';
        foreach ($logs as $lg) {
            $html .= '<div class="li"><span class="d">' . e((string)$lg['created_at']) . '</span><span class="a">' . e((string)$lg['detail']) . '</span></div>';
        }
        $html .= '</div>';
        $html .= '</div></div></div>';
        $html .= self::footer();
        return ['html' => $html, 'title' => '订单 ' . $no . ' - 商城'];
    }

    /* ================= M5：支付 ================= */

    /** 已启用的支付渠道（mock 常驻；alipay/wxpay 按后台开关开启） */
    private static function payChannels(): array
    {
        $chs = ['mock'];
        if (get_option('mall.pay_alipay_on', '1') === '1') { $chs[] = 'alipay'; }
        if (get_option('mall.pay_wxpay_on', '1') === '1') { $chs[] = 'wxpay'; }
        // 扫码收款（微信/支付宝收款码）：开启且至少配置一个收款码
        if (get_option('mall.pay_qr_on', '0') === '1'
            && (trim((string)get_option('mall.pay_wx_qr', '')) !== '' || trim((string)get_option('mall.pay_ali_qr', '')) !== '')) {
            $chs[] = 'qr';
        }
        return $chs;
    }

    /** 读取某渠道商户配置（来自 ye_options，mall.pay_{driver}_* 前缀） */
    private static function payCfg(string $driver): array
    {
        if ($driver === 'alipay') {
            return [
                'app_id' => get_option('mall.pay_alipay_app_id', ''),
                'private_key' => get_option('mall.pay_alipay_private_key', ''),
                'public_key' => get_option('mall.pay_alipay_public_key', ''),
                'sandbox' => get_option('mall.pay_alipay_sandbox', '0') === '1',
                'gateway' => get_option('mall.pay_alipay_gateway', ''),
                // 默认空=校验证书；仅在无 CA 的极端本地调试时才设为 1（见 Alipay::verifySsl）
                'insecure_ssl' => get_option('mall.pay_alipay_insecure_ssl', '0'),
            ];
        }
        if ($driver === 'wxpay') {
            return [
                'mch_id' => get_option('mall.pay_wxpay_mch_id', ''),
                'app_id' => get_option('mall.pay_wxpay_app_id', ''),
                'key' => get_option('mall.pay_wxpay_key', ''),
                'secret' => get_option('mall.pay_wxpay_secret', ''),
                'sandbox' => get_option('mall.pay_wxpay_sandbox', '0') === '1',
                'sandbox_key' => get_option('mall.pay_wxpay_sandbox_key', ''),
                'gateway' => get_option('mall.pay_wxpay_gateway', ''),
                'cert_path' => get_option('mall.pay_wxpay_cert', ''),
                'key_path' => get_option('mall.pay_wxpay_keyfile', ''),
                // 默认空=校验证书；仅在无 CA 的极端本地调试时才设为 1（见 Wxpay::verifySsl）
                'insecure_ssl' => get_option('mall.pay_wxpay_insecure_ssl', '0'),
            ];
        }
        if ($driver === 'qr') {
            return [
                'payee' => get_option('mall.pay_payee', ''),
                'wx_account' => get_option('mall.pay_wx_account', ''),
                'wx_qr' => get_option('mall.pay_wx_qr', ''),
                'ali_account' => get_option('mall.pay_ali_account', ''),
                'ali_qr' => get_option('mall.pay_ali_qr', ''),
                'note' => get_option('mall.pay_qr_note', ''),
            ];
        }
        return [];
    }

    /** 收银台渠道状态：ready=可直接付 / sim=沙箱模拟 / off=未配置 */
    private static function payChannelStates(): array
    {
        // 沙箱入口是否展示：走统一守卫（开关 + 仅本机/内网），与 pay_submit 保持一致
        $sim = self::sandboxAllowed();
        $out = [];
        foreach (self::payChannels() as $ch) {
            if ($ch === 'mock') {
                $out[] = ['key' => 'mock', 'label' => '模拟支付（测试）', 'ic' => '模', 'state' => 'ready', 'note' => '即时到账·可本地测试'];
                continue;
            }
            if ($ch === 'qr') {
                $out[] = ['key' => 'qr', 'label' => '扫码支付', 'ic' => '码', 'state' => 'ready', 'note' => '微信/支付宝收款码'];
                continue;
            }
            $cfg = self::payCfg($ch);
            if ($ch === 'alipay') { $driver = new \MallPay\Alipay($cfg); $label = '支付宝'; $ic = '支'; }
            else { $driver = new \MallPay\Wxpay($cfg); $label = '微信支付'; $ic = '微'; }
            if ($driver->configured()) {
                $term = $ch === 'alipay' ? 'PC+手机网站' : 'PC扫码+手机H5+微信内';
                $out[] = ['key' => $ch, 'label' => $label, 'ic' => $ic, 'state' => 'ready', 'note' => $term . (!empty($cfg['sandbox']) ? '·沙箱' : '')];
            } elseif ($sim) {
                $out[] = ['key' => $ch, 'label' => $label, 'ic' => $ic, 'state' => 'sim', 'note' => '沙箱模拟·可本地测试'];
            } else {
                $out[] = ['key' => $ch, 'label' => $label, 'ic' => $ic, 'state' => 'off', 'note' => '未配置商户号'];
            }
        }
        return $out;
    }

    /**
     * 支付核心闭环（幂等）：本地 mock 通道与外部 notify 回调共用。
     * 校验订单状态/金额 → payment_log（trade_no UNIQUE 幂等）→ 订单 0→1 → pay 日志。
     * 返回 'success' 或错误文案。
     */
    private static function payApplyNotify(string $driver, string $orderNo, string $tradeNo, float $money, string $raw = ''): string
    {
        $o = Db::row('SELECT id, order_no, uid, status, pay_price FROM ' . self::t('order') . ' WHERE order_no=? LIMIT 1', [$orderNo]);
        if ($o === null) { return 'order not found'; }
        if ((int)$o['status'] !== 0) { return 'success'; } // 已支付/已取消：幂等应答
        if (abs((float)$o['pay_price'] - round($money, 2)) > 0.01) { return 'amount mismatch'; }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $dup = Db::row('SELECT id FROM ' . self::t('payment_log') . ' WHERE trade_no=? LIMIT 1', [$tradeNo]);
            if ($dup !== null) { $pdo->rollBack(); return 'success'; } // 重复通知：幂等
            Db::insert('mall_payment_log', [
                'out_trade_no' => $orderNo, 'order_no' => $orderNo, 'pay_type' => $driver,
                'trade_no' => $tradeNo, 'amount' => round($money, 2), 'status' => 1,
                'notify_raw' => $raw, 'notify_at' => date('Y-m-d H:i:s'),
            ]);
            $st = Db::run('UPDATE ' . self::t('order') . ' SET status=1, pay_time=NOW(), pay_type=? WHERE order_no=? AND status=0', [$driver, $orderNo]);
            if ($st->rowCount() === 0) { throw new RuntimeException('order status race'); }
            Db::insert('mall_order_log', ['order_id' => (int)$o['id'], 'order_no' => $orderNo, 'action' => 'pay', 'detail' => '支付成功（' . $driver . '），流水号 ' . $tradeNo, 'operator' => 'system']);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return 'fail: ' . $e->getMessage();
        }
        // 支付成功后会员侧副作用（赠积分 + 生成提货码），失败不影响主流程
        try { self::onPaid($o, $driver, $tradeNo); } catch (\Throwable $e) {}
        // M11 拼团成团判定 / M12 付款站内消息，失败不影响主流程
        try { self::promoAfterPaid($orderNo); } catch (\Throwable $e) {}
        try {
            self::msgSend((int)$o['uid'], 'pay', '付款成功',
                '订单 ' . $orderNo . ' 已付款 ¥' . number_format((float)$o['pay_price'], 2, '.', '') . '，商家将尽快发货。',
                '/order/' . rawurlencode($orderNo));
        } catch (\Throwable $e) {}
        return 'success';
    }

    /** 渠道回调验签（mock：共享密钥；alipay/wxpay：驱动内 RSA2 / HMAC-SHA256 验签） */
    private static function payVerifyNotify(string $driver, array $params): bool
    {
        if ($driver === 'mock') {
            $secret = (string)get_option('mall.pay_mock_secret', 'mock-secret');
            return $secret !== '' && hash_equals($secret, (string)($params['secret'] ?? ''));
        }
        if ($driver === 'alipay') {
            $alipay = new \MallPay\Alipay(self::payCfg('alipay'));
            return $alipay->configured() && $alipay->verify($params);
        }
        if ($driver === 'wxpay') {
            $wxpay = new \MallPay\Wxpay(self::payCfg('wxpay'));
            return $wxpay->configured() && $wxpay->verify($params);
        }
        return false;
    }

    /** 渠道异步回调入口：/mall/pay/notify/{driver}（echo + exit；alipay=纯文本，wxpay=XML） */
/**
     * 沙箱模拟支付是否允许（修阻塞 3）：
     *   ① 后台开关 mall.pay_sandbox_sim 必须显式开（默认关）
     *   ② 且请求来自本机/内网（生产域名下无论开关如何都不走沙箱）
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

    private static function sandboxAllowed(): bool
    {
        if (trim((string)get_option('mall.pay_sandbox_sim', '0')) !== '1') { return false; }
        return self::isLocalOrPrivateIp((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    private static function payNotify(string $driver): void
    {
        // ★ 回调端点绝不向外部输出任何内部信息。
        //   本函数体内的一切警告/输出都被下面的缓冲接管，对外只返回协议约定的报文。
        //   踩过的坑：DEBUG=true 时 display_errors=1，simplexml 的警告会把
        //   "/var/www/html/plugins/mall/lib.php on line 2612" 回显给**未认证**的 POST，
        //   泄露服务端路径与行号。XXE 本身不可利用（已实测），但错误回显就是信息泄露。
        while (ob_get_level() > 0) { ob_end_clean(); }
        ob_start();
        try {
            self::payNotifyInner($driver);
        } catch (Throwable $e) {
            error_log('[mall payNotify ' . $driver . '] ' . $e->getMessage());
            if ($driver === 'wxpay') {
                header('Content-Type: text/xml; charset=utf-8');
                echo '<xml><return_code>FAIL</return_code><return_msg>error</return_msg></xml>';
            } else {
                header('Content-Type: text/plain; charset=utf-8');
                echo 'failure';
            }
        }
        // 丢弃缓冲区内的杂输出（PHP 警告/Notice），只保留协议报文
        $out = (string)ob_get_clean();
        // 兜底：若警告与报文混在一起（display_errors=1），剥掉 HTML 警告片段
        $out = preg_replace('/<br\s*\/?>\s*<b>[^<]*<\/b>[^<]*/i', '', $out);
        $out = preg_replace('/<br\s*\/?>/i', '', $out);
        echo trim($out);
        exit;
    }

    /** 回调主体（只在 payNotify 的缓冲保护下调用） */
    private static function payNotifyInner(string $driver): void
    {
        $p = array_merge($_GET, $_POST);
        $body = '';
        if ($driver === 'wxpay') {
            $body = (string)(file_get_contents('php://input') ?? '');
            // LIBXML_NONET：解析时禁止访问网络（纵深防御）
            // libxml_use_internal_errors：解析错误进内部收集，不产生 PHP 警告
            $prev = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            if ($xml !== false) {
                foreach ((array)$xml as $k => $v) { $p[(string)$k] = (string)$v; }
            }
        }
        if (!self::payVerifyNotify($driver, $p)) {
            if ($driver === 'wxpay') {
                header('Content-Type: text/xml; charset=utf-8');
                echo '<xml><return_code>FAIL</return_code><return_msg>verify fail</return_msg></xml>';
            } else {
                header('Content-Type: text/plain; charset=utf-8');
                echo 'verify fail';
            }
            exit;
        }
        $out = trim((string)($p['out_trade_no'] ?? ''));
        $tradeNo = trim((string)($p['trade_no'] ?? ($p['transaction_id'] ?? '')));
        $money = $driver === 'alipay' ? (float)($p['total_amount'] ?? 0) : (float)((int)($p['total_fee'] ?? 0)) / 100;
        if ($out === '' || $tradeNo === '') { echo 'bad request'; exit; }
        $raw = $driver === 'wxpay' ? $body : http_build_query($p);
        $res = self::payApplyNotify($driver, $out, $tradeNo, $money, $raw);
        if ($driver === 'wxpay') {
            header('Content-Type: text/xml; charset=utf-8');
            echo $res === 'success' ? '<xml><return_code>SUCCESS</return_code></xml>' : '<xml><return_code>FAIL</return_code></xml>';
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo $res === 'success' ? 'success' : 'failure';
        }
        exit;
    }

    /* ================= 页面：收银台 ================= */

    private static function payPage(array $path, array $query): array
    {
        $cats = self::categories();
        $uid = self::uid();
        $no = (string)($path[1] ?? '');
        $html = self::css() . self::topbar() . self::header() . self::navBar($cats);
        $html .= '<div class="mall-crumb"><div class="mall-w"><a href="/mall">商城</a> <i>&gt;</i> <a href="/order">我的订单</a> <i>&gt;</i> <span>收银台</span></div></div>';
        $html .= self::flash();
        $o = $no !== '' && $uid > 0
            ? Db::row('SELECT * FROM ' . self::t('order') . ' WHERE order_no=? AND uid=? LIMIT 1', [$no, $uid])
            : null;
        if ($o === null) {
            $html .= '<div class="mall-panel"><div class="mall-w">' . self::loginGuide('/order', '登录后在收银台支付') . '</div></div>' . self::footer();
            return ['html' => $html, 'title' => '收银台 - 商城'];
        }
        $goods = Db::rows('SELECT title, spec_text, num, total_price FROM ' . self::t('order_goods') . ' WHERE order_id=?', [(int)$o['id']]);
        $logs = Db::rows('SELECT * FROM ' . self::t('payment_log') . ' WHERE order_no=? ORDER BY id DESC', [$no]);
        $status = (int)$o['status'];

        $html .= '<div class="mall-panel"><div class="mall-w"><div class="mall-pay">';
        $html .= '<div class="pay-hd"><div class="l"><span class="lb">应付金额</span><span class="amt"><i>¥</i><b>' . e(number_format((float)$o['pay_price'], 2, '.', '')) . '</b></span></div>'
            . '<div class="r"><span class="no">订单号 ' . e((string)$o['order_no']) . '</span>'
            . '<span class="st s' . $status . '">' . e(self::orderStatus($status)) . '</span></div></div>';
        if ($status !== 0) {
            $html .= '<div class="pay-note">该订单当前状态为「' . e(self::orderStatus($status)) . '」' . ($status === 1 ? '，支付已完成' : '，无需支付') . '。</div>'
                . '<div class="pay-acts"><a class="mall-btn-buy" href="/order/' . e($no) . '">查看订单</a></div>';
        } else {
            $html .= '<div class="pay-goods">';
            foreach ($goods as $g) {
                $html .= '<div class="li"><span class="t">' . e((string)$g['title']) . ' <span class="spec">' . e((string)$g['spec_text']) . '</span></span>'
                    . '<span class="n">× ' . (int)$g['num'] . '</span><span class="s">¥' . e(number_format((float)$g['total_price'], 2, '.', '')) . '</span></div>';
            }
            $html .= '</div>';
            $html .= '<form method="post" action="/pay/' . e($no) . '" class="pay-form">'
                . Csrf::field()
                . '<input type="hidden" name="act" value="pay_submit">'
                . '<input type="hidden" name="order_no" value="' . e($no) . '">';
            $html .= '<div class="pay-chans">';
            $firstReady = true;
            foreach (self::payChannelStates() as $c) {
                $off = $c['state'] === 'off';
                $cls = 'chan' . ($off ? ' dis' : '') . ($firstReady && !$off ? ' on' : '');
                if (!$off) { $firstReady = false; }
                $checked = !$off && $c['key'] === 'mock' ? ' checked' : '';
                $html .= '<label class="' . $cls . '"><input type="radio" name="chan" value="' . e($c['key']) . '"' . ($off ? ' disabled' : '') . $checked . '>'
                    . '<span class="ic">' . e($c['ic']) . '</span><span class="n">' . e($c['label']) . '</span><em>' . e($c['note']) . '</em></label>';
            }
            $html .= '</div><div class="pay-acts"><button type="submit" class="mall-btn-buy">立即支付</button>'
                . '<a class="mall-btn-plain" href="/order/' . e($no) . '">稍后支付</a></div>'
                . '<p class="dim pay-tip">下单后请尽快完成支付；未支付订单可随时在「我的订单」取消。</p></form>';
        }
        if ($logs) {
            $html .= '<div class="pay-logs"><b>支付记录</b>';
            foreach ($logs as $lg) {
                $html .= '<div class="li"><span class="d">' . e((string)$lg['created_at']) . '</span>'
                    . '<span class="t">' . e((string)$lg['pay_type']) . ' · ' . e((string)$lg['trade_no']) . '</span>'
                    . '<span class="s">' . ((int)$lg['status'] === 1 ? '成功' : ((int)$lg['status'] === 2 ? '失败' : '待支付')) . ' ¥' . e(number_format((float)$lg['amount'], 2, '.', '')) . '</span></div>';
            }
            $html .= '</div>';
        }
        $html .= '</div></div></div>';
        $html .= self::footer();
        return ['html' => $html, 'title' => '收银台 - 商城'];
    }

    /**
     * 沙箱模拟收银台：/mall/pay/sim/{driver}/{order_no}
     * 渠道未配置商户号时由「沙箱模拟」开关启用，本地即可端到端测试支付宝/微信付款闭环。
     */
    private static function paySimPage(string $driver, string $no): array
    {
        $cats = self::categories();
        $uid = self::uid();
        $html = self::css() . self::topbar() . self::header() . self::navBar($cats);
        $html .= '<div class="mall-crumb"><div class="mall-w"><a href="/mall">商城</a> <i>&gt;</i> <a href="/order">我的订单</a> <i>&gt;</i> <span>收银台</span></div></div>';
        if ($uid <= 0) {
            $html .= '<div class="mall-panel"><div class="mall-w">' . self::loginGuide('/pay/sim/' . $driver . '/' . $no, '登录后支付') . '</div></div>' . self::footer();
            return ['html' => $html, 'title' => '收银台 - 商城'];
        }
        if ($driver !== 'alipay' && $driver !== 'wxpay') { self::go('/pay/' . rawurlencode($no)); }
        $o = Db::row('SELECT * FROM ' . self::t('order') . ' WHERE order_no=? AND uid=? LIMIT 1', [$no, $uid]);
        if ($o === null || (int)$o['status'] !== 0) { self::go('/order/' . rawurlencode($no), '订单不可支付'); }
        $brand = $driver === 'alipay'
            ? ['name' => '支付宝', 'cls' => 'ali', 'ic' => '支', 'tip' => '支付宝沙箱模拟收银台（本地测试用，不会产生真实扣款）']
            : ['name' => '微信支付', 'cls' => 'wx', 'ic' => '微', 'tip' => '微信支付沙箱模拟收银台（本地测试用，不会产生真实扣款）'];
        $html .= '<div class="mall-panel"><div class="mall-w"><div class="mall-sim ' . $brand['cls'] . '">'
            . '<div class="sim-hd"><span class="sim-ic">' . e($brand['ic']) . '</span>' . e($brand['name']) . ' <em>沙箱模拟</em></div>'
            . '<div class="sim-amt"><span>¥</span><b>' . e(number_format((float)$o['pay_price'], 2, '.', '')) . '</b></div>'
            . '<div class="sim-no">订单号 ' . e($no) . '</div>'
            . '<form method="post" action="/pay/' . e($no) . '" class="sim-form">'
            . Csrf::field()
            . '<input type="hidden" name="act" value="pay_sim_confirm">'
            . '<input type="hidden" name="order_no" value="' . e($no) . '">'
            . '<input type="hidden" name="chan" value="' . e($driver) . '">'
            . '<button type="submit" class="sim-pay">确认付款（模拟）</button>'
            . '</form>'
            . '<p class="sim-tip">' . e($brand['tip']) . '</p>'
            . '<a class="sim-back" href="/pay/' . e($no) . '">返回选择支付方式</a>'
            . '</div></div></div>' . self::footer();
        return ['html' => $html, 'title' => $brand['name'] . '收银台 - 商城'];
    }

    /**
     * 微信 NATIVE 扫码页：/mall/pay/qr/wxpay/{order_no}（真实渠道已配置时）
     * 展示统一下单返回的 code_url，由前端/第三方生成二维码供微信扫码。
     */
    private static function payQrPage(string $driver, string $no): array
    {
        $cats = self::categories();
        $uid = self::uid();
        $html = self::css() . self::topbar() . self::header() . self::navBar($cats);
        $html .= '<div class="mall-crumb"><div class="mall-w"><a href="/mall">商城</a> <i>&gt;</i> <a href="/order">我的订单</a> <i>&gt;</i> <span>收银台</span></div></div>';
        if ($uid <= 0) {
            $html .= '<div class="mall-panel"><div class="mall-w">' . self::loginGuide('/pay/qr/' . $driver . '/' . $no, '登录后支付') . '</div></div>' . self::footer();
            return ['html' => $html, 'title' => '收银台 - 商城'];
        }
        if ($driver === 'qr') { return self::payCollectPage($no); }
        if ($driver !== 'wxpay') { self::go('/pay/' . rawurlencode($no)); }
        $o = Db::row('SELECT * FROM ' . self::t('order') . ' WHERE order_no=? AND uid=? LIMIT 1', [$no, $uid]);
        if ($o === null || (int)$o['status'] !== 0) { self::go('/order/' . rawurlencode($no), '订单不可支付'); }
        $codeUrl = (string)($_SESSION['mall_wx_codeurl'] ?? '');
        if ($codeUrl === '') { self::go('/pay/' . rawurlencode($no), '二维码已失效，请重新下单'); }
        $html .= '<div class="mall-panel"><div class="mall-w"><div class="mall-sim wx">'
            . '<div class="sim-hd"><span class="sim-ic">微</span>微信支付 <em>扫码支付</em></div>'
            . '<div class="sim-qr"><code>' . e($codeUrl) . '</code></div>'
            . '<p class="sim-tip">请用微信「扫一扫」扫描上方 code_url 对应的二维码完成支付（真实商户号生效时）。</p>'
            . '<a class="sim-back" href="/order/' . e($no) . '">查看订单</a>'
            . '</div></div></div>' . self::footer();
        return ['html' => $html, 'title' => '微信扫码支付 - 商城'];
    }

    /**
     * 扫码收款页：/pay/qr/qr/{order_no}
     * 展示后台配置的微信/支付宝收款码，顾客扫码付款后点「我已完成支付」闭环（订单置为已付，待商家在微信/支付宝后台核对）。
     */
    private static function payCollectPage(string $no): array
    {
        $cats = self::categories();
        $uid = self::uid();
        $html = self::css() . self::topbar() . self::header() . self::navBar($cats);
        $html .= '<div class="mall-crumb"><div class="mall-w"><a href="/mall">商城</a> <i>&gt;</i> <a href="/order">我的订单</a> <i>&gt;</i> <span>收银台</span></div></div>';
        if ($uid <= 0) {
            $html .= '<div class="mall-panel"><div class="mall-w">' . self::loginGuide('/pay/qr/qr/' . $no, '登录后支付') . '</div></div>' . self::footer();
            return ['html' => $html, 'title' => '收银台 - 商城'];
        }
        $o = Db::row('SELECT * FROM ' . self::t('order') . ' WHERE order_no=? AND uid=? LIMIT 1', [$no, $uid]);
        if ($o === null || (int)$o['status'] !== 0) { self::go('/order/' . rawurlencode($no), '订单不可支付'); }
        $cfg = self::payCfg('qr');
        $payee = $cfg['payee'] !== '' ? $cfg['payee'] : '本店';
        $note = $cfg['note'] !== '' ? $cfg['note'] : ('请使用微信或支付宝扫码支付；付款后点击「我已完成支付」，并备注订单号 ' . $no . '。商家将在收款后台核对后发货。');
        $html .= '<div class="mall-panel"><div class="mall-w"><div class="mall-collect">';
        $html .= '<div class="sim-hd"><span class="sim-ic">码</span>扫码支付 <em>微信 / 支付宝</em></div>';
        $html .= '<div class="sim-amt"><span>¥</span><b>' . e(number_format((float)$o['pay_price'], 2, '.', '')) . '</b></div>';
        $html .= '<div class="sim-no">订单号 ' . e($no) . '</div>';
        $html .= '<div class="collect-payee">收款方：' . e($payee) . '</div>';
        $html .= '<div class="collect-qrs">';
        if ($cfg['wx_qr'] !== '') {
            $html .= '<div class="cqr wx"><div class="cqr-ic">微</div><div class="cqr-t">微信支付</div>'
                . '<img class="cqr-img" src="' . e($cfg['wx_qr']) . '" alt="微信收款码">'
                . ($cfg['wx_account'] !== '' ? '<div class="cqr-acc">账号：' . e($cfg['wx_account']) . '</div>' : '')
                . '</div>';
        }
        if ($cfg['ali_qr'] !== '') {
            $html .= '<div class="cqr ali"><div class="cqr-ic">支</div><div class="cqr-t">支付宝</div>'
                . '<img class="cqr-img" src="' . e($cfg['ali_qr']) . '" alt="支付宝收款码">'
                . ($cfg['ali_account'] !== '' ? '<div class="cqr-acc">账号：' . e($cfg['ali_account']) . '</div>' : '')
                . '</div>';
        }
        $html .= '</div>';
        $html .= '<p class="sim-tip">' . e($note) . '</p>';
        $html .= '<form method="post" action="/pay/' . e($no) . '" class="sim-form">'
            . Csrf::field()
            . '<input type="hidden" name="act" value="pay_qr_confirm">'
            . '<input type="hidden" name="order_no" value="' . e($no) . '">'
            . '<button type="submit" class="sim-pay">我已完成支付</button>'
            . '</form>';
        $html .= '<a class="sim-back" href="/pay/' . e($no) . '">返回选择支付方式</a>';
        $html .= '</div></div></div>' . self::footer();
        return ['html' => $html, 'title' => '扫码支付 - 商城'];
    }

    /**
     * 微信 OAuth 授权地址（snsapi_base 静默取 openid），用于微信内 JSAPI 支付前置。
     */
    private static function wxOauthUrl(string $no): string
    {
        $cfg = self::payCfg('wxpay');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443' ? 'https' : 'http';
        $redirect = $scheme . '://' . $host . '/pay/wxoauth';
        return 'https://open.weixin.qq.com/connect/oauth2/authorize?appid=' . urlencode((string)($cfg['app_id'] ?? ''))
            . '&redirect_uri=' . urlencode($redirect) . '&response_type=code&scope=snsapi_base&state=' . urlencode($no) . '#wechat_redirect';
    }

    /** 微信 OAuth 回调：code → openid → 建 JSAPI 预支付 → 跳 wxjs 页（内部 header + exit） */
    private static function wxOauthPage(): void
    {
        while (ob_get_level() > 0) { ob_end_clean(); }
        $code = trim((string)($_GET['code'] ?? ''));
        $no = trim((string)($_GET['state'] ?? ''));
        if ($code === '') { header('Location: /pay/' . rawurlencode($no)); exit; }
        $wx = new \MallPay\Wxpay(self::payCfg('wxpay'));
        $r = $wx->openId($code);
        if (!$r['ok']) { header('Location: /pay/' . rawurlencode($no) . '?err=' . urlencode($r['msg'] ?? 'openid 获取失败')); exit; }
        $_SESSION['mall_wx_openid'] = $r['openid'];
        $msg = self::wxJsMake($no);
        if ($msg !== '') { header('Location: /pay/' . rawurlencode($no) . '?err=' . urlencode($msg)); exit; }
        header('Location: /pay/wxjs/' . rawurlencode($no)); exit;
    }

    /** 微信内 JSAPI 预下单：建单 + 取 pay_params 存会话；返回 '' 成功或错误文案 */
    private static function wxJsMake(string $no): string
    {
        $o = Db::row('SELECT id, order_no, uid, status, pay_price FROM ' . self::t('order') . ' WHERE order_no=? LIMIT 1', [$no]);
        if ($o === null) { return '订单不存在'; }
        if ((int)$o['status'] !== 0) { return '订单状态已变化，无需支付'; }
        $openid = (string)($_SESSION['mall_wx_openid'] ?? '');
        if ($openid === '') { return '未获取微信 openid'; }
        $cfg = self::payCfg('wxpay');
        $driver = new \MallPay\Wxpay($cfg);
        $order = ['order_no' => $no, 'pay_price' => (float)$o['pay_price'], 'subject' => self::brandName() . '订单' . $no];
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443' ? 'https' : 'http';
        $notify = $scheme . '://' . $host . '/pay/notify/wxpay';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $r = $driver->jsapiUnified($order, $notify, $ip, $openid);
        if (!$r['ok']) { return '微信下单失败：' . ($r['msg'] ?? ''); }
        $_SESSION['mall_wx_jsapi'] = $r['pay_params'];
        return '';
    }

    /**
     * 微信 JSAPI 调起页：/mall/pay/wxjs/{order_no}
     * 读取会话中的 pay_params，由前端 WeixinJSBridge.invoke 调起微信支付（仅微信内浏览器生效）。
     */
    private static function payWxJsPage(string $no): array
    {
        $cats = self::categories();
        $uid = self::uid();
        $html = self::css() . self::topbar() . self::header() . self::navBar($cats);
        $html .= '<div class="mall-crumb"><div class="mall-w"><a href="/mall">商城</a> <i>&gt;</i> <a href="/order">我的订单</a> <i>&gt;</i> <span>收银台</span></div></div>';
        if ($uid <= 0) {
            $html .= '<div class="mall-panel"><div class="mall-w">' . self::loginGuide('/pay/wxjs/' . $no, '登录后支付') . '</div></div>' . self::footer();
            return ['html' => $html, 'title' => '收银台 - 商城'];
        }
        $o = Db::row('SELECT * FROM ' . self::t('order') . ' WHERE order_no=? AND uid=? LIMIT 1', [$no, $uid]);
        if ($o === null || (int)$o['status'] !== 0) { self::go('/order/' . rawurlencode($no), '订单不可支付'); }
        $params = (array)($_SESSION['mall_wx_jsapi'] ?? []);
        $html .= '<div class="mall-panel"><div class="mall-w"><div class="mall-wxjs">'
            . '<div class="sim-hd"><span class="sim-ic">微</span>微信支付 <em>公众号支付</em></div>'
            . '<div class="sim-amt"><span>¥</span><b>' . e(number_format((float)$o['pay_price'], 2, '.', '')) . '</b></div>'
            . '<div class="sim-no">订单号 ' . e($no) . '</div>';
        if (!$params || !self::isWechat()) {
            $html .= '<p class="sim-tip">请在微信中打开本页面以调起微信支付。若已使用微信，请返回重新发起支付。</p>'
                . '<a class="sim-back" href="/pay/' . e($no) . '">返回选择支付方式</a></div></div></div>' . self::footer();
            return ['html' => $html, 'title' => '微信支付 - 商城'];
        }
        $json = json_encode($params, JSON_UNESCAPED_UNICODE);
        $html .= '<button type="button" id="wxjs-pay" class="sim-pay">立即支付</button>'
            . '<p class="sim-tip">点击后将在微信中调起支付。</p>'
            . '<a class="sim-back" href="/order/' . e($no) . '">查看订单</a>'
            . '<script>var WXPAY=' . $json . ';'
            . 'function wxJsCall(){if(typeof WeixinJSBridge==="undefined"){document.addEventListener("WeixinJSBridgeReady",wxJsCall,false);}else{WeixinJSBridge.invoke("getBrandWCPayRequest",WXPAY,function(r){if(r.err_msg==="get_brand_wcpay_request:ok"){location.href="/order/' . e($no) . '";}else{alert("支付未完成："+r.err_msg);}});}}'
            . 'document.getElementById("wxjs-pay").addEventListener("click",wxJsCall);wxJsCall();</script>'
            . '</div></div></div>' . self::footer();
        return ['html' => $html, 'title' => '微信支付 - 商城'];
    }

    /** 幂等建退款流水表（本地运行无迁移也可直接用） */
    /**
     * 发票信息列（幂等：列不存在才加）。
     * 对齐 51puer /member/invoice.html —— 本地茶店多为个人客户，
     * 不必做独立发票管理页，但结算时能填抬头/税号是 B2C 基本能力。
     */
    private static function ensureInvoiceColumn(): void
    {
        try {
            $t = self::t('order');
            $has = Db::rows('SHOW COLUMNS FROM ' . $t . " LIKE 'invoice_info'");
            if (!$has) {
                Db::run('ALTER TABLE ' . $t . ' ADD COLUMN invoice_info VARCHAR(255) NOT NULL DEFAULT ""');
            }
        } catch (Throwable $e) {
            // 加列失败不阻断下单（发票是附加信息）
        }
    }

    /** 发票类型 → 展示文本 */
    private static function invoiceText(array $post): string
    {
        $type = (string)($post['invoice_type'] ?? '0');
        if ($type === '0' || $type === '') { return ''; }
        $title = trim((string)($post['invoice_title'] ?? ''));
        $tax = trim((string)($post['invoice_taxno'] ?? ''));
        $kind = $type === '2' ? '增值税专用发票' : '增值税普通发票';
        if ($title === '') { return ''; }
        $s = $kind . '｜抬头：' . $title;
        if ($tax !== '') { $s .= '｜税号：' . $tax; }
        return mb_substr($s, 0, 255);
    }
    private static function ensureRefundTable(): void
    {
        Db::run('CREATE TABLE IF NOT EXISTS ' . self::t('refund_log') . ' ('
            . 'id INT AUTO_INCREMENT PRIMARY KEY,'
            . 'order_no VARCHAR(64) NOT NULL,'
            . 'pay_type VARCHAR(32) NOT NULL DEFAULT "",'
            . 'refund_no VARCHAR(64) NOT NULL,'
            . 'amount DECIMAL(10,2) NOT NULL DEFAULT 0,'
            . 'status TINYINT NOT NULL DEFAULT 0,'
            . 'reason VARCHAR(255) NOT NULL DEFAULT "",'
            . 'created_at DATETIME NOT NULL,'
            . 'KEY(order_no)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    /**
     * 退款闭环（admin 调用，public）。原路退回 + 记录 + 订单置「售后中」。
     * 已配置商户号走真实退款接口；本地/沙箱/扫码收款回退为人工记录。
     * 返回 'success'（通道受理）/ 'refund_recorded'（人工记录）/ 错误文案。
     */
    public static function refundOrder(string $orderNo, string $reason = ''): string
    {
        $o = Db::row('SELECT id, order_no, status, pay_price, pay_type FROM ' . self::t('order') . ' WHERE order_no=? LIMIT 1', [$orderNo]);
        if ($o === null) { return '订单不存在'; }
        $st = (int)$o['status'];
        if (!in_array($st, [1, 2, 3], true)) { return '仅「已付款」订单可退款'; }
        $driver = (string)$o['pay_type'];
        $refundNo = 'RF' . date('YmdHis') . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $realOk = false;
        $realMsg = '';
        if (in_array($driver, ['alipay', 'wxpay'], true)) {
            $cfg = self::payCfg($driver);
            if ($driver === 'alipay') {
                $al = new \MallPay\Alipay($cfg);
                if ($al->configured()) {
                    $rr = $al->refund(['order_no' => $orderNo, 'refund_no' => $refundNo, 'refund_amount' => (float)$o['pay_price'], 'reason' => $reason]);
                    $realOk = $rr['ok'];
                    $realMsg = $rr['msg'] ?? '';
                }
            } else {
                $wx = new \MallPay\Wxpay($cfg);
                if ($wx->configured()) {
                    $rr = $wx->refund(['order_no' => $orderNo, 'refund_no' => $refundNo, 'total_amount' => (float)$o['pay_price'], 'refund_amount' => (float)$o['pay_price'], 'reason' => $reason]);
                    $realOk = $rr['ok'];
                    $realMsg = $rr['msg'] ?? '';
                }
            }
        }
        self::ensureRefundTable();
        Db::insert('mall_refund_log', [
            'order_no' => $orderNo, 'pay_type' => $driver, 'refund_no' => $refundNo,
            'amount' => round((float)$o['pay_price'], 2), 'status' => $realOk ? 1 : 0,
            'reason' => $reason, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        Db::run('UPDATE ' . self::t('order') . ' SET status=5 WHERE order_no=? AND status IN (1,2,3)', [$orderNo]);
        Db::insert('mall_order_log', [
            'order_id' => (int)$o['id'], 'order_no' => $orderNo, 'action' => 'refund',
            'detail' => '退款 ¥' . number_format((float)$o['pay_price'], 2, '.', '') . '（' . ($realOk ? '通道已受理' : '沙箱/人工记录') . '）单号 ' . $refundNo,
            'operator' => 'admin',
        ]);
        return $realOk ? 'success' : 'refund_recorded';
    }

    /* ================= M6：优惠券 ================= */

    /** 可领券（启用、未删、在有效期、有余量） */
    private static function couponList(): array
    {
        return Db::rows('SELECT * FROM ' . self::t('coupon') . ' WHERE status=1 AND is_delete_time=0'
            . ' AND (start_time IS NULL OR start_time<=NOW()) AND (end_time IS NULL OR end_time>=NOW())'
            . ' AND issued_count<total_count ORDER BY id DESC');
    }

    private static function myCoupons(int $uid): array
    {
        return Db::rows('SELECT uc.*, c.name, c.type, c.threshold, c.amount, c.discount_rate, c.max_discount, c.end_time FROM ' . self::t('user_coupon') . ' uc'
            . ' JOIN ' . self::t('coupon') . ' c ON c.id=uc.coupon_id'
            . ' WHERE uc.uid=? ORDER BY uc.status ASC, uc.received_at DESC', [$uid]);
    }

    /** 领取：限领校验 + 余量条件扣减（防超发） */
    private static function couponGet(int $uid, int $couponId): void
    {
        $c = Db::row('SELECT id, total_count, issued_count, per_user_limit, status, is_delete_time, start_time, end_time'
            . ' FROM ' . self::t('coupon') . ' WHERE id=? LIMIT 1', [$couponId]);
        if ($c === null || (int)$c['status'] !== 1 || (int)$c['is_delete_time'] > 0) { self::go('/coupon/', '优惠券不存在或已停用'); }
        $now = date('Y-m-d H:i:s');
        if (($c['start_time'] !== null && (string)$c['start_time'] > $now) || ($c['end_time'] !== null && (string)$c['end_time'] < $now)) { self::go('/coupon/', '不在领取时间内'); }
        $got = (int)Db::one('SELECT COUNT(*) FROM ' . self::t('user_coupon') . ' WHERE coupon_id=? AND uid=?', [$couponId, $uid]);
        if ($got >= max(1, (int)$c['per_user_limit'])) { self::go('/coupon/', '已达每人限领数量'); }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $st = Db::run('UPDATE ' . self::t('coupon') . ' SET issued_count=issued_count+1 WHERE id=? AND issued_count<total_count', [$couponId]);
            if ($st->rowCount() === 0) { throw new RuntimeException('该券已领完'); }
            Db::insert('mall_user_coupon', ['coupon_id' => $couponId, 'uid' => $uid, 'status' => 0]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            self::go('/coupon/', '领取失败：' . $e->getMessage());
        }
    }

    /** 结算可用券（未用 + 未过期 + 门槛满足）；返回字段含 type/discount_rate/max_discount 与即时优惠额 discount_now */
    private static function usableCoupons(int $uid, float $goodsTotal): array
    {
        $now = date('Y-m-d H:i:s');
        $rows = Db::rows('SELECT uc.id ucid, c.name, c.type, c.threshold, c.amount, c.discount_rate, c.max_discount'
            . ' FROM ' . self::t('user_coupon') . ' uc'
            . ' JOIN ' . self::t('coupon') . ' c ON c.id=uc.coupon_id'
            . ' WHERE uc.uid=? AND uc.status=0 AND c.threshold<=? AND (c.end_time IS NULL OR c.end_time>=?)'
            . ' ORDER BY c.amount DESC', [$uid, $goodsTotal, $now]);
        foreach ($rows as &$r) { $r['discount_now'] = self::couponDiscount($r, $goodsTotal); }
        unset($r);
        return $rows;
    }

    /** 单券针对某金额的优惠额（满减/折扣通用） */
    private static function couponDiscount(array $c, float $total): float
    {
        if ((float)$c['threshold'] > $total) { return 0.0; }
        if ((int)$c['type'] === 2) { // 折扣券
            $d = round($total * (1 - (float)$c['discount_rate']), 2);
            $cap = (float)$c['max_discount'];
            if ($cap > 0) { $d = min($d, $cap); }
            return round($d, 2);
        }
        return round(min((float)$c['amount'], $total), 2); // 满减券
    }

    /* ================= M10：积分抵扣 ================= */

    /** 积分抵扣可用额度：返回可用积分 / 兑换比例 / 最多可用积分 / 最多可抵金额 */
    private static function usablePoints(int $uid, float $payable): array
    {
        $m = self::memberRow($uid);
        $avail = (int)$m['points'];
        $rate = (float)get_option('mall.points_rate', 100); // 多少积分抵 ¥1
        if ($rate <= 0) { $rate = 100; }
        $maxRate = (float)get_option('mall.points_max_rate', 0.5); // 积分最多抵扣应付比例
        if ($maxRate < 0) { $maxRate = 0; }
        if ($maxRate > 1) { $maxRate = 1; }
        $maxByMoney = (int)floor(max(0.0, $payable) * $maxRate * $rate);
        $maxUse = (int)min($avail, $maxByMoney);
        if ($maxUse < 0) { $maxUse = 0; }
        return ['avail' => $avail, 'rate' => $rate, 'max_use' => $maxUse, 'max_discount' => round($maxUse / $rate, 2)];
    }

    /** 给定使用积分数 → 抵扣金额（不超过应付） */
    private static function pointsDiscount(int $usePoints, float $payable): float
    {
        $use = (int)$usePoints;
        if ($use <= 0) { return 0.0; }
        $rate = (float)get_option('mall.points_rate', 100);
        if ($rate <= 0) { $rate = 100; }
        return round(min($use / $rate, max(0.0, $payable)), 2);
    }

    /** 新人券自动发放：用户首次进入领券中心时，把 grant_newcomer=1 且未拥有的券发给他（受余量/限领约束） */
    private static function grantNewcomerCoupons(int $uid): void
    {
        if ($uid <= 0) { return; }
        $list = Db::rows('SELECT * FROM ' . self::t('coupon') . ' WHERE grant_newcomer=1 AND status=1 AND is_delete_time=0'
            . ' AND (start_time IS NULL OR start_time<=NOW()) AND (end_time IS NULL OR end_time>=NOW())');
        foreach ($list as $c) {
            $got = (int)Db::one('SELECT COUNT(*) FROM ' . self::t('user_coupon') . ' WHERE coupon_id=? AND uid=?', [(int)$c['id'], $uid]);
            if ($got >= max(1, (int)$c['per_user_limit'])) { continue; }
            if ((int)$c['issued_count'] >= (int)$c['total_count']) { continue; }
            $pdo = Db::pdo();
            $pdo->beginTransaction();
            try {
                $st = Db::run('UPDATE ' . self::t('coupon') . ' SET issued_count=issued_count+1 WHERE id=? AND issued_count<total_count', [(int)$c['id']]);
                if ($st->rowCount() === 0) { $pdo->rollBack(); continue; }
                Db::insert('mall_user_coupon', ['coupon_id' => (int)$c['id'], 'uid' => $uid, 'status' => 0]);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
            }
        }
    }

    /* ================= 页面：领券中心 / 我的券包 ================= */

    /**
     * 帮助中心 /help（对齐 51puer /help.html）
     *
     * 内容来源优先用博客已有单页（ye_contents type='page'，slug 以 help- 开头），
     * 没有时展示商城自身的服务政策 —— 保证这一页任何时候都有实际内容，
     * 不会出现"帮助中心是空页"这种更糟的情况。
     */
    /**
     * 帮助中心（板块 + 文章结构，对齐 51puer）
     * 数据源：plugins/mall/help_data.php（由 51puer 帮助中心清洗迁移而来）
     * 路由：/help、/help/{section}、/help/{section}/{n}
     */
    private static function helpPage(array $path): array
    {
        $cats = self::categories();
        $data = require __DIR__ . '/help_data.php';
        $sections = $data['sections'] ?? [];

        // 解析路由
        $curSec = (string)($path[1] ?? '');
        $curIdx = (int)($path[2] ?? 0);
        $secIdx = 0;
        foreach ($sections as $i => $s) {
            if ((string)($s['slug'] ?? '') === $curSec) { $secIdx = $i; break; }
        }
        if ($curSec === '') { $secIdx = 0; }
        $sec = $sections[$secIdx] ?? null;
        if ($sec === null) { return self::notFound(); }
        $arts = $sec['articles'] ?? [];
        if (!isset($arts[$curIdx])) { $curIdx = 0; }
        $art = $arts[$curIdx] ?? null;

        $html = self::css() . self::topbar() . self::header() . self::navBar($cats);
        // ★ 注意运算符优先级：三元表达式必须整体括号，否则会与前面的 . 连接串错
        $crumb = '<div class="mall-crumb"><div class="mall-w"><a href="/">首页</a> <i>&gt;</i>'
            . ' <a href="/help">帮助中心</a> <i>&gt;</i> <span>' . e((string)$sec['name']) . '</span>'
            . '</div></div>';
        $html .= $crumb;
        $html .= self::flash();

        // ★★ 搜索框必须先定义：$nav 的拼接里用到了 $searchBox，
        //    原实现把定义放在 $nav 之后 → Undefined variable $searchBox，
        //    且侧边栏搜索框不渲染（用户无法搜索）。
        // ★ 帮助中心搜索（跨全部文章搜标题与正文）
        $q = trim((string)($_GET['q'] ?? ''));
        $searchBox = '<div class="mall-help-search">'
            . '<form method="get" action="/help"><input type="search" name="q" value="' . e($q) . '" placeholder="搜索帮助内容"><button type="submit">搜索</button></form>'
            . '</div>';
        // 左侧：板块 + 文章列表
        $nav = $searchBox . '<div class="mall-help-nav"><h3>帮助中心</h3><ul>';
        foreach ($sections as $i => $s) {
            $on = $i === $secIdx ? ' class="on"' : '';
            $nav .= '<li><a' . $on . ' href="/help/' . e((string)$s['slug']) . '">' . e((string)$s['name']) . '</a></li>';
            foreach (($s['articles'] ?? []) as $k => $a) {
                if ($i !== $secIdx) { continue; }
                $aon = $k === $curIdx ? ' class="on"' : '';
                $nav .= '<li class="sub"><a' . $aon . ' href="/help/' . e((string)$s['slug']) . '/' . $k . '">'
                    . e((string)$a['title']) . '</a></li>';
            }
        }
        $nav .= '</ul></div>';

        $hits = [];
        if ($q !== '') {
            $needle = mb_strtolower($q);
            foreach ($sections as $si => $ss) {
                foreach (($ss['articles'] ?? []) as $ai => $aa) {
                    $hay = mb_strtolower((string)$aa['title'] . ' ' . strip_tags((string)$aa['body']));
                    if (mb_strpos($hay, $needle) === false) { continue; }
                    // 截取命中上下文片段
                    $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string)$aa['body'])));
                    $pp = mb_strpos(mb_strtolower($plain), $needle);
                    $snip = $pp === false ? mb_substr($plain, 0, 90)
                        : (($pp > 24 ? '…' : '') . mb_substr($plain, max(0, $pp - 24), 90) . '…');
                    $hits[] = [$ss['name'], $si, $ai, (string)$aa['title'], $snip];
                }
            }
        }

        // 右侧：正文
        $freeFrom = (float)get_option('mall.free_from', '51');
        $fee = (float)get_option('mall.freight_fee', '10');
        $notice = trim((string)get_option('mall.notice', ''));
        $name = self::brandName();

        // 搜索模式：优先展示结果列表
        if ($q !== '') {
            $body = '<h2>搜索「' . e($q) . '」</h2>';
            if ($hits === []) {
                $body .= '<p class="dim">没有找到相关内容。可以换个关键词，或联系客服协助。</p>';
            } else {
                $body .= '<p class="dim">共找到 ' . count($hits) . ' 条：</p><ul class="mall-help-hits">';
                foreach ($hits as $hh) {
                    $body .= '<li><a href="/help/' . e((string)$sections[$hh[1]]['slug']) . '/' . (int)$hh[2] . '">'
                        . e($hh[3]) . '</a><span class="dim">' . e($hh[0]) . ' · ' . e($hh[4]) . '</span></li>';
                }
                $body .= '</ul>';
            }
            $body .= '<p style="margin-top:16px"><a href="/help">返回帮助中心首页</a></p>';
            $html .= '<div class="mall-panel"><div class="mall-w"><div class="mall-help-wrap">'
                . $searchBox . '<div class="mall-help-main">' . $body . '</div></div></div></div>' . self::footer();
            return ['html' => $html, 'title' => '搜索「' . $q . '」 - 帮助中心 · ' . $name];
        }

        $body = '<h2>' . e((string)($art['title'] ?? $sec['name'])) . '</h2>';
        // 顶部政策速览（每次都显示，且数字与结算一致）
        $body .= '<div class="mall-note" style="margin-bottom:14px"><b>当前政策：</b>'
            . '单笔满 ¥' . number_format($freeFrom, 2) . ' 包邮（未满收 ¥' . number_format($fee, 2) . '）；'
            . '支持 <b>15 天</b>无理由退换货；暂不支持货到付款。</div>';
        $body .= ($art['body'] ?? '');
        if ($notice !== '') {
            $body .= '<div class="mall-note" style="margin-top:16px"><b>商城公告：</b>' . e($notice) . '</div>';
        }
        $body .= '<p class="dim" style="color:#999;font-size:12.5px;margin-top:18px">'
            . '以上为 ' . e($name) . ' 的服务说明。若与结算页实际显示不一致，以结算页为准。</p>';

        // 同板块文章互链
        if (count($arts) > 1) {
            $rel = '';
            foreach ($arts as $k => $a) {
                if ($k === $curIdx) { continue; }
                $rel .= '<li><a href="/help/' . e((string)$sec['slug']) . '/' . $k . '">' . e((string)$a['title']) . '</a></li>';
            }
            if ($rel !== '') {
                $body .= '<div style="margin-top:22px"><h4>本板块其他内容</h4><ul>' . $rel . '</ul></div>';
            }
        }

        $html .= '<div class="mall-panel"><div class="mall-w"><div class="mall-help-wrap">'
            . $nav . '<div class="mall-help-main">' . $body . '</div>'
            . '</div></div></div>' . self::footer();

        return ['html' => $html, 'title' => (string)($art['title'] ?? $sec['name']) . ' - 帮助中心 · ' . $name];
    }
    private static function couponPage(array $query): array
    {
        $cats = self::categories();
        $uid = self::uid();
        $html = self::css() . self::topbar() . self::header() . self::navBar($cats);
        $html .= '<div class="mall-crumb"><div class="mall-w"><a href="/mall">商城</a> <i>&gt;</i> <span>优惠券</span></div></div>';
        $html .= self::flash();
        if ($uid <= 0) {
            $html .= '<div class="mall-panel"><div class="mall-w">' . self::loginGuide('/coupon', '登录后领券') . '</div></div>' . self::footer();
            return ['html' => $html, 'title' => '优惠券 - 商城'];
        }
        self::grantNewcomerCoupons($uid); // M9 注册送：首次进入自动发新人券
        $fmtEnd = static fn($t): string => $t !== null ? ' · ' . date('Y.m.d', strtotime((string)$t)) . ' 前有效' : '';
        $html .= '<div class="mall-panel"><div class="mall-w">';
        // 可领
        $list = self::couponList();
        $html .= '<div class="mall-cp-sec"><h3>可领优惠券</h3><div class="mall-cp-grid">';
        if ($list === []) {
            $html .= '<div class="dim" style="padding:20px 0;color:#999;font-size:13px">暂无可领的优惠券，常回来看看。</div>';
        }
        foreach ($list as $c) {
            $got = (int)Db::one('SELECT COUNT(*) FROM ' . self::t('user_coupon') . ' WHERE coupon_id=? AND uid=?', [(int)$c['id'], $uid]);
            $full = $got >= max(1, (int)$c['per_user_limit']);
            $isDisc = (int)$c['type'] === 2;
            if ($isDisc) {
                $zhe = rtrim(rtrim(number_format((float)$c['discount_rate'] * 10, 1, '.', ''), '0'), '.');
                $vHtml = '<b>' . e($zhe) . '</b><i>折</i>';
                $thTxt = ((float)$c['threshold'] > 0 ? '满 ' . e(number_format((float)$c['threshold'], 2, '.', '')) . ' ' : '') . '可用'
                    . ((float)$c['max_discount'] > 0 ? ' · 最高减¥' . e(number_format((float)$c['max_discount'], 2, '.', '')) : '');
                $tag = '折扣券';
            } else {
                $vHtml = '<i>¥</i><b>' . e(number_format((float)$c['amount'], 2, '.', '')) . '</b>';
                $thTxt = '满 ' . e(number_format((float)$c['threshold'], 2, '.', '')) . ' 可用';
                $tag = '满减券';
            }
            $newBadge = (int)$c['grant_newcomer'] === 1 ? '<span class="mall-cp-new">新人</span>' : '';
            $html .= '<div class="mall-cp' . ($isDisc ? ' discount' : '') . '"><div class="v">' . $vHtml . '<span class="th">' . $thTxt . '</span></div>'
                . '<div class="n">' . $newBadge . e((string)$c['name']) . '<span class="d">' . $fmtEnd($c['end_time']) . '</span></div>'
                . ($full
                    ? '<button class="mall-cp-btn dis" disabled>已领取</button>'
                    : '<form method="post" action="/coupon/">' . Csrf::field()
                        . '<input type="hidden" name="act" value="coupon_get"><input type="hidden" name="coupon_id" value="' . (int)$c['id'] . '">'
                        . '<button class="mall-cp-btn">领取</button></form>')
                . '</div>';
        }
        $html .= '</div></div>';
        // 我的券包
        $mine = self::myCoupons($uid);
        $html .= '<div class="mall-cp-sec"><h3>我的券包</h3><div class="mall-cp-grid">';
        if ($mine === []) {
            $html .= '<div class="dim" style="padding:20px 0;color:#999;font-size:13px">还没有优惠券，从上面领一张吧。</div>';
        }
        foreach ($mine as $m) {
            $stCls = ['ok', 'use', 'exp'][(int)$m['status']] ?? '';
            $stTxt = ['未使用', '已使用', '已过期'][(int)$m['status']] ?? '';
            $isDisc = (int)$m['type'] === 2;
            if ($isDisc) {
                $zhe = rtrim(rtrim(number_format((float)$m['discount_rate'] * 10, 1, '.', ''), '0'), '.');
                $vHtml = '<b>' . e($zhe) . '</b><i>折</i>';
                $thTxt = ((float)$m['threshold'] > 0 ? '满 ' . e(number_format((float)$m['threshold'], 2, '.', '')) . ' ' : '') . '可用'
                    . ((float)$m['max_discount'] > 0 ? ' · 最高减¥' . e(number_format((float)$m['max_discount'], 2, '.', '')) : '');
            } else {
                $vHtml = '<i>¥</i><b>' . e(number_format((float)$m['amount'], 2, '.', '')) . '</b>';
                $thTxt = '满 ' . e(number_format((float)$m['threshold'], 2, '.', '')) . ' 可用';
            }
            $newBadge = (int)$m['grant_newcomer'] === 1 ? '<span class="mall-cp-new">新人</span>' : '';
            $html .= '<div class="mall-cp' . ($isDisc ? ' discount' : '') . ((int)$m['status'] === 0 ? '' : ' used') . '"><div class="v">' . $vHtml . '<span class="th">' . $thTxt . '</span></div>'
                . '<div class="n">' . $newBadge . e((string)$m['name']) . '<span class="d">' . $fmtEnd($m['end_time']) . '</span></div>'
                . '<span class="mall-cp-st ' . $stCls . '">' . $stTxt . '</span></div>';
        }
        $html .= '</div></div>';
        $html .= '</div></div>';
        $html .= self::footer();
        return ['html' => $html, 'title' => '优惠券 - 商城'];
    }

    /* ================= 模型 ================= */

    /** 可见分类（按 sort） */
    private static function categories(): array
    {
        return Db::rows('SELECT id, pid, name, slug, icon FROM ' . self::t('goods_category') . ' WHERE is_show=1 AND is_delete_time=0 ORDER BY sort ASC, id ASC');
    }

    private static function goodsQuery(array $cond, int $limit): array
    {
        $where = ' WHERE status=1 AND is_delete_time=0';
        $args = [];
        foreach ($cond as $k => $v) { $where .= ' AND ' . $k . '=?'; $args[] = $v; }
        return self::goodsQueryRaw($where . ' ORDER BY id DESC LIMIT ' . max(1, $limit), $args);
    }

    private static function goodsQueryRaw(string $suffix, array $args): array
    {
        return Db::rows('SELECT id, title, subtitle, cover, min_price, market_price, total_stock, sales_count, virtual_sales FROM ' . self::t('goods') . $suffix, $args);
    }

    /** 商品卡（236.5×390 五列栅格单元） */
    private static function goodsCard(array $g): string
    {
        $cover = (string)$g['cover'];
        $img = $cover !== '' ? '<img src="' . e(mallThumb($cover)) . '" alt="' . e((string)$g['title']) . '" loading="lazy">' : '<div class="mall-card-img-ph">商品图</div>';
        return '<a class="mall-card" href="' . e(self::goodsUrl((int)$g['id'])) . '">'
            . '<div class="mall-card-img">' . $img . '</div>'
            . '<div class="mall-card-title">' . e((string)$g['title']) . '</div>'
            . '<div class="mall-card-price"><span class="p"><i>¥</i>' . e(number_format((float)$g['min_price'], 2, '.', '')) . '</span>'
            . ((float)$g['market_price'] > 0 ? '<span class="m">¥' . e(number_format((float)$g['market_price'], 2, '.', '')) . '</span>' : '')
            . '</div>'
            . '<div class="mall-card-sales">已售 ' . ((int)$g['sales_count'] + (int)$g['virtual_sales']) . '</div>'
            . '</a>';
    }

    private static function emptyGrid(string $text): string
    {
        return '<div class="mall-empty">' . e($text) . '</div>';
    }

    /* ================= 结构块（topbar / header / nav / footer） ================= */

    private static function topbar(): string
    {
        $user = '';
        $u = Auth::user();
        if ($u !== null) {
            $user = '<span class="mall-top-hi">您好，' . e((string)($u['display_name'] ?? $u['username'] ?? '')) . '</span><a href="' . e(ui_user()) . '">用户中心</a>';
        } else {
            $user = '<a href="' . e(ui_login('/')) . '">请登录</a><a href="/register">免费注册</a>';
        }
        // M12：未读消息角标
        $msgHtml = '';
        if (Auth::user() !== null) {
            $unread = self::msgUnread((int)self::uid());
            $msgHtml = '<a href="/mall/user/message">站内消息' . ($unread > 0 ? '<em class="mall-msg-badge">' . ($unread > 99 ? '99+' : $unread) . '</em>' : '') . '</a>';
        }
        return '<div class="mall-topbar"><div class="mall-w"><div class="mall-top-l">' . $user
            . '<a href="' . e(self::articleListUrl()) . '">最新资讯</a></div>'
            . '<div class="mall-top-r">' . $msgHtml . '<a href="/promo">促销活动</a><a href="/mall/user/">会员中心</a><a href="/coupon">优惠券</a><a href="/order">我的订单</a><a href="/cart">购物车</a></div></div></div>';
    }

    /** 搜索热词：取挂载商品最多的「通用标签」——真实有量且必有落地页，SSR 直出不依赖 JS */
    private static function hotKeywords(int $limit = 7): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            try {
                $rows = Db::rows('SELECT name FROM ' . self::t('tag')
                    . ' WHERE is_show=1 AND is_delete_time=0 AND goods_count>0'
                    . ' ORDER BY goods_count DESC LIMIT ' . max(1, $limit));
                foreach ($rows as $r) {
                    $nm = trim((string)($r['name'] ?? ''));
                    if ($nm !== '') {
                        $cache[] = $nm;
                    }
                }
            } catch (\Throwable $e) {
                $cache = [];
            }
        }
        return array_slice($cache, 0, $limit);
    }

    private static function header(): string
    {
        $n = self::cartCount();
        $badge = $n > 0 ? '<em class="mall-cart-badge">' . ($n > 99 ? '99+' : $n) . '</em>' : '';
        // 热搜词（SSR 直出）：主流的电商头部必备，既强化层级又给商城内部入口
        $hot = self::hotKeywords(7);
        $kwHtml = '';
        if ($hot) {
            $as = '';
            foreach ($hot as $h) {
                $as .= '<a href="' . e(self::searchUrl($h)) . '">' . e($h) . '</a>';
            }
            $kwHtml = '<div class="mall-hotkw"><span>热搜</span>' . $as . '</div>';
        }
        // ★ 品牌统一：站名走配置（mall.name / site.name），logo 用图片（site.logo，缺省回退插件内置）
        $brandName = self::brandName();
        $logoUrl = self::brandLogoUrl();
        return '<div class="mall-header"><div class="mall-w">'
            . '<a class="mall-logo" href="/">'
            . '<img src="' . e($logoUrl) . '" alt="' . e($brandName) . '" width="160" height="60">'
            . '<i>云南普洱茶 · 产地直供</i></a>'
            . '<div class="mall-hd-search">'
            . '<form class="mall-search" method="get" action="/goods/lists.html"><input type="search" name="keyword" placeholder="搜索商品"><button type="submit">搜索</button></form>'
            . $kwHtml
            . '</div>'
            . '<a class="mall-cart-btn" href="/cart"><span>我的购物车</span>' . $badge . '</a>'
            . '</div></div>';
    }

/* ================= ★ SEO（统一层） ================= */

    /** 站点名：优先独立配置 mall.site_name，回退宿主 site.name */
    /* ================= ★ 品牌（统一入口） ================= */

    /**
     * 品牌名：mall.name（后台「商城名称」可改）→ site.name → siteName() 兜底。
     * 原来页头/页脚/支付标题各自硬编码「夜叉商城」，后台改站名无效。
     */
    public static function brandName(): string
    {
        $n = trim((string)get_option('mall.name', ''));
        if ($n !== '') { return $n; }
        $n = trim((string)get_option('site.name', ''));
        if ($n !== '') { return $n; }
        return self::siteName();
    }

    /**
     * 品牌 logo URL：mall.logo → site.logo → 插件内置 51puer logo。
     * 文件不存在时回退，避免裂图。
     */
    public static function brandLogoUrl(): string
    {
        $fallback = '/plugins/mall/assets/logo.png';
        foreach (['mall.logo', 'site.logo'] as $k) {
            $rel = trim((string)get_option($k, ''));
            if ($rel === '') { continue; }
            if (preg_match('#^https?://#i', $rel)) { return $rel; }
            $root = defined('YC_ROOT') ? YC_ROOT : dirname(__DIR__, 2);
            if (is_file($root . $rel)) { return $rel . '?v=' . filemtime($root . $rel); }
        }
        $root = defined('YC_ROOT') ? YC_ROOT : dirname(__DIR__, 2);
        $abs = $root . $fallback;
        return is_file($abs) ? $fallback . '?v=' . filemtime($abs) : $fallback;
    }

    public static function siteName(): string
    {
        $n = trim((string)get_option('mall.site_name', ''));
        if ($n !== '') { return $n; }
        return site_label();
    }

/** 标题规范化：去掉兜底后缀「- 商城」「· 商城」，供 seoTitle() 统一加站点名 */
    private static function normTitle(string $t): string
    {
        $t = trim($t);
        foreach ([' - 商城', '·商城', ' - 商城 ', '｜商城'] as $suf) {
            if (mb_substr($t, -mb_strlen($suf)) === $suf) {
                $t = trim(mb_substr($t, 0, mb_strlen($t) - mb_strlen($suf)));
                break;
            }
        }
        // 清掉规范化后可能出现的悬挂分隔符
        $t = trim($t, " -·|｜");
        return $t === '' ? self::siteName() : $t;
    }

    /** 标题组装：主标题 + 站点名（站点名已在主标题里则不重复） */
    public static function seoTitle(string $main): string
    {
        $s = self::siteName();
        $main = trim($main);
        if ($main === '') { return $s; }
        if (mb_strpos($main, $s) !== false) { return $main; }
        return $main . ' - ' . $s;
    }

    /**
     * 统一 SEO：按页面类型生成 title(由各页自设) / description / keywords / canonical / OG。
     * 交易与账户类页面（购物车/结算/订单/支付/会员中心）**不做 SEO**：
     *   加 noindex，避免被索引成低价值页并稀释权重。
     */
    private static function seo(string $head, array $path): void
    {
        $site = self::siteName();
        $scheme = function_exists('yc_theme_scheme') ? yc_theme_scheme() : 'https';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $base = $scheme . '://' . $host;

        // 交易/账户页：明确 noindex, follow（可跟随链接传播权重，但不收录自身）
        $noIndex = in_array($head, ['cart', 'checkout', 'order', 'pay', 'user', 'address', 'coupon'], true);
        if ($noIndex) {
            $GLOBALS['YC_META']['robots'] = 'noindex, follow';
            return;
        }

        $desc = '';
        $kw = '';
        $canon = '';

        if ($head === '') {
            // 首页
            $n = (int)Db::one('SELECT COUNT(*) FROM ' . self::t('goods')
                . ' WHERE status=1 AND is_delete_time=0 AND total_stock>0');
            $cats = [];
            foreach (Db::rows('SELECT name FROM ' . self::t('goods_category')
                . ' WHERE pid=0 AND is_delete_time=0 ORDER BY sort ASC LIMIT 8') as $c) {
                $cats[] = (string)$c['name'];
            }
            $catStr = implode('、', $cats);
            $desc = $site . '——云南普洱茶产地直供，在售' . number_format($n) . '款：'
                . $catStr . '。生茶熟茶、古树山头、老茶中期茶，支持 15 天无理由退换。';
            // 关键词去堆砌：只留站级核心词（分类名已在 description 里，重复堆叠会被判作弊）
            $kw = '普洱茶,云南普洱茶,普洱生茶,普洱熟茶,古树茶,普洱茶价格,普洱饼茶,普洱茶购买';
            $canon = '/';
        } elseif ($head === 'list') {
            $cid = (int)($_GET['category_id'] ?? 0);
            $cn = '';
            if ($cid > 0) {
                $cn = (string)Db::one('SELECT name FROM ' . self::t('goods_category') . ' WHERE id=? AND is_delete_time=0', [$cid]);
            }
            if ($cn !== '') {
                $n = (int)Db::one('SELECT COUNT(*) FROM ' . self::t('goods')
                    . ' WHERE category_id=? AND status=1 AND is_delete_time=0 AND total_stock>0', [$cid]);
                $price = Db::row('SELECT MIN(min_price) AS lo, MAX(min_price) AS hi FROM ' . self::t('goods')
                    . ' WHERE category_id=? AND status=1 AND is_delete_time=0 AND min_price>0', [$cid]);
                $range = ($price && (float)$price['lo'] > 0)
                    ? '价格 ¥' . number_format((float)$price['lo'], 0) . ' 起'
                    : '';
                $desc = $cn . '（' . number_format($n) . '款在售）——' . $site . '产地直供。'
                    . ($range !== '' ? $range . '，' : '') . '正品保障、15天无理由退换。';
                $kw = $cn . ',' . $cn . '价格,' . $cn . '推荐,云南' . $cn . ',普洱茶';
                $canon = '/list.html?category_id=' . $cid;
            } else {
                $kwv = trim((string)($_GET['keyword'] ?? $_GET['q'] ?? ''));
                if ($kwv !== '') {
                    $desc = '搜索「' . $kwv . '」的结果——' . $site . '，云南普洱茶产地直供，正品保障。';
                    $kw = $kwv . ',普洱茶搜索';
                    $canon = '/goods/lists.html?keyword=' . rawurlencode($kwv);
                } else {
                    $n = (int)Db::one('SELECT COUNT(*) FROM ' . self::t('goods')
                        . ' WHERE status=1 AND is_delete_time=0 AND total_stock>0');
                    $desc = $site . '全部商品（' . number_format($n) . '款在售）——云南普洱茶生熟饼砖沱、'
                        . '红茶白茶与茶具，产地直供、正品保障、15天无理由退换。';
                    $kw = '普洱茶,全部商品,云南普洱茶,普洱生茶,普洱熟茶,茶具';
                    $canon = '/list.html';
                }
            }
        } elseif ($head === 'goods' && isset($path[1])) {
            $gid = (int)preg_replace('/\D/', '', (string)$path[1]);
            if ($gid > 0) {
                $g = Db::row('SELECT title, subtitle, min_price, category_id, brand_id, seo_desc, seo_keywords'
                    . ' FROM ' . self::t('goods') . ' WHERE id=? AND status=1 AND is_delete_time=0 LIMIT 1', [$gid]);
                if ($g) {
                    $cn = (int)$g['category_id'] > 0
                        ? (string)Db::one('SELECT name FROM ' . self::t('goods_category') . ' WHERE id=?', [(int)$g['category_id']])
                        : '';
                    $bn = (int)$g['brand_id'] > 0
                        ? (string)Db::one('SELECT name FROM ' . self::t('brand') . ' WHERE id=?', [(int)$g['brand_id']])
                        : '';
                    $own = trim((string)($g['seo_desc'] ?? ''));
                    if ($own !== '') {
                        $desc = $own;
                    } else {
                        $desc = (string)$g['title'] . '——'
                            . ($bn !== '' ? $bn . ' ' : '') . ($cn !== '' ? $cn : '普洱茶')
                            . '，¥' . number_format((float)$g['min_price'], 2, '.', '') . '。'
                            . $site . '产地直供，正品保障、15天无理由退换。';
                    }
                    $kwOwn = trim((string)($g['seo_keywords'] ?? ''));
                    $parts = [(string)$g['title']];
                    if ($bn !== '') { $parts[] = $bn; }
                    if ($cn !== '') { $parts[] = $cn; }
                    $parts[] = '普洱茶';
                    $kw = $kwOwn !== '' ? $kwOwn : implode(',', $parts);
                    $canon = '/goods-' . $gid . '.html';
                }
            }
        } elseif ($head === 'article') {
            $aid = 0;
            if (isset($path[1]) && preg_match('/^(\d+)/', (string)$path[1], $m)) { $aid = (int)$m[1]; }
            if ($aid > 0) {
                $a = Db::row('SELECT title, excerpt FROM ' . Db::t('contents') . ' WHERE id=? AND status=1 LIMIT 1', [$aid]);
                if ($a) {
                    $ex = trim((string)($a['excerpt'] ?? ''));
                    $desc = $ex !== '' ? $ex : ((string)$a['title'] . '——' . $site . '茶业资讯。');
                    $kw = '普洱茶资讯,茶业新闻,普洱茶知识';
                    $canon = '/article-' . $aid . '.html';
                }
            } elseif ($head === 'article') {
                $desc = $site . '茶业资讯：普洱茶行情、产区知识、冲泡与仓储，以及新品与活动公告。';
                $kw = '普洱茶资讯,茶业新闻,普洱茶行情,普洱茶知识';
                $canon = '/article/lists.html';
            }
        } elseif ($head === 'brand') {
            $slug = trim((string)($path[1] ?? ''));
            if ($slug !== '') {
                $b = Db::row('SELECT id, name FROM ' . self::t('brand') . ' WHERE slug=? LIMIT 1', [$slug]);
                if ($b) {
                    $n = (int)Db::one('SELECT COUNT(*) FROM ' . self::t('goods')
                        . ' WHERE brand_id=? AND status=1 AND is_delete_time=0 AND total_stock>0', [(int)$b['id']]);
                    $desc = (string)$b['name'] . '普洱茶（' . $n . '款在售）——' . $site . '正品保障，产地直供，支持15天无理由退换。';
                    $kw = (string)$b['name'] . ',普洱茶,' . (string)$b['name'] . '普洱茶价格';
                    $canon = '/brand/' . rawurlencode($slug) . '/';
                }
            } else {
                $desc = $site . '品牌中心：中茶、大益、下关、老同志、中木等普洱茶品牌，按品牌选购。';
                $kw = '普洱茶品牌,中茶,大益,下关,老同志,普洱茶品牌大全';
                $canon = '/brand';
            }
        } elseif ($head === 'tag') {
            $slug = trim((string)($path[1] ?? ''));
            if ($slug !== '') {
                $tg = Db::row('SELECT name FROM ' . self::t('tag') . ' WHERE slug=? LIMIT 1', [$slug]);
                if ($tg) {
                    $desc = (string)$tg['name'] . '普洱茶——' . $site . '按标签选购，产地直供、正品保障。';
                    $kw = (string)$tg['name'] . ',普洱茶,' . (string)$tg['name'] . '普洱茶';
                    $canon = '/mall/tag/' . rawurlencode($slug) . '/';
                }
            } else {
                $desc = $site . '商品标签总览：按生熟、山头、年份、形态快速找到想要的普洱茶。';
                $kw = '普洱茶标签,普洱生茶,普洱熟茶,古树茶,山头茶';
                $canon = '/mall/tag/';
            }
        } elseif ($head === 'topic') {
            $tid = (int)($path[1] ?? 0);
            if ($tid > 0) {
                $tp = Db::row('SELECT title FROM ' . self::t('topic') . ' WHERE id=? AND status=1 LIMIT 1', [$tid]);
                if ($tp) {
                    $desc = (string)$tp['title'] . '——' . $site . '专题，人工精选、按预算分档推荐。';
                    $kw = (string)$tp['title'] . ',普洱茶专题,普洱茶推荐';
                    $canon = '/topic/' . $tid . '.html';
                }
            } else {
                $desc = $site . '专题活动：按预算、场景与人群精选的普洱茶清单，每个专题都标明挑选理由。';
                $kw = '普洱茶推荐,普洱茶专题,口粮茶,送礼茶叶,古树茶';
                $canon = '/topic';
            }
        } elseif ($head === 'promo') {
            $desc = $site . '促销活动：限时折扣、拼团、优惠券与组合优惠，实时更新。';
            $kw = '普洱茶优惠,普洱茶促销,普洱茶折扣,优惠券';
            $canon = '/promo';
        } elseif ($head === 'help') {
            $desc = $site . '购物指南：配送与发货、退换货政策、发票、冲泡与仓储的常见问题。';
            $kw = '普洱茶购买,退换货,发货,发票,普洱茶冲泡,普洱茶保存';
            $canon = '/help';
        }

        if ($desc !== '') { $GLOBALS['YC_META']['description'] = $desc; }
        if ($kw !== '') { $GLOBALS['YC_META']['keywords'] = $kw; }
        if ($canon !== '' && empty($GLOBALS['YC_CANONICAL'])) {
            $GLOBALS['YC_CANONICAL'] = $base . $canon;
        }
        if ($desc !== '') {
            $GLOBALS['YC_OG']['description'] = $desc;
            if (empty($GLOBALS['YC_OG']['title'])) {
                $GLOBALS['YC_OG']['title'] = $GLOBALS['YC_META']['title'] ?? self::seoTitle('');
            }
        }
    }

    private static function navBar(array $cats, int $activeCat = 0): string
    {
        $tops = array_values(array_filter($cats, fn($c) => (int)$c['pid'] === 0));
        // 子类（供二级入口与下拉面板用）
        $kids = array_values(array_filter($cats, fn($c) => (int)$c['pid'] !== 0));

        // ★ 左侧「全部商品分类」触发器 + 下拉面板（对齐 51puer 的 .ns-header > nav .category）
        //   51puer hover 出 380px 白底分类面板；我们分类是单层（7 顶层、无子类），
        //   所以面板用「分类名 + 该类可售款数」，比硬造二级更实用。
        // ★ 面板对齐 51puer 的「大项 + 下级属性」两级结构
        //   实测 51puer 面板为 6 大项：品类(6) 品牌(18) 年份(18) 价格(6) 工艺(4) 外观(7)
        //   我们按同一结构给「能筛的」都做成链接（品类/品牌走字段，年份/工艺/外观走参数）
        $cnt = function (string $where, array $args) {
            return (int)Db::one('SELECT COUNT(*) FROM ' . self::t('goods') . ' g'
                . ' WHERE g.status=1 AND g.is_delete_time=0 AND g.total_stock>0 AND ' . $where, $args);
        };
        $groups = [];

        // ① 品类（字段）
        $g1 = [];
        foreach ($tops as $c) {
            // 父分类用聚合数量（含子类）
            $n = $cnt('g.category_id IN (SELECT id FROM ' . self::t('goods_category') . ' WHERE id=? OR pid=?)',
                [(int)$c['id'], (int)$c['id']]);
            $sub = [];
            foreach ($kids as $k) {
                if ((int)$k['pid'] !== (int)$c['id']) { continue; }
                $kn = $cnt('g.category_id=?', [(int)$k['id']]);
                $sub[] = [(string)$k['name'], self::listUrl((int)$k['id']), $kn];
            }
            if ($n > 0 || $sub) { $g1[] = [(string)$c['name'], self::listUrl((int)$c['id']), $n, $sub]; }
        }
        $groups[] = ['品类', $g1];

        // ② 品牌（字段，只列有货的）
        $g2 = [];
        foreach (Db::rows('SELECT b.id, b.name, b.slug FROM ' . self::t('brand') . ' b'
            . ' WHERE b.is_show=1 AND b.is_delete_time=0 ORDER BY b.sort ASC, b.id ASC LIMIT 24') as $b) {
            $n = $cnt('g.brand_id=?', [(int)$b['id']]);
            if ($n <= 0) { continue; }   // 无在售商品的品牌不进面板
            // ★ 指向品牌独立页（原来指向列表页筛选，白白浪费了品牌页）
            $g2[] = [(string)$b['name'], self::brandUrl((string)$b['slug']), $n];
        }
        if ($g2) { $groups[] = ['品牌', $g2]; }

        // ③ 年份（虚拟属性，链接带 year=）
        $g3 = [];
        foreach (['2021年','2020年','2019年','2018年','2017年','2016年','2015年','2014年','2013年','2012年',
                  '2011年','2010年','2009年','2008年','2007年','2006年','2001-2005年','2000年及以前'] as $yp) {
            ($yp === '2000年及以前')
                ? ($n = $cnt('g.title REGEXP ?', ['20[0-0][0-9]年']))
                : ((strpos($yp, '-') !== false)
                    ? ($n = $cnt('g.title REGEXP ?', ['20(0[1-5])年']) )
                    : ($n = $cnt('g.title LIKE ?', ['%' . $yp . '%'])));
            if ($n > 0) { $g3[] = [$yp, '/list.html?year=' . rawurlencode($yp), $n]; }
        }
        if ($g3) { $groups[] = ['年份', $g3]; }

        // ④ 价格（6 档，对齐 51puer）
        $g4 = [];
        foreach ([['100元以内',0,100],['101~300元',100,300],['301~500元',300,500],
                  ['501~1000元',500,1000],['1001元~2000元',1000,2000],['2000元以上',2000,0]] as $pc) {
            $n = ($pc[2] > 0)
                ? $cnt('g.min_price>? AND g.min_price<=?', [$pc[1], $pc[2]])
                : $cnt('g.min_price>?', [$pc[1]]);
            if ($n > 0) { $g4[] = [$pc[0], '/list.html?min=' . $pc[1] . ($pc[2] > 0 ? '&max=' . $pc[2] : ''), $n]; }
        }
        if ($g4) { $groups[] = ['价格', $g4]; }

        // ⑤ 工艺（attrs 生熟）
        $g5 = [];
        foreach (['生茶','熟茶','工艺茶','生熟混合装'] as $vv) {
            $n = $cnt('g.attrs LIKE ?', ['%"生熟": "' . $vv . '"%']);
            if ($n > 0) { $g5[] = [$vv, '/list.html?craft=' . rawurlencode($vv), $n]; }
        }
        if ($g5) { $groups[] = ['工艺', $g5]; }

        // ⑥ 外观（attrs 形态）
        $g6 = [];
        foreach (['饼茶','沱茶','砖茶','散茶','礼品茶','茶膏茶粉','小沱茶','龙珠'] as $vv) {
            $n = $cnt('g.attrs LIKE ?', ['%"形态": "' . $vv . '"%']);
            if ($n > 0) { $g6[] = [$vv, '/list.html?shape=' . rawurlencode($vv), $n]; }
        }
        if ($g6) { $groups[] = ['外观', $g6]; }

        // 渲染：大项标题 + 下级属性（每项带款数）
        $panel = '';
        foreach ($groups as $grp) {
            $head = e($grp[0]);
            // 品牌组标题做成指向品牌总览的链接
            if ($grp[0] === '品牌') {
                // <b> 内不能再套 <a>（无效 HTML）→ 用 text node + 同级 <a>
                $head = '品牌<a class="mall-nav-cat-all" href="/brand">全部 &gt;</a>';
            }
            $panel .= '<div class="mall-nav-cat-g"><b>' . $head . '</b><div>';
            foreach ($grp[1] as $it) {
                $panel .= '<a href="' . e((string)$it[1]) . '">' . e((string)$it[0])
                    . '<em>' . (int)$it[2] . '</em></a>';
                // 子类：紧跟父类之后、缩进显示（对齐 51puer「品类 → 普洱茶熟茶/生茶」）
                foreach ((array)($it[3] ?? []) as $sub) {
                    $panel .= '<a class="mall-nav-sub2" href="' . e((string)$sub[1]) . '">'
                        . e((string)$sub[0]) . '<em>' . (int)$sub[2] . '</em></a>';
                }
            }
            $panel .= '</div></div>';
        }
        $allBtn = '<div class="mall-nav-cat"><a class="mall-nav-cat-btn" href="/list.html">全部商品分类</a>'
            . '<div class="mall-nav-cat-panel">' . $panel . '</div></div>';

        $items = '<a href="/" class="on">首页</a>';
        foreach ($tops as $c) {
            $on = (int)$c['id'] === $activeCat ? ' class="on"' : '';
            // ★ 普洱茶打头（sort=0 已保证），其子类紧跟其后作为二级入口
            $items .= '<a' . $on . ' href="' . e(self::listUrl((int)$c['id'])) . '">' . e((string)$c['name']) . '</a>';
            foreach ($kids as $k) {
                if ((int)$k['pid'] !== (int)$c['id']) { continue; }
                $kon = (int)$k['id'] === $activeCat ? ' class="on"' : '';
                $items .= '<a class="mall-nav-sub"' . $kon . ' href="'
                    . e(self::listUrl((int)$k['id'])) . '">' . e((string)$k['name']) . '</a>';
            }
        }
        // ★ 品牌独立页入口（原来只有 /brand 页存在但无任何导航入口）
        $items .= '<a href="/brand" class="mall-nav-brand">品牌</a>';
        $items .= '<a class="mall-nav-r" href="/topic">专题活动</a><a class="mall-nav-r" href="/help">购物指南</a>';

        return '<div class="mall-nav"><div class="mall-w">' . $allBtn . $items . '</div></div>';
    }

    /** 页脚：服务保障条 + 品牌区 + 四列导航 + 版权条（全部 SSR 直出，链接均为已验证可达的真实路由） */
    private static function footer(): string
    {
        // 1) 服务保障（电商信任层）
        $promise = [
            ['正品', '产地直供 · 假一赔十'],
            ['退换', '15 天无理由退换'],
            ['包邮', '单笔满 51 元包邮'],
            ['售后', '在线留言 · 全程跟踪'],
        ];
        $ps = '';
        foreach ($promise as $p) {
            $ps .= '<li><b>' . e($p[0]) . '</b><span>' . e($p[1]) . '</span></li>';
        }

        // 2) 四列导航
        $cols = [
            '购物指南' => [
                ['全部商品', '/list.html'], ['商品标签', '/mall/tag/'], ['专题活动', '/topic'],
                ['促销活动', '/promo'], ['领券中心', '/coupon'],
            ],
            '配送与支付' => [
                ['我的购物车', '/cart'], ['我的订单', '/mall/user/order'],
                ['收货地址', '/mall/user/address'], ['账户余额', '/mall/user/balance'],
            ],
            '售后服务' => [
                ['退款退货', '/mall/user/aftersale'], ['评价晒单', '/mall/user/review'],
                ['我的收藏', '/mall/user/favorite'], ['站内消息', '/mall/user/message'],
                  ['物流查询', '/mall/user/logistics'],
            ],
            '会员与帮助' => [
                ['会员中心', '/mall/user/'], ['会员等级', '/mall/user/level'],
                ['我的积分', '/mall/user/points'], ['每日签到', '/mall/user/signin'],
                  ['帮助中心', '/help'],
            ],
        ];
        $links = '';
        foreach ($cols as $title => $items) {
            $li = '';
            foreach ($items as $a) {
                $li .= '<li><a href="' . e($a[1]) . '">' . e($a[0]) . '</a></li>';
            }
            $links .= '<div class="mall-ft-col"><h4>' . e((string)$title) . '</h4><ul>' . $li . '</ul></div>';
        }

        return '<div class="mall-footer">'
            // 服务保障条
            . '<div class="mall-ft-promise"><div class="mall-w"><ul>' . $ps . '</ul></div></div>'
            // 品牌区 + 导航列
            . '<div class="mall-ft-main"><div class="mall-w">'
            . '<div class="mall-ft-brand">'
            . '<a class="mall-logo" href="/">'
            . '<img src="' . e(self::brandLogoUrl()) . '" alt="' . e(self::brandName()) . '" width="140" height="52">'
            . '<i>云南普洱茶 · 产地直供</i></a>'
            . '<p>产地直供普洱茶 · 生熟饼砖沱 · 满 51 元包邮 · 15 天无理由退换</p>'
            . '<p class="mall-ft-cta"><a href="/mall/user/message">在线留言</a><a href="/mall/user/aftersale">售后申请</a></p>'
            . '</div>'
            . '<div class="mall-ft-links">' . $links . '</div>'
            . '</div></div>'
            // ★ AI 导购浮窗（ai 插件启用且已配置才渲染；未配置零成本）
            . self::aiGuide((string)(self::$pageCtx['hint'] ?? ''), (array)(self::$pageCtx['quick'] ?? []), (array)(self::$pageCtx['ctx'] ?? []))
            // ★ 合规展示区（ICP备案 / 公安网安备案 / ICP经营许可证 / 工商执照）
            //   对标 51puer 页脚的四项合规展示。全部走后台配置，未配置则不渲染该项，
            //   不出现空占位（避免"有备案链接但点开是空的"这种更差的情况）。
            . self::complianceBlock()
            // 版权条
            . '<div class="mall-ft-copy"><div class="mall-w">'
            . '<p>' . e(self::brandName()) . ' &nbsp;|&nbsp; 商品交易与售后服务由本站提供</p>'
            . '<p class="dim">页面所展示的价格、库存与赠品信息均为站内参考，成交以订单结算金额为准</p>'
            . '</div></div>'
            . '</div>';
    }


    /**
     * AI 导购浮窗（委托给 AiGuide 类；ai 插件未启用/未配置时返回空串）。
     */
    private static function aiGuide(string $hint = '', array $quick = [], array $ctx = []): string
    {
        try {
            if (!\Plugins::isOn('ai')) { return ''; }
            $f = __DIR__ . '/AiGuide.php';
            if (!is_file($f)) { return ''; }
            require_once $f;
            if (!class_exists('MallAiGuide')) { return ''; }
            return \MallAiGuide::render($hint, '', $quick, $ctx);
        } catch (Throwable $e) {
            return '';   // 导购挂件绝不能弄挂页面
        }
    }

    /**
     * 页脚合规展示（ICP备案 / 公安网安备案 / ICP经营许可证 / 工商执照）。
     * 每项均可后台配置：备案号填文字、执照填图片 URL。未配置的项不渲染。
     */
    private static function complianceBlock(): string
    {
        $items = [];
        $icp = trim((string)get_option('mall.icp_no', ''));
        if ($icp !== '') {
            $items[] = ['ICP 备案', $icp, trim((string)get_option('mall.icp_url', 'https://beian.miit.gov.cn/'))];
        }
        $police = trim((string)get_option('mall.police_no', ''));
        if ($police !== '') {
            $items[] = ['公安网安备案', $police, trim((string)get_option('mall.police_url', ''))];
        }
        $licNo = trim((string)get_option('mall.license_no', ''));
        $licImg = trim((string)get_option('mall.license_img', ''));
        if ($licNo !== '' || $licImg !== '') {
            $items[] = ['ICP 经营许可证', $licNo !== '' ? $licNo : '查看', $licImg];
        }
        $bizImg = trim((string)get_option('mall.business_img', ''));
        if ($bizImg !== '') {
            $items[] = ['工商执照', '查看', $bizImg];
        }
        if ($items === []) { return ''; }
        $h = '<div class="mall-ft-compliance"><div class="mall-w">';
        $parts = [];
        foreach ($items as $it) {
            $label = e((string)$it[0]);
            $val = e((string)$it[1]);
            $url = (string)$it[2];
            if ($url === '') {
                $parts[] = '<span>' . $label . '：' . $val . '</span>';
            } else {
                $parts[] = '<a href="' . e($url) . '" target="_blank" rel="noopener nofollow">'
                    . $label . '：' . $val . '</a>';
            }
        }
        $h .= implode('<i>|</i>', $parts) . '</div></div>';
        return $h;
    }

    /** 商品详情收藏按钮（M7）：未登录→点跳转登录；登录→AJAX 切换收藏态 */
    private static function favBtn(int $goodsId, bool $faved): string
    {
        static $once = false;
        $cls = $faved ? 'mall-btn-fav on' : 'mall-btn-fav';
        $label = $faved ? '已收藏' : '收藏';
        $login = e(ui_login(self::goodsUrl((int)$goodsId)));
        $html = '<button type="button" class="' . $cls . '" data-fav="' . $goodsId . '" data-login="' . $login . '">' . $label . '</button>';
        if (!$once) {
            $once = true;
            $csrf = Csrf::token();
            $uid = self::uid();
            $html .= '<script>window.__mall={csrf:' . json_encode($csrf) . ',uid:' . (int)$uid . ',login:' . json_encode($login) . '};'
                . 'document.addEventListener("click",function(e){'
                . 'var b=e.target.closest("[data-fav]");if(!b)return;e.preventDefault();'
                . 'if(!window.__mall.uid||window.__mall.uid<=0){location.href=window.__mall.login;return;}'
                . 'var id=b.getAttribute("data-fav");var on=b.classList.contains("on");'
                . 'var fd=new FormData();fd.append("act",on?"favorite_del":"favorite_add");fd.append("goods_id",id);fd.append("_csrf",window.__mall.csrf);'
                . 'fetch(location.pathname,{method:"POST",body:fd,headers:{"X-Requested-With":"XMLHttpRequest"},credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){'
                . 'if(j&&j.ok){if(on){b.classList.remove("on");b.textContent="收藏";}else{b.classList.add("on");b.textContent="已收藏";}}else if(j&&j.login){location.href=j.url;}}).catch(function(){});'
                . '});</script>';
        }
        return $html;
    }

    /** 内联样式（51puer 令牌；源文件 assets/mall.css） */
    private static function css(): string
    {
        static $css = null;
        if ($css === null) {
            $css = '<style data-mall>' . (string)file_get_contents(__DIR__ . '/assets/mall.css') . '</style>';
        }
        return $css;
    }
}

/** 第三方插件独立页兜底：/mall 单段经 plugin_page 走这里（与内核 case 'mall' 同入口） */
function yc_mall_page(string $slug): array
{
    return Mall::dispatch([], $_GET);
}

/** 商品卡 / 购物车 / 订单缩略图助手：本地 WebP 走 _t.webp 缩略图，外链/非 webp 原样返回。
 *  依赖 imgopt 插件的 ImgOpt::thumb；未启用时退化为原图（不影响功能）。 */
if (!function_exists('mallThumb')) {
    function mallThumb(string $u): string
    {
        if (class_exists('ImgOpt')) {
            return ImgOpt::thumb($u);
        }
        return $u;
    }
}

/**
 * 轻量 HTML 净化：用于渲染从 51puer 映射来的商品详情（富文本 HTML）。
 * 去掉危险标签/属性，保留图文结构；映射到本地的图片已改写为 /uploads/mall 路径。
 * 注意：本函数仅面向「可信的映射数据」，非用户自由输入场景。
 */
if (!function_exists('mallSanitizeHtml')) {
    function mallSanitizeHtml(string $html): string
    {
        $html = (string)$html;
        // 移除危险块级标签及其内容
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html);
        $html = preg_replace('#<iframe\b[^>]*>.*?</iframe>#is', '', $html);
        $html = preg_replace('#<object\b[^>]*>.*?</object>#is', '', $html);
        $html = preg_replace('#<embed\b[^>]*>#is', '', $html);
        $html = preg_replace('#<link\b[^>]*>#is', '', $html);
        $html = preg_replace('#<meta\b[^>]*>#is', '', $html);
        $html = preg_replace('#<!--.*?-->#s', '', $html);
        // 移除事件属性与 javascript: 协议
        $html = preg_replace('#\s(on[a-z]+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = preg_replace('#\s(on[a-z]+)\s*=\s*#i', '', $html);
        $html = preg_replace('#javascript:#i', '', $html);
        // 去掉 51puer 详情自带的 inline style / class（与 mall.css 冲突、且含乱码字体声明），
        // 改由 .mall-detail-body 下的通用样式统一控制
        $html = preg_replace('#\sstyle\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = preg_replace('#\sclass\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        // 去掉指向站外的外链（保留站内相对链接），避免详情里残留 51puer 等外链
        $html = preg_replace('#\shref\s*=\s*("https?://[^"]*"|\'https?://[^\']*\'|https?://[^\s>]+)#i', '', $html);
        // 移除 <a> 标签本身（保留文字），51puer 详情里没有需要保留的站内链接
        $html = preg_replace('#</?a\b[^>]*>#i', '', $html);
        // 去掉正文中残留的站外可见 URL 文本（如 www.51puer.com/...）
        $html = preg_replace('#https?://[^\s<>"\']+#i', '', $html);
        // 去掉 iframe/object/embed 残留标签名（若上面正则未闭合）
        $html = preg_replace('#</?(iframe|object|embed|script|style|link|meta)\b[^>]*>#i', '', $html);
        return $html;
    }
}
