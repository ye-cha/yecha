<?php
declare(strict_types=1);
// 商城插件 · 后台管理（M2 商品中心）：总览 / 分类 / 商品+SKU / 设置
// POST 自处理（宿主 admin POST 段已统一 Csrf::check()）；上传复用宿主 do=upload 通道（pc_upload）
require_once __DIR__ . '/lib.php';

function yc_mall_admin(): string
{
    if (!\Auth::isAdmin()) { return '<div class="card"><h3>商城</h3><div class="stub">需要管理员权限。</div></div>'; }
    $msg = ml_admin_handle();
    $tab = (string)($_GET['tab'] ?? 'home');
    $base = '/admin/?s=plug_mall';

    $tabs = ['home' => '总览', 'cate' => '商品分类', 'brand' => '品牌', 'tag' => '标签', 'goods' => '商品管理', 'order' => '订单管理', 'coupon' => '优惠券',
        'seckill' => '秒杀', 'groupon' => '拼团', 'bargain' => '砍价', 'bundle' => '组合优惠', 'level' => '会员等级', 'set' => '商城设置'];
    $tabBar = '<div class="mla-tabs">';
    foreach ($tabs as $k => $label) {
        $tabBar .= '<a href="' . $base . '&amp;tab=' . $k . '"' . ($tab === $k ? ' class="on"' : '') . '>' . $label . '</a>';
    }
    $tabBar .= '</div>';

    switch ($tab) {
        case 'cate':
            $out = ml_cate_page($base);
            break;
        case 'brand':
            $out = ml_brand_page($base);
            break;
        case 'tag':
            $out = ml_tag_page($base);
            break;
        case 'goods':
            $out = ml_goods_page($base);
            break;
        case 'order':
            $out = ml_order_page($base);
            break;
        case 'coupon':
            $out = ml_coupon_page($base);
            break;
        case 'seckill':
            $out = ml_seckill_page($base);
            break;
        case 'groupon':
            $out = ml_groupon_page($base);
            break;
        case 'bargain':
            $out = ml_bargain_page($base);
            break;
        case 'topic':

            $out = ml_topic_page($base);

            break;
        case 'bundle':
            $out = ml_bundle_page($base);
            break;
        case 'level':
            $out = ml_level_page($base);
            break;
        case 'set':
            $out = ml_set_page($base);
            break;
        default:
            $out = ml_home_page($base);
            break;
    }
    return ml_css() . ($msg !== '' ? '<div class="mla-msg">' . e($msg) . '</div>' : '') . $tabBar . $out;
}

/* ================= POST 处理（返回提示文案） ================= */

function ml_admin_handle(): string
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return (string)($_SESSION['ml_flash'] ?? ''); }
    unset($_SESSION['ml_flash']);
    $act = (string)($_POST['act'] ?? '');
    $base = '/admin/?s=plug_mall';
    $redir = static function (string $to, string $msg) use ($base): void {
        $_SESSION['ml_flash'] = $msg;
        header('Location: ' . ($to !== '' ? $base . $to : $base));
        exit;
    };

    switch ($act) {
        case 'cate_save':
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $slug = trim((string)($_POST['slug'] ?? ''));
            if ($name === '') { $redir('&tab=cate', '分类名不能为空'); }
            if ($slug === '') { $slug = Str::tagSlug($name); }
            $slug = str_replace(' ', '-', $slug);
            // slug 唯一（排除自身）
            $dup = Db::one('SELECT id FROM ' . Mall::t('goods_category') . ' WHERE slug=? AND id<>? AND is_delete_time=0 LIMIT 1', [$slug, $id]);
            if ($dup !== null) { $redir('&tab=cate', 'slug 已被占用：' . $slug); }
            $row = [
                'pid' => 0, 'name' => $name, 'slug' => $slug,
                'icon' => trim((string)($_POST['icon'] ?? '')),
                'sort' => (int)($_POST['sort'] ?? 99),
                'is_show' => (($_POST['is_show'] ?? '1') === '1') ? 1 : 0,
                'seo_title' => trim((string)($_POST['seo_title'] ?? '')),
                'seo_keywords' => trim((string)($_POST['seo_keywords'] ?? '')),
                'seo_desc' => trim((string)($_POST['seo_desc'] ?? '')),
            ];
            if ($id > 0) {
                Db::update('mall_goods_category', $row, 'id=?', [$id]);
                $redir('&tab=cate', '分类已保存：' . $name);
            }
            Db::insert('mall_goods_category', $row);
            $redir('&tab=cate', '分类已创建：' . $name);

        case 'cate_delete':
            $id = (int)($_POST['id'] ?? 0);
            $n = (int)Db::one('SELECT COUNT(*) FROM ' . Mall::t('goods') . ' WHERE category_id=? AND is_delete_time=0', [$id]);
            if ($n > 0) { $redir('&tab=cate', '该分类下还有 ' . $n . ' 个商品，先移走再删'); }
            Db::update('mall_goods_category', ['is_delete_time' => time(), 'is_show' => 0], 'id=?', [$id]);
            $redir('&tab=cate', '分类已删除');

        case 'brand_save':
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') { $redir('&tab=brand', '品牌名不能为空'); }
            $slug = trim((string)($_POST['slug'] ?? ''));
            if ($slug === '') { $slug = Str::tagSlug($name); }
            $row = [
                'name' => $name,
                'logo' => trim((string)($_POST['logo'] ?? '')),
                'sort' => (int)($_POST['sort'] ?? 99),
                'is_show' => (($_POST['is_show'] ?? '1') === '1') ? 1 : 0,
            ];
            if ($id > 0) { Db::update('mall_brand', $row, 'id=?', [$id]); $redir('&tab=brand', '品牌已保存：' . $name); }
            Db::insert('mall_brand', $row);
            $redir('&tab=brand', '品牌已创建：' . $name);

        case 'brand_delete':
            $id = (int)($_POST['id'] ?? 0);
            Db::update('mall_brand', ['is_delete_time' => time(), 'is_show' => 0], 'id=?', [$id]);
            $redir('&tab=brand', '品牌已删除');

        case 'tag_save':
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') { $redir('&tab=tag', '标签名不能为空'); }
            $slug = trim((string)($_POST['slug'] ?? ''));
            if ($slug === '') { $slug = Str::tagSlug($name); }
            $dup = Db::one('SELECT id FROM ' . Mall::t('tag') . ' WHERE slug=? AND id<>? AND is_delete_time=0 LIMIT 1', [$slug, $id]);
            if ($dup !== null) { $redir('&tab=tag', 'slug 已被占用：' . $slug); }
            $row = ['name' => $name, 'slug' => $slug, 'sort' => (int)($_POST['sort'] ?? 99), 'is_show' => (($_POST['is_show'] ?? '1') === '1') ? 1 : 0];
            if ($id > 0) { Db::update('mall_tag', $row, 'id=?', [$id]); $redir('&tab=tag', '标签已保存：' . $name); }
            Db::insert('mall_tag', $row);
            $redir('&tab=tag', '标签已创建：' . $name);

        case 'tag_delete':
            $id = (int)($_POST['id'] ?? 0);
            $gt = (int)Db::one('SELECT COUNT(*) FROM ' . Mall::t('goods_tag') . ' WHERE tag_id=?', [$id]);
            if ($gt > 0) {
                Db::delete('mall_goods_tag', 'tag_id=?', [$id]);
                Db::update('mall_tag', ['goods_count' => 0], 'id=?', [$id]);
            }
            Db::update('mall_tag', ['is_delete_time' => time(), 'is_show' => 0], 'id=?', [$id]);
            $redir('&tab=tag', '标签已删除（已解绑 ' . $gt . ' 个商品）');

        case 'goods_save':
            return ml_goods_save($base);

        case 'goods_flag':
            $id = (int)($_POST['id'] ?? 0);
            $what = (string)($_POST['what'] ?? '');
            $g = Db::row('SELECT id, status, is_recommend FROM ' . Mall::t('goods') . ' WHERE id=? AND is_delete_time=0 LIMIT 1', [$id]);
            if ($g === null) { $redir('&tab=goods', '商品不存在'); }
            if ($what === 'status') {
                Db::update('mall_goods', ['status' => ((int)$g['status'] === 1) ? 0 : 1], 'id=?', [$id]);
                $redir('&tab=goods', ((int)$g['status'] === 1 ? '已下架' : '已上架'));
            }
            if ($what === 'recommend') {
                Db::update('mall_goods', ['is_recommend' => ((int)$g['is_recommend'] === 1) ? 0 : 1], 'id=?', [$id]);
                $redir('&tab=goods', ((int)$g['is_recommend'] === 1 ? '已取消推荐' : '已设为推荐'));
            }
            if ($what === 'delete') {
                Db::update('mall_goods', ['is_delete_time' => time(), 'status' => 0], 'id=?', [$id]);
                $redir('&tab=goods', '商品已删除');
            }
            $redir('&tab=goods', '未知操作');

        case 'set_save':
            set_option('mall.name', trim((string)($_POST['mall_name'] ?? '')));
            set_option('mall.notice', trim((string)($_POST['mall_notice'] ?? '')));
            set_option('mall.service', trim((string)($_POST['mall_service'] ?? '')));
            // AI 导购浮窗开关（需 ai 插件启用且已配置 API Key 才会真正渲染）
            set_option('mall.ai_guide', (string)($_POST['ai_guide'] ?? '0') === '1' ? '1' : '0');
            // 页脚合规展示（ICP备案 / 公安网安备案 / ICP经营许可证 / 工商执照）
            set_option('mall.icp_no', trim((string)($_POST['icp_no'] ?? '')));
            set_option('mall.icp_url', trim((string)($_POST['icp_url'] ?? '')));
            set_option('mall.police_no', trim((string)($_POST['police_no'] ?? '')));
            set_option('mall.police_url', trim((string)($_POST['police_url'] ?? '')));
            set_option('mall.license_no', trim((string)($_POST['license_no'] ?? '')));
            set_option('mall.license_img', trim((string)($_POST['license_img'] ?? '')));
            set_option('mall.business_img', trim((string)($_POST['business_img'] ?? '')));
            set_option('mall.freight_fee', (string)max(0, (float)($_POST['freight_fee'] ?? 10)));
            set_option('mall.free_from', (string)max(0, (float)($_POST['free_from'] ?? 51)));
            // M10 积分与签到
            set_option('mall.points_rate', (string)max(1, (int)($_POST['points_rate'] ?? 100)));
            set_option('mall.points_max_rate', (string)min(1.0, max(0.0, (float)($_POST['points_max_rate'] ?? 0.5))));
            set_option('mall.signin_points', (string)max(1, (int)($_POST['signin_points'] ?? 5)));
            set_option('mall.signin_bonus', (string)max(0, (int)($_POST['signin_bonus'] ?? 10)));
            // 支付渠道开关 + 沙箱模拟
            set_option('mall.pay_mock_secret', trim((string)($_POST['pay_mock_secret'] ?? '')) ?: 'mock-secret');
            // 仅当表单提交了该字段才写：未提交=本次不涉及，保持原值（否则局部保存会把其他开关清零）
            if (array_key_exists('pay_sandbox_sim', $_POST)) {
                set_option('mall.pay_sandbox_sim', $_POST['pay_sandbox_sim'] === '1' ? '1' : '0');
            }
            // 仅当表单提交了该字段才写：未提交=本次不涉及，保持原值（否则局部保存会把其他开关清零）
            if (array_key_exists('pay_alipay_on', $_POST)) {
                set_option('mall.pay_alipay_on', $_POST['pay_alipay_on'] === '1' ? '1' : '0');
            }
            // 仅当表单提交了该字段才写：未提交=本次不涉及，保持原值（否则局部保存会把其他开关清零）
            if (array_key_exists('pay_wxpay_on', $_POST)) {
                set_option('mall.pay_wxpay_on', $_POST['pay_wxpay_on'] === '1' ? '1' : '0');
            }
            // 支付宝
            set_option('mall.pay_alipay_app_id', trim((string)($_POST['pay_alipay_app_id'] ?? '')));
            set_option('mall.pay_alipay_private_key', trim((string)($_POST['pay_alipay_private_key'] ?? '')));
            set_option('mall.pay_alipay_public_key', trim((string)($_POST['pay_alipay_public_key'] ?? '')));
            // 仅当表单提交了该字段才写：未提交=本次不涉及，保持原值（否则局部保存会把其他开关清零）
            if (array_key_exists('pay_alipay_sandbox', $_POST)) {
                set_option('mall.pay_alipay_sandbox', $_POST['pay_alipay_sandbox'] === '1' ? '1' : '0');
            }
            // 微信
            set_option('mall.pay_wxpay_mch_id', trim((string)($_POST['pay_wxpay_mch_id'] ?? '')));
            set_option('mall.pay_wxpay_app_id', trim((string)($_POST['pay_wxpay_app_id'] ?? '')));
            set_option('mall.pay_wxpay_key', trim((string)($_POST['pay_wxpay_key'] ?? '')));
            set_option('mall.pay_wxpay_secret', trim((string)($_POST['pay_wxpay_secret'] ?? '')));
            // 仅当表单提交了该字段才写：未提交=本次不涉及，保持原值（否则局部保存会把其他开关清零）
            if (array_key_exists('pay_wxpay_sandbox', $_POST)) {
                set_option('mall.pay_wxpay_sandbox', $_POST['pay_wxpay_sandbox'] === '1' ? '1' : '0');
            }
            // 扫码收款（微信/支付宝收款码）
            // 仅当表单提交了该字段才写：未提交=本次不涉及，保持原值（否则局部保存会把其他开关清零）
            if (array_key_exists('pay_qr_on', $_POST)) {
                set_option('mall.pay_qr_on', $_POST['pay_qr_on'] === '1' ? '1' : '0');
            }
            set_option('mall.pay_payee', trim((string)($_POST['pay_payee'] ?? '')));
            set_option('mall.pay_wx_account', trim((string)($_POST['pay_wx_account'] ?? '')));
            set_option('mall.pay_wx_qr', trim((string)($_POST['pay_wx_qr'] ?? '')));
            set_option('mall.pay_ali_account', trim((string)($_POST['pay_ali_account'] ?? '')));
            set_option('mall.pay_ali_qr', trim((string)($_POST['pay_ali_qr'] ?? '')));
            set_option('mall.pay_qr_note', trim((string)($_POST['pay_qr_note'] ?? '')));
            // M11 促销开关
            // 仅当表单提交了该字段才写：未提交=本次不涉及，保持原值（否则局部保存会把其他开关清零）
            if (array_key_exists('promo_on', $_POST)) {
                set_option('mall.promo_on', $_POST['promo_on'] === '1' ? '1' : '0');
            }
            // 仅当表单提交了该字段才写：未提交=本次不涉及，保持原值（否则局部保存会把其他开关清零）
            if (array_key_exists('seckill_on', $_POST)) {
                set_option('mall.seckill_on', $_POST['seckill_on'] === '1' ? '1' : '0');
            }
            // 仅当表单提交了该字段才写：未提交=本次不涉及，保持原值（否则局部保存会把其他开关清零）
            if (array_key_exists('groupon_on', $_POST)) {
                set_option('mall.groupon_on', $_POST['groupon_on'] === '1' ? '1' : '0');
            }
            // 仅当表单提交了该字段才写：未提交=本次不涉及，保持原值（否则局部保存会把其他开关清零）
            if (array_key_exists('bargain_on', $_POST)) {
                set_option('mall.bargain_on', $_POST['bargain_on'] === '1' ? '1' : '0');
            }
            // 仅当表单提交了该字段才写：未提交=本次不涉及，保持原值（否则局部保存会把其他开关清零）
            if (array_key_exists('bundle_on', $_POST)) {
                set_option('mall.bundle_on', $_POST['bundle_on'] === '1' ? '1' : '0');
            }
            // M12 履约通知
            set_option('mall.auto_cancel_hours', (string)max(0, (int)($_POST['auto_cancel_hours'] ?? 2)));
            set_option('mall.auto_finish_days', (string)max(0, (int)($_POST['auto_finish_days'] ?? 15)));
            // 仅当表单提交了该字段才写：未提交=本次不涉及，保持原值（否则局部保存会把其他开关清零）
            if (array_key_exists('msg_order', $_POST)) {
                set_option('mall.msg_order', $_POST['msg_order'] === '1' ? '1' : '0');
            }
            // 仅当表单提交了该字段才写：未提交=本次不涉及，保持原值（否则局部保存会把其他开关清零）
            if (array_key_exists('msg_pay', $_POST)) {
                set_option('mall.msg_pay', $_POST['msg_pay'] === '1' ? '1' : '0');
            }
            // 仅当表单提交了该字段才写：未提交=本次不涉及，保持原值（否则局部保存会把其他开关清零）
            if (array_key_exists('msg_ship', $_POST)) {
                set_option('mall.msg_ship', $_POST['msg_ship'] === '1' ? '1' : '0');
            }
            // 仅当表单提交了该字段才写：未提交=本次不涉及，保持原值（否则局部保存会把其他开关清零）
            if (array_key_exists('msg_finish', $_POST)) {
                set_option('mall.msg_finish', $_POST['msg_finish'] === '1' ? '1' : '0');
            }
            // 仅当表单提交了该字段才写：未提交=本次不涉及，保持原值（否则局部保存会把其他开关清零）
            if (array_key_exists('msg_cancel', $_POST)) {
                set_option('mall.msg_cancel', $_POST['msg_cancel'] === '1' ? '1' : '0');
            }
            // M13 会员等级
            // 仅当表单提交了该字段才写：未提交=本次不涉及，保持原值（否则局部保存会把其他开关清零）
            if (array_key_exists('level_on', $_POST)) {
                set_option('mall.level_on', $_POST['level_on'] === '1' ? '1' : '0');
            }
            // 仅当表单提交了该字段才写：未提交=本次不涉及，保持原值（否则局部保存会把其他开关清零）
            if (array_key_exists('level_on_promo', $_POST)) {
                set_option('mall.level_on_promo', $_POST['level_on_promo'] === '1' ? '1' : '0');
            }
            $redir('&tab=set', '商城设置已保存');

        case 'pay_qr_upload': {
            if (!\Auth::isAdmin()) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => 0, 'msg' => '无权限']); exit; }
            $key = trim((string)($_POST['key'] ?? ''));
            if (!in_array($key, ['wx', 'ali'], true)) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => 0, 'msg' => '参数错误']); exit; }
            $f = $_FILES['file'] ?? null;
            if (!$f || (int)($f['error'] ?? 1) !== 0) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => 0, 'msg' => '上传失败']); exit; }
            $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => 0, 'msg' => '仅支持图片']); exit; }
            $dir = __DIR__ . '/../../uploads/mall/pay/';
            if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
            $name = 'qr_' . $key . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file((string)$f['tmp_name'], $dir . $name)) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => 0, 'msg' => '保存失败']); exit; }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => 1, 'url' => '/uploads/mall/pay/' . $name]);
            exit;
        }

        /* ---------- M11 促销活动 CRUD ---------- */
        case 'seckill_save': {
            $id = (int)($_POST['id'] ?? 0);
            $row = ml_promo_fields('seckill');
            if ($row['sku_id'] <= 0) { $redir('&tab=seckill', '请选择参与秒杀的规格（SKU ID）'); }
            $sku = Db::row('SELECT id, goods_id, price FROM ' . Mall::t('goods_sku') . ' WHERE id=? AND is_delete_time=0 LIMIT 1', [$row['sku_id']]);
            if ($sku === null) { $redir('&tab=seckill', 'SKU 不存在'); }
            $row['goods_id'] = (int)$sku['goods_id'];
            if ($id > 0) { Db::update('mall_seckill', $row, 'id=?', [$id]); $redir('&tab=seckill', '秒杀活动已保存'); }
            Db::insert('mall_seckill', $row);
            $redir('&tab=seckill', '秒杀活动已创建');
        }
        case 'seckill_delete':
            Db::update('mall_seckill', ['is_delete_time' => time(), 'status' => 0], 'id=?', [(int)($_POST['id'] ?? 0)]);
            $redir('&tab=seckill', '秒杀活动已删除');

        case 'groupon_save': {
            $id = (int)($_POST['id'] ?? 0);
            $row = ml_promo_fields('groupon');
            if ($row['sku_id'] <= 0) { $redir('&tab=groupon', '请选择参与拼团的规格（SKU ID）'); }
            $sku = Db::row('SELECT id, goods_id, price FROM ' . Mall::t('goods_sku') . ' WHERE id=? AND is_delete_time=0 LIMIT 1', [$row['sku_id']]);
            if ($sku === null) { $redir('&tab=groupon', 'SKU 不存在'); }
            $row['goods_id'] = (int)$sku['goods_id'];
            if ($id > 0) { Db::update('mall_groupon', $row, 'id=?', [$id]); $redir('&tab=groupon', '拼团活动已保存'); }
            Db::insert('mall_groupon', $row);
            $redir('&tab=groupon', '拼团活动已创建');
        }
        case 'groupon_delete':
            Db::update('mall_groupon', ['is_delete_time' => time(), 'status' => 0], 'id=?', [(int)($_POST['id'] ?? 0)]);
            $redir('&tab=groupon', '拼团活动已删除');

        case 'bargain_save': {
            $id = (int)($_POST['id'] ?? 0);
            $row = ml_promo_fields('bargain');
            if ($row['sku_id'] <= 0) { $redir('&tab=bargain', '请选择参与砍价的规格（SKU ID）'); }
            $sku = Db::row('SELECT id, goods_id, price FROM ' . Mall::t('goods_sku') . ' WHERE id=? AND is_delete_time=0 LIMIT 1', [$row['sku_id']]);
            if ($sku === null) { $redir('&tab=bargain', 'SKU 不存在'); }
            $row['goods_id'] = (int)$sku['goods_id'];
            if ($id > 0) { Db::update('mall_bargain', $row, 'id=?', [$id]); $redir('&tab=bargain', '砍价活动已保存'); }
            Db::insert('mall_bargain', $row);
            $redir('&tab=bargain', '砍价活动已创建');
        }
        case 'bargain_delete':
            Db::update('mall_bargain', ['is_delete_time' => time(), 'status' => 0], 'id=?', [(int)($_POST['id'] ?? 0)]);
            $redir('&tab=bargain', '砍价活动已删除');

        case 'bundle_save': {
            $id = (int)($_POST['id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '') { $redir('&tab=bundle', '套餐名称不能为空'); }
            $row = [
                'title' => $title,
                'summary' => trim((string)($_POST['summary'] ?? '')),
                'cover' => trim((string)($_POST['cover'] ?? '')),
                'type' => (int)($_POST['type'] ?? 2) === 1 ? 1 : 2,
                'price' => max(0, (float)($_POST['price'] ?? 0)),
                'need_num' => max(1, (int)($_POST['need_num'] ?? 2)),
                'total_value' => max(0, (float)($_POST['total_value'] ?? 0)),
                'sort' => (int)($_POST['sort'] ?? 99),
                'status' => (($_POST['status'] ?? '1') === '1') ? 1 : 0,
            ];
            if ($id > 0) {
                Db::update('mall_bundle', $row, 'id=?', [$id]);
                $redir('&tab=bundle&edit=' . $id, '套餐已保存');
            }
            $newId = Db::insert('mall_bundle', $row);
            $redir('&tab=bundle&edit=' . $newId, '套餐已创建，请添加套餐内商品');
        }

            case 'topic_save': {

                $id = (int)($_POST['id'] ?? 0);

                $title = trim((string)($_POST['title'] ?? ''));

                if ($title === '') { $redir('&tab=topic', '标题不能为空'); }

                $row = [

                    'title' => mb_substr($title, 0, 128),

                    'summary' => mb_substr(trim((string)($_POST['summary'] ?? '')), 0, 255),

                    'cover' => trim((string)($_POST['cover'] ?? '')),

                    'content' => (string)($_POST['content'] ?? ''),

                    'sort' => (int)($_POST['sort'] ?? 0),

                    'status' => (string)($_POST['status'] ?? '1') === '1' ? 1 : 0,

                ];

                if ($id > 0) { Db::update('mall_topic', $row, 'id=?', [$id]); }

                else { $id = (int)Db::insert('mall_topic', $row); }

                $redir('&tab=topic&edit=' . $id, '专题已保存');

            }

            case 'topic_delete': {

                $id = (int)($_POST['id'] ?? 0);

                Db::update('mall_topic', ['is_delete_time' => time()], 'id=?', [$id]);

                Db::run('DELETE FROM ' . Mall::t('topic_goods') . ' WHERE topic_id=?', [$id]);

                $redir('&tab=topic', '专题已删除');

            }

            case 'topic_goods_add': {

                $tid = (int)($_POST['topic_id'] ?? 0);

                $gid = (int)($_POST['goods_id'] ?? 0);

                if ($tid <= 0 || $gid <= 0) { $redir('&tab=topic&edit=' . $tid, '请填写商品 ID'); }

                $g = Db::row('SELECT id FROM ' . Mall::t('goods') . ' WHERE id=? AND is_delete_time=0 LIMIT 1', [$gid]);

                if ($g === null) { $redir('&tab=topic&edit=' . $tid, '商品不存在'); }

                try { Db::insert('mall_topic_goods', ['topic_id' => $tid, 'goods_id' => $gid, 'sort' => (int)($_POST['sort'] ?? 0)]); }

                catch (Throwable $e) { $redir('&tab=topic&edit=' . $tid, '该商品已在该专题中'); }

                $redir('&tab=topic&edit=' . $tid, '商品已加入专题');

            }

            case 'topic_goods_del': {

                $id = (int)($_POST['id'] ?? 0);

                $tid = (int)($_POST['topic_id'] ?? 0);

                Db::run('DELETE FROM ' . Mall::t('topic_goods') . ' WHERE id=?', [$id]);

                $redir('&tab=topic&edit=' . $tid, '已移除');

            }

        case 'bundle_delete':
            Db::update('mall_bundle', ['is_delete_time' => time(), 'status' => 0], 'id=?', [(int)($_POST['id'] ?? 0)]);
            $redir('&tab=bundle', '套餐已删除');

        case 'bundle_goods_add': {
            $bid = (int)($_POST['bundle_id'] ?? 0);
            $gid = (int)($_POST['goods_id'] ?? 0);
            if ($bid <= 0 || $gid <= 0) { $redir('&tab=bundle&edit=' . $bid, '请选择商品'); }
            $skuId = (int)($_POST['sku_id'] ?? 0);
            Db::insert('mall_bundle_goods', [
                'bundle_id' => $bid, 'goods_id' => $gid, 'sku_id' => $skuId,
                'num' => max(1, (int)($_POST['num'] ?? 1)), 'sort' => 99,
            ]);
            // 自动回算原价合计
            ml_bundle_recalc($bid);
            $redir('&tab=bundle&edit=' . $bid, '套餐商品已添加');
        }
        case 'bundle_goods_del': {
            $id = (int)($_POST['id'] ?? 0);
            $bid = (int)($_POST['bundle_id'] ?? 0);
            Db::run('DELETE FROM ' . Mall::t('bundle_goods') . ' WHERE id=?', [$id]);
            ml_bundle_recalc($bid);
            $redir('&tab=bundle&edit=' . $bid, '套餐商品已移除');
        }

        /* ---------- M13 会员等级 ---------- */
        case 'level_save': {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') { $redir('&tab=level', '等级名称不能为空'); }
            $row = [
                'level' => max(1, (int)($_POST['level'] ?? 1)),
                'name' => $name,
                'icon' => trim((string)($_POST['icon'] ?? '')),
                'discount' => min(1.0, max(0.01, (float)($_POST['discount'] ?? 1))),
                'min_consume' => max(0, (float)($_POST['min_consume'] ?? 0)),
                'min_points' => max(0, (int)($_POST['min_points'] ?? 0)),
                'benefit' => trim((string)($_POST['benefit'] ?? '')),
                'sort' => (int)($_POST['sort'] ?? 99),
                'status' => (($_POST['status'] ?? '1') === '1') ? 1 : 0,
            ];
            $dup = Db::one('SELECT id FROM ' . Mall::t('level') . ' WHERE level=? AND id<>? LIMIT 1', [$row['level'], $id]);
            if ($dup !== null) { $redir('&tab=level', '等级值 ' . $row['level'] . ' 已存在'); }
            if ($id > 0) { Db::update('mall_level', $row, 'id=?', [$id]); $redir('&tab=level', '等级已保存：' . $name); }
            Db::insert('mall_level', $row);
            $redir('&tab=level', '等级已创建：' . $name);
        }
        case 'level_delete':
            Db::update('mall_level', ['status' => 0], 'id=?', [(int)($_POST['id'] ?? 0)]);
            $redir('&tab=level', '等级已停用');

        case 'coupon_save': {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $type = (int)($_POST['type'] ?? 1) === 2 ? 2 : 1;
            $threshold = max(0, (float)($_POST['threshold'] ?? 0));
            $totalCount = max(1, (int)($_POST['total_count'] ?? 1));
            if ($name === '') { $redir('&tab=coupon', '券名不能为空'); }
            if ($type === 2) {
                $rate = (float)($_POST['discount_rate'] ?? 0);
                if ($rate <= 0 || $rate >= 1) { $redir('&tab=coupon', '折扣率需介于 0~1（如 0.85=8.5折）'); }
                $amount = 0.0;
                $maxDiscount = max(0, (float)($_POST['max_discount'] ?? 0));
            } else {
                $amount = max(0.01, (float)($_POST['amount'] ?? 0));
                $maxDiscount = 0.0;
            }
            $row = [
                'name' => mb_substr($name, 0, 64),
                'type' => $type,
                'threshold' => $threshold,
                'amount' => $amount,
                'discount_rate' => $type === 2 ? round((float)($_POST['discount_rate'] ?? 0), 3) : 0.000,
                'max_discount' => $maxDiscount,
                'total_count' => $totalCount,
                'per_user_limit' => max(1, (int)($_POST['per_user_limit'] ?? 1)),
                'start_time' => trim((string)($_POST['start_time'] ?? '')) !== '' ? trim((string)$_POST['start_time']) : null,
                'end_time' => trim((string)($_POST['end_time'] ?? '')) !== '' ? trim((string)$_POST['end_time']) : null,
                'status' => (($_POST['status'] ?? '1') === '1') ? 1 : 0,
                'grant_newcomer' => (($_POST['grant_newcomer'] ?? '0') === '1') ? 1 : 0,
            ];
            if ($id > 0) {
                Db::update('mall_coupon', $row, 'id=?', [$id]);
                $redir('&tab=coupon', '优惠券已保存：' . $name);
            }
            Db::insert('mall_coupon', $row);
            $redir('&tab=coupon', '优惠券已创建：' . $name);
        }

        case 'coupon_grant': { // M9 定向发：把某券发放给指定用户（uid 列表，逗号分隔）或全部用户
            $cid = (int)($_POST['coupon_id'] ?? 0);
            $c = Db::row('SELECT * FROM ' . Mall::t('coupon') . ' WHERE id=? AND is_delete_time=0 LIMIT 1', [$cid]);
            if ($c === null) { $redir('&tab=coupon', '优惠券不存在'); }
            $mode = (string)($_POST['mode'] ?? 'all');
            $uids = [];
            if ($mode === 'uids') {
                $raw = preg_split('/[\s,]+/', trim((string)($_POST['uids'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
                foreach ($raw as $x) { $u = (int)$x; if ($u > 0) { $uids[] = $u; } }
                if ($uids === []) { $redir('&tab=coupon', '请填写有效 uid'); }
            } else {
                $uids = array_column(Db::rows('SELECT id FROM ' . Db::t('users') . ' ORDER BY id ASC'), 'id');
            }
            $granted = 0;
            $pdo = Db::pdo();
            foreach ($uids as $uid) {
                $got = (int)Db::one('SELECT COUNT(*) FROM ' . Mall::t('user_coupon') . ' WHERE coupon_id=? AND uid=?', [$cid, $uid]);
                if ($got >= max(1, (int)$c['per_user_limit'])) { continue; }
                if ((int)$c['issued_count'] >= (int)$c['total_count']) { break; }
                $pdo->beginTransaction();
                try {
                    $st = Db::run('UPDATE ' . Mall::t('coupon') . ' SET issued_count=issued_count+1 WHERE id=? AND issued_count<total_count', [$cid]);
                    if ($st->rowCount() === 0) { $pdo->rollBack(); continue; }
                    Db::insert('mall_user_coupon', ['coupon_id' => $cid, 'uid' => $uid, 'status' => 0]);
                    $pdo->commit();
                    $granted++;
                } catch (\Throwable $e) { $pdo->rollBack(); }
            }
            $redir('&tab=coupon', '已定向发放 ' . $granted . ' 张「' . $c['name'] . '」');
        }

        case 'coupon_delete': {
            $id = (int)($_POST['id'] ?? 0);
            Db::update('mall_coupon', ['is_delete_time' => time(), 'status' => 0], 'id=?', [$id]);
            $redir('&tab=coupon', '优惠券已删除（已发出的券仍可使用）');
        }

        case 'order_deliver': {
            $id = (int)($_POST['id'] ?? 0);
            $exName = trim((string)($_POST['express_name'] ?? ''));
            $exNo = trim((string)($_POST['express_no'] ?? ''));
            $o = Db::row('SELECT id, order_no, status FROM ' . Mall::t('order') . ' WHERE id=? LIMIT 1', [$id]);
            if ($o === null) { $redir('&tab=order', '订单不存在'); }
            if ((int)$o['status'] !== 1) { $redir('&tab=order&no=' . rawurlencode((string)$o['order_no']), '仅「待发货」订单可发货'); }
            if ($exName === '' || $exNo === '') { $redir('&tab=order&no=' . rawurlencode((string)$o['order_no']), '快递公司与运单号必填'); }
            // M12：发货统一走 shipNotify（置待收货 + 写首条轨迹 + 站内消息）
            \Mall::shipNotify((string)$o['order_no'], $exName, $exNo);
            Db::insert('mall_order_log', ['order_id' => $id, 'order_no' => $o['order_no'], 'action' => 'deliver', 'detail' => '已发货：' . $exName . ' ' . $exNo, 'operator' => 'admin']);
            $redir('&tab=order&no=' . rawurlencode((string)$o['order_no']), '已发货：' . $exName . ' ' . $exNo);
        }

        case 'order_trace_add': {
            $no = trim((string)($_POST['order_no'] ?? ''));
            $txt = trim((string)($_POST['content'] ?? ''));
            if ($no === '' || $txt === '') { $redir('&tab=order&no=' . rawurlencode($no), '轨迹内容不能为空'); }
            \Mall::traceAdd($no, (string)($_POST['status_text'] ?? '运输中'), $txt);
            $redir('&tab=order&no=' . rawurlencode($no), '物流轨迹已录入');
        }

        case 'order_cron': {
            $res = \Mall::logisticsCron();
            $redir('&tab=order', '履约定时任务执行完毕：自动取消 ' . (int)$res['cancel'] . ' 单，自动完成 ' . (int)$res['finish'] . ' 单');
        }

        case 'order_admin_cancel': {
            $no = (string)($_POST['order_no'] ?? '');
            $err = \Mall::orderCancelDo($no, null, true);
            $redir('&tab=order&no=' . rawurlencode($no), $err !== '' ? $err : '订单已取消，库存已恢复');
        }

        case 'pay_refund': {
            $no = (string)($_POST['order_no'] ?? '');
            $reason = trim((string)($_POST['reason'] ?? '管理员退款'));
            if ($no === '') { $redir('&tab=order', '订单号缺失'); }
            $res = \Mall::refundOrder($no, $reason);
            if ($res === 'success') { $redir('&tab=order&no=' . rawurlencode($no), '退款已提交，通道受理中'); }
            if ($res === 'refund_recorded') { $redir('&tab=order&no=' . rawurlencode($no), '退款已记录（沙箱/人工，未调用真实通道）'); }
            $redir('&tab=order&no=' . rawurlencode($no), '退款失败：' . $res);
        }
    }
    return '';
}

/* 商品保存（事务：goods upsert + SKU 全量重写 + 冗余聚合） */
function ml_goods_save(string $base): string
{
    $redir = static function (string $to, string $msg) use ($base): void {
        $_SESSION['ml_flash'] = $msg;
        header('Location: ' . $base . $to);
        exit;
    };
    $id = (int)($_POST['id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    if ($title === '') { $redir('&tab=goods', '商品标题不能为空'); }
    $slug = trim((string)($_POST['slug'] ?? ''));
    if ($slug === '') { $slug = Str::slug($title); }
    $dup = Db::one('SELECT id FROM ' . Mall::t('goods') . ' WHERE slug=? AND id<>? AND is_delete_time=0 LIMIT 1', [$slug, $id]);
    if ($dup !== null) { $redir('&tab=goods&edit=' . $id, '商品 slug 已被占用：' . $slug); }
    // 图集：textarea 每行一个 URL
    $images = array_values(array_filter(array_map('trim', explode("\n", (string)($_POST['images'] ?? ''))), fn($s) => $s !== ''));

    // ★ 图片自动转 WebP（imgopt 插件启用时生效）
    //   直接调用而非 Hooks —— 宿主 Hooks::run() 在生产是死代码（零调用点）。
    //   处理 cover / images / detail 内嵌图三处；未装插件时静默跳过。
    $__imgChanged = 0;
    if (class_exists('ImgOpt') && \Plugins::isOn('imgopt')) {
        $__coverIn = trim((string)($_POST['cover'] ?? ''));
        $__detailIn = trim((string)($_POST['detail'] ?? ''));
        $__r = \ImgOpt::onGoodsSaved($__coverIn, $images, $__detailIn);
        if ($__r['changed'] > 0) {
            $_POST['cover'] = $__r['cover'];
            $images = $__r['images'];
            $_POST['detail'] = $__r['detail'];
            $__imgChanged = (int)$__r['changed'];
        }
    }

    $goods = [
        'category_id' => (int)($_POST['category_id'] ?? 0),
        'brand_id' => (int)($_POST['brand_id'] ?? 0),
        'title' => $title,
        'subtitle' => trim((string)($_POST['subtitle'] ?? '')),
        'slug' => $slug,
        'cover' => trim((string)($_POST['cover'] ?? '')),
        'images' => json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'detail' => trim((string)($_POST['detail'] ?? '')),
        'status' => (($_POST['status'] ?? '0') === '1') ? 1 : 0,
        'is_recommend' => (($_POST['is_recommend'] ?? '0') === '1') ? 1 : 0,
        'market_price' => max(0, (float)($_POST['market_price'] ?? 0)),
        'freight_template_id' => 0,
        'seo_title' => trim((string)($_POST['seo_title'] ?? '')),
        'seo_keywords' => trim((string)($_POST['seo_keywords'] ?? '')),
        'seo_desc' => trim((string)($_POST['seo_desc'] ?? '')),
    ];

    // SKU 行：按 id 更新已有行（保护购物车/订单 sku_id 引用），新行插入，提交中消失的行软删
    $skus = [];
    $specArr = (array)($_POST['sku_spec'] ?? []);
    $idArr = (array)($_POST['sku_id'] ?? []);
    foreach ($specArr as $i => $spec) {
        $spec = trim((string)$spec);
        $price = (float)($_POST['sku_price'][$i] ?? 0);
        if ($spec === '' && $price <= 0) { continue; }
        $skus[] = [
            'id' => (int)($idArr[$i] ?? 0),
            'spec_json' => json_encode(['规格' => $spec], JSON_UNESCAPED_UNICODE),
            'spec_text' => $spec,
            'price' => $price,
            'market_price' => max(0, (float)($_POST['sku_market'][$i] ?? 0)),
            'stock' => (int)($_POST['sku_stock'][$i] ?? 0),
            'sku_no' => trim((string)($_POST['sku_no'][$i] ?? '')),
            'is_default' => 0,
        ];
    }
    if ($skus === []) {
        $skus[] = ['id' => 0, 'spec_json' => null, 'spec_text' => '默认', 'price' => max(0, (float)($_POST['market_price'] ?? 0)), 'market_price' => 0, 'stock' => 0, 'sku_no' => '', 'is_default' => 1];
    }
    $defIdx = (int)($_POST['sku_default'] ?? 0);
    if (!isset($skus[$defIdx])) { $defIdx = 0; }
    $skus[$defIdx]['is_default'] = 1;

    $pdo = Db::pdo();
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            Db::update('mall_goods', $goods, 'id=?', [$id]);
        } else {
            $id = (int)Db::insert('mall_goods', $goods);
        }
        // SKU：按 id 更新已有行 / 插入新行 / 提交中消失的行软删（保购物车与订单引用）
        $old = [];
        foreach (Db::rows('SELECT id FROM ' . Mall::t('goods_sku') . ' WHERE goods_id=?', [$id]) as $r) { $old[(int)$r['id']] = true; }
        $keep = [];
        foreach ($skus as $s) {
            $sid = (int)$s['id'];
            unset($s['id']);
            $s['goods_id'] = $id;
            if ($sid > 0 && isset($old[$sid])) {
                Db::update('mall_goods_sku', $s, 'id=?', [$sid]);
                $keep[$sid] = true;
            } else {
                Db::insert('mall_goods_sku', $s);
            }
        }
        foreach (array_keys(array_diff_key($old, $keep)) as $gid) {
            Db::update('mall_goods_sku', ['is_delete_time' => time()], 'id=?', [$gid]);
        }
        // 冗余聚合
        $agg = Db::row('SELECT MIN(price) mp, SUM(stock) st FROM ' . Mall::t('goods_sku') . ' WHERE goods_id=? AND is_delete_time=0', [$id]);
        Db::update('mall_goods', [
            'min_price' => (float)($agg['mp'] ?? 0),
            'total_stock' => (int)($agg['st'] ?? 0),
        ], 'id=?', [$id]);
        // 标签同步（全量重写该商品标签）
        Db::delete('mall_goods_tag', 'goods_id=?', [$id]);
        $tagIds = array_values(array_filter(array_map('intval', (array)($_POST['tags'] ?? [])), fn($t) => $t > 0));
        $tagIds = array_unique($tagIds);
        foreach ($tagIds as $tid) {
            Db::insert('mall_goods_tag', ['goods_id' => $id, 'tag_id' => $tid]);
        }
        // 标签商品计数重算
        Db::run('UPDATE ' . Mall::t('tag') . ' t SET goods_count=(SELECT COUNT(*) FROM ' . Mall::t('goods_tag') . ' gt WHERE gt.tag_id=t.id) WHERE t.is_delete_time=0');
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return '保存失败：' . $e->getMessage();
    }
    $redir('&tab=goods&edit=' . $id, '商品已保存：' . $title);
}

/* ================= Tab：总览 ================= */

function ml_home_page(string $base): string
{
    $stat = [
        'goods' => (int)Db::one('SELECT COUNT(*) FROM ' . Mall::t('goods') . ' WHERE is_delete_time=0'),
        'on' => (int)Db::one('SELECT COUNT(*) FROM ' . Mall::t('goods') . ' WHERE status=1 AND is_delete_time=0'),
        'cate' => (int)Db::one('SELECT COUNT(*) FROM ' . Mall::t('goods_category') . ' WHERE is_delete_time=0'),
        'stock' => (int)Db::one('SELECT COALESCE(SUM(total_stock),0) FROM ' . Mall::t('goods') . ' WHERE is_delete_time=0'),
        'order' => 0,
        'review' => 0,
    ];
    if (Plugins::tableExists('mall_order')) { $stat['order'] = (int)Db::one('SELECT COUNT(*) FROM ' . Mall::t('order')); }
    if (Plugins::tableExists('mall_review')) { $stat['review'] = (int)Db::one('SELECT COUNT(*) FROM ' . Mall::t('review')); }

    $cards = '';
    $items = [
        ['商品总数', $stat['goods'], '个', 'goods'],
        ['上架中', $stat['on'], '个', 'goods'],
        ['分类', $stat['cate'], '个', 'cate'],
        ['总库存', $stat['stock'], '件', 'goods'],
        ['订单', $stat['order'], '单', 'order'],
        ['评价', $stat['review'], '条', ''],
    ];
    foreach ($items as $it) {
        $link = $it[3] !== '' ? '<a href="' . $base . '&amp;tab=' . $it[3] . '">' : '';
        $cards .= '<div class="mla-card"><div class="n">' . $link . (int)$it[1] . ($link !== '' ? '</a>' : '') . '<small>' . e((string)$it[2]) . '</small></div><div class="t">' . e((string)$it[0]) . '</div></div>';
    }
    return '<div class="mla-card"><h3>商城总览</h3><p class="dim">前台 <a href="/mall" target="_blank">/mall</a> · 骨架已完成，M2 商品中心上线。</p>'
        . '<div class="mla-stats">' . $cards . '</div>'
        . '<p class="dim" style="margin-top:10px">下一步：先建「商品分类」，再录商品（含 SKU 价格库存），前台即见。</p></div>';
}

/* ================= Tab：分类 ================= */

function ml_cate_page(string $base): string
{
    $editId = (int)($_GET['edit'] ?? 0);
    $edit = $editId > 0 ? Db::row('SELECT * FROM ' . Mall::t('goods_category') . ' WHERE id=? AND is_delete_time=0 LIMIT 1', [$editId]) : null;

    $form = '<form method="post" action="' . $base . '&amp;tab=cate" class="mla-card"><h3>' . ($edit !== null ? '编辑分类 #' . (int)$edit['id'] : '＋ 新增分类') . '</h3>'
        . \Csrf::field()
        . '<input type="hidden" name="act" value="cate_save"><input type="hidden" name="id" value="' . ($edit !== null ? (int)$edit['id'] : 0) . '">'
        . '<div class="mla-row">'
        . ml_field('名称', 'name', (string)($edit['name'] ?? ''), 'required')
        . ml_field('slug（URL 用，留空自动）', 'slug', (string)($edit['slug'] ?? ''))
        . ml_field('排序（小在前）', 'sort', (string)($edit['sort'] ?? 99), 'style="width:80px"')
        . ml_select('显示', 'is_show', (int)($edit['is_show'] ?? 1))
        . '</div>'
        . ml_field('图标 URL（选填）', 'icon', (string)($edit['icon'] ?? ''))
        . '<div class="mla-row">'
        . ml_field('SEO 标题', 'seo_title', (string)($edit['seo_title'] ?? ''))
        . ml_field('SEO 关键词', 'seo_keywords', (string)($edit['seo_keywords'] ?? ''))
        . ml_field('SEO 描述', 'seo_desc', (string)($edit['seo_desc'] ?? ''))
        . '</div>'
        . '<button class="mla-btn p">保存分类</button>'
        . ($edit !== null ? ' <a class="mla-btn" href="' . $base . '&amp;tab=cate">取消编辑</a>' : '')
        . '</form>';

    $cats = Db::rows('SELECT c.*, (SELECT COUNT(*) FROM ' . Mall::t('goods') . ' g WHERE g.category_id=c.id AND g.is_delete_time=0) n FROM ' . Mall::t('goods_category') . ' c WHERE c.is_delete_time=0 ORDER BY c.sort ASC, c.id ASC');
    $rows = '';
    foreach ($cats as $c) {
        $rows .= '<tr><td><b>' . e((string)$c['name']) . '</b><div class="dim">/' . e((string)$c['slug']) . '/</div></td>'
            . '<td>' . (int)$c['n'] . '</td><td>' . (int)$c['sort'] . '</td>'
            . '<td>' . ((int)$c['is_show'] === 1 ? '<span class="mla-tag ok">显示</span>' : '<span class="mla-tag">隐藏</span>') . '</td>'
            . '<td><a class="mla-btn" href="' . $base . '&amp;tab=cate&amp;edit=' . (int)$c['id'] . '">编辑</a> '
            . '<form class="inline" method="post" action="' . $base . '&amp;tab=cate" onsubmit="return confirm(\'删除分类「' . e((string)$c['name']) . '」？\')">' . \Csrf::field()
            . '<input type="hidden" name="act" value="cate_delete"><input type="hidden" name="id" value="' . (int)$c['id'] . '"><button class="mla-btn danger">删除</button></form></td></tr>';
    }
    $list = '<div class="mla-card"><h3>分类列表</h3><table><tr><th>名称 / slug</th><th>商品</th><th>排序</th><th>状态</th><th>操作</th></tr>' . ($rows !== '' ? $rows : '<tr><td colspan="5" class="dim">暂无分类</td></tr>') . '</table></div>';
    return $form . $list;
}

/* ================= Tab：品牌 ================= */

function ml_brand_page(string $base): string
{
    $editId = (int)($_GET['edit'] ?? 0);
    $edit = $editId > 0 ? Db::row('SELECT * FROM ' . Mall::t('brand') . ' WHERE id=? AND is_delete_time=0 LIMIT 1', [$editId]) : null;

    $form = '<form method="post" action="' . $base . '&amp;tab=brand" class="mla-card"><h3>' . ($edit !== null ? '编辑品牌 #' . (int)$edit['id'] : '＋ 新增品牌') . '</h3>'
        . \Csrf::field()
        . '<input type="hidden" name="act" value="brand_save"><input type="hidden" name="id" value="' . ($edit !== null ? (int)$edit['id'] : 0) . '">'
        . '<div class="mla-row">'
        . ml_field('品牌名', 'name', (string)($edit['name'] ?? ''), 'required')
        . ml_field('排序', 'sort', (string)($edit['sort'] ?? 99), 'style="width:80px"')
        . ml_select('显示', 'is_show', (int)($edit['is_show'] ?? 1))
        . '</div>'
        . ml_field('Logo URL（选填）', 'logo', (string)($edit['logo'] ?? ''))
        . '<button class="mla-btn p">保存品牌</button>'
        . ($edit !== null ? ' <a class="mla-btn" href="' . $base . '&amp;tab=brand">取消编辑</a>' : '')
        . '</form>';

    $rows = Db::rows('SELECT b.*, (SELECT COUNT(*) FROM ' . Mall::t('goods') . ' g WHERE g.brand_id=b.id AND g.is_delete_time=0) n FROM ' . Mall::t('brand') . ' b WHERE b.is_delete_time=0 ORDER BY b.sort ASC, b.id ASC');
    $tr = '';
    foreach ($rows as $b) {
        $tr .= '<tr><td><b>' . e((string)$b['name']) . '</b>' . ((string)$b['logo'] !== '' ? ' <img src="' . e((string)$b['logo']) . '" style="height:18px;vertical-align:middle" alt="">' : '') . '</td>'
            . '<td>' . (int)$b['n'] . '</td><td>' . (int)$b['sort'] . '</td>'
            . '<td>' . ((int)$b['is_show'] === 1 ? '<span class="mla-tag ok">显示</span>' : '<span class="mla-tag">隐藏</span>') . '</td>'
            . '<td><a class="mla-btn" href="' . $base . '&amp;tab=brand&amp;edit=' . (int)$b['id'] . '">编辑</a> '
            . '<form class="inline" method="post" action="' . $base . '&amp;tab=brand" onsubmit="return confirm(\'删除品牌「' . e((string)$b['name']) . '」？\')">' . \Csrf::field()
            . '<input type="hidden" name="act" value="brand_delete"><input type="hidden" name="id" value="' . (int)$b['id'] . '"><button class="mla-btn danger">删除</button></form></td></tr>';
    }
    $list = '<div class="mla-card"><h3>品牌列表</h3><table><tr><th>名称 / Logo</th><th>商品</th><th>排序</th><th>状态</th><th>操作</th></tr>' . ($tr !== '' ? $tr : '<tr><td colspan="5" class="dim">暂无品牌</td></tr>') . '</table></div>';
    return $form . $list;
}

/* ================= Tab：标签 ================= */

function ml_tag_page(string $base): string
{
    $editId = (int)($_GET['edit'] ?? 0);
    $edit = $editId > 0 ? Db::row('SELECT * FROM ' . Mall::t('tag') . ' WHERE id=? AND is_delete_time=0 LIMIT 1', [$editId]) : null;

    $form = '<form method="post" action="' . $base . '&amp;tab=tag" class="mla-card"><h3>' . ($edit !== null ? '编辑标签 #' . (int)$edit['id'] : '＋ 新增标签') . '</h3>'
        . \Csrf::field()
        . '<input type="hidden" name="act" value="tag_save"><input type="hidden" name="id" value="' . ($edit !== null ? (int)$edit['id'] : 0) . '">'
        . '<div class="mla-row">'
        . ml_field('标签名', 'name', (string)($edit['name'] ?? ''), 'required')
        . ml_field('slug（留空自动）', 'slug', (string)($edit['slug'] ?? ''))
        . ml_field('排序', 'sort', (string)($edit['sort'] ?? 99), 'style="width:80px"')
        . ml_select('显示', 'is_show', (int)($edit['is_show'] ?? 1))
        . '</div>'
        . '<button class="mla-btn p">保存标签</button>'
        . ($edit !== null ? ' <a class="mla-btn" href="' . $base . '&amp;tab=tag">取消编辑</a>' : '')
        . '</form>';

    $rows = Db::rows('SELECT * FROM ' . Mall::t('tag') . ' WHERE is_delete_time=0 ORDER BY sort ASC, id ASC');
    $tr = '';
    foreach ($rows as $t) {
        $tr .= '<tr><td><b>' . e((string)$t['name']) . '</b><div class="dim">/' . e((string)$t['slug']) . '/</div></td>'
            . '<td>' . (int)$t['goods_count'] . '</td><td>' . (int)$t['sort'] . '</td>'
            . '<td>' . ((int)$t['is_show'] === 1 ? '<span class="mla-tag ok">显示</span>' : '<span class="mla-tag">隐藏</span>') . '</td>'
            . '<td><a class="mla-btn" href="' . $base . '&amp;tab=tag&amp;edit=' . (int)$t['id'] . '">编辑</a> '
            . '<form class="inline" method="post" action="' . $base . '&amp;tab=tag" onsubmit="return confirm(\'删除标签「' . e((string)$t['name']) . '」？会解绑已关联商品。\')">' . \Csrf::field()
            . '<input type="hidden" name="act" value="tag_delete"><input type="hidden" name="id" value="' . (int)$t['id'] . '"><button class="mla-btn danger">删除</button></form></td></tr>';
    }
    $list = '<div class="mla-card"><h3>标签列表</h3><table><tr><th>名称 / slug</th><th>商品数</th><th>排序</th><th>状态</th><th>操作</th></tr>' . ($tr !== '' ? $tr : '<tr><td colspan="5" class="dim">暂无标签</td></tr>') . '</table></div>';
    return $form . $list;
}

/* ================= Tab：商品 ================= */

function ml_goods_page(string $base): string
{
    $editId = (int)($_GET['edit'] ?? -1);
    if ($editId >= 0) { return ml_goods_edit($base, $editId); }

    $q = trim((string)($_GET['q'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 15;
    $where = 'g.is_delete_time=0';
    $args = [];
    if ($q !== '') { $where .= ' AND g.title LIKE ?'; $args[] = '%' . $q . '%'; }
    $total = (int)Db::one('SELECT COUNT(*) FROM ' . Mall::t('goods') . ' g WHERE ' . $where, $args);
    $list = Db::rows('SELECT g.*, c.name cname FROM ' . Mall::t('goods') . ' g LEFT JOIN ' . Mall::t('goods_category') . ' c ON c.id=g.category_id WHERE ' . $where . ' ORDER BY g.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $args);

    $search = '<form method="get" action="/admin/" class="mla-card mla-row" style="align-items:center">'
        . '<input type="hidden" name="s" value="plug_mall"><input type="hidden" name="tab" value="goods">'
        . '<input type="text" name="q" value="' . e($q) . '" placeholder="按标题搜索" style="width:220px">'
        . '<button class="mla-btn">搜索</button>'
        . '<a class="mla-btn p" style="margin-left:auto" href="' . $base . '&amp;tab=goods&amp;edit=0">＋ 新增商品</a></form>';

    $rows = '';
    foreach ($list as $g) {
        $cover = (string)$g['cover'];
        $img = $cover !== '' ? '<img src="' . e($cover) . '" style="width:44px;height:44px;object-fit:cover" alt="">' : '<div class="mla-nopic">无图</div>';
        $st = (int)$g['status'] === 1 ? '<span class="mla-tag ok">上架</span>' : '<span class="mla-tag">下架</span>';
        $rec = (int)$g['is_recommend'] === 1 ? '<span class="mla-tag rec">推荐</span>' : '';
        $rows .= '<tr><td>' . $img . '</td>'
            . '<td><b>' . e((string)$g['title']) . '</b><div class="dim">/' . e((string)$g['slug']) . ' · ' . e((string)($g['cname'] ?: '未分类')) . ' · 库存 ' . (int)$g['total_stock'] . '</div></td>'
            . '<td>¥' . e(number_format((float)$g['min_price'], 2)) . '</td>'
            . '<td>' . $st . $rec . '</td>'
            . '<td style="white-space:nowrap">'
            . '<a class="mla-btn" href="' . $base . '&amp;tab=goods&amp;edit=' . (int)$g['id'] . '">编辑</a> '
            . '<form class="inline" method="post" action="' . $base . '&amp;tab=goods">' . \Csrf::field() . '<input type="hidden" name="act" value="goods_flag"><input type="hidden" name="id" value="' . (int)$g['id'] . '"><input type="hidden" name="what" value="status"><button class="mla-btn">' . ((int)$g['status'] === 1 ? '下架' : '上架') . '</button></form> '
            . '<form class="inline" method="post" action="' . $base . '&amp;tab=goods">' . \Csrf::field() . '<input type="hidden" name="act" value="goods_flag"><input type="hidden" name="id" value="' . (int)$g['id'] . '"><input type="hidden" name="what" value="recommend"><button class="mla-btn">' . ((int)$g['is_recommend'] === 1 ? '取消推荐' : '推荐') . '</button></form> '
            . '<form class="inline" method="post" action="' . $base . '&amp;tab=goods" onsubmit="return confirm(\'删除商品「' . e((string)$g['title']) . '」？\')">' . \Csrf::field() . '<input type="hidden" name="act" value="goods_flag"><input type="hidden" name="id" value="' . (int)$g['id'] . '"><input type="hidden" name="what" value="delete"><button class="mla-btn danger">删除</button></form>'
            . '</td></tr>';
    }
    $tbl = '<div class="mla-card"><h3>商品（' . $total . '）</h3><table><tr><th>图</th><th>标题 / 分类 / 库存</th><th>价格</th><th>状态</th><th>操作</th></tr>'
        . ($rows !== '' ? $rows : '<tr><td colspan="5" class="dim">暂无商品，点右上「新增商品」录入</td></tr>') . '</table>';
    $tp = (int)ceil($total / $per);
    if ($tp > 1) {
        $pg = '<div class="mla-pager">';
        for ($i = 1; $i <= $tp; $i++) {
            $pg .= $i === $page ? '<b>' . $i . '</b>' : '<a href="' . $base . '&amp;tab=goods&amp;q=' . rawurlencode($q) . '&amp;page=' . $i . '">' . $i . '</a>';
        }
        $tbl .= $pg . '</div>';
    }
    return $search . $tbl . '</div>';
}

/* 商品编辑表单（基本信息 + 封面图集 + SKU 行） */
function ml_goods_edit(string $base, int $id): string
{
    $g = $id > 0 ? Db::row('SELECT * FROM ' . Mall::t('goods') . ' WHERE id=? LIMIT 1', [$id]) : null;
    if ($id > 0 && $g === null) { return '<div class="mla-card">商品不存在。<a href="' . $base . '&amp;tab=goods">返回列表</a></div>'; }
    $cats = Db::rows('SELECT id, name FROM ' . Mall::t('goods_category') . ' WHERE is_delete_time=0 ORDER BY sort ASC, id ASC');
    $catOpts = '<option value="0">未分类</option>';
    foreach ($cats as $c) {
        $catOpts .= '<option value="' . (int)$c['id'] . '"' . ((int)($g['category_id'] ?? 0) === (int)$c['id'] ? ' selected' : '') . '>' . e((string)$c['name']) . '</option>';
    }
    $brands = Db::rows('SELECT id, name FROM ' . Mall::t('brand') . ' WHERE is_delete_time=0 AND is_show=1 ORDER BY sort ASC, id ASC');
    $brandOpts = '<option value="0">无品牌</option>';
    foreach ($brands as $b) {
        $brandOpts .= '<option value="' . (int)$b['id'] . '"' . ((int)($g['brand_id'] ?? 0) === (int)$b['id'] ? ' selected' : '') . '>' . e((string)$b['name']) . '</option>';
    }
    $tags = Db::rows('SELECT id, name FROM ' . Mall::t('tag') . ' WHERE is_delete_time=0 AND is_show=1 ORDER BY sort ASC, id ASC');
    $curTags = [];
    if ($g !== null) {
        $curTags = array_column(Db::rows('SELECT tag_id FROM ' . Mall::t('goods_tag') . ' WHERE goods_id=?', [$id]), 'tag_id');
        $curTags = array_map('intval', $curTags);
    }
    $tagChecks = '';
    foreach ($tags as $t) {
        $on = in_array((int)$t['id'], $curTags, true) ? ' checked' : '';
        $tagChecks .= '<label class="mla-tag-chk"><input type="checkbox" name="tags[]" value="' . (int)$t['id'] . '"' . $on . '> ' . e((string)$t['name']) . '</label>';
    }
    if ($tagChecks === '') { $tagChecks = '<span class="dim">暂无标签，去「标签」页新建</span>'; }
    $imgs = [];
    if ($g !== null && $g['images'] !== null) { $imgs = (array)json_decode((string)$g['images'], true); }

    // 上传按钮（复用宿主 do=upload；JS 填回 URL）
    $upBtn = static function (string $target, string $label): string {
        return '<button type="button" class="mla-btn up" data-target="' . $target . '">' . e($label) . '</button>'
            . '<input type="file" accept="image/*" style="display:none" data-up-for="' . $target . '">';
    };

    $skuRows = '';
    if ($g !== null) {
        $skus = Db::rows('SELECT * FROM ' . Mall::t('goods_sku') . ' WHERE goods_id=? AND is_delete_time=0 ORDER BY is_default DESC, price ASC', [$id]);
        $i = 0;
        foreach ($skus as $s) {
            $skuRows .= ml_sku_row($i, (string)$s['spec_text'], (string)$s['price'], (string)$s['market_price'], (string)$s['stock'], (string)$s['sku_no'], (int)$s['is_default'] === 1, (int)$s['id']);
            $i++;
        }
        if ($skuRows === '') { $skuRows = ml_sku_row(0, '默认', '', '', '', '', true); $i = 1; }
    } else {
        $skuRows = ml_sku_row(0, '默认', '', '', '', '', true);
        $i = 1;
    }

    $h = '<form method="post" action="' . $base . '&amp;tab=goods" class="mla-card" id="mla-goods-form">'
        . '<h3>' . ($g !== null ? '编辑商品 #' . (int)$g['id'] . ' <a class="dim" href="/mall/goods/' . (int)$g['id'] . '.html" target="_blank">前台查看 →</a>' : '＋ 新增商品') . '</h3>'
        . \Csrf::field()
        . '<input type="hidden" name="act" value="goods_save"><input type="hidden" name="id" value="' . ($g !== null ? (int)$g['id'] : 0) . '">'
        . '<div class="mla-row">'
        . ml_field('商品标题', 'title', (string)($g['title'] ?? ''), 'required style="flex:2"')
        . ml_field('副标题（卖点一句话）', 'subtitle', (string)($g['subtitle'] ?? ''), 'style="flex:3"')
        . '</div>'
        . '<div class="mla-row">'
        . '<label class="mla-f">分类<select name="category_id">' . $catOpts . '</select></label>'
        . ml_field('slug（留空自动）', 'slug', (string)($g['slug'] ?? ''))
        . '<label class="mla-f">划线价（选填）<input type="text" name="market_price" value="' . e((string)($g['market_price'] != 0 ? rtrim(rtrim((string)$g['market_price'], '0'), '.') : '')) . '" placeholder="如 199"></label>'
        . '<label class="mla-f">状态<select name="status"><option value="1"' . ((int)($g['status'] ?? 0) === 1 ? ' selected' : '') . '>上架</option><option value="0"' . ((int)($g['status'] ?? 0) === 0 ? ' selected' : '') . '>下架</option></select></label>'
        . '<label class="mla-f">推荐<select name="is_recommend"><option value="0"' . ((int)($g['is_recommend'] ?? 0) === 0 ? ' selected' : '') . '>否</option><option value="1"' . ((int)($g['is_recommend'] ?? 0) === 1 ? ' selected' : '') . '>是</option></select></label>'
        . '</div>'
        // 品牌 + 标签
        . '<div class="mla-row">'
        . '<label class="mla-f">品牌<select name="brand_id">' . $brandOpts . '</select></label>'
        . '<div class="mla-f" style="flex:4"><span class="dim">商品标签</span><div class="mla-tag-box">' . $tagChecks . '</div></div>'
        . '</div>'
        // 封面 + 图集
        . '<div class="mla-row">'
        . '<label class="mla-f" style="flex:2">封面图 URL' . $upBtn('cover', '上传封面') . '<input type="text" name="cover" id="f-cover" value="' . e((string)($g['cover'] ?? '')) . '" placeholder="/uploads/…"></label>'
        . '<label class="mla-f" style="flex:3">图集 URL（每行一张，第一张可留空）' . $upBtn('images', '上传图集') . '<textarea name="images" id="f-images" rows="4" placeholder="每行一个图片 URL">' . e(implode("\n", $imgs)) . '</textarea></label>'
        . '</div>'
        . '<div class="mla-cover-preview"><span class="dim">封面预览：</span><img id="mla-cover-img" src="' . e((string)($g['cover'] ?? '')) . '" alt="" style="' . ((string)($g['cover'] ?? '') === '' ? 'display:none' : '') . 'max-height:90px;border:1px solid #e5e0d5"></div>'
        . ml_field('商品详情（纯文本，M3 接富文本）', 'detail', (string)($g['detail'] ?? ''), 'textarea rows=6')
        // SKU
        . '<h3 style="margin-top:14px">规格 / SKU（价格库存按行填写）</h3>'
        . '<table class="mla-sku"><tr><th>规格文案</th><th>售价 ¥</th><th>划线价 ¥</th><th>库存</th><th>货号</th><th>默认</th><th></th></tr>'
        . $skuRows
        . '</table>'
        . '<p><button type="button" class="mla-btn" id="mla-add-sku">＋ 添加规格行</button> <span class="dim">留空「默认」行则自动生成默认 SKU</span></p>'
        . '<div class="mla-row">'
        . ml_field('SEO 标题', 'seo_title', (string)($g['seo_title'] ?? ''))
        . ml_field('SEO 关键词', 'seo_keywords', (string)($g['seo_keywords'] ?? ''))
        . ml_field('SEO 描述', 'seo_desc', (string)($g['seo_desc'] ?? ''))
        . '</div>'
        . '<button class="mla-btn p">保存商品</button> <a class="mla-btn" href="' . $base . '&amp;tab=goods">返回列表</a>'
        . '</form>';

    return $h . ml_goods_js();
}

function ml_sku_row(int $i, string $spec, string $price, string $market, string $stock, string $no, bool $isDef, int $skuId = 0): string
{
    return '<tr>'
        . '<td><input type="hidden" name="sku_id[]" value="' . ($skuId > 0 ? $skuId : '') . '"><input type="text" name="sku_spec[]" value="' . e($spec) . '" placeholder="如 357g/饼"></td>'
        . '<td><input type="text" name="sku_price[]" value="' . e($price) . '" required></td>'
        . '<td><input type="text" name="sku_market[]" value="' . e($market) . '"></td>'
        . '<td><input type="text" name="sku_stock[]" value="' . e($stock) . '"></td>'
        . '<td><input type="text" name="sku_no[]" value="' . e($no) . '"></td>'
        . '<td><input type="radio" name="sku_default" value="' . $i . '"' . ($isDef ? ' checked' : '') . '></td>'
        . '<td><button type="button" class="mla-btn danger sku-del">删</button></td>'
        . '</tr>';
}

/* 交互增强 JS（仅增强：SKU 加行/删行、封面上传回填、封面预览；主体内容全 SSR） */
function ml_goods_js(): string
{
    return <<<'HTML'
<script>
(function () {
  var form = document.getElementById('mla-goods-form');
  if (!form) return;
  // SKU 加行：克隆首行并清空，重排 radio 值
  var add = document.getElementById('mla-add-sku');
  var tbl = form.querySelector('.mla-sku');
  function reindex() {
    var rows = tbl.querySelectorAll('tbody tr, tr');
    var n = 0;
    rows.forEach(function (tr) {
      var r = tr.querySelector('input[type=radio]');
      if (r) { r.value = String(n++); }
    });
  }
  if (add) add.addEventListener('click', function () {
    var first = tbl.querySelector('tr');
    var tr = first.cloneNode(true);
    tr.querySelectorAll('input[type=text]').forEach(function (inp) { inp.value = ''; });
    tr.querySelector('input[type=radio]').checked = false;
    tbl.appendChild(tr);
    reindex();
  });
  tbl.addEventListener('click', function (ev) {
    if (ev.target.classList.contains('sku-del')) {
      var tr = ev.target.closest('tr');
      if (tbl.querySelectorAll('tr').length <= 2) { return; } // 至少留一行
      tr.remove(); reindex();
    }
  });
  // 上传：走宿主 do=upload（CSRF 取自页面任一 hidden _csrf）
  form.querySelectorAll('input[data-up-for]').forEach(function (file) {
    file.addEventListener('change', function () {
      var f = file.files && file.files[0];
      if (!f) return;
      var target = file.getAttribute('data-up-for');
      var tokenInput = form.querySelector('input[name=_csrf]');
      var fd = new FormData();
      fd.append('do', 'upload'); fd.append('file', f);
      if (tokenInput) { fd.append('_csrf', tokenInput.value); }
      fetch('/admin/', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) { alert(j.error || '上传失败'); return; }
          var el = document.getElementById('f-' + target);
          if (target === 'cover') { el.value = j.url; preview(); }
          else { el.value = el.value.trim() === '' ? j.url : el.value.replace(/\s*$/, '\n') + j.url; }
        }).catch(function () { alert('上传请求失败'); });
    });
  });
  form.querySelectorAll('button.up[data-target]').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.getAttribute('data-target');
      form.querySelector('input[data-up-for="' + t + '"]').click();
    });
  });
  // 封面预览
  var cover = document.getElementById('f-cover'), prev = document.getElementById('mla-cover-img');
  function preview() {
    if (!prev) return;
    prev.src = cover.value; prev.style.display = cover.value ? '' : 'none';
  }
  if (cover) cover.addEventListener('change', preview);
})();
</script>
HTML;
}

/* ================= Tab：订单 ================= */

function ml_order_page(string $base): string
{
    $no = trim((string)($_GET['no'] ?? ''));
    if ($no !== '') { return ml_order_detail($base, $no); }

    $st = isset($_GET['st']) && $_GET['st'] !== '' ? (int)$_GET['st'] : -1;
    $q = trim((string)($_GET['q'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 15;
    $where = '1';
    $args = [];
    if ($st >= 0 && $st <= 5) { $where .= ' AND o.status=?'; $args[] = $st; }
    if ($q !== '') { $where .= ' AND o.order_no LIKE ?'; $args[] = '%' . $q . '%'; }
    $total = (int)Db::one('SELECT COUNT(*) FROM ' . Mall::t('order') . ' o WHERE ' . $where, $args);
    $rows = Db::rows('SELECT o.*, u.username FROM ' . Mall::t('order') . ' o LEFT JOIN ' . Db::t('users') . ' u ON u.id=o.uid'
        . ' WHERE ' . $where . ' ORDER BY o.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $args);

    $stName = ['待付款', '待发货', '待收货', '已完成', '已取消', '售后中'];
    $filter = '<form method="get" action="/admin/" class="mla-card mla-row" style="align-items:center">'
        . '<input type="hidden" name="s" value="plug_mall"><input type="hidden" name="tab" value="order">'
        . '<label class="mla-f">状态<select name="st"><option value="">全部</option>';
    foreach ($stName as $k => $n) { $filter .= '<option value="' . $k . '"' . ($st === $k ? ' selected' : '') . '>' . $n . '</option>'; }
    $filter .= '</select></label>'
        . '<label class="mla-f">订单号<input type="text" name="q" value="' . e($q) . '" style="width:220px"></label>'
        . '<button class="mla-btn">筛选</button></form>';

    $rowsHtml = '';
    foreach ($rows as $o) {
        $s = (int)$o['status'];
        $rowsHtml .= '<tr><td><a href="' . $base . '&amp;tab=order&amp;no=' . e((string)$o['order_no']) . '"><b>' . e((string)$o['order_no']) . '</b></a></td>'
            . '<td>' . e((string)($o['username'] ?: ('uid#' . (int)$o['uid']))) . '</td>'
            . '<td>¥' . e(number_format((float)$o['pay_price'], 2)) . '</td>'
            . '<td><span class="mla-tag' . ($s === 0 ? ' rec' : ($s === 3 || $s === 1 ? ' ok' : '')) . '">' . $stName[$s] . '</span></td>'
            . '<td class="dim">' . e((string)$o['created_at']) . '</td>'
            . '<td><a class="mla-btn" href="' . $base . '&amp;tab=order&amp;no=' . e((string)$o['order_no']) . '">详情</a></td></tr>';
    }
    $tbl = '<div class="mla-card"><h3>订单（' . $total . '）</h3><table><tr><th>订单号</th><th>用户</th><th>实付</th><th>状态</th><th>下单时间</th><th>操作</th></tr>'
        . ($rowsHtml !== '' ? $rowsHtml : '<tr><td colspan="6" class="dim">暂无订单</td></tr>') . '</table></div>';
    $tp = (int)ceil($total / $per);
    if ($tp > 1) {
        $pg = '<div class="mla-pager">';
        for ($i = 1; $i <= $tp; $i++) {
            $pg .= $i === $page ? '<b>' . $i . '</b>' : '<a href="' . $base . '&amp;tab=order&amp;st=' . $st . '&amp;q=' . rawurlencode($q) . '&amp;page=' . $i . '">' . $i . '</a>';
        }
        $tbl .= $pg . '</div>';
    }
    return $filter . $tbl;
}

function ml_order_detail(string $base, string $no): string
{
    $o = Db::row('SELECT o.*, u.username FROM ' . Mall::t('order') . ' o LEFT JOIN ' . Db::t('users') . ' u ON u.id=o.uid WHERE o.order_no=? LIMIT 1', [$no]);
    if ($o === null) { return '<div class="mla-card">订单不存在。<a href="' . $base . '&amp;tab=order">返回列表</a></div>'; }
    $stName = ['待付款', '待发货', '待收货', '已完成', '已取消', '售后中'];
    $goods = Db::rows('SELECT * FROM ' . Mall::t('order_goods') . ' WHERE order_id=?', [(int)$o['id']]);
    $logs = Db::rows('SELECT * FROM ' . Mall::t('order_log') . ' WHERE order_id=? ORDER BY id DESC', [(int)$o['id']]);
    $addr = (array)json_decode((string)$o['address_json'], true);

    $h = '<div class="mla-card"><h3>订单 ' . e((string)$o['order_no']) . ' <span class="mla-tag' . ((int)$o['status'] === 0 ? ' rec' : ' ok') . '">' . $stName[(int)$o['status']] . '</span>'
        . ' <a class="mla-btn" style="float:right" href="' . $base . '&amp;tab=order">返回列表</a></h3>'
        . '<p class="dim">用户 ' . e((string)($o['username'] ?: ('uid#' . (int)$o['uid']))) . ' · 下单 ' . e((string)$o['created_at']) . '</p>'
        . '<p><b>收货：</b>' . e((string)($addr['name'] ?? '')) . ' ' . e((string)($addr['mobile'] ?? '')) . ' · '
        . e((string)($addr['province'] ?? '') . ($addr['city'] ?? '') . ($addr['county'] ?? '') . ' ' . ($addr['address_detail'] ?? ''))
        . ((string)$o['remark'] !== '' ? '<br><b>备注：</b>' . e((string)$o['remark']) : '') . '</p>'
        . '<table><tr><th>商品</th><th>规格</th><th>单价</th><th>数量</th><th>小计</th></tr>';
    foreach ($goods as $g) {
        $h .= '<tr><td>' . e((string)$g['title']) . '</td><td>' . e((string)$g['spec_text']) . '</td>'
            . '<td>¥' . e(number_format((float)$g['price'], 2)) . '</td><td>' . (int)$g['num'] . '</td>'
            . '<td>¥' . e(number_format((float)$g['total_price'], 2)) . '</td></tr>';
    }
    $h .= '</table><p class="dim">商品合计 ¥' . e(number_format((float)$o['total_goods_price'], 2))
        . ' · 运费 ¥' . e(number_format((float)$o['freight_price'], 2))
        . ' · 实付 <b style="color:#a21400">¥' . e(number_format((float)$o['pay_price'], 2)) . '</b></p>';

    // 发货表单（仅待发货）
    if ((int)$o['status'] === 1) {
        $h .= '<form method="post" action="' . $base . '&amp;tab=order" class="mla-row" style="margin-top:8px">' . \Csrf::field()
            . '<input type="hidden" name="act" value="order_deliver"><input type="hidden" name="id" value="' . (int)$o['id'] . '">'
            . ml_field('快递公司', 'express_name', (string)$o['express_name'], 'required placeholder="顺丰/中通…"')
            . ml_field('运单号', 'express_no', (string)$o['express_no'], 'required style="flex:2"')
            . '<button class="mla-btn p">发货</button></form>';
    }
    if (in_array((int)$o['status'], [0, 1], true)) {
        $h .= '<form method="post" action="' . $base . '&amp;tab=order" class="inline" onsubmit="return confirm(\'取消该订单并恢复库存？\')">' . \Csrf::field()
            . '<input type="hidden" name="act" value="order_admin_cancel"><input type="hidden" name="order_no" value="' . e((string)$o['order_no']) . '">'
            . '<button class="mla-btn danger" style="margin-top:8px">取消订单</button></form>';
    }
    // 退款（原路退回，仅已付款订单）
    if (in_array((int)$o['status'], [1, 2, 3], true)) {
        $h .= '<form method="post" action="' . $base . '&amp;tab=order" class="inline" onsubmit="return confirm(\'确认退款 ¥' . e(number_format((float)$o['pay_price'], 2)) . '？款项将按原渠道退回。\')">' . \Csrf::field()
            . '<input type="hidden" name="act" value="pay_refund"><input type="hidden" name="order_no" value="' . e((string)$o['order_no']) . '">'
            . '<button class="mla-btn warn" style="margin-top:8px">退款</button></form>';
    }
    // 快递信息（已发货）
    if ((string)$o['express_name'] !== '') {
        $h .= '<p class="dim" style="margin-top:8px">快递：' . e((string)$o['express_name']) . ' ' . e((string)$o['express_no']) . ' · ' . e((string)$o['delivery_time']) . '</p>';
    }
    $h .= '</div>';

    $lg = '<div class="mla-card"><h3>订单动态</h3><table><tr><th>时间</th><th>动态</th><th>操作者</th></tr>';
    foreach ($logs as $row) {
        $lg .= '<tr><td class="dim">' . e((string)$row['created_at']) . '</td><td>' . e((string)$row['detail']) . '</td><td>' . e((string)$row['operator']) . '</td></tr>';
    }
    $lg .= '</table></div>';
    return $h . $lg;
}

/* ================= Tab：优惠券 ================= */

function ml_coupon_page(string $base): string
{
    $editId = (int)($_GET['edit'] ?? 0);
    $edit = $editId > 0 ? Db::row('SELECT * FROM ' . Mall::t('coupon') . ' WHERE id=? AND is_delete_time=0 LIMIT 1', [$editId]) : null;

    $etype = (int)($edit['type'] ?? 1);
    $form = '<form method="post" action="' . $base . '&amp;tab=coupon" class="mla-card"><h3>' . ($edit !== null ? '编辑优惠券 #' . (int)$edit['id'] : '＋ 新增优惠券') . '</h3>'
        . \Csrf::field()
        . '<input type="hidden" name="act" value="coupon_save"><input type="hidden" name="id" value="' . ($edit !== null ? (int)$edit['id'] : 0) . '">'
        . '<div class="mla-row">'
        . ml_field('券名', 'name', (string)($edit['name'] ?? ''), 'required style="flex:2"')
        . '<label class="mla-f">类型<select name="type" id="mla-cp-type"><option value="1"' . ($etype === 1 ? ' selected' : '') . '>满减券</option><option value="2"' . ($etype === 2 ? ' selected' : '') . '>折扣券</option></label>'
        . '</div><div class="mla-row" id="mla-cp-fixed">'
        . ml_field('面额 ¥（满减券）', 'amount', (string)(($edit['amount'] ?? 0) != 0 ? rtrim(rtrim((string)($edit['amount'] ?? 0), '0'), '.') : ''), 'required')
        . '</div><div class="mla-row" id="mla-cp-disc">'
        . ml_field('折扣率（0.85=8.5折）', 'discount_rate', (string)(($edit['discount_rate'] ?? 0) != 0 ? rtrim(rtrim((string)($edit['discount_rate'] ?? 0), '0'), '.') : ''))
        . ml_field('封顶 ¥（0=不封顶）', 'max_discount', (string)(($edit['max_discount'] ?? 0) != 0 ? rtrim(rtrim((string)($edit['max_discount'] ?? 0), '0'), '.') : '0'))
        . '</div><div class="mla-row">'
        . ml_field('使用门槛 ¥（满 X 可用，0=无门槛）', 'threshold', (string)(($edit['threshold'] ?? 0) != 0 ? rtrim(rtrim((string)($edit['threshold'] ?? 0), '0'), '.') : '0'))
        . ml_field('发放总量', 'total_count', (string)($edit['total_count'] ?? 100))
        . ml_field('每人限领', 'per_user_limit', (string)($edit['per_user_limit'] ?? 1))
        . '</div><div class="mla-row">'
        . ml_field('生效时间（Y-m-d H:i，留空=立即）', 'start_time', (string)($edit['start_time'] ?? ''))
        . ml_field('失效时间（Y-m-d H:i，留空=长期）', 'end_time', (string)($edit['end_time'] ?? ''))
        . '<label class="mla-f">状态<select name="status"><option value="1"' . ((int)($edit['status'] ?? 1) === 1 ? ' selected' : '') . '>启用</option><option value="0"' . ((int)($edit['status'] ?? 1) === 0 ? ' selected' : '') . '>停用</option></select></label>'
        . '<label class="mla-f"><input type="hidden" name="grant_newcomer" value="0"><input type="checkbox" name="grant_newcomer" value="1"' . ((int)($edit['grant_newcomer'] ?? 0) === 1 ? ' checked' : '') . '> 新人自动发放</label>'
        . '</div>'
        . '<button class="mla-btn p">保存</button>'
        . ($edit !== null ? ' <a class="mla-btn" href="' . $base . '&amp;tab=coupon">取消编辑</a>' : '')
        . '</form>'
        . '<script>(function(){var s=document.getElementById("mla-cp-type");if(!s)return;function t(){var d=s.value==="2";document.getElementById("mla-cp-disc").style.display=d?"":"none";document.getElementById("mla-cp-fixed").style.display=d?"none":"";}s.addEventListener("change",t);t();})();</script>';

    $rows = Db::rows('SELECT c.*, (SELECT COUNT(*) FROM ' . Mall::t('user_coupon') . ' uc WHERE uc.coupon_id=c.id AND uc.status=0) unused FROM ' . Mall::t('coupon') . ' c WHERE c.is_delete_time=0 ORDER BY c.id DESC');
    $rowsHtml = '';
    foreach ($rows as $c) {
        $range = ((string)$c['start_time'] !== '' ? date('m.d', strtotime((string)$c['start_time'])) : '') . ' ~ '
            . ($c['end_time'] !== null ? date('m.d', strtotime((string)$c['end_time'])) : '长期');
        $cpRule = (int)$c['type'] === 2
            ? '折扣 ' . e(rtrim(rtrim(number_format((float)$c['discount_rate'] * 10, 1, '.', ''), '0'), '.')) . '折'
                . ((float)$c['max_discount'] > 0 ? '（封顶¥' . e(number_format((float)$c['max_discount'], 2)) . '）' : '')
                . ' · 满¥' . e(number_format((float)$c['threshold'], 2)) . '可用'
            : '满 ¥' . e(number_format((float)$c['threshold'], 2)) . ' 减 ¥' . e(number_format((float)$c['amount'], 2));
        $newTag = (int)$c['grant_newcomer'] === 1 ? ' <span class="mla-tag ok">新人</span>' : '';
        $rowsHtml .= '<tr><td><b>' . e((string)$c['name']) . '</b>' . $newTag . '<div class="dim">' . $cpRule . '</div></td>'
            . '<td>' . (int)$c['issued_count'] . ' / ' . (int)$c['total_count'] . '</td>'
            . '<td>' . (int)$c['unused'] . '</td>'
            . '<td class="dim">' . e($range) . '</td>'
            . '<td>' . ((int)$c['status'] === 1 ? '<span class="mla-tag ok">启用</span>' : '<span class="mla-tag">停用</span>') . '</td>'
            . '<td style="white-space:nowrap"><a class="mla-btn" href="' . $base . '&amp;tab=coupon&amp;edit=' . (int)$c['id'] . '">编辑</a> '
            . '<form class="inline" method="post" action="' . $base . '&amp;tab=coupon" onsubmit="return confirm(\'删除优惠券「' . e((string)$c['name']) . '」？已发出的券仍可用。\')">' . \Csrf::field()
            . '<input type="hidden" name="act" value="coupon_delete"><input type="hidden" name="id" value="' . (int)$c['id'] . '"><button class="mla-btn danger">删除</button></form></td></tr>';
    }
    $list = '<div class="mla-card"><h3>优惠券（' . count($rows) . '）</h3><table><tr><th>券名 / 规则</th><th>已发 / 总量</th><th>未使用</th><th>有效期</th><th>状态</th><th>操作</th></tr>'
        . ($rowsHtml !== '' ? $rowsHtml : '<tr><td colspan="6" class="dim">暂无优惠券</td></tr>') . '</table></div>';

    // M9 定向发：选券 → 发给全部用户或指定 uid
    $cpOpts = '';
    foreach ($rows as $c) { $cpOpts .= '<option value="' . (int)$c['id'] . '">' . e((string)$c['name']) . '</option>'; }
    $grant = '<div class="mla-card"><h3>定向发放</h3><form method="post" action="' . $base . '&amp;tab=coupon" class="mla-row">'
        . \Csrf::field()
        . '<input type="hidden" name="act" value="coupon_grant">'
        . '<label class="mla-f">优惠券<select name="coupon_id">' . $cpOpts . '</select></label>'
        . '<label class="mla-f">范围<select name="mode"><option value="all">全部用户</option><option value="uids">指定 uid</option></select></label>'
        . ml_field('uid（逗号分隔，仅指定模式）', 'uids', '')
        . '<button class="mla-btn p">发放</button></form></div>';

    return $form . $list . $grant;
}

/* ================= Tab：设置 ================= */

function ml_set_page(string $base): string
{
    $on = static function (string $k): string { return get_option($k, '0') === '1' ? ' checked' : ''; };
    $val = static function (string $k, string $d = ''): string { return e((string)get_option($k, $d)); };
    $cfg = '<form method="post" action="' . $base . '&amp;tab=set" class="mla-card"><h3>商城设置</h3>'
        . \Csrf::field()
        . '<input type="hidden" name="act" value="set_save">'
        . ml_field('商城名称（前台头部 Logo 字）', 'mall_name', (string)get_option('mall.name', '夜叉商城'))
        . ml_field('公告（顶部条，选填）', 'mall_notice', (string)get_option('mall.notice', ''))
        . ml_field('客服（QQ/微信/电话，选填）', 'mall_service', (string)get_option('mall.service', ''))
        . ml_select('AI 导购浮窗（需先配置 AI 能力插件）', 'ai_guide', (int)get_option('mall.ai_guide', '1'))
        // ★ 页脚合规展示（对标 51puer）：未填则不显示该项，不留空占位
        . '<div class="mla-row">'
        . ml_field('ICP 备案号', 'icp_no', (string)get_option('mall.icp_no', ''), 'placeholder=如 滇ICP备xxxxxxxx号')
        . ml_field('ICP 备案查询链接', 'icp_url', (string)get_option('mall.icp_url', 'https://beian.miit.gov.cn/'))
        . '</div>'
        . '<div class="mla-row">'
        . ml_field('公安网安备案号', 'police_no', (string)get_option('mall.police_no', ''), 'placeholder=如 滇公网安备 xxxxxxxxxxxx号')
        . ml_field('公安备案链接', 'police_url', (string)get_option('mall.police_url', ''))
        . '</div>'
        . '<div class="mla-row">'
        . ml_field('ICP 经营许可证号', 'license_no', (string)get_option('mall.license_no', ''))
        . ml_field('经营许可证图片 URL', 'license_img', (string)get_option('mall.license_img', ''))
        . ml_field('工商执照图片 URL', 'business_img', (string)get_option('mall.business_img', ''))
        . '</div>'
        . '</div><div class="mla-row">'
        . ml_field('固定运费 ¥（0=不收运费）', 'freight_fee', (string)get_option('mall.freight_fee', '10'))
        . ml_field('满 ¥ 包邮（0=从不包邮）', 'free_from', (string)get_option('mall.free_from', '51'))
        . '</div>'
        . '<button class="mla-btn p">保存基础设置</button></form>';

    // M10 积分与签到
    $pts = '<form method="post" action="' . $base . '&amp;tab=set" class="mla-card"><h3>积分与签到</h3>'
        . \Csrf::field()
        . '<input type="hidden" name="act" value="set_save">'
        . '<div class="mla-row">'
        . ml_field('积分兑换比例（多少积分抵 ¥1）', 'points_rate', (string)get_option('mall.points_rate', '100'))
        . ml_field('积分最多抵扣应付比例（0~1，0.5=50%）', 'points_max_rate', (string)get_option('mall.points_max_rate', '0.5'))
        . '</div><div class="mla-row">'
        . ml_field('每日签到基础积分', 'signin_points', (string)get_option('mall.signin_points', '5'))
        . ml_field('连签 7 天额外奖励积分', 'signin_bonus', (string)get_option('mall.signin_bonus', '10'))
        . '</div>'
        . '<button class="mla-btn p">保存积分设置</button></form>';

    // 支付模块
    $pay = '<form method="post" action="' . $base . '&amp;tab=set" class="mla-card"><h3>支付模块</h3>'
        . \Csrf::field()
        . '<input type="hidden" name="act" value="set_save">'
        . '<div class="mla-row">'
        . '<label class="mla-f"><input type="hidden" name="pay_sandbox_sim" value="0"><input type="checkbox" name="pay_sandbox_sim" value="1"' . $on('mall.pay_sandbox_sim') . '> 沙箱模拟（未配商户号时本地可测支付宝/微信）</label>'
        . '<label class="mla-f"><input type="hidden" name="pay_alipay_on" value="0"><input type="checkbox" name="pay_alipay_on" value="1"' . $on('mall.pay_alipay_on') . '> 启用支付宝</label>'
        . '<label class="mla-f"><input type="hidden" name="pay_wxpay_on" value="0"><input type="checkbox" name="pay_wxpay_on" value="1"' . $on('mall.pay_wxpay_on') . '> 启用微信支付</label>'
        . '</div><div class="mla-row">'
        . ml_field('mock 回调密钥', 'pay_mock_secret', (string)get_option('mall.pay_mock_secret', 'mock-secret'))
        . '</div>'
        . '<hr class="mla-hr"><h4>支付宝（电脑网站支付 · RSA2）</h4>'
        . '<div class="mla-row">'
        . ml_field('AppId', 'pay_alipay_app_id', $val('mall.pay_alipay_app_id'))
        . '<label class="mla-f"><input type="hidden" name="pay_alipay_sandbox" value="0"><input type="checkbox" name="pay_alipay_sandbox" value="1"' . $on('mall.pay_alipay_sandbox') . '> 沙箱网关</label>'
        . '</div>'
        . ml_field('应用私钥（PKCS1/PKCS8，可裸 base64）', 'pay_alipay_private_key', $val('mall.pay_alipay_private_key'), 'textarea rows=3')
        . ml_field('支付宝公钥', 'pay_alipay_public_key', $val('mall.pay_alipay_public_key'), 'textarea rows=3')
        . '<hr class="mla-hr"><h4>微信支付（NATIVE 扫码 · HMAC-SHA256）</h4>'
        . '<div class="mla-row">'
        . ml_field('商户号 mch_id', 'pay_wxpay_mch_id', $val('mall.pay_wxpay_mch_id'))
        . ml_field('AppId', 'pay_wxpay_app_id', $val('mall.pay_wxpay_app_id'))
        . '<label class="mla-f"><input type="hidden" name="pay_wxpay_sandbox" value="0"><input type="checkbox" name="pay_wxpay_sandbox" value="1"' . $on('mall.pay_wxpay_sandbox') . '> 沙箱网关</label>'
        . '</div>'
        . ml_field('APIv2 密钥 key', 'pay_wxpay_key', $val('mall.pay_wxpay_key'), 'textarea rows=2')
        . ml_field('AppSecret（公众号 JSAPI 取 openid 用）', 'pay_wxpay_secret', $val('mall.pay_wxpay_secret'), 'textarea rows=2')
        . '<p class="dim">终端覆盖：PC 扫码（NATIVE）+ 手机浏览器 H5（MWEB）+ 微信内 JSAPI（公众号支付，需 AppId/AppSecret 与微信商户平台配置授权域名）。</p>'
        . '<hr class="mla-hr"><h4>扫码收款（微信 / 支付宝收款码 · 无需商户号）</h4>'
        . '<div class="mla-row">'
        . '<label class="mla-f"><input type="hidden" name="pay_qr_on" value="0"><input type="checkbox" name="pay_qr_on" value="1"' . $on('mall.pay_qr_on') . '> 启用扫码收款</label>'
        . ml_field('收款方名称（如店铺名，展示用）', 'pay_payee', $val('mall.pay_payee'))
        . '</div>'
        . '<div class="mla-qr-box"><div class="mla-qr-h">微信收款</div>'
        . ml_field('微信收款账号（微信号 / 手机号 / 姓名，选填）', 'pay_wx_account', $val('mall.pay_wx_account'))
        . ml_field('微信收款二维码 URL（可粘贴，也可下方上传）', 'pay_wx_qr', $val('mall.pay_wx_qr'))
        . '<div class="mla-up"><input type="file" id="qrfile_wx" accept="image/*"> <span id="qrmsg_wx" class="dim"></span>'
        . ($val('mall.pay_wx_qr') !== '' ? '<img class="mla-qr-prev" src="' . e($val('mall.pay_wx_qr')) . '" alt="微信收款码">' : '')
        . '<p class="dim">选图后自动上传并填入上方 URL。</p></div></div>'
        . '<div class="mla-qr-box"><div class="mla-qr-h">支付宝收款</div>'
        . ml_field('支付宝收款账号（账号 / 姓名，选填）', 'pay_ali_account', $val('mall.pay_ali_account'))
        . ml_field('支付宝收款二维码 URL', 'pay_ali_qr', $val('mall.pay_ali_qr'))
        . '<div class="mla-up"><input type="file" id="qrfile_ali" accept="image/*"> <span id="qrmsg_ali" class="dim"></span>'
        . ($val('mall.pay_ali_qr') !== '' ? '<img class="mla-qr-prev" src="' . e($val('mall.pay_ali_qr')) . '" alt="支付宝收款码">' : '')
        . '<p class="dim">选图后自动上传并填入上方 URL。</p></div></div>'
        . ml_field('付款说明（如「请扫码后备注订单号」）', 'pay_qr_note', $val('mall.pay_qr_note'), 'textarea rows=2')
        . '<script>
(function(){
  function up(inputId, name, msgId){
    var inp=document.getElementById(inputId); if(!inp) return;
    inp.addEventListener("change", function(){
      var f=inp.files[0]; if(!f) return;
      var fd=new FormData(); fd.append("act","pay_qr_upload"); fd.append("key", name.indexOf("wx")>=0?"wx":"ali"); fd.append("file", f);
      var csrf=document.querySelector("input[name=_csrf]"); if(csrf) fd.append("_csrf", csrf.value);
      var msg=document.getElementById(msgId); if(msg) msg.textContent="上传中…";
      fetch(location.pathname+"?s=plug_mall&tab=set",{method:"POST",body:fd}).then(function(r){return r.json();}).then(function(j){
        if(j.ok){ var hf=document.querySelector("input[name="+name+"]"); if(hf) hf.value=j.url;
          var prev=inp.parentNode.querySelector("img.mla-qr-prev"); if(!prev){ prev=document.createElement("img"); prev.className="mla-qr-prev"; inp.parentNode.appendChild(prev);} prev.src=j.url;
          if(msg) msg.textContent="已上传"; } else { if(msg) msg.textContent="失败："+(j.msg||""); }
      }).catch(function(e){ if(msg) msg.textContent="上传失败"; });
    });
  }
  up("qrfile_wx","pay_wx_qr","qrmsg_wx"); up("qrfile_ali","pay_ali_qr","qrmsg_ali");
})();
</script>'
        . '<button class="mla-btn p">保存支付配置</button></form>'
        . '<div class="mla-card"><h3>说明</h3><p class="dim">配置存于宿主 KV（ye_options，mall.* 前缀）。异步回调地址：<b>/mall/pay/notify/{alipay|wxpay}</b>（参数由渠道自动附带，验签在驱动内完成）。开启「沙箱模拟」且未填商户号时，收银台走本地模拟收银台，可无凭证端到端测试付款闭环。</p></div>';

    // M11 促销玩法开关
    $promo = '<form method="post" action="' . $base . '&amp;tab=set" class="mla-card"><h3>促销玩法（M11）</h3>'
        . \Csrf::field()
        . '<input type="hidden" name="act" value="set_save">'
        . '<div class="mla-row">'
        . '<label class="mla-f"><input type="hidden" name="promo_on" value="0"><input type="checkbox" name="promo_on" value="1"' . $on('mall.promo_on') . '> 促销总开关</label>'
        . '<label class="mla-f"><input type="hidden" name="seckill_on" value="0"><input type="checkbox" name="seckill_on" value="1"' . $on('mall.seckill_on') . '> 限时秒杀</label>'
        . '<label class="mla-f"><input type="hidden" name="groupon_on" value="0"><input type="checkbox" name="groupon_on" value="1"' . $on('mall.groupon_on') . '> 拼团</label>'
        . '<label class="mla-f"><input type="hidden" name="bargain_on" value="0"><input type="checkbox" name="bargain_on" value="1"' . $on('mall.bargain_on') . '> 砍价</label>'
        . '<label class="mla-f"><input type="hidden" name="bundle_on" value="0"><input type="checkbox" name="bundle_on" value="1"' . $on('mall.bundle_on') . '> 组合优惠</label>'
        . '</div>'
        . '<p class="dim">活动本身在「秒杀 / 拼团 / 砍价 / 组合优惠」各 Tab 维护；活动价默认与会员折扣二选一取更低者。</p>'
        . '<button class="mla-btn p">保存促销开关</button></form>';

    // M12 履约通知
    $logi = '<form method="post" action="' . $base . '&amp;tab=set" class="mla-card"><h3>履约通知（M12）</h3>'
        . \Csrf::field()
        . '<input type="hidden" name="act" value="set_save">'
        . '<div class="mla-row">'
        . ml_field('未付款自动取消（小时，0=关闭）', 'auto_cancel_hours', (string)get_option('mall.auto_cancel_hours', '2'))
        . ml_field('发货后自动完成（天，0=关闭）', 'auto_finish_days', (string)get_option('mall.auto_finish_days', '15'))
        . '</div>'
        . '<div class="mla-row">'
        . '<label class="mla-f"><input type="hidden" name="msg_order" value="0"><input type="checkbox" name="msg_order" value="1"' . $on('mall.msg_order') . '> 下单消息</label>'
        . '<label class="mla-f"><input type="hidden" name="msg_pay" value="0"><input type="checkbox" name="msg_pay" value="1"' . $on('mall.msg_pay') . '> 付款消息</label>'
        . '<label class="mla-f"><input type="hidden" name="msg_ship" value="0"><input type="checkbox" name="msg_ship" value="1"' . $on('mall.msg_ship') . '> 发货消息</label>'
        . '<label class="mla-f"><input type="hidden" name="msg_finish" value="0"><input type="checkbox" name="msg_finish" value="1"' . $on('mall.msg_finish') . '> 完成消息</label>'
        . '<label class="mla-f"><input type="hidden" name="msg_cancel" value="0"><input type="checkbox" name="msg_cancel" value="1"' . $on('mall.msg_cancel') . '> 取消消息</label>'
        . '</div>'
        . '<button class="mla-btn p">保存履约设置</button></form>';

    // M13 会员等级
    $lv = '<form method="post" action="' . $base . '&amp;tab=set" class="mla-card"><h3>会员等级折扣（M13）</h3>'
        . \Csrf::field()
        . '<input type="hidden" name="act" value="set_save">'
        . '<div class="mla-row">'
        . '<label class="mla-f"><input type="hidden" name="level_on" value="0"><input type="checkbox" name="level_on" value="1"' . $on('mall.level_on') . '> 启用等级折扣</label>'
        . '<label class="mla-f"><input type="hidden" name="level_on_promo" value="0"><input type="checkbox" name="level_on_promo" value="1"' . $on('mall.level_on_promo') . '> 折扣与促销价叠加</label>'
        . '</div>'
        . '<button class="mla-btn p">保存等级设置</button></form>';

    return $cfg . $pts . $promo . $lv . $logi . $pay;
}

/* ================= 小工具 ================= */

/* ============================================================
 * M11 促销玩法 Tab：秒杀 / 拼团 / 砍价 / 组合优惠
 * ============================================================ */

/** 活动表单字段提取（按类型返回可写库数组） */
function ml_promo_fields(string $type): array
{
    $t = static function (string $k, string $def = ''): string {
        $v = trim((string)($_POST[$k] ?? ''));
        return $v === '' ? $def : $v;
    };
    $start = $t('start_time', date('Y-m-d 00:00:00'));
    $end = $t('end_time', date('Y-m-d 23:59:59', strtotime('+7 day')));
    $skuId = max(0, (int)($_POST['sku_id'] ?? 0));
    $common = [
        'sku_id' => $skuId,
        'title' => trim((string)($_POST['title'] ?? '')),
        'sort' => (int)($_POST['sort'] ?? 99),
        'status' => (($_POST['status'] ?? '1') === '1') ? 1 : 0,
    ];
    if ($type === 'seckill') {
        return $common + [
            'goods_id' => 0,
            'price' => max(0, (float)($_POST['price'] ?? 0)),
            'market_price' => max(0, (float)($_POST['market_price'] ?? 0)),
            'seckill_stock' => max(0, (int)($_POST['seckill_stock'] ?? 0)),
            'per_limit' => max(0, (int)($_POST['per_limit'] ?? 1)),
            'start_time' => $start,
            'end_time' => $end,
        ];
    }
    if ($type === 'groupon') {
        return $common + [
            'goods_id' => 0,
            'price' => max(0, (float)($_POST['price'] ?? 0)),
            'market_price' => max(0, (float)($_POST['market_price'] ?? 0)),
            'group_size' => max(2, (int)($_POST['group_size'] ?? 2)),
            'hours' => max(1, (int)($_POST['hours'] ?? 24)),
            'per_limit' => max(0, (int)($_POST['per_limit'] ?? 0)),
            'start_time' => $start,
            'end_time' => $end,
        ];
    }
    return $common + [ // bargain
        'goods_id' => 0,
        'floor_price' => max(0, (float)($_POST['floor_price'] ?? 0)),
        'stock' => max(0, (int)($_POST['stock'] ?? 0)),
        'buy_limit' => max(1, (int)($_POST['buy_limit'] ?? 1)),
        'hours' => max(1, (int)($_POST['hours'] ?? 24)),
        'min_cut' => max(0.01, (float)($_POST['min_cut'] ?? 0.01)),
        'max_cut' => max(0, (float)($_POST['max_cut'] ?? 0)),
        'need_help' => max(0, (int)($_POST['need_help'] ?? 0)),
        'start_time' => $start,
        'end_time' => $end,
    ];
}

/** 套餐原价合计回算（按套餐内 SKU 现价 × 数量） */
function ml_bundle_recalc(int $bid): void
{
    if ($bid <= 0) { return; }
    $rows = Db::rows('SELECT bg.*, IFNULL(sk.price,0) price FROM ' . Mall::t('bundle_goods') . ' bg'
        . ' LEFT JOIN ' . Mall::t('goods_sku') . ' sk ON sk.id=bg.sku_id WHERE bg.bundle_id=?', [$bid]);
    $sum = 0.0;
    foreach ($rows as $r) { $sum += (float)$r['price'] * max(1, (int)$r['num']); }
    Db::update('mall_bundle', ['total_value' => round($sum, 2)], 'id=?', [$bid]);
}

/** Tab：秒杀 */
function ml_seckill_page(string $base): string
{
    $editId = (int)($_GET['edit'] ?? 0);
    $edit = $editId > 0 ? Db::row('SELECT s.*, g.title gtitle, sk.spec_text FROM ' . Mall::t('seckill') . ' s'
        . ' LEFT JOIN ' . Mall::t('goods') . ' g ON g.id=s.goods_id'
        . ' LEFT JOIN ' . Mall::t('goods_sku') . ' sk ON sk.id=s.sku_id'
        . ' WHERE s.id=? AND s.is_delete_time=0 LIMIT 1', [$editId]) : null;

    $form = '<form method="post" action="' . $base . '&amp;tab=seckill" class="mla-card"><h3>' . ($edit !== null ? '编辑秒杀 #' . (int)$edit['id'] : '＋ 新增秒杀活动') . '</h3>'
        . \Csrf::field() . '<input type="hidden" name="act" value="seckill_save"><input type="hidden" name="id" value="' . ($edit !== null ? (int)$edit['id'] : 0) . '">'
        . '<div class="mla-row">'
        . ml_field('SKU ID（商品编辑页可见）', 'sku_id', (string)($edit['sku_id'] ?? ''), 'required')
        . ml_field('活动标题', 'title', (string)($edit['title'] ?? ''))
        . '</div><div class="mla-row">'
        . ml_field('秒杀价', 'price', (string)($edit['price'] ?? ''), 'required')
        . ml_field('划线价', 'market_price', (string)($edit['market_price'] ?? ''))
        . ml_field('活动库存', 'seckill_stock', (string)($edit['seckill_stock'] ?? 10))
        . ml_field('每人限购(0不限)', 'per_limit', (string)($edit['per_limit'] ?? 1))
        . '</div><div class="mla-row">'
        . ml_field('开始时间', 'start_time', (string)($edit['start_time'] ?? date('Y-m-d 00:00:00')))
        . ml_field('结束时间', 'end_time', (string)($edit['end_time'] ?? date('Y-m-d 23:59:59', strtotime('+7 day'))))
        . ml_field('排序', 'sort', (string)($edit['sort'] ?? 99))
        . ml_select('启用', 'status', (int)($edit['status'] ?? 1))
        . '</div>'
        . ($edit !== null ? '<p class="dim">当前商品：' . e((string)($edit['gtitle'] ?? '—') . ' / ' . (string)($edit['spec_text'] ?? '')) . '</p>' : '')
        . '<button class="mla-btn p">保存秒杀活动</button>'
        . ($edit !== null ? ' <a class="mla-btn" href="' . $base . '&amp;tab=seckill">取消编辑</a>' : '')
        . '</form>';

    $rows = Db::rows('SELECT s.*, g.title gtitle, sk.spec_text, sk.price skprice FROM ' . Mall::t('seckill') . ' s'
        . ' LEFT JOIN ' . Mall::t('goods') . ' g ON g.id=s.goods_id'
        . ' LEFT JOIN ' . Mall::t('goods_sku') . ' sk ON sk.id=s.sku_id'
        . ' WHERE s.is_delete_time=0 ORDER BY s.start_time DESC, s.id DESC');
    $tr = '';
    foreach ($rows as $r) {
        $tr .= '<tr><td><b>' . e((string)($r['gtitle'] ?? 'SKU#' . (int)$r['sku_id'])) . '</b><div class="dim">' . e((string)($r['spec_text'] ?? '')) . '</div></td>'
            . '<td class="c-red">¥' . e(number_format((float)$r['price'], 2, '.', '')) . '<div class="dim">原价 ¥' . e(number_format((float)($r['skprice'] ?? 0), 2, '.', '')) . '</div></td>'
            . '<td>' . (int)$r['sold'] . ' / ' . (int)$r['seckill_stock'] . '</td>'
            . '<td>' . (int)$r['per_limit'] . '</td>'
            . '<td class="dim">' . e((string)$r['start_time']) . '<br>' . e((string)$r['end_time']) . '</td>'
            . '<td>' . ((int)$r['status'] === 1 ? '<span class="mla-tag ok">启用</span>' : '<span class="mla-tag">停用</span>') . '</td>'
            . '<td><a class="mla-btn" href="' . $base . '&amp;tab=seckill&amp;edit=' . (int)$r['id'] . '">编辑</a> '
            . '<form class="inline" method="post" action="' . $base . '&amp;tab=seckill" onsubmit="return confirm(\'删除该秒杀活动？\')">' . \Csrf::field()
            . '<input type="hidden" name="act" value="seckill_delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="mla-btn danger">删除</button></form></td></tr>';
    }
    $list = '<div class="mla-card"><h3>秒杀活动列表</h3><table><tr><th>商品 / 规格</th><th>秒杀价</th><th>已售/库存</th><th>限购</th><th>活动时间</th><th>状态</th><th>操作</th></tr>'
        . ($tr !== '' ? $tr : '<tr><td colspan="7" class="dim">暂无秒杀活动</td></tr>') . '</table></div>';
    return $form . $list;
}

/** Tab：拼团 */
function ml_groupon_page(string $base): string
{
    $editId = (int)($_GET['edit'] ?? 0);
    $edit = $editId > 0 ? Db::row('SELECT p.*, g.title gtitle FROM ' . Mall::t('groupon') . ' p'
        . ' LEFT JOIN ' . Mall::t('goods') . ' g ON g.id=p.goods_id'
        . ' WHERE p.id=? AND p.is_delete_time=0 LIMIT 1', [$editId]) : null;
    $form = '<form method="post" action="' . $base . '&amp;tab=groupon" class="mla-card"><h3>' . ($edit !== null ? '编辑拼团 #' . (int)$edit['id'] : '＋ 新增拼团活动') . '</h3>'
        . \Csrf::field() . '<input type="hidden" name="act" value="groupon_save"><input type="hidden" name="id" value="' . ($edit !== null ? (int)$edit['id'] : 0) . '">'
        . '<div class="mla-row">'
        . ml_field('SKU ID', 'sku_id', (string)($edit['sku_id'] ?? ''), 'required')
        . ml_field('活动标题', 'title', (string)($edit['title'] ?? ''))
        . '</div><div class="mla-row">'
        . ml_field('团购价', 'price', (string)($edit['price'] ?? ''), 'required')
        . ml_field('单买划线价', 'market_price', (string)($edit['market_price'] ?? ''))
        . ml_field('成团人数', 'group_size', (string)($edit['group_size'] ?? 2))
        . ml_field('团有效小时', 'hours', (string)($edit['hours'] ?? 24))
        . '</div><div class="mla-row">'
        . ml_field('每人限购(0不限)', 'per_limit', (string)($edit['per_limit'] ?? 0))
        . ml_field('排序', 'sort', (string)($edit['sort'] ?? 99))
        . ml_select('启用', 'status', (int)($edit['status'] ?? 1))
        . '</div>'
        . '<button class="mla-btn p">保存拼团活动</button>'
        . ($edit !== null ? ' <a class="mla-btn" href="' . $base . '&amp;tab=groupon">取消编辑</a>' : '')
        . '</form>';

    $rows = Db::rows('SELECT p.*, g.title gtitle, (SELECT COUNT(*) FROM ' . Mall::t('groupon_team') . ' t WHERE t.groupon_id=p.id AND t.is_delete_time=0) teams'
        . ' FROM ' . Mall::t('groupon') . ' p LEFT JOIN ' . Mall::t('goods') . ' g ON g.id=p.goods_id'
        . ' WHERE p.is_delete_time=0 ORDER BY p.sort ASC, p.id DESC');
    $tr = '';
    foreach ($rows as $r) {
        $tr .= '<tr><td><b>' . e((string)($r['gtitle'] ?? 'SKU#' . (int)$r['sku_id'])) . '</b></td>'
            . '<td class="c-red">¥' . e(number_format((float)$r['price'], 2, '.', '')) . '</td>'
            . '<td>' . (int)$r['group_size'] . ' 人 / ' . (int)$r['hours'] . 'h</td>'
            . '<td>' . (int)$r['teams'] . ' 团・成 ' . (int)$r['sales'] . '</td>'
            . '<td>' . ((int)$r['status'] === 1 ? '<span class="mla-tag ok">启用</span>' : '<span class="mla-tag">停用</span>') . '</td>'
            . '<td><a class="mla-btn" href="' . $base . '&amp;tab=groupon&amp;edit=' . (int)$r['id'] . '">编辑</a> '
            . '<form class="inline" method="post" action="' . $base . '&amp;tab=groupon" onsubmit="return confirm(\'删除该拼团活动？\')">' . \Csrf::field()
            . '<input type="hidden" name="act" value="groupon_delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="mla-btn danger">删除</button></form></td></tr>';
    }
    $list = '<div class="mla-card"><h3>拼团活动列表</h3><table><tr><th>商品</th><th>团购价</th><th>成团规则</th><th>团数</th><th>状态</th><th>操作</th></tr>'
        . ($tr !== '' ? $tr : '<tr><td colspan="6" class="dim">暂无拼团活动</td></tr>') . '</table></div>';
    return $form . $list;
}

/** Tab：砍价 */
function ml_bargain_page(string $base): string
{
    $editId = (int)($_GET['edit'] ?? 0);
    $edit = $editId > 0 ? Db::row('SELECT b.*, g.title gtitle FROM ' . Mall::t('bargain') . ' b'
        . ' LEFT JOIN ' . Mall::t('goods') . ' g ON g.id=b.goods_id'
        . ' WHERE b.id=? AND b.is_delete_time=0 LIMIT 1', [$editId]) : null;
    $form = '<form method="post" action="' . $base . '&amp;tab=bargain" class="mla-card"><h3>' . ($edit !== null ? '编辑砍价 #' . (int)$edit['id'] : '＋ 新增砍价活动') . '</h3>'
        . \Csrf::field() . '<input type="hidden" name="act" value="bargain_save"><input type="hidden" name="id" value="' . ($edit !== null ? (int)$edit['id'] : 0) . '">'
        . '<div class="mla-row">'
        . ml_field('SKU ID', 'sku_id', (string)($edit['sku_id'] ?? ''), 'required')
        . ml_field('活动标题', 'title', (string)($edit['title'] ?? ''))
        . ml_field('砍到底价', 'floor_price', (string)($edit['floor_price'] ?? ''), 'required')
        . '</div><div class="mla-row">'
        . ml_field('活动库存(0不限)', 'stock', (string)($edit['stock'] ?? 0))
        . ml_field('时效(小时)', 'hours', (string)($edit['hours'] ?? 24))
        . ml_field('单次最小额', 'min_cut', (string)($edit['min_cut'] ?? 0.01))
        . ml_field('单次最大额(0不限)', 'max_cut', (string)($edit['max_cut'] ?? 0))
        . '</div><div class="mla-row">'
        . ml_field('需帮砍人数(0不限)', 'need_help', (string)($edit['need_help'] ?? 0))
        . ml_field('每人可发起次数', 'buy_limit', (string)($edit['buy_limit'] ?? 1))
        . ml_field('排序', 'sort', (string)($edit['sort'] ?? 99))
        . ml_select('启用', 'status', (int)($edit['status'] ?? 1))
        . '</div>'
        . '<button class="mla-btn p">保存砍价活动</button>'
        . ($edit !== null ? ' <a class="mla-btn" href="' . $base . '&amp;tab=bargain">取消编辑</a>' : '')
        . '</form>';

    $rows = Db::rows('SELECT b.*, g.title gtitle, (SELECT COUNT(*) FROM ' . Mall::t('bargain_launch') . ' l WHERE l.bargain_id=b.id) launches'
        . ' FROM ' . Mall::t('bargain') . ' b LEFT JOIN ' . Mall::t('goods') . ' g ON g.id=b.goods_id'
        . ' WHERE b.is_delete_time=0 ORDER BY b.sort ASC, b.id DESC');
    $tr = '';
    foreach ($rows as $r) {
        $tr .= '<tr><td><b>' . e((string)($r['gtitle'] ?? 'SKU#' . (int)$r['sku_id'])) . '</b></td>'
            . '<td class="c-red">¥' . e(number_format((float)$r['floor_price'], 2, '.', '')) . '</td>'
            . '<td>' . (int)$r['hours'] . 'h・' . (int)$r['stock'] . ' 件</td>'
            . '<td>' . (int)$r['launches'] . ' 次</td>'
            . '<td>' . ((int)$r['status'] === 1 ? '<span class="mla-tag ok">启用</span>' : '<span class="mla-tag">停用</span>') . '</td>'
            . '<td><a class="mla-btn" href="' . $base . '&amp;tab=bargain&amp;edit=' . (int)$r['id'] . '">编辑</a> '
            . '<form class="inline" method="post" action="' . $base . '&amp;tab=bargain" onsubmit="return confirm(\'删除该砍价活动？\')">' . \Csrf::field()
            . '<input type="hidden" name="act" value="bargain_delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="mla-btn danger">删除</button></form></td></tr>';
    }
    $list = '<div class="mla-card"><h3>砍价活动列表</h3><table><tr><th>商品</th><th>底价</th><th>规则</th><th>发起数</th><th>状态</th><th>操作</th></tr>'
        . ($tr !== '' ? $tr : '<tr><td colspan="6" class="dim">暂无砍价活动</td></tr>') . '</table></div>';
    return $form . $list;
}

/** Tab：组合优惠（套餐 → 内含商品） */
function ml_bundle_page(string $base): string
{
    $editId = (int)($_GET['edit'] ?? 0);
    if ($editId > 0) { return ml_bundle_edit($base, $editId); }
    $edit0 = (int)($_GET['create'] ?? 0);

    $form = '<form method="post" action="' . $base . '&amp;tab=bundle" class="mla-card"><h3>＋ 新增组合套餐</h3>'
        . \Csrf::field() . '<input type="hidden" name="act" value="bundle_save"><input type="hidden" name="id" value="0">'
        . '<div class="mla-row">'
        . ml_field('套餐名称', 'title', '', 'required')
        . ml_field('副标题/说明', 'summary', '')
        . '<label>类型<select name="type"><option value="2">套餐固定价</option><option value="1">满件立减</option></select></label>'
        . ml_field('套餐价 / 减金额', 'price', '')
        . '</div><div class="mla-row">'
        . ml_field('满件数(type1)', 'need_num', '2')
        . ml_field('封面图 URL', 'cover', '')
        . ml_field('排序', 'sort', '99')
        . ml_select('启用', 'status', 1)
        . '</div><button class="mla-btn p">创建套餐</button></form>';

    $rows = Db::rows('SELECT * FROM ' . Mall::t('bundle') . ' WHERE is_delete_time=0 ORDER BY sort ASC, id DESC');
    $tr = '';
    foreach ($rows as $r) {
        $cnt = (int)Db::one('SELECT COUNT(*) FROM ' . Mall::t('bundle_goods') . ' WHERE bundle_id=?', [(int)$r['id']]);
        $tr .= '<tr><td><b>' . e((string)$r['title']) . '</b><div class="dim">' . e((string)$r['summary']) . '</div></td>'
            . '<td>' . ((int)$r['type'] === 2 ? '套餐价 <b class="c-red">¥' . e(number_format((float)$r['price'], 2, '.', '')) . '</b>'
                : '满 ' . (int)$r['need_num'] . ' 件减 <b class="c-red">¥' . e(number_format((float)$r['price'], 2, '.', '')) . '</b>') . '</td>'
            . '<td><s class="dim">¥' . e(number_format((float)$r['total_value'], 2, '.', '')) . '</s></td>'
            . '<td>' . $cnt . ' 件</td>'
            . '<td>' . ((int)$r['status'] === 1 ? '<span class="mla-tag ok">启用</span>' : '<span class="mla-tag">停用</span>') . '</td>'
            . '<td><a class="mla-btn" href="' . $base . '&amp;tab=bundle&amp;edit=' . (int)$r['id'] . '">编辑商品</a> '
            . '<form class="inline" method="post" action="' . $base . '&amp;tab=bundle" onsubmit="return confirm(\'删除该套餐？\')">' . \Csrf::field()
            . '<input type="hidden" name="act" value="bundle_delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="mla-btn danger">删除</button></form></td></tr>';
    }
    $list = '<div class="mla-card"><h3>组合套餐列表</h3><table><tr><th>名称</th><th>优惠</th><th>原价合计</th><th>含商品</th><th>状态</th><th>操作</th></tr>'
        . ($tr !== '' ? $tr : '<tr><td colspan="6" class="dim">暂无套餐</td></tr>') . '</table></div>';
    return $form . $list;
}

/* ============================================================
 * M15 专题活动 Tab
 * ============================================================ */

function ml_topic_page(string $base): string
{
    $editId = (int)($_GET['edit'] ?? 0);
    $edit = $editId > 0 ? Db::row('SELECT * FROM ' . Mall::t('topic') . ' WHERE id=? AND is_delete_time=0 LIMIT 1', [$editId]) : null;
    $form = '<form method="post" action="' . $base . '&amp;tab=topic" class="mla-card"><h3>'
        . ($edit !== null ? '编辑专题 #' . (int)$edit['id'] : '＋ 新增专题') . '</h3>'
        . \Csrf::field() . '<input type="hidden" name="act" value="topic_save"><input type="hidden" name="id" value="'
        . ($edit !== null ? (int)$edit['id'] : 0) . '">'
        . '<div class="mla-row">'
        . ml_field('标题', 'title', (string)($edit['title'] ?? ''), 'required')
        . ml_field('简介（选填）', 'summary', (string)($edit['summary'] ?? ''))
        . '</div><div class="mla-row">'
        . ml_field('封面图 URL（选填）', 'cover', (string)($edit['cover'] ?? ''))
        . ml_field('排序（小在前）', 'sort', (string)($edit['sort'] ?? '0'))
        . ml_select('启用', 'status', (int)($edit['status'] ?? 1))
        . '</div>'
        . ml_field('正文（支持 Markdown 或 HTML，选填）', 'content', (string)($edit['content'] ?? ''), 'textarea rows=8')
        . '<button class="mla-btn p">' . ($edit !== null ? '保存专题' : '创建专题') . '</button>'
        . ($edit !== null ? ' <a class="mla-btn" href="' . $base . '&amp;tab=topic">取消编辑</a>' : '')
        . '</form>';

    $rows = Db::rows('SELECT * FROM ' . Mall::t('topic') . ' WHERE is_delete_time=0 ORDER BY sort ASC, id DESC');
    $tr = '';
    $cnt = 0;
    foreach ($rows as $r) {
        $n = (int)Db::one('SELECT COUNT(*) FROM ' . Mall::t('topic_goods') . ' WHERE topic_id=?', [(int)$r['id']]);
        $cnt++;
        $tr .= '<tr><td><b>' . e((string)$r['title']) . '</b><div class="dim">' . e((string)$r['summary']) . '</div></td>'
            . '<td>' . $n . ' 件</td>'
            . '<td>' . ((int)$r['status'] === 1 ? '<span class="mla-tag ok">启用</span>' : '<span class="mla-tag">停用</span>') . '</td>'
            . '<td style="white-space:nowrap">'
            . '<a class="mla-btn" href="' . $base . '&amp;tab=topic&amp;edit=' . (int)$r['id'] . '">编辑</a> '
            . '<a class="mla-btn" href="/topic/' . (int)$r['id'] . '.html" target="_blank" rel="noopener">前台</a> '
            . '<form class="inline" method="post" action="' . $base . '&amp;tab=topic" onsubmit="return confirm(\'删除该专题？\')">' . \Csrf::field()
            . '<input type="hidden" name="act" value="topic_delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="mla-btn danger">删除</button></form>'
            . '</td></tr>';
    }
    $list = '<div class="mla-card"><h3>专题列表</h3><table><tr><th>标题</th><th>含商品</th><th>状态</th><th>操作</th></tr>'
        . ($tr !== '' ? $tr : '<tr><td colspan="4" class="dim">暂无专题</td></tr>') . '</table></div>';

    $gm = '';
    if ($edit !== null) {
        $gtr = '';
        $gn = 0;
        foreach (Db::rows('SELECT tg.id, tg.goods_id, g.title, g.min_price FROM ' . Mall::t('topic_goods') . ' tg'
            . ' JOIN ' . Mall::t('goods') . ' g ON g.id=tg.goods_id WHERE tg.topic_id=? ORDER BY tg.sort ASC, tg.id ASC', [(int)$edit['id']]) as $g) {
            $gn++;
            $gtr .= '<tr><td>' . e((string)$g['title']) . '</td><td>¥' . e(number_format((float)$g['min_price'], 2, '.', '')) . '</td>'
                . '<td><form class="inline" method="post" action="' . $base . '&amp;tab=topic">' . \Csrf::field()
                . '<input type="hidden" name="act" value="topic_goods_del"><input type="hidden" name="id" value="' . (int)$g['id'] . '">'
                . '<input type="hidden" name="topic_id" value="' . (int)$edit['id'] . '"><button class="mla-btn danger">移除</button></form></td></tr>';
        }
        $gm = '<div class="mla-card"><h3>专题商品（' . $gn . '）</h3>'
            . '<table><tr><th>商品</th><th>价格</th><th>操作</th></tr>'
            . ($gtr !== '' ? $gtr : '<tr><td colspan="3" class="dim">尚未挂商品</td></tr>') . '</table>'
            . '<form method="post" action="' . $base . '&amp;tab=topic" class="mla-row" style="align-items:flex-end;margin-top:10px">'
            . \Csrf::field() . '<input type="hidden" name="act" value="topic_goods_add"><input type="hidden" name="topic_id" value="' . (int)$edit['id'] . '">'
            . ml_field('商品 ID', 'goods_id', '', 'required')
            . ml_field('排序', 'sort', '0')
            . '<button class="mla-btn p">添加商品</button></form></div>';
    }
    return $form . $list . $gm;
}

function ml_bundle_edit(string $base, int $id): string
{
    $b = Db::row('SELECT * FROM ' . Mall::t('bundle') . ' WHERE id=? AND is_delete_time=0 LIMIT 1', [$id]);
    if ($b === null) { return '<div class="mla-card"><p class="dim">套餐不存在。</p></div>'; }
    $form = '<form method="post" action="' . $base . '&amp;tab=bundle" class="mla-card"><h3>编辑套餐 #' . $id . '：<b>' . e((string)$b['title']) . '</b></h3>'
        . \Csrf::field() . '<input type="hidden" name="act" value="bundle_save"><input type="hidden" name="id" value="' . $id . '">'
        . '<div class="mla-row">'
        . ml_field('套餐名称', 'title', (string)$b['title'], 'required')
        . ml_field('副标题/说明', 'summary', (string)$b['summary'])
        . '<label>类型<select name="type"><option value="2"' . ((int)$b['type'] === 2 ? ' selected' : '') . '>套餐固定价</option>'
        . '<option value="1"' . ((int)$b['type'] === 1 ? ' selected' : '') . '>满件立减</option></select></label>'
        . ml_field('套餐价 / 减金额', 'price', (string)$b['price'])
        . '</div><div class="mla-row">'
        . ml_field('满件数(type1)', 'need_num', (string)$b['need_num'])
        . ml_field('封面图 URL', 'cover', (string)$b['cover'])
        . ml_field('排序', 'sort', (string)$b['sort'])
        . ml_select('启用', 'status', (int)$b['status'])
        . '</div><button class="mla-btn p">保存套餐</button> '
        . '<a class="mla-btn" href="' . $base . '&amp;tab=bundle">返回列表</a></form>';

    $items = Db::rows('SELECT bg.*, g.title gtitle, IFNULL(sk.spec_text,\'\') spec, IFNULL(sk.price,0) price FROM ' . Mall::t('bundle_goods') . ' bg'
        . ' JOIN ' . Mall::t('goods') . ' g ON g.id=bg.goods_id'
        . ' LEFT JOIN ' . Mall::t('goods_sku') . ' sk ON sk.id=bg.sku_id'
        . ' WHERE bg.bundle_id=? ORDER BY bg.sort ASC, bg.id ASC', [$id]);
    $tr = '';
    $total = 0.0;
    foreach ($items as $it) {
        $total += (float)$it['price'] * (int)$it['num'];
        $tr .= '<tr><td>' . e((string)$it['gtitle']) . '<div class="dim">' . e((string)$it['spec']) . '</div></td>'
            . '<td>' . (int)$it['goods_id'] . ' / ' . (int)$it['sku_id'] . '</td>'
            . '<td>' . (int)$it['num'] . '</td>'
            . '<td>¥' . e(number_format((float)$it['price'], 2, '.', '')) . '</td>'
            . '<td><form class="inline" method="post" action="' . $base . '&amp;tab=bundle" onsubmit="return confirm(\'移除该商品？\')">' . \Csrf::field()
            . '<input type="hidden" name="act" value="bundle_goods_del"><input type="hidden" name="id" value="' . (int)$it['id'] . '">'
            . '<input type="hidden" name="bundle_id" value="' . $id . '"><button class="mla-btn danger">移除</button></form></td></tr>';
    }
    $addForm = '<form method="post" action="' . $base . '&amp;tab=bundle" class="mla-row" style="align-items:flex-end;margin-top:10px">'
        . \Csrf::field() . '<input type="hidden" name="act" value="bundle_goods_add"><input type="hidden" name="bundle_id" value="' . $id . '">'
        . ml_field('商品 ID', 'goods_id', '', 'required')
        . ml_field('规格 SKU ID(0=默认)', 'sku_id', '0')
        . ml_field('数量', 'num', '1')
        . '<button class="mla-btn p">添加商品</button></form>';
    $itemsCard = '<div class="mla-card"><h3>套餐内商品（原价合计 ¥' . e(number_format($total, 2, '.', '')) . '）</h3>'
        . '<table><tr><th>商品</th><th>goods/sku</th><th>数量</th><th>单价</th><th>操作</th></tr>' . ($tr !== '' ? $tr : '<tr><td colspan="5" class="dim">尚未添加商品</td></tr>') . '</table>'
        . $addForm . '</div>';
    return $form . $itemsCard;
}

/* ============================================================
 * M13 Tab：会员等级
 * ============================================================ */

function ml_level_page(string $base): string
{
    $editId = (int)($_GET['edit'] ?? 0);
    $edit = $editId > 0 ? Db::row('SELECT * FROM ' . Mall::t('level') . ' WHERE id=? LIMIT 1', [$editId]) : null;
    $form = '<form method="post" action="' . $base . '&amp;tab=level" class="mla-card"><h3>' . ($edit !== null ? '编辑等级 Lv' . (int)$edit['level'] : '＋ 新增会员等级') . '</h3>'
        . \Csrf::field() . '<input type="hidden" name="act" value="level_save"><input type="hidden" name="id" value="' . ($edit !== null ? (int)$edit['id'] : 0) . '">'
        . '<div class="mla-row">'
        . ml_field('等级值(数字)', 'level', (string)($edit['level'] ?? 1), 'required')
        . ml_field('等级名称', 'name', (string)($edit['name'] ?? ''), 'required')
        . ml_field('图标 emoji', 'icon', (string)($edit['icon'] ?? ''))
        . ml_field('折扣率(0.95=95折)', 'discount', (string)($edit['discount'] ?? 1))
        . '</div><div class="mla-row">'
        . ml_field('升级累计消费门槛', 'min_consume', (string)($edit['min_consume'] ?? 0))
        . ml_field('升级积分门槛', 'min_points', (string)($edit['min_points'] ?? 0))
        . ml_field('权益说明', 'benefit', (string)($edit['benefit'] ?? ''))
        . ml_field('排序', 'sort', (string)($edit['sort'] ?? 99))
        . '</div>'
        . ml_select('启用', 'status', (int)($edit['status'] ?? 1))
        . ' <button class="mla-btn p">保存等级</button>'
        . ($edit !== null ? ' <a class="mla-btn" href="' . $base . '&amp;tab=level">取消编辑</a>' : '')
        . '</form>';

    $rows = Db::rows('SELECT l.*, (SELECT COUNT(*) FROM ' . Mall::t('member') . ' m WHERE m.level=l.level) mems'
        . ' FROM ' . Mall::t('level') . ' l ORDER BY l.level ASC');
    $tr = '';
    foreach ($rows as $r) {
        $tr .= '<tr><td><b>Lv' . (int)$r['level'] . '</b></td>'
            . '<td>' . e((string)$r['icon'] . (string)$r['name']) . '</td>'
            . '<td>' . ((float)$r['discount'] >= 1 ? '无折扣' : '<b class="c-red">' . e(number_format((float)$r['discount'] * 100, 1, '.', '')) . ' 折</b>') . '</td>'
            . '<td>' . ((float)$r['min_consume'] > 0 ? '消费 ¥' . e(number_format((float)$r['min_consume'], 2, '.', '')) : '—')
            . ' / ' . ((int)$r['min_points'] > 0 ? (int)$r['min_points'] . ' 积分' : '—') . '</td>'
            . '<td class="dim">' . e((string)$r['benefit']) . '</td>'
            . '<td>' . (int)$r['mems'] . ' 人</td>'
            . '<td>' . ((int)$r['status'] === 1 ? '<span class="mla-tag ok">启用</span>' : '<span class="mla-tag">停用</span>') . '</td>'
            . '<td><a class="mla-btn" href="' . $base . '&amp;tab=level&amp;edit=' . (int)$r['id'] . '">编辑</a> '
            . '<form class="inline" method="post" action="' . $base . '&amp;tab=level" onsubmit="return confirm(\'停用该等级？\')">' . \Csrf::field()
            . '<input type="hidden" name="act" value="level_delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="mla-btn danger">停用</button></form></td></tr>';
    }
    $list = '<div class="mla-card"><h3>会员等级列表</h3><table><tr><th>等级</th><th>名称</th><th>折扣</th><th>升级门槛</th><th>权益</th><th>会员数</th><th>状态</th><th>操作</th></tr>'
        . ($tr !== '' ? $tr : '<tr><td colspan="8" class="dim">暂无等级</td></tr>') . '</table>'
        . '<p class="dim">说明：会员累计消费或积分达到门槛后，系统会在其访问商城时自动升级并发放对应折扣；折扣默认不与促销价叠加（可在商城设置改为叠加）。</p></div>';
    return $form . $list;
}

function ml_field(string $label, string $name, string $val, string $extra = ''): string
{
    if (str_starts_with($extra, 'textarea')) {
        $rows = '4';
        if (preg_match('/rows=(\d+)/', $extra, $m)) { $rows = $m[1]; }
        return '<label class="mla-f">' . e($label) . '<textarea name="' . e($name) . '" rows="' . $rows . '">' . e($val) . '</textarea></label>';
    }
    return '<label class="mla-f">' . e($label) . '<input type="text" name="' . e($name) . '" value="' . e($val) . '" ' . $extra . '></label>';
}

function ml_select(string $label, string $name, int $val): string
{
    return '<label class="mla-f">' . e($label) . '<select name="' . e($name) . '"><option value="1"' . ($val === 1 ? ' selected' : '') . '>显示</option><option value="0"' . ($val === 0 ? ' selected' : '') . '>隐藏</option></select></label>';
}

function ml_css(): string
{
    static $css = null;
    if ($css === null) {
        $css = '<style>'
            // 51puer 方角 + 砖红主色，融入宿主后台卡片
            . '.mla-tabs{display:flex;gap:0;margin:10px 0;border-bottom:2px solid #a21400}'
            . '.mla-tabs a{padding:7px 16px;font-size:13.5px;color:#555;text-decoration:none;border:1px solid #e5e0d5;border-bottom:0;background:#faf9f6;margin-right:4px}'
            . '.mla-tabs a.on{background:#a21400;border-color:#a21400;color:#fff}'
            . '.mla-card{border:1px solid #e5e0d5;background:#fff;border-radius:0;padding:14px 16px;margin:10px 0}'
            . '.mla-card h3{margin:0 0 8px;font-size:15.5px}'
            . '.mla-card table,.mla table{width:100%;border-collapse:collapse;font-size:13px}'
            . '.mla th,.mla td,.mla-card th,.mla-card td{border-bottom:1px solid #eee7db;padding:7px 8px;text-align:left;vertical-align:middle}'
            . '.mla-card th{color:#6f767e;font-weight:600;font-size:12px}'
            . '.mla-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}'
            . '.mla-f{display:flex;flex-direction:column;gap:3px;font-size:12px;color:#6f767e;flex:1;min-width:120px}'
            . '.mla-f input[type=text],.mla-f textarea,.mla-f select{box-sizing:border-box;border:1px solid #ded7ca;border-radius:0;padding:5px 8px;font:inherit;width:100%;background:#fff}'
            . '.mla-btn{display:inline-block;padding:4px 12px;border:1px solid #ded7ca;background:#fff;color:#333;font-size:12.5px;cursor:pointer;text-decoration:none;border-radius:0}'
            . '.mla-btn:hover{border-color:#a21400;color:#a21400}'
            . '.mla-btn.p{background:#a21400;border-color:#a21400;color:#fff}'
            . '.mla-btn.p:hover{background:#7d0d00;color:#fff}'
            . '.mla-btn.danger{color:#b33}'
            . '.mla-msg{background:#eef4ee;border:1px solid #cfe2d0;color:#2e6b34;border-radius:0;padding:8px 12px;margin:8px 0;font-size:13px}'
            . '.mla-tag{display:inline-block;padding:0 8px;font-size:11px;background:#f0ede4;color:#6f767e;border-radius:0;margin-right:4px}'
            . '.mla-tag.ok{background:#e2efe2;color:#2e6b34}'
            . '.mla-tag.rec{background:#f9e8e8;color:#e23435}'
            . '.mla-stats{display:grid;grid-template-columns:repeat(6,1fr);gap:8px;margin-top:10px}'
            . '.mla-stats .mla-card{margin:0;text-align:center}'
            . '.mla-stats .n{font-size:22px;font-weight:700;color:#a21400}'
            . '.mla-stats .n a{color:#a21400;text-decoration:none}'
            . '.mla-stats .n small{font-size:12px;color:#999;font-weight:400;margin-left:2px}'
            . '.mla-stats .t{font-size:12px;color:#6f767e;margin-top:2px}'
            . '.mla-sku input[type=text]{box-sizing:border-box;border:1px solid #ded7ca;border-radius:0;padding:4px 6px;font:inherit;width:100%;background:#fff}'
            . '.mla-sku td{padding:4px 4px}'
            . '.mla-nopic{width:44px;height:44px;display:flex;align-items:center;justify-content:center;background:#f4f1ea;color:#bbb;font-size:11px}'
            . '.mla-pager{display:flex;gap:6px;justify-content:flex-end;padding:8px 0}'
            . '.mla-pager a,.mla-pager b{padding:3px 10px;border:1px solid #ded7ca;font-size:12.5px;text-decoration:none;color:#555}'
            . '.mla-pager b{background:#a21400;border-color:#a21400;color:#fff}'
            . '.mla .dim,.mla-card .dim{color:#9a9a90;font-size:12.5px}'
            . '.mla form.inline{display:inline}'
            . '.mla-cover-preview{margin:6px 0}'
            . '.mla-hr{border:0;border-top:1px dashed #e5e0d5;margin:14px 0}'
            . '.mla-card h4{margin:6px 0 10px;font-size:14px;color:#7d0d00}'
            . '.mla-f input[type=checkbox]{width:auto;margin-right:6px;vertical-align:middle;accent-color:#a21400}'
            . '.mla-f{font-weight:500}'
            . '</style>';
    }
    return $css;
}
