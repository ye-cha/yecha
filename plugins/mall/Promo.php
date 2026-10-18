<?php
declare(strict_types=1);
/**
 * M11 促销玩法：秒杀 / 拼团 / 砍价 / 组合优惠
 * - 以 trait 挂进 Mall（对齐 M7 Member.php 的解耦路线），不改动 lib.php 主体结构
 * - 定价入口：unitPrice()（单价管线）+ bundleDiscount()（订单级组合优惠）
 * - 路由：/mall/promo/{seckill|groupon|bargain|bundle}[/{id}]
 */
trait MallPromo
{
    /* ========================= 开关 / 活动查询 ========================= */

    /** 促销总开关 + 分项开关 */
    private static function promoOn(string $k): bool
    {
        if (get_option('mall.promo_on', '1') !== '1') { return false; }
        return get_option($k, '1') === '1';
    }

    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** 取 SKU 正在进行的秒杀（取最低价且活动库存未售罄） */
    public static function activeSeckill(int $skuId): ?array
    {
        if ($skuId <= 0 || !self::promoOn('mall.seckill_on')) { return null; }
        $row = Db::row('SELECT * FROM ' . self::t('seckill')
            . ' WHERE sku_id=? AND status=1 AND is_delete_time=0 AND start_time<=? AND end_time>=? AND seckill_stock>sold'
            . ' ORDER BY price ASC, id ASC LIMIT 1', [$skuId, self::now(), self::now()]);
        return $row === null ? null : $row;
    }

    /** 取 SKU 正在进行的拼团活动 */
    public static function activeGroupon(int $skuId): ?array
    {
        if ($skuId <= 0 || !self::promoOn('mall.groupon_on')) { return null; }
        $row = Db::row('SELECT * FROM ' . self::t('groupon')
            . ' WHERE sku_id=? AND status=1 AND is_delete_time=0'
            . ' AND (start_time IS NULL OR start_time<=?) AND (end_time IS NULL OR end_time>=?)'
            . ' ORDER BY price ASC, id ASC LIMIT 1', [$skuId, self::now(), self::now()]);
        return $row === null ? null : $row;
    }

    /** 取 SKU 正在进行的砍价活动 */
    public static function activeBargain(int $skuId): ?array
    {
        if ($skuId <= 0 || !self::promoOn('mall.bargain_on')) { return null; }
        $row = Db::row('SELECT * FROM ' . self::t('bargain')
            . ' WHERE sku_id=? AND status=1 AND is_delete_time=0'
            . ' AND (start_time IS NULL OR start_time<=?) AND (end_time IS NULL OR end_time>=?)'
            . ' ORDER BY id ASC LIMIT 1', [$skuId, self::now(), self::now()]);
        return $row === null ? null : $row;
    }

    /** 活动类型 → 中文标签（订单明细展示用） */
    public static function promoLabel(string $type): string
    {
        return ['seckill' => '限时秒杀', 'groupon' => '拼团', 'bargain' => '砍价', 'bundle' => '组合优惠'][$type] ?? '';
    }

    /** 秒杀已购数量（按有效订单统计限购） */
    private static function promoBought(int $uid, string $type, int $promoId): int
    {
        if ($uid <= 0) { return 0; }
        return (int)Db::one('SELECT IFNULL(SUM(og.num),0) FROM ' . self::t('order_goods') . ' og'
            . ' JOIN ' . self::t('order') . ' o ON o.id=og.order_id'
            . ' WHERE og.promo_type=? AND og.promo_id=? AND o.uid=? AND o.status IN (1,2,3)', [$type, $promoId, $uid]);
    }

    /* ========================= 定价管线 ========================= */

    /**
     * 统一单价管线（独占 + 叠加规则）
     * 规则：秒杀 / 拼团 / 砍价 为互斥活动价（取其一，按最优惠）；会员等级折扣作用于原价，
     *       默认不与活动价叠加（mall.level_on_promo=1 时可叠加）。
     * @param array $ctx ['groupon_id'=>int,'team_id'=>int,'bargain_id'=>int,'launch_id'=>int]
     * @return array price/origin/type/id/label/level_name/level_discount
     */
    public static function unitPrice(int $goodsId, int $skuId, float $base, int $uid, array $ctx = []): array
    {
        $out = [
            'price' => $base, 'origin' => $base, 'type' => '', 'id' => 0,
            'label' => '', 'level_name' => '', 'level_discount' => 1.0,
            // ★ 被限购挡下的活动信息（供调用方提示用户，而不是静默改价）
            //   key = 活动类型，值 = ['title'=>..., 'limit'=>..., 'bought'=>...]
            'limited' => null,
        ];
        $best = null; // ['price','type','id','label']

        // 1) 秒杀
        $sk = self::activeSeckill($skuId);
        if ($sk !== null) {
            $limit = (int)$sk['per_limit'];
            $bought = self::promoBought($uid, 'seckill', (int)$sk['id']);
            $ok = $limit <= 0 || $bought < $limit;
            if (!$ok) {
                // 记录"有活动但被限购挡住"，而不是无声丢弃 —— 否则上层无从告知用户
                $out['limited'] = [
                    'type' => 'seckill',
                    'id' => (int)$sk['id'],
                    'title' => (string)($sk['title'] ?? ''),
                    'limit' => $limit,
                    'bought' => $bought,
                ];
                $sk = null;
            }
        }
        if ($sk !== null) {
            $best = ['price' => (float)$sk['price'], 'type' => 'seckill', 'id' => (int)$sk['id'], 'label' => '限时秒杀'];
        }

        // 2) 拼团（需结算上下文指定活动）
        $gid = (int)($ctx['groupon_id'] ?? 0);
        if ($gid > 0) {
            $gp = Db::row('SELECT * FROM ' . self::t('groupon') . ' WHERE id=? AND status=1 AND is_delete_time=0 LIMIT 1', [$gid]);
            if ($gp !== null && (int)$gp['sku_id'] === $skuId) {
                $cand = ['price' => (float)$gp['price'], 'type' => 'groupon', 'id' => (int)$gp['id'], 'label' => $gp['title'] !== '' ? (string)$gp['title'] : '拼团价'];
                if ($best === null || $cand['price'] < $best['price']) { $best = $cand; }
            }
        }

        // 3) 砍价（需上下文指定发起记录；归属校验）
        $lid = (int)($ctx['launch_id'] ?? 0);
        if ($lid > 0 && $uid > 0) {
            $lc = Db::row('SELECT * FROM ' . self::t('bargain_launch')
                . ' WHERE id=? AND uid=? AND status IN (0,1) AND expire_at>=? LIMIT 1', [$lid, $uid, self::now()]);
            if ($lc !== null && (int)$lc['sku_id'] === $skuId) {
                $cand = ['price' => (float)$lc['current_price'], 'type' => 'bargain', 'id' => (int)$lc['bargain_id'], 'label' => '砍价到 ¥' . number_format((float)$lc['current_price'], 2, '.', '')];
                if ($best === null || $cand['price'] < $best['price']) { $best = $cand; }
            }
        }

        // 4) 会员等级折扣（作用于原价；默认不与活动价叠加）
        $lv = self::memberLevelDiscount($uid);
        $out['level_name'] = $lv['name'];
        $out['level_discount'] = $lv['discount'];
        $member = round($base * $lv['discount'], 2);
        $stack = get_option('mall.level_on_promo', '0') === '1';
        if ($best !== null && !$stack) {
            // 活动价与会员折扣取更低者
            if ($member < $best['price']) {
                $out['price'] = $member;
                $out['type'] = 'normal';
                $out['id'] = 0;
                $out['label'] = $lv['name'] !== '' ? $lv['name'] . '价' : '';
            } else {
                $out['price'] = $best['price'];
                $out['type'] = $best['type'];
                $out['id'] = $best['id'];
                $out['label'] = $best['label'];
            }
        } elseif ($best !== null && $stack) {
            $out['price'] = round($best['price'] * $lv['discount'], 2);
            $out['type'] = $best['type'];
            $out['id'] = $best['id'];
            $out['label'] = $best['label'] . ' + ' . $lv['name'] . '折';
        } else {
            $out['price'] = $member;
            $out['type'] = '';
            $out['id'] = 0;
            $out['label'] = ($lv['discount'] < 1 && $lv['name'] !== '') ? $lv['name'] . '价' : '';
        }
        $out['origin'] = $base;
        if ($out['price'] > $base) { $out['price'] = $base; }
        return $out;
    }

    /**
     * 订单级组合优惠：返回最佳套餐优惠
     * @param array $items checkoutItems 结果（含 goods_id/sku_id/num/price）
     */
    public static function bundleDiscount(array $items, float $total): array
    {
        $out = ['discount' => 0.0, 'label' => '', 'id' => 0];
        if (!self::promoOn('mall.bundle_on') || $items === [] || $total <= 0) { return $out; }
        $now = self::now();
        $bundles = Db::rows('SELECT * FROM ' . self::t('bundle')
            . ' WHERE status=1 AND is_delete_time=0 ORDER BY sort ASC, id ASC');
        foreach ($bundles as $b) {
            $rows = Db::rows('SELECT * FROM ' . self::t('bundle_goods') . ' WHERE bundle_id=? ORDER BY sort ASC', [(int)$b['id']]);
            if ($rows === []) { continue; }
            $sum = 0.0;
            $sets = PHP_INT_MAX;
            $need = 0;
            foreach ($rows as $bg) {
                $has = 0;
                $lineSum = 0.0;
                foreach ($items as $it) {
                    if ((int)$it['goods_id'] !== (int)$bg['goods_id']) { continue; }
                    if ((int)$bg['sku_id'] > 0 && (int)$it['sku_id'] !== (int)$bg['sku_id']) { continue; }
                    $has += (int)$it['num'];
                    $lineSum += (float)$it['price'] * (int)$it['num'];
                }
                if ($has < (int)$bg['num']) { $sets = 0; break; }
                $need += (int)$bg['num'];
                $sum += $lineSum;
                $sets = min($sets, intdiv($has, (int)$bg['num']));
            }
            $d = 0.0;
            if ((int)$b['type'] === 2) { // 套餐固定价：可多份，每份按 (原价合计 - 套餐价) 优惠
                if ($sets > 0 && $sum > 0) {
                    $perDay = $sum / max(1, $sets);
                    if ($perDay > (float)$b['price']) { $d = ($perDay - (float)$b['price']) * $sets; }
                }
            } else { // 满件减：套餐内商品合计件数达标即减
                if ($need >= (int)$b['need_num'] && $sum > 0) {
                    $d = min((float)$b['price'], $sum);
                }
            }
            if ($d > $out['discount']) {
                $out = ['discount' => round($d, 2), 'label' => (string)$b['title'], 'id' => (int)$b['id']];
            }
        }
        if ($out['discount'] > 0) { $out['discount'] = min($out['discount'], $total); }
        return $out;
    }

    /* ========================= 商品详情页活动块 ========================= */

    /** 商品详情页：当前 SKU 参与的活动（无活动返回空串） */
    public static function goodsPromoBlock(int $goodsId, int $skuId, float $price): string
    {
        if (!self::promoOn('mall.seckill_on') && !self::promoOn('mall.groupon_on') && !self::promoOn('mall.bargain_on')) { return ''; }
        $h = '';
        $sk = self::activeSeckill($skuId);
        if ($sk !== null) {
            $left = max(0, (int)$sk['seckill_stock'] - (int)$sk['sold']);
            $h .= '<div class="mall-promo-row sk"><span class="mall-promo-tag sk">限时秒杀</span>'
                . '<span class="mall-promo-price">¥' . e(number_format((float)$sk['price'], 2, '.', '')) . '</span>'
                . '<s>¥' . e(number_format($price, 2, '.', '')) . '</s>'
                . '<span class="mall-promo-tip">剩余 ' . $left . ' 件' . ((int)$sk['per_limit'] > 0 ? '，每人限购 ' . (int)$sk['per_limit'] . ' 件' : '') . '</span>'
                . '<span class="mall-promo-clock" data-end="' . e((string)$sk['end_time']) . '">活动截止：' . e((string)$sk['end_time']) . '</span>'
                . '</div>';
        }
        $gp = self::activeGroupon($skuId);
        if ($gp !== null) {
            $saved = max(0, round($price - (float)$gp['price'], 2));
            $h .= '<div class="mall-promo-row gp"><span class="mall-promo-tag gp">拼团</span>'
                . '<span class="mall-promo-price">¥' . e(number_format((float)$gp['price'], 2, '.', '')) . '</span>'
                . '<span class="mall-promo-tip">' . (int)$gp['group_size'] . ' 人成团，省 ¥' . e(number_format($saved, 2, '.', '')) . '</span>'
                . '<a class="mall-btn-go" href="/mall/promo/groupon/' . (int)$gp['id'] . '/">去拼团</a>'
                . '</div>';
        }
        $bg = self::activeBargain($skuId);
        if ($bg !== null) {
            $h .= '<div class="mall-promo-row bg"><span class="mall-promo-tag bg">砍价</span>'
                . '<span class="mall-promo-price">最低 ¥' . e(number_format((float)$bg['floor_price'], 2, '.', '')) . '</span>'
                . '<span class="mall-promo-tip">原价 ¥' . e(number_format($price, 2, '.', '')) . '，' . (int)$bg['hours'] . ' 小时內可砍</span>'
                . '<a class="mall-btn-go" href="/mall/promo/bargain/' . (int)$bg['id'] . '/">去砍价</a>'
                . '</div>';
        }
        return $h === '' ? '' : '<div class="mall-promo">' . $h . '</div>';
    }

    /* ========================= 页面路由 ========================= */

    /** /mall/promo/{type}[/{id}] */
    public static function promoPage(array $path, array $query): array
    {
        $type = (string)($path[1] ?? '');
        $id = (int)($path[2] ?? 0);
        $cats = self::categories();
        $head = self::css() . self::topbar() . self::header() . self::navBar($cats);
        switch ($type) {
            case 'seckill':
                $title = '限时秒杀';
                $body = self::seckillList();
                break;
            case 'groupon':
                if ($id > 0) { $title = '拼团详情'; $body = self::grouponDetail($id); break; }
                $title = '拼团专区';
                $body = self::grouponList();
                break;
            case 'bargain':
                if ($id > 0) { $title = '砍价详情'; $body = self::bargainDetail($id); break; }
                $title = '砍价专区';
                $body = self::bargainList();
                break;
            case 'topic':
                if ($id > 0) { $title = '专题'; $body = self::topicDetail($id); break; }
                $title = '专题活动';
                $body = self::topicList();
                break;
            case 'discounts':
                $title = '优惠专区';
                $body = self::discountList();
                break;
            case 'bundle':
                if ($id > 0) { $title = '组合套餐'; $body = self::bundleDetail($id); break; }
                $title = '组合优惠';
                $body = self::bundleList();
                break;
            default:
                $title = '促销活动';
                $body = self::promoIndex();
                break;
        }
        return ['html' => $head . '<div class="mall-w"><div class="mall-wp"><h2 class="mall-wp-hd">' . e($title) . '</h2>' . $body . '</div></div>' . self::footer(), 'title' => $title . ' - 商城'];
    }

    /** 活动总入口：四宫格 */
    /* ---------- 专题活动（对标 51puer /topic.html） ---------- */

    /** 专题是否存在（表可能未建） */
    private static function topicReady(): bool
    {
        try {
            return (bool)Db::rows('SHOW TABLES LIKE \'' . self::t('topic') . '\'');
        } catch (Throwable $e) {
            return false;
        }
    }

    /** 专题列表 */
    private static function topicList(): string
    {
        if (!self::topicReady()) { return self::promoEmpty('专题功能尚未初始化，请先在后台创建专题。'); }
        $rows = Db::rows('SELECT id, title, summary, cover FROM ' . self::t('topic')
            . ' WHERE status=1 AND is_delete_time=0 ORDER BY sort ASC, id DESC LIMIT 60');
        if ($rows === []) { return self::promoEmpty('暂无专题活动。'); }
        $h = '<div class="mall-topic-grid">';
        foreach ($rows as $r) {
            $n = (int)Db::one('SELECT COUNT(*) FROM ' . self::t('topic_goods') . ' WHERE topic_id=?', [(int)$r['id']]);
            $h .= '<a class="mall-topic-card" href="/topic/' . (int)$r['id'] . '.html">'
                . '<div class="mall-topic-img">'
                . ((string)$r['cover'] !== ''
                    ? '<img src="' . e(mallThumb((string)$r['cover'])) . '" alt="">'
                    : '<div class="mall-img-ph">专题图</div>')
                . '</div>'
                . '<div class="mall-topic-tx"><b>' . e((string)$r['title']) . '</b>'
                . ((string)$r['summary'] !== '' ? '<span>' . e((string)$r['summary']) . '</span>' : '')
                . ($n > 0 ? '<em>' . $n . ' 件商品</em>' : '')
                . '</div></a>';
        }
        return $h . '</div>';
    }

    /** 专题详情 */
    private static function topicDetail(int $id): string
    {
        if (!self::topicReady()) { return self::promoEmpty('专题功能尚未初始化。'); }
        $t = Db::row('SELECT * FROM ' . self::t('topic') . ' WHERE id=? AND status=1 AND is_delete_time=0 LIMIT 1', [$id]);
        if ($t === null) { return self::promoEmpty('专题不存在或已下线。'); }
        $h = '<div class="mall-topic-detail">';
        $h .= '<div class="mall-topic-hd">'
            . ((string)$t['cover'] !== '' ? '<div class="cv"><img src="' . e((string)$t['cover']) . '" alt=""></div>' : '')
            . '<div class="tx"><h2>' . e((string)$t['title']) . '</h2>'
            . ((string)$t['summary'] !== '' ? '<p>' . e((string)$t['summary']) . '</p>' : '')
            . '</div></div>';
        // 富文本正文
        $body = trim((string)($t['content'] ?? ''));
        if ($body !== '') {
            $h .= '<div class="mall-topic-body">'
                . (str_contains($body, '<') ? self::mallSanitizeHtml($body) : \Markdown::render($body))
                . '</div>';
        }
        // 关联商品
        $goods = Db::rows('SELECT g.id, g.title, g.cover, g.min_price, g.market_price, g.sales_count'
            . ' FROM ' . self::t('topic_goods') . ' tg JOIN ' . self::t('goods') . ' g ON g.id=tg.goods_id'
            . ' WHERE tg.topic_id=? AND g.status=1 AND g.is_delete_time=0'
            . ' ORDER BY tg.sort ASC, tg.id ASC LIMIT 60', [$id]);
        if ($goods !== []) {
            $h .= '<h3 class="mall-topic-gt">专题商品（' . count($goods) . '）</h3><div class="mall-rec-grid">';
            foreach ($goods as $g) {
                $h .= self::cardHtml($g);
            }
            $h .= '</div>';
        } else {
            $h .= '<p class="dim" style="color:#999;font-size:13px">该专题暂未挂商品。</p>';
        }
        return $h . '</div>';
    }

    /**
     * 优惠专区：把所有"当前有活动价"的商品聚合成一页（对齐 51puer /discounts.html）。
     * 数据来源：秒杀 + 拼团 + 砍价 活动表，按折扣力度排序。
     */
    private static function discountList(): string
    {
        $rows = [];
        if (self::promoOn('mall.seckill_on')) {
            foreach (Db::rows('SELECT s.id, s.title, s.price, s.goods_id, s.sku_id, s.seckill_stock, s.sold,'
                . ' s.per_limit, s.end_time, g.title gtitle, g.cover, g.min_price'
                . ' FROM ' . self::t('seckill') . ' s JOIN ' . self::t('goods') . ' g ON g.id=s.goods_id'
                . ' WHERE s.status=1 AND s.is_delete_time=0 AND g.status=1 AND g.is_delete_time=0'
                . ' AND s.seckill_stock > s.sold LIMIT 100') as $r) {
                $rows[] = ['kind' => '秒杀', 'k' => 'seckill', 'id' => (int)$r['id'],
                    'goods_id' => (int)$r['goods_id'], 'title' => (string)$r['gtitle'],
                    'cover' => (string)$r['cover'], 'price' => (float)$r['price'],
                    'origin' => (float)$r['min_price'], 'extra' => '剩余 ' . max(0, (int)$r['seckill_stock'] - (int)$r['sold']) . ' 件'];
            }
        }
        if (self::promoOn('mall.groupon_on')) {
            foreach (Db::rows('SELECT p.id, p.price, p.group_size, p.goods_id, g.title gtitle, g.cover, g.min_price'
                . ' FROM ' . self::t('groupon') . ' p JOIN ' . self::t('goods') . ' g ON g.id=p.goods_id'
                . ' WHERE p.status=1 AND p.is_delete_time=0 AND g.status=1 AND g.is_delete_time=0 LIMIT 100') as $r) {
                $rows[] = ['kind' => '拼团', 'k' => 'groupon', 'id' => (int)$r['id'],
                    'goods_id' => (int)$r['goods_id'], 'title' => (string)$r['gtitle'],
                    'cover' => (string)$r['cover'], 'price' => (float)$r['price'],
                    'origin' => (float)$r['min_price'], 'extra' => (int)$r['group_size'] . ' 人成团'];
            }
        }
        if (self::promoOn('mall.bargain_on')) {
            foreach (Db::rows('SELECT b.id, b.floor_price, b.goods_id, g.title gtitle, g.cover, g.min_price'
                . ' FROM ' . self::t('bargain') . ' b JOIN ' . self::t('goods') . ' g ON g.id=b.goods_id'
                . ' WHERE b.status=1 AND b.is_delete_time=0 AND g.status=1 AND g.is_delete_time=0 LIMIT 100') as $r) {
                $rows[] = ['kind' => '砍价', 'k' => 'bargain', 'id' => (int)$r['id'],
                    'goods_id' => (int)$r['goods_id'], 'title' => (string)$r['gtitle'],
                    'cover' => (string)$r['cover'], 'price' => (float)$r['floor_price'],
                    'origin' => (float)$r['min_price'], 'extra' => '砍到底价'];
            }
        }
        if ($rows === []) {
            return self::promoEmpty('当前没有进行中的优惠活动。');
        }
        // 按折扣力度（省多少比例）降序
        usort($rows, static function ($a, $b) {
            $ra = $a['origin'] > 0 ? $a['price'] / $a['origin'] : 1;
            $rb = $b['origin'] > 0 ? $b['price'] / $b['origin'] : 1;
            return $ra <=> $rb;
        });
        $h = '<div class="mall-rec"><div class="mall-rec-grid">';
        foreach ($rows as $r) {
            $off = ($r['origin'] > 0 && $r['price'] < $r['origin'])
                ? number_format((1 - $r['price'] / $r['origin']) * 100, 1, '.', '') : '';
            $h .= '<a class="mall-card" href="/goods-' . (int)$r['goods_id'] . '.html">'
                . '<div class="mall-card-img">'
                . ((string)$r['cover'] !== '' ? '<img src="' . e(mallThumb((string)$r['cover'])) . '" alt="">' : '<div class="mall-img-ph">商品图</div>')
                . '<span class="mall-card-tag">' . e((string)$r['kind']) . '</span></div>'
                . '<div class="mall-card-title">' . e((string)$r['title']) . '</div>'
                . '<div class="mall-card-price">¥' . e(number_format((float)$r['price'], 2, '.', ''))
                . ($off !== '' ? ' <s>' . e(number_format((float)$r['origin'], 2, '.', '')) . '</s>'
                    . ' <em class="mall-off">' . e($off) . '% off</em>' : '')
                . '</div>'
                . '<div class="mall-card-sub">' . e((string)$r['extra']) . '</div>'
                . '</a>';
        }
        return $h . '</div></div>';
    }
    private static function promoIndex(): string
    {
        $items = [
            ['seckill', '限时秒杀', '整点开抢，限量低价', self::promoOn('mall.seckill_on')],
            ['groupon', '拼团专区', '邀友成团，共享优惠', self::promoOn('mall.groupon_on')],
            ['bargain', '砍价专区', '好友帮砍，底价入手', self::promoOn('mall.bargain_on')],
            ['bundle', '组合优惠', '搭配购买，更省更多', self::promoOn('mall.bundle_on')],
            ['discounts', '优惠专区', '全部活动价商品一览', true],  // 聚合入口
        ];
        $h = '<div class="mall-promo-nav">';
        foreach ($items as $it) {
            if (!$it[3]) { continue; }
            $h .= '<a class="mall-promo-nav-item" href="/mall/promo/' . $it[0] . '/"><b>' . e($it[1]) . '</b><span>' . e($it[2]) . '</span></a>';
        }
        return $h . '</div>';
    }

    private static function promoEmpty(string $t): string
    {
        return '<div class="mall-rec-ph">' . e($t) . '</div>';
    }

    /* ---------- 秒杀列表 ---------- */
    private static function seckillList(): string
    {
        if (!self::promoOn('mall.seckill_on')) { return self::promoEmpty('秒杀活动暂未开启。'); }
        $now = self::now();
        $rows = Db::rows('SELECT s.*, g.title gtitle, g.cover, g.market_price orig, sk.price skprice, sk.spec_text'
            . ' FROM ' . self::t('seckill') . ' s'
            . ' JOIN ' . self::t('goods') . ' g ON g.id=s.goods_id'
            . ' JOIN ' . self::t('goods_sku') . ' sk ON sk.id=s.sku_id'
            . ' WHERE s.status=1 AND s.is_delete_time=0 AND s.end_time>=? ORDER BY s.start_time ASC, s.sort ASC', [$now]);
        if ($rows === []) { return self::promoEmpty('暂无进行中的秒杀活动。'); }
        $h = '<div class="mall-promo-grid">';
        foreach ($rows as $r) {
            $left = max(0, (int)$r['seckill_stock'] - (int)$r['sold']);
            $started = (string)$r['start_time'] <= $now;
            $soldOut = $left <= 0;
            $pct = (int)$r['seckill_stock'] > 0 ? min(100, (int)round((int)$r['sold'] * 100 / (int)$r['seckill_stock'])) : 100;
            $h .= '<div class="mall-promo-card">'
                . '<a class="mall-card-img" href="/mall/goods/' . (int)$r['goods_id'] . '.html">'
                . ((string)$r['cover'] !== '' ? '<img src="' . e((string)$r['cover']) . '" alt="' . e((string)$r['gtitle']) . '">' : '<div class="mall-img-ph">商品图</div>')
                . '</a>'
                . '<div class="mall-promo-info">'
                . '<div class="mall-card-title">' . e((string)$r['gtitle']) . '<span class="mall-promo-spec">' . e((string)$r['spec_text']) . '</span></div>'
                . '<div class="mall-promo-p"><span class="mall-promo-price">¥' . e(number_format((float)$r['price'], 2, '.', '')) . '</span><s>¥' . e(number_format((float)$r['skprice'], 2, '.', '')) . '</s></div>'
                . '<div class="mall-promo-bar"><i style="width:' . $pct . '%"></i></div>'
                . '<div class="mall-promo-meta">已抢 ' . (int)$r['sold'] . ' / ' . (int)$r['seckill_stock'] . ' 件・剩余 ' . $left . '</div>'
                . '<div class="mall-promo-acts">'
                . ($soldOut
                    ? '<span class="mall-btn-buy off">已抢光</span>'
                    : ($started
                        ? '<a class="mall-btn-buy" href="/mall/checkout/?buy=' . (int)$r['sku_id'] . ':1">立即抢购</a>'
                        : '<span class="mall-btn-buy off">' . e((string)$r['start_time']) . ' 开抢</span>'))
                . '</div></div></div>';
        }
        return $h . '</div>';
    }

    /* ---------- 拼团 ---------- */
    private static function grouponList(): string
    {
        if (!self::promoOn('mall.groupon_on')) { return self::promoEmpty('拼团活动暂未开启。'); }
        $rows = Db::rows('SELECT p.*, g.title gtitle, g.cover, sk.price skprice, sk.spec_text'
            . ' FROM ' . self::t('groupon') . ' p'
            . ' JOIN ' . self::t('goods') . ' g ON g.id=p.goods_id'
            . ' JOIN ' . self::t('goods_sku') . ' sk ON sk.id=p.sku_id'
            . ' WHERE p.status=1 AND p.is_delete_time=0 ORDER BY p.sort ASC, p.id DESC');
        if ($rows === []) { return self::promoEmpty('暂无拼团活动。'); }
        $h = '<div class="mall-promo-grid">';
        foreach ($rows as $r) {
            $save = max(0, round((float)$r['skprice'] - (float)$r['price'], 2));
            $teams = (int)Db::one('SELECT COUNT(*) FROM ' . self::t('groupon_team') . ' WHERE groupon_id=? AND status=0 AND is_delete_time=0 AND expire_at>=?', [(int)$r['id'], self::now()]);
            $h .= '<div class="mall-promo-card">'
                . '<a class="mall-card-img" href="/mall/promo/groupon/' . (int)$r['id'] . '/">'
                . ((string)$r['cover'] !== '' ? '<img src="' . e((string)$r['cover']) . '" alt="' . e((string)$r['gtitle']) . '">' : '<div class="mall-img-ph">商品图</div>')
                . '</a>'
                . '<div class="mall-promo-info">'
                . '<div class="mall-card-title">' . e((string)$r['gtitle']) . '<span class="mall-promo-spec">' . e((string)$r['spec_text']) . '</span></div>'
                . '<div class="mall-promo-p"><span class="mall-promo-price">¥' . e(number_format((float)$r['price'], 2, '.', '')) . '</span><s>¥' . e(number_format((float)$r['skprice'], 2, '.', '')) . '</s></div>'
                . '<div class="mall-promo-meta">' . (int)$r['group_size'] . ' 人成团・省 ¥' . e(number_format($save, 2, '.', '')) . '・' . $teams . ' 个团待拼</div>'
                . '<div class="mall-promo-acts"><a class="mall-btn-buy" href="/mall/promo/groupon/' . (int)$r['id'] . '/">去开团</a></div>'
                . '</div></div>';
        }
        return $h . '</div>';
    }

    private static function grouponDetail(int $id): string
    {
        $p = Db::row('SELECT p.*, g.title gtitle, g.cover, sk.price skprice, sk.spec_text, sk.stock'
            . ' FROM ' . self::t('groupon') . ' p'
            . ' JOIN ' . self::t('goods') . ' g ON g.id=p.goods_id'
            . ' JOIN ' . self::t('goods_sku') . ' sk ON sk.id=p.sku_id'
            . ' WHERE p.id=? AND p.is_delete_time=0 LIMIT 1', [$id]);
        if ($p === null) { return self::promoEmpty('拼团活动不存在或已结束。'); }
        $uid = self::uid();
        $self = self::loginGuide('/mall/promo/groupon/' . $id . '/', '登录后参与拼团');
        $save = max(0, round((float)$p['skprice'] - (float)$p['price'], 2));

        $h = '<div class="mall-promo-top">'
            . '<div class="mall-card-img">' . ((string)$p['cover'] !== '' ? '<img src="' . e((string)$p['cover']) . '" alt="">' : '<div class="mall-img-ph">商品图</div>') . '</div>'
            . '<div class="mall-promo-topinfo">'
            . '<h3>' . e((string)$p['gtitle']) . '<span class="mall-promo-spec">' . e((string)$p['spec_text']) . '</span></h3>'
            . '<div class="mall-promo-p big"><span class="mall-promo-price">¥' . e(number_format((float)$p['price'], 2, '.', '')) . '</span><s>¥' . e(number_format((float)$p['skprice'], 2, '.', '')) . '</s><span class="mall-promo-tip">省 ¥' . e(number_format($save, 2, '.', '')) . '</span></div>'
            . '<div class="mall-promo-rule">规则：' . (int)$p['group_size'] . ' 人成团，团有效期 ' . (int)$p['hours'] . ' 小时，超时未成团自动关闭。</div>'
            . '<div class="mall-promo-acts">';
        if ($uid > 0) {
            $h .= '<form method="post" action="/mall/promo/groupon/' . $id . '/">' . (\Csrf::field())
                . '<input type="hidden" name="act" value="promo_groupon_join">'
                . '<input type="hidden" name="groupon_id" value="' . $id . '">'
                . '<input type="hidden" name="team_id" value="0">'
                . '<button class="mall-btn-buy" type="submit">我要开团</button></form>';
        } else {
            $h .= $self;
        }
        $h .= '</div></div></div>';

        // 可参与的团（进行中且未满）
        $teams = Db::rows('SELECT t.*, u.username FROM ' . self::t('groupon_team') . ' t'
            . ' LEFT JOIN ' . Db::t('users') . ' u ON u.id=t.leader_uid'
            . ' WHERE t.groupon_id=? AND t.status=0 AND t.is_delete_time=0 AND t.expire_at>=? AND t.joined_count<t.need_size'
            . ' ORDER BY t.id DESC LIMIT 20', [$id, self::now()]);
        $h .= '<h3 class="mall-wp-sub">可参与的团</h3>';
        if ($teams === []) {
            $h .= self::promoEmpty('暂无可参与的团，你可以直接开团。');
        } else {
            $h .= '<div class="mall-mm-tbl-wrap"><table class="mall-mm-tbl"><tr><th>团长</th><th>进度</th><th>剩余时间</th><th>操作</th></tr>';
            foreach ($teams as $t) {
                $mine = $uid > 0 && (int)Db::one('SELECT COUNT(*) FROM ' . self::t('groupon_join') . ' WHERE team_id=? AND uid=?', [(int)$t['id'], $uid]) > 0;
                $h .= '<tr><td>' . e((string)($t['username'] ?? '用户' . (int)$t['leader_uid'])) . '</td>'
                    . '<td>' . (int)$t['joined_count'] . ' / ' . (int)$t['need_size'] . '</td>'
                    . '<td class="mall-promo-clock dim" data-end="' . e((string)$t['expire_at']) . '">' . e((string)$t['expire_at']) . '</td>'
                    . '<td>' . ($mine
                        ? '<span class="mall-btn-go off">已参与</span>'
                        : ($uid > 0
                            ? '<form class="inline" method="post" action="/mall/promo/groupon/' . $id . '/">' . (\Csrf::field())
                                . '<input type="hidden" name="act" value="promo_groupon_join">'
                                . '<input type="hidden" name="groupon_id" value="' . $id . '">'
                                . '<input type="hidden" name="team_id" value="' . (int)$t['id'] . '">'
                                . '<button class="mall-btn-go" type="submit">参与该团</button></form>'
                            : '<a class="mall-btn-go" href="' . e(ui_login('/mall/promo/groupon/' . $id . '/')) . '">登录后参团</a>'))
                    . '</td></tr>';
            }
            $h .= '</table></div>';
        }
        return $h;
    }

    /* ---------- 砍价 ---------- */
    private static function bargainList(): string
    {
        if (!self::promoOn('mall.bargain_on')) { return self::promoEmpty('砍价活动暂未开启。'); }
        $rows = Db::rows('SELECT b.*, g.title gtitle, g.cover, sk.price skprice, sk.spec_text'
            . ' FROM ' . self::t('bargain') . ' b'
            . ' JOIN ' . self::t('goods') . ' g ON g.id=b.goods_id'
            . ' JOIN ' . self::t('goods_sku') . ' sk ON sk.id=b.sku_id'
            . ' WHERE b.status=1 AND b.is_delete_time=0 ORDER BY b.sort ASC, b.id DESC');
        if ($rows === []) { return self::promoEmpty('暂无砍价活动。'); }
        $h = '<div class="mall-promo-grid">';
        foreach ($rows as $r) {
            $save = max(0, round((float)$r['skprice'] - (float)$r['floor_price'], 2));
            $h .= '<div class="mall-promo-card">'
                . '<a class="mall-card-img" href="/mall/promo/bargain/' . (int)$r['id'] . '/">'
                . ((string)$r['cover'] !== '' ? '<img src="' . e((string)$r['cover']) . '" alt="">' : '<div class="mall-img-ph">商品图</div>')
                . '</a>'
                . '<div class="mall-promo-info">'
                . '<div class="mall-card-title">' . e((string)$r['gtitle']) . '<span class="mall-promo-spec">' . e((string)$r['spec_text']) . '</span></div>'
                . '<div class="mall-promo-p"><span class="mall-promo-price">底价 ¥' . e(number_format((float)$r['floor_price'], 2, '.', '')) . '</span><s>¥' . e(number_format((float)$r['skprice'], 2, '.', '')) . '</s></div>'
                . '<div class="mall-promo-meta">最多可砍 ¥' . e(number_format($save, 2, '.', '')) . '・' . (int)$r['hours'] . ' 小时内有效</div>'
                . '<div class="mall-promo-acts"><a class="mall-btn-buy" href="/mall/promo/bargain/' . (int)$r['id'] . '/">去砍价</a></div>'
                . '</div></div>';
        }
        return $h . '</div>';
    }

    private static function bargainDetail(int $id): string
    {
        $b = Db::row('SELECT b.*, g.title gtitle, g.cover, sk.price skprice, sk.spec_text'
            . ' FROM ' . self::t('bargain') . ' b'
            . ' JOIN ' . self::t('goods') . ' g ON g.id=b.goods_id'
            . ' JOIN ' . self::t('goods_sku') . ' sk ON sk.id=b.sku_id'
            . ' WHERE b.id=? AND b.is_delete_time=0 LIMIT 1', [$id]);
        if ($b === null) { return self::promoEmpty('砍价活动不存在或已结束。'); }
        $uid = self::uid();
        $origin = (float)$b['skprice'];
        $now = self::now();

        // 当前用户进行中的砍价
        // ★ 必须把「已砍到底价」(status=1) 也算进来：
        //   砍到底时 cut 处理器会置 status=1，而原查询只认 status=0，
        //   于是**砍到底价那一刻 UI 反而丢失购买入口**（退回「发起砍价」态）——
        //   数据没丢（带 launch_id 仍能按底价结算），但用户看不到按钮。
        $lc = null;
        $lcDone = null;   // 已砍到底、待下单
        if ($uid > 0) {
            $lc = Db::row('SELECT * FROM ' . self::t('bargain_launch')
                . ' WHERE bargain_id=? AND uid=? AND status=0 AND expire_at>=? ORDER BY id DESC LIMIT 1', [$id, $uid, $now]);
            if ($lc === null) {
                $lcDone = Db::row('SELECT * FROM ' . self::t('bargain_launch')
                    . ' WHERE bargain_id=? AND uid=? AND status=1 AND order_no=\'\' ORDER BY id DESC LIMIT 1', [$id, $uid]);
            }
        }
        $h = '<div class="mall-promo-top">'
            . '<div class="mall-card-img">' . ((string)$b['cover'] !== '' ? '<img src="' . e((string)$b['cover']) . '" alt="">' : '<div class="mall-img-ph">商品图</div>') . '</div>'
            . '<div class="mall-promo-topinfo">'
            . '<h3>' . e((string)$b['gtitle']) . '<span class="mall-promo-spec">' . e((string)$b['spec_text']) . '</span></h3>'
            . '<div class="mall-promo-p big"><s>¥' . e(number_format($origin, 2, '.', '')) . '</s>'
            . '<span class="mall-promo-price">底价 ¥' . e(number_format((float)$b['floor_price'], 2, '.', '')) . '</span></div>';

        if ($lc !== null || $lcDone !== null) {
            // 已砍到底价、待下单：用 lcDone 渲染（无「再砍一刀」，只有购买入口）
            $atFloor = ($lc === null && $lcDone !== null);
            if ($atFloor) {
                $lc = $lcDone;
            }
            // ★ 进度百分比：统一成字符串再输出。
            //   原写法 `e(min(100, (float)$pctTxt))` 会把 min() 的 float 结果直接喂给
            //   e(?string $s) → TypeError（声明了 strict_types），
            //   导致「发起砍价后详情页整页致命错误（仅 569 字节空白）」，
            //   连砍价按钮与 CSRF 都不渲染 → 砍价功能完全不可用。
            $pctRaw = $origin > 0 ? (1 - (float)$lc['current_price'] / $origin) * 100 : 0.0;
            $pctTxt = number_format($pctRaw, 1, '.', '');
            $pctBar = number_format(min(100.0, max(0.0, $pctRaw)), 1, '.', '');
            $h .= '<div class="mall-promo-cut">当前价 <b>¥' . e(number_format((float)$lc['current_price'], 2, '.', '')) . '</b>'
                . '・已砍 ' . e($pctTxt) . '%・' . (int)$lc['cut_count'] . ' 刀・' . (int)$lc['helper_count'] . ' 位好友助力</div>'
                . '<div class="mall-promo-bar"><i style="width:' . e($pctBar) . '%"></i></div>';
            $helps = Db::rows('SELECT * FROM ' . self::t('bargain_help') . ' WHERE launch_id=? ORDER BY id DESC LIMIT 10', [(int)$lc['id']]);
            if ($helps !== []) {
                $h .= '<ul class="mall-promo-helps">';
                foreach ($helps as $hp) {
                    $h .= '<li>' . e((string)$hp['helper_name']) . ' 砍掉 <b>¥' . e(number_format((float)$hp['cut_price'], 2, '.', '')) . '</b></li>';
                }
                $h .= '</ul>';
            }
            $h .= '<div class="mall-promo-acts">';
            if (!$atFloor) {
                $h .= '<form class="inline" method="post" action="/mall/promo/bargain/' . $id . '/">' . (\Csrf::field())
                    . '<input type="hidden" name="act" value="promo_bargain_cut">'
                    . '<input type="hidden" name="launch_id" value="' . (int)$lc['id'] . '">'
                    . '<button class="mall-btn-buy" type="submit">自己再砍一刀</button></form>';
            } else {
                $h .= '<div class="mall-promo-rule" style="color:#b3541e;font-weight:600">'
                    . '已砍到底价 ¥' . e(number_format((float)$lc['floor_price'], 2, '.', ''))
                    . '，可直接下单（' . (int)$lc['cut_count'] . ' 刀）</div>';
            }
            $h .= ' <a class="mall-btn-go" href="/mall/checkout/?buy=' . (int)$lc['sku_id'] . ':1&amp;launch_id=' . (int)$lc['id'] . '">按当前价购买</a>'
                . '</div>';
        } else {
            $h .= '<div class="mall-promo-rule">规则：发起砍价后 ' . (int)$b['hours'] . ' 小时内可邀好友帮砍，砍到底价即可下单。</div>'
                . '<div class="mall-promo-acts">';
            if ($uid > 0) {
                $h .= '<form method="post" action="/mall/promo/bargain/' . $id . '/">' . (\Csrf::field())
                    . '<input type="hidden" name="act" value="promo_bargain_start">'
                    . '<input type="hidden" name="bargain_id" value="' . $id . '">'
                    . '<button class="mall-btn-buy" type="submit">发起砍价</button></form>';
            } else {
                $h .= self::loginGuide('/mall/promo/bargain/' . $id . '/', '登录后发起砍价');
            }
            $h .= '</div>';
        }
        return $h . '</div></div>';
    }

    /* ---------- 组合优惠 ---------- */
    private static function bundleList(): string
    {
        if (!self::promoOn('mall.bundle_on')) { return self::promoEmpty('组合优惠暂未开启。'); }
        $rows = Db::rows('SELECT * FROM ' . self::t('bundle') . ' WHERE status=1 AND is_delete_time=0 ORDER BY sort ASC, id DESC');
        if ($rows === []) { return self::promoEmpty('暂无组合优惠套餐。'); }
        $h = '<div class="mall-promo-grid">';
        foreach ($rows as $r) {
            $h .= '<div class="mall-promo-card">'
                . '<a class="mall-card-img" href="/mall/promo/bundle/' . (int)$r['id'] . '/">'
                . ((string)$r['cover'] !== '' ? '<img src="' . e((string)$r['cover']) . '" alt="">' : '<div class="mall-img-ph">套餐图</div>')
                . '</a>'
                . '<div class="mall-promo-info">'
                . '<div class="mall-card-title">' . e((string)$r['title']) . '</div>'
                . '<div class="mall-promo-p">' . ((int)$r['type'] === 2
                    ? '<span class="mall-promo-price">套餐价 ¥' . e(number_format((float)$r['price'], 2, '.', '')) . '</span><s>¥' . e(number_format((float)$r['total_value'], 2, '.', '')) . '</s>'
                    : '<span class="mall-promo-price">满 ' . (int)$r['need_num'] . ' 件减 ¥' . e(number_format((float)$r['price'], 2, '.', '')) . '</span>') . '</div>'
                . '<div class="mall-promo-meta">' . e((string)$r['summary']) . '</div>'
                . '<div class="mall-promo-acts"><a class="mall-btn-buy" href="/mall/promo/bundle/' . (int)$r['id'] . '/">查看详情</a></div>'
                . '</div></div>';
        }
        return $h . '</div>';
    }

    private static function bundleDetail(int $id): string
    {
        $b = Db::row('SELECT * FROM ' . self::t('bundle') . ' WHERE id=? AND is_delete_time=0 LIMIT 1', [$id]);
        if ($b === null) { return self::promoEmpty('套餐不存在或已下架。'); }
        $items = Db::rows('SELECT bg.*, g.title gtitle, g.cover, IFNULL(sk.price,0) price, IFNULL(sk.spec_text,\'\') spec'
            . ' FROM ' . self::t('bundle_goods') . ' bg'
            . ' JOIN ' . self::t('goods') . ' g ON g.id=bg.goods_id'
            . ' LEFT JOIN ' . self::t('goods_sku') . ' sk ON sk.id=bg.sku_id'
            . ' WHERE bg.bundle_id=? ORDER BY bg.sort ASC', [$id]);
        if ($items === []) { return self::promoEmpty('该套餐暂未配置商品。'); }
        $total = 0.0;
        $skuIds = [];
        $h = '<div class="mall-bundle"><table class="mall-mm-tbl"><tr><th>商品</th><th>规格</th><th>数量</th><th>单价</th></tr>';
        foreach ($items as $it) {
            $skuId = (int)$it['sku_id'] > 0 ? (int)$it['sku_id'] : (int)Db::one('SELECT id FROM ' . self::t('goods_sku') . ' WHERE goods_id=? AND is_delete_time=0 ORDER BY is_default DESC, id ASC LIMIT 1', [(int)$it['goods_id']]);
            if ($skuId > 0) { $skuIds[] = $skuId; }
            $total += (float)$it['price'] * (int)$it['num'];
            $h .= '<tr><td><a href="/mall/goods/' . (int)$it['goods_id'] . '.html">' . e((string)$it['gtitle']) . '</a></td>'
                . '<td class="dim">' . e((string)$it['spec']) . '</td>'
                . '<td>' . (int)$it['num'] . '</td>'
                . '<td class="c-red">¥' . e(number_format((float)$it['price'], 2, '.', '')) . '</td></tr>';
        }
        $h .= '</table>';
        $save = max(0, round($total - ((int)$b['type'] === 2 ? (float)$b['price'] : (float)$b['price']), 2));
        $h .= '<div class="mall-bundle-sum">'
            . '<div>原价合计 <s>¥' . e(number_format($total, 2, '.', '')) . '</s></div>'
            . '<div class="big">' . ((int)$b['type'] === 2 ? '套餐价 <b class="c-red">¥' . e(number_format((float)$b['price'], 2, '.', '')) . '</b>' : '满 ' . (int)$b['need_num'] . ' 件立减 <b class="c-red">¥' . e(number_format((float)$b['price'], 2, '.', '')) . '</b>') . '</div>'
            . '<div class="mall-promo-tip">立省 ¥' . e(number_format($save, 2, '.', '')) . '</div>'
            . '<div class="mall-promo-acts"><a class="mall-btn-buy" href="/mall/checkout/?sku=' . e(implode(',', $skuIds)) . '">立即凑单购买</a></div>'
            . '</div></div>';
        return $h;
    }

    /* ========================= POST 动作 ========================= */

    /** 促销类 POST 动作（由 lib.php::handlePost 转发） */
    public static function promoPost(string $act, int $uid): void
    {
        switch ($act) {
            case 'promo_groupon_join': {
                if ($uid <= 0) { self::go(ui_login('/mall/promo/groupon/')); }
                $gid = (int)($_POST['groupon_id'] ?? 0);
                $teamId = (int)($_POST['team_id'] ?? 0);
                $p = Db::row('SELECT * FROM ' . self::t('groupon') . ' WHERE id=? AND status=1 AND is_delete_time=0 LIMIT 1', [$gid]);
                if ($p === null) { self::go('/mall/promo/groupon/' . $gid . '/', '拼团活动不存在或已结束'); }
                $back = '/mall/promo/groupon/' . $gid . '/';
                if ($teamId > 0) {
                    $t = Db::row('SELECT * FROM ' . self::t('groupon_team') . ' WHERE id=? AND groupon_id=? AND status=0 AND expire_at>=? LIMIT 1', [$teamId, $gid, self::now()]);
                    if ($t === null) { self::go($back, '该团不存在、已满或已过期'); }
                    if ((int)$t['joined_count'] >= (int)$t['need_size']) { self::go($back, '该团人数已满'); }
                    if ((int)Db::one('SELECT COUNT(*) FROM ' . self::t('groupon_join') . ' WHERE team_id=? AND uid=?', [$teamId, $uid]) > 0) {
                        self::go($back, '你已在该团中');
                    }
                }
                // ★ go() 是 HTTP 302（header('Location: ...')），不是 HTML —— 必须用裸 &，
                //   写成 &amp; 会让参数名变成 "amp;groupon_id"，结算页 promoContext 取不到活动，
                //   于是**结算页显示原价、下单时才按拼团价成交**（用户看到的价格与实际不符）。
                //   对照：Promo.php 里 HTML href 中的 &amp; 是正确的，勿一并改动。
                self::go('/checkout/?buy=' . (int)$p['sku_id'] . ':1&groupon_id=' . $gid . '&team_id=' . $teamId);
            }
            case 'promo_bargain_start': {
                if ($uid <= 0) { self::go(ui_login('/mall/promo/bargain/')); }
                $bid = (int)($_POST['bargain_id'] ?? 0);
                $b = Db::row('SELECT * FROM ' . self::t('bargain') . ' WHERE id=? AND status=1 AND is_delete_time=0 LIMIT 1', [$bid]);
                if ($b === null) { self::go('/mall/promo/bargain/' . $bid . '/', '砍价活动不存在或已结束'); }
                $running = Db::row('SELECT id FROM ' . self::t('bargain_launch') . ' WHERE bargain_id=? AND uid=? AND status=0 AND expire_at>=? LIMIT 1', [$bid, $uid, self::now()]);
                if ($running !== null) { self::go('/mall/promo/bargain/' . $bid . '/', '你已有进行中的砍价'); }
                $sku = Db::row('SELECT * FROM ' . self::t('goods_sku') . ' WHERE id=? AND is_delete_time=0 LIMIT 1', [(int)$b['sku_id']]);
                if ($sku === null) { self::go('/mall/promo/bargain/' . $bid . '/', '规格不存在'); }
                $expire = date('Y-m-d H:i:s', time() + (int)$b['hours'] * 3600);
                $lid = Db::insert('mall_bargain_launch', [
                    'bargain_id' => $bid, 'uid' => $uid, 'goods_id' => (int)$b['goods_id'], 'sku_id' => (int)$b['sku_id'],
                    'origin_price' => (float)$sku['price'], 'floor_price' => (float)$b['floor_price'],
                    'current_price' => (float)$sku['price'], 'cut_count' => 0, 'helper_count' => 0,
                    'status' => 0, 'expire_at' => $expire, 'order_no' => '',
                ]);
                self::go('/mall/promo/bargain/' . $bid . '/', '砍价已发起，快去邀请好友帮砍');
            }
            case 'promo_bargain_cut': {
                if ($uid <= 0) { self::go(ui_login('/mall/promo/bargain/')); }
                $lid = (int)($_POST['launch_id'] ?? 0);
                $lc = Db::row('SELECT * FROM ' . self::t('bargain_launch') . ' WHERE id=? AND uid=? AND status=0 AND expire_at>=? LIMIT 1', [$lid, $uid, self::now()]);
                $back = '/mall/promo/bargain/' . (int)($lc['bargain_id'] ?? 0) . '/';
                if ($lc === null) { self::go($back, '砍价不存在、已完成或已过期'); }
                $b = Db::row('SELECT * FROM ' . self::t('bargain') . ' WHERE id=? LIMIT 1', [(int)$lc['bargain_id']]);
                if ($b === null) { self::go($back, '活动已失效'); }
                $cur = (float)$lc['current_price'];
                $floor = (float)$lc['floor_price'];
                if ($cur <= $floor) { self::go($back, '已砍到底价，可直接下单购买'); }
                $name = '我';
                $u = Db::row('SELECT username FROM ' . Db::t('users') . ' WHERE id=? LIMIT 1', [$uid]);
                if ($u !== null) { $name = (string)$u['username']; }
                // 按次递减：剩余可砍额度随机取 min~max（最后一刀砍到底价）
                $remain = $cur - $floor;
                $min = max(0.01, (float)$b['min_cut']);
                $max = (float)$b['max_cut'] > 0 ? min((float)$b['max_cut'], $remain) : $remain;
                if ($max < $min) { $max = $remain; }
                $cut = round(mt_rand((int)($min * 100), (int)($max * 100)) / 100, 2);
                if ($cut > $remain) { $cut = $remain; }
                if ($cut < 0.01) { $cut = min(0.01, $remain); }
                $newPrice = round($cur - $cut, 2);
                if ($newPrice < $floor) { $newPrice = $floor; }
                $pdo = Db::pdo();
                $pdo->beginTransaction();
                try {
                    $has = (int)Db::one('SELECT COUNT(*) FROM ' . self::t('bargain_help') . ' WHERE launch_id=? AND helper_uid=?', [$lid, $uid]);
                    if ($has === 0) { Db::insert('mall_bargain_help', ['launch_id' => $lid, 'helper_uid' => $uid, 'helper_name' => mb_substr($name, 0, 32), 'cut_price' => $cut]); }
                    $upd = [
                        'current_price' => $newPrice,
                        'cut_count' => (int)$lc['cut_count'] + 1,
                        'helper_count' => (int)$lc['helper_count'] + ($has === 0 ? 1 : 0),
                    ];
                    if ($newPrice <= $floor) { $upd['status'] = 1; }
                    Db::update('mall_bargain_launch', $upd, 'id=?', [$lid]);
                    $pdo->commit();
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    self::go($back, '砍价失败：' . $e->getMessage());
                }
                self::go($back, '砍价成功，本次砍掉 ¥' . number_format($cut, 2, '.', ''));
            }
        }
        self::go('/mall');
    }

    /* ========================= 下单联动 ========================= */

    /**
     * 订单创建后：登记秒杀销量 / 拼团参团 / 砍价占用
     * @param array $items checkoutItems 结果
     */
    public static function promoAfterOrder(int $orderId, string $orderNo, int $uid, array $items, array $ctx): void
    {
        foreach ($items as $r) {
            $type = (string)($r['promo_type'] ?? '');
            if ($type === 'seckill' && (int)($r['promo_id'] ?? 0) > 0) {
                Db::run('UPDATE ' . self::t('seckill') . ' SET sold=sold+? WHERE id=? AND seckill_stock>sold', [(int)$r['num'], (int)$r['promo_id']]);
            }
        }
        // 拼团参团
        $gid = (int)($ctx['groupon_id'] ?? 0);
        if ($gid > 0) {
            $p = Db::row('SELECT * FROM ' . self::t('groupon') . ' WHERE id=? LIMIT 1', [$gid]);
            if ($p !== null) {
                $teamId = (int)($ctx['team_id'] ?? 0);
                if ($teamId <= 0) {
                    $teamId = Db::insert('mall_groupon_team', [
                        'groupon_id' => $gid, 'leader_uid' => $uid, 'need_size' => (int)$p['group_size'],
                        'joined_count' => 0, 'status' => 0,
                        'expire_at' => date('Y-m-d H:i:s', time() + (int)$p['hours'] * 3600),
                    ]);
                }
                Db::insert('mall_groupon_join', [
                    'team_id' => $teamId, 'groupon_id' => $gid, 'uid' => $uid,
                    'order_no' => $orderNo, 'is_leader' => ((int)($ctx['team_id'] ?? 0) <= 0 ? 1 : 0),
                ]);
                Db::run('UPDATE ' . self::t('groupon_team') . ' SET joined_count=joined_count+1 WHERE id=?', [$teamId]);
            }
        }
    }

    /** 支付成功：拼团成团判定 */
    public static function promoAfterPaid(string $orderNo): void
    {
        $j = Db::row('SELECT * FROM ' . self::t('groupon_join') . ' WHERE order_no=? LIMIT 1', [$orderNo]);
        if ($j !== null) {
            $t = Db::row('SELECT * FROM ' . self::t('groupon_team') . ' WHERE id=? LIMIT 1', [(int)$j['team_id']]);
            if ($t !== null && (int)$t['status'] === 0 && (int)$t['joined_count'] >= (int)$t['need_size']) {
                Db::update('mall_groupon_team', ['status' => 1, 'success_at' => self::now()], 'id=?', [(int)$t['id']]);
                Db::run('UPDATE ' . self::t('groupon') . ' SET sales=sales+1 WHERE id=?', [(int)$t['groupon_id']]);
            }
        }
        // 砍价：下单后标记已使用
        $lid = (int)Db::one('SELECT promo_id FROM ' . self::t('order_goods') . ' og JOIN ' . self::t('order') . ' o ON o.id=og.order_id'
            . ' WHERE o.order_no=? AND og.promo_type=? LIMIT 1', [$orderNo, 'bargain']);
        if ($lid > 0) { /* launch 由 ctx 记录，占位：砍价单付款后不回滚 */ }
    }

    /** 取消订单：退回秒杀活动库存、退出未支付团 */
    public static function promoAfterCancel(string $orderNo, int $uid): void
    {
        $items = Db::rows('SELECT promo_type, promo_id, num FROM ' . self::t('order_goods') . ' og'
            . ' JOIN ' . self::t('order') . ' o ON o.id=og.order_id WHERE o.order_no=?', [$orderNo]);
        foreach ($items as $r) {
            if ((string)$r['promo_type'] === 'seckill' && (int)$r['promo_id'] > 0) {
                Db::run('UPDATE ' . self::t('seckill') . ' SET sold=GREATEST(0, sold-?) WHERE id=?', [(int)$r['num'], (int)$r['promo_id']]);
            }
        }
        $j = Db::row('SELECT * FROM ' . self::t('groupon_join') . ' WHERE order_no=? AND uid=? LIMIT 1', [$orderNo, $uid]);
        if ($j !== null) {
            $tid = (int)$j['team_id'];
            Db::run('DELETE FROM ' . self::t('groupon_join') . ' WHERE id=?', [(int)$j['id']]);
            Db::run('UPDATE ' . self::t('groupon_team') . ' SET joined_count=GREATEST(0, joined_count-1) WHERE id=?', [$tid]);
            $t = Db::row('SELECT * FROM ' . self::t('groupon_team') . ' WHERE id=? LIMIT 1', [$tid]);
            if ($t !== null && (int)$t['joined_count'] <= 0) { Db::update('mall_groupon_team', ['status' => 2], 'id=?', [$tid]); }
        }
    }
}
