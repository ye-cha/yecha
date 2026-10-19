<?php
declare(strict_types=1);
/**
 * M12 履约通知
 * - 物流轨迹：ye_mall_delivery_trace（后台发货/录入轨迹 → 订单详情可见）
 * - 自动取消：未付款订单超时自动取消（mall.auto_cancel_hours）；发货超时自动完成（mall.auto_finish_days）
 * - 站内消息：ye_mall_message（下单/付款/发货/完成/取消触发）+ 会员中心消息箱 + 未读角标
 */
trait MallLogistics
{
    /* ========================= 站内消息 ========================= */

    /** 发站内消息（uid=0 为广播） */
    public static function msgSend(int $uid, string $type, string $title, string $content, string $link = ''): void
    {
        if ($uid <= 0) { return; }
        $sw = [
            'order' => 'mall.msg_order', 'pay' => 'mall.msg_pay', 'ship' => 'mall.msg_ship',
            'finish' => 'mall.msg_finish', 'cancel' => 'mall.msg_cancel',
        ];
        $k = $sw[$type] ?? '';
        if ($k !== '' && get_option($k, '1') !== '1') { return; }
        Db::insert('mall_message', [
            'uid' => $uid, 'type' => $type, 'title' => mb_substr($title, 0, 128),
            'content' => mb_substr($content, 0, 500), 'link' => mb_substr($link, 0, 255), 'is_read' => 0,
        ]);
    }

    public static function msgUnread(int $uid): int
    {
        if ($uid <= 0) { return 0; }
        return (int)Db::one('SELECT COUNT(*) FROM ' . self::t('message') . ' WHERE uid=? AND is_read=0', [$uid]);
    }

    /* ========================= 物流轨迹 ========================= */

    /** 订单物流轨迹（倒序取时间最新在前） */
    public static function deliveryTrace(string $orderNo): array
    {
        return Db::rows('SELECT * FROM ' . self::t('delivery_trace') . ' WHERE order_no=? ORDER BY occur_time DESC, id DESC', [$orderNo]);
    }

    /** 录入一条轨迹 */
    public static function traceAdd(string $orderNo, string $statusText, string $content): void
    {
        $o = Db::row('SELECT express_name, express_no FROM ' . self::t('order') . ' WHERE order_no=? LIMIT 1', [$orderNo]);
        Db::insert('mall_delivery_trace', [
            'order_no' => $orderNo,
            'express_name' => (string)($o['express_name'] ?? ''),
            'express_no' => (string)($o['express_no'] ?? ''),
            'occur_time' => date('Y-m-d H:i:s'),
            'status_text' => mb_substr($statusText, 0, 32),
            'content' => mb_substr($content, 0, 255),
        ]);
    }

    /** 订单详情页：物流信息模块（有快递单号才显示） */
    public static function logisticsBox(array $o): string
    {
        $no = (string)$o['order_no'];
        $expNo = (string)($o['express_no'] ?? '');
        if ($expNo === '') { return ''; }
        $traces = self::deliveryTrace($no);
        $h = '<div class="mm-sec"><div class="mm-title">物流信息</div>'
            . '<div class="mall-lg-row"><span>物流公司</span><b>' . e((string)($o['express_name'] ?: '-')) . '</b></div>'
            . '<div class="mall-lg-row"><span>运单号</span><b>' . e($expNo) . '</b>'
            . ($o['delivery_time'] !== null ? '<span class="dim">发货时间：' . e((string)$o['delivery_time']) . '</span>' : '') . '</div>';
        if ($traces === []) {
            $h .= '<div class="mall-rec-ph">暂无物流轨迹，商家发货后将自动更新。</div>';
        } else {
            $h .= '<ul class="mall-lg-trace">';
            $isFirst = true;
            foreach ($traces as $t) {
                $h .= '<li' . ($isFirst ? ' class="on"' : '') . '><span class="dot"></span>'
                    . '<div class="ct">' . e((string)$t['content']) . '</div>'
                    . '<div class="tm dim">' . e((string)$t['occur_time']) . ($t['status_text'] !== '' ? '・' . e((string)$t['status_text']) : '') . '</div></li>';
                $isFirst = false;
            }
            $h .= '</ul>';
        }
        return $h . '</div>';
    }

    /* ========================= 会员中心：消息箱 ========================= */

    public static function mmMessage(int $uid): string
    {
        $rows = Db::rows('SELECT * FROM ' . self::t('message') . ' WHERE uid=? ORDER BY id DESC LIMIT 50', [$uid]);
        $h = '<div class="mm-sec"><div class="mm-title">站内消息</div>';
        if ($rows === []) {
            $h .= '<div class="mall-rec-ph">暂无消息。</div>';
        } else {
            $h .= '<ul class="mm-msg">';
            foreach ($rows as $m) {
                $unread = (int)$m['is_read'] === 0;
                $h .= '<li' . ($unread ? ' class="un"' : '') . '>'
                    . '<div class="mm-msg-hd"><b>' . e((string)$m['title']) . '</b>'
                    . ($unread ? ' <span class="mm-st w">未读</span>' : '')
                    . '<span class="dim">' . e((string)$m['created_at']) . '</span></div>'
                    . '<div class="mm-msg-ct">' . e((string)$m['content']) . '</div>'
                    . '<div class="mm-msg-ft">'
                    . ($m['link'] !== '' ? '<a class="mall-btn-go" href="' . e((string)$m['link']) . '">查看详情</a> ' : '')
                    . ($unread ? '<form class="inline" method="post" action="/mall/user/message">' . (\Csrf::field())
                        . '<input type="hidden" name="act" value="member_msg_read"><input type="hidden" name="id" value="' . (int)$m['id'] . '">'
                        . '<button class="mall-btn-go" type="submit">标记已读</button></form>' : '')
                    . '</div></li>';
            }
            $h .= '</ul>';
        }
        return $h . '</div>';
    }

    /* ========================= 定时任务 ========================= */

    /**
     * /mall/cron?token=xxx —— 履约定时任务 HTTP 入口（受 token 保护，返回纯文本）
     * 建议服务器 crontab 每小时调用：curl -s "https://站点/mall/cron?token=xxx"
     */
    public static function cronEndpoint(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        $want = (string)get_option('mall.cron_token', '');
        $tok = (string)($_GET['token'] ?? '');
        if ($want === '' || $tok === '' || !hash_equals($want, $tok)) {
            http_response_code(403);
            echo "forbidden\n";
            exit;
        }
        $res = self::logisticsCron();
        echo 'ok cancel=' . (int)$res['cancel'] . ' finish=' . (int)$res['finish'] . "\n";
        exit;
    }

    /**
     * 履约定时任务：① 超时未付款自动取消 ② 发货超时自动完成
     * @return array ['cancel'=>n,'finish'=>n]
     */
    public static function logisticsCron(): array
    {
        $out = ['cancel' => 0, 'finish' => 0];
        $now = date('Y-m-d H:i:s');

        $hours = (int)get_option('mall.auto_cancel_hours', '2');
        if ($hours > 0) {
            $rows = Db::rows('SELECT id, order_no, uid FROM ' . self::t('order')
                . ' WHERE status=0 AND created_at < DATE_SUB(?, INTERVAL ? HOUR) LIMIT 200', [$now, $hours]);
            foreach ($rows as $r) {
                // 第4参数 byAuto=true：取消原因写「超时未付款自动取消」，
                // 而不是笼统的「管理员取消」（cron 与人工取消要能区分）
                $err = self::orderCancelDo((string)$r['order_no'], null, true, true);
                if ($err === '') {
                    $out['cancel']++;
                    self::msgSend((int)$r['uid'], 'cancel', '订单已自动取消',
                        '订单 ' . $r['order_no'] . ' 超时未付款，已自动取消。',
                        '/mall/order/' . rawurlencode((string)$r['order_no']));
                }
            }
        }

        $days = (int)get_option('mall.auto_finish_days', '15');
        if ($days > 0) {
            $rows = Db::rows('SELECT id, order_no, uid FROM ' . self::t('order')
                . ' WHERE status=2 AND delivery_time IS NOT NULL AND delivery_time < DATE_SUB(?, INTERVAL ? DAY) LIMIT 200', [$now, $days]);
            foreach ($rows as $r) {
                $n = Db::update('mall_order', ['status' => 3, 'confirm_time' => $now, 'finish_time' => $now], 'id=? AND status=2', [(int)$r['id']]);
                if ($n > 0) {
                    $out['finish']++;
                    Db::insert('mall_order_log', ['order_id' => (int)$r['id'], 'order_no' => (string)$r['order_no'], 'action' => 'auto_finish', 'detail' => '超时自动确认收货', 'operator' => 'cron']);
                    self::msgSend((int)$r['uid'], 'finish', '订单已自动完成', '订单 ' . $r['order_no'] . ' 已自动确认收货，欢迎评价。', '/mall/order/' . rawurlencode((string)$r['order_no']));
                }
            }
        }
        return $out;
    }

    /* ========================= 发货/订单消息封装 ========================= */

    /** 后台发货后调用：写入轨迹 + 站内消息 */
    public static function shipNotify(string $orderNo, string $expressName, string $expressNo): void
    {
        Db::update('mall_order', [
            'express_name' => $expressName, 'express_no' => $expressNo,
            'status' => 2, 'delivery_time' => date('Y-m-d H:i:s'),
        ], 'order_no=?', [$orderNo]);
        self::traceAdd($orderNo, '已发货', '商家已发货，物流公司：' . $expressName . '，运单号：' . $expressNo);
        $uid = (int)Db::one('SELECT uid FROM ' . self::t('order') . ' WHERE order_no=? LIMIT 1', [$orderNo]);
        self::msgSend($uid, 'ship', '订单已发货', '订单 ' . $orderNo . ' 已发货（' . $expressName . ' ' . $expressNo . '）。', '/mall/order/' . rawurlencode($orderNo));
    }

    /** 收货：轨迹 + 消息 */
    public static function finishNotify(string $orderNo, int $uid): void
    {
        self::traceAdd($orderNo, '已签收', '您的包裹已签收，感谢您的购买。');
        self::msgSend($uid, 'finish', '订单已完成', '订单 ' . $orderNo . ' 已完成，欢迎前往评价晒单。', '/mall/order/' . rawurlencode($orderNo));
    }
}
