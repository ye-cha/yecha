<?php
declare(strict_types=1);
/**
 * M13 会员等级折扣
 * - ye_mall_level 定义等级（折扣率 / 升级门槛）
 * - 会员累计消费 / 积分达标自动升级（member.level 冗余，读取时校准）
 * - 折扣参与 unitPrice() 单价管线（Promo.php 调用 memberLevelDiscount）
 */
trait MallLevel
{
    /** 等级列表（按 level 升序） */
    public static function memberLevels(): array
    {
        return Db::rows('SELECT * FROM ' . self::t('level') . ' WHERE status=1 ORDER BY level ASC');
    }

    /**
     * 取会员当前等级 + 折扣率（并自动校准升级）
     * @return array name/discount/level/icon/next/min_consume
     */
    public static function memberLevelDiscount(int $uid): array
    {
        $out = ['name' => '', 'discount' => 1.0, 'level' => 1, 'icon' => '', 'next' => null, 'next_gap' => 0.0];
        if (get_option('mall.level_on', '1') !== '1') { return $out; }
        if ($uid <= 0) { return $out; }
        $m = self::memberRow($uid);
        $consume = (float)($m['total_consume'] ?? 0);
        $points = (int)($m['points'] ?? 0);
        // 按门槛取最高达标等级
        // ⚠️ 门槛为 0 表示该维度「不设限」，不可当作 0<=X 恒真（否则所有人直升最高等级）：
        //    只认 (min_consume>0 且达标) 或 (min_points>0 且达标) 或 (两门槛都为 0 = 基础等级)
        $lv = Db::row('SELECT * FROM ' . self::t('level')
            . ' WHERE status=1 AND ('
            . ' (min_consume>0 AND min_consume<=?)'
            . ' OR (min_points>0 AND min_points<=?)'
            . ' OR (min_consume=0 AND min_points=0)'
            . ' ) ORDER BY level DESC LIMIT 1', [$consume, $points]);
        if ($lv === null) {
            $lv = Db::row('SELECT * FROM ' . self::t('level') . ' WHERE status=1 ORDER BY level ASC LIMIT 1');
        }
        if ($lv === null) { return $out; }

        // 等级由门槛单向推导，因此做「双向校准」而非只升不降：
        // 既能正常升级，也能修正历史脏值（如早期版本误判导致的高等级）
        $cur = (int)($m['level'] ?? 1);
        if ((int)$lv['level'] !== $cur) {
            Db::update('mall_member', ['level' => (int)$lv['level']], 'uid=?', [$uid]);
            $cur = (int)$lv['level'];
        }
        $row = Db::row('SELECT * FROM ' . self::t('level') . ' WHERE level=? LIMIT 1', [$cur]);
        if ($row === null) { $row = $lv; }

        $out['level'] = (int)$row['level'];
        $out['name'] = (string)$row['name'];
        $out['icon'] = (string)$row['icon'];
        $out['discount'] = (float)$row['discount'];
        if ($out['discount'] <= 0 || $out['discount'] > 1) { $out['discount'] = 1.0; }

        $next = Db::row('SELECT * FROM ' . self::t('level') . ' WHERE level>? AND status=1 ORDER BY level ASC LIMIT 1', [$cur]);
        if ($next !== null) {
            $need = (float)$next['min_consume'];
            if ($need > 0 && (float)$next['min_points'] > 0) {
                // 双门槛：取消费维度差额做展示
                $out['next_gap'] = max(0.0, $need - $consume);
            } elseif ($need > 0) {
                $out['next_gap'] = max(0.0, $need - $consume);
            } else {
                $out['next_gap'] = max(0, (int)$next['min_points'] - $points);
            }
            $out['next'] = $next;
        }
        return $out;
    }

    /** 商品详情页用的会员价文案（有折扣才输出） */
    public static function memberPriceNote(float $price, array $lv): string
    {
        if ($lv['discount'] >= 1 || $lv['name'] === '') { return ''; }
        $p = round($price * $lv['discount'], 2);
        return '<div class="mall-promo-row lv"><span class="mall-promo-tag lv">' . e($lv['icon'] . $lv['name']) . '</span>'
            . '<span class="mall-promo-price">¥' . e(number_format($p, 2, '.', '')) . '</span>'
            . '<span class="mall-promo-tip">您的专属价，立省 ¥' . e(number_format($price - $p, 2, '.', '')) . '</span></div>';
    }

    /** 会员中心「会员等级」页：当前等级卡片 + 等级权益表 + 升级进度 */
    public static function mmLevel(int $uid): string
    {
        $lv = self::memberLevelDiscount($uid);
        $m = self::memberRow($uid);
        $consume = (float)($m['total_consume'] ?? 0);
        $levels = self::memberLevels();

        $h = '<div class="mm-sec"><div class="mm-title">我的会员等级</div>';
        $h .= '<div class="mm-level-card">'
            . '<div class="mm-level-badge">' . e($lv['icon'] !== '' ? $lv['icon'] : '★') . '<span>Lv' . (int)$lv['level'] . '</span></div>'
            . '<div class="mm-level-info">'
            . '<div class="mm-level-name">' . e($lv['name'] !== '' ? $lv['name'] : '普通会员') . '</div>'
            . '<div class="dim">折扣率：' . e(number_format($lv['discount'] * 100, 1, '.', '')) . ' 折优惠</div>'
            . '<div class="dim">累计消费：¥' . e(number_format($consume, 2, '.', '')) . '・积分：' . (int)($m['points'] ?? 0) . '</div>';
        if ($lv['next'] !== null) {
            $need = (float)$lv['next']['min_consume'];
            $pct = $need > 0 ? min(100, round($consume / $need * 100, 1)) : 100;
            $h .= '<div class="mm-level-next">距 <b>' . e((string)$lv['next']['name']) . '</b> '
                . ($need > 0 ? '还需消费 ¥' . e(number_format(max(0.0, $need - $consume), 2, '.', '')) : '还需 ' . max(0, (int)$lv['next']['min_points'] - (int)($m['points'] ?? 0)) . ' 积分')
                . '</div><div class="mm-level-bar"><i style="width:' . e((string)$pct) . '%"></i></div>';
        } else {
            $h .= '<div class="mm-level-next">已是最高等级，感谢一路相伴。</div>';
        }
        $h .= '</div></div>';

        $h .= '<table class="mm-tbl"><tr><th>等级</th><th>名称</th><th>折扣</th><th>升级门槛</th><th>权益</th></tr>';
        foreach ($levels as $r) {
            $mine = (int)$r['level'] === (int)$lv['level'];
            $th = [];
            if ((float)$r['min_consume'] > 0) { $th[] = '累计消费 ¥' . number_format((float)$r['min_consume'], 2, '.', ''); }
            if ((int)$r['min_points'] > 0) { $th[] = '积分 ' . (int)$r['min_points']; }
            if ($th === []) { $th[] = '注册即得'; }
            $h .= '<tr' . ($mine ? ' class="on"' : '') . '><td>Lv' . (int)$r['level'] . '</td>'
                . '<td>' . e((string)$r['name']) . ($mine ? ' <span class="mall-promo-tag lv">当前</span>' : '') . '</td>'
                . '<td>' . ($r['discount'] >= 1 ? '无折扣' : e(number_format((float)$r['discount'] * 100, 1, '.', '')) . ' 折') . '</td>'
                . '<td>' . e(implode(' / ', $th)) . '</td>'
                . '<td class="dim">' . e((string)$r['benefit']) . '</td></tr>';
        }
        $h .= '</table></div>';
        return $h;
    }
}
