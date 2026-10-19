<?php
declare(strict_types=1);
/**
 * 微信支付驱动（API v2，sign_type=HMAC-SHA256）
 * - PC 扫码支付（NATIVE，trade_type=NATIVE，返回 code_url 生成二维码）
 * - 手机 H5 支付（trade_type=MWEB，返回 mweb_url 跳转微信）
 * - 微信内 JSAPI 支付（trade_type=JSAPI，需 openid，返回前端 WeixinJSBridge 调起参数）
 * - 退款（原路退回，需商户证书；本地沙箱回退为人工记录）
 * - 生产/沙箱网关自动切换；商户 APIv2 密钥签名与验签。
 * - 仅负责「构造请求 / 验签 / 调接口」，业务落库统一走 Mall::payApplyNotify / Mall::refundOrder。
 */
namespace MallPay;

class Wxpay
{
    public const GATEWAY_PROD = 'https://api.mch.weixin.qq.com/pay/unifiedorder';
    public const GATEWAY_SANDBOX = 'https://api.mch.weixin.qq.com/sandboxnew/pay/unifiedorder';
    public const REFUND_GATEWAY_PROD = 'https://api.mch.weixin.qq.com/secapi/pay/refund';
    public const REFUND_GATEWAY_SANDBOX = 'https://api.mch.weixin.qq.com/sandboxnew/secapi/pay/refund';
    public const OAUTH_TOKEN = 'https://api.weixin.qq.com/sns/oauth2/access_token';

    /** @param array{mch_id?:string,app_id?:string,key?:string,secret?:string,sandbox?:bool,sandbox_key?:string,gateway?:string} $cfg */
    public function __construct(private array $cfg)
    {
    }

    public function configured(): bool
    {
        return trim((string)($this->cfg['mch_id'] ?? '')) !== ''
            && trim((string)($this->cfg['app_id'] ?? '')) !== ''
            && trim((string)($this->cfg['key'] ?? '')) !== '';
    }

    public function gateway(): string
    {
        if (!empty($this->cfg['gateway'])) {
            return (string)$this->cfg['gateway'];
        }
        return !empty($this->cfg['sandbox']) ? self::GATEWAY_SANDBOX : self::GATEWAY_PROD;
    }

    public function nonce(): string
    {
        return strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 32));
    }

    /**
     * 统一下单基础参数（公共段），trade_type 由子类指定。
     * @param array{order_no:string,pay_price:float,subject:string} $order
     */
    private function baseParams(array $order, string $notifyUrl, string $clientIp, string $tradeType, array $extra = []): array
    {
        return array_merge([
            'appid' => $this->cfg['app_id'],
            'mch_id' => $this->cfg['mch_id'],
            'nonce_str' => $this->nonce(),
            'body' => mb_substr($order['subject'], 0, 128),
            'out_trade_no' => $order['order_no'],
            'total_fee' => (int)round((float)$order['pay_price'] * 100),
            'spbill_create_ip' => $clientIp,
            'notify_url' => $notifyUrl,
            'trade_type' => $tradeType,
            'sign_type' => 'HMAC-SHA256',
        ], $extra);
    }

    /** NATIVE 统一下单，返回 ['ok'=>true,'code_url'=>...] 或 ['ok'=>false,'msg'=>...] */
    public function nativeUnified(array $order, string $notifyUrl, string $clientIp): array
    {
        return $this->unified($this->baseParams($order, $notifyUrl, $clientIp, 'NATIVE'));
    }

    /** H5 统一下单（MWEB），返回 ['ok'=>true,'mweb_url'=>...] 或 ['ok'=>false,'msg'=>...] */
    public function h5Unified(array $order, string $notifyUrl, string $clientIp, string $wapUrl): array
    {
        $params = $this->baseParams($order, $notifyUrl, $clientIp, 'MWEB', [
            'scene_info' => json_encode([
                'h5_info' => ['type' => 'Wap', 'wap_url' => $wapUrl, 'wap_name' => '夜叉商城'],
            ], JSON_UNESCAPED_UNICODE),
        ]);
        return $this->unified($params, 'mweb_url');
    }

    /** JSAPI 统一下单（微信内），返回 ['ok'=>true,'prepay_id'=>...,'pay_params'=>[...]] 或 ['ok'=>false,'msg'=>...] */
    public function jsapiUnified(array $order, string $notifyUrl, string $clientIp, string $openid): array
    {
        $params = $this->baseParams($order, $notifyUrl, $clientIp, 'JSAPI', ['openid' => $openid]);
        $r = $this->unified($params, 'prepay_id');
        if (!$r['ok']) { return $r; }
        return ['ok' => true, 'prepay_id' => $r['prepay_id'], 'pay_params' => $this->jsApiSign($r['prepay_id'])];
    }

    /** 统一下单：发送并返回 code_url / mweb_url / prepay_id（按 $pick 取主字段） */
    private function unified(array $params, string $pick = 'code_url'): array
    {
        $params['sign'] = $this->calcSign($params);
        $xml = $this->toXml($params);
        $resp = $this->postXml($this->gateway(), $xml);
        $arr = $this->fromXml($resp);
        if (($arr['return_code'] ?? '') !== 'SUCCESS' || ($arr['result_code'] ?? '') !== 'SUCCESS') {
            return ['ok' => false, 'msg' => $arr['return_msg'] ?? ($arr['err_code_des'] ?? 'unifiedorder failed')];
        }
        $out = ['ok' => true];
        if ($pick === 'code_url') { $out['code_url'] = $arr['code_url'] ?? ''; }
        elseif ($pick === 'mweb_url') { $out['mweb_url'] = $arr['mweb_url'] ?? ''; }
        else { $out['prepay_id'] = $arr['prepay_id'] ?? ''; }
        return $out;
    }

    /** 生成 JSAPI 前端调起参数（WeixinJSBridge / wx.requestPayment） */
    public function jsApiSign(string $prepayId): array
    {
        $time = (string)time();
        $nonce = $this->nonce();
        $pkg = 'prepay_id=' . $prepayId;
        $map = [
            'appId' => (string)$this->cfg['app_id'],
            'timeStamp' => $time,
            'nonceStr' => $nonce,
            'package' => $pkg,
            'signType' => 'MD5',
        ];
        $str = '';
        foreach ($map as $k => $v) { $str .= $k . '=' . $v . '&'; }
        $str .= 'key=' . $this->apiKey();
        $map['paySign'] = strtoupper(md5($str));
        return $map;
    }

    /** OAuth code → openid（微信内 JSAPI 支付前置；appid+secret 需配置） */
    public function openId(string $code): array
    {
        if (trim((string)($this->cfg['app_id'] ?? '')) === '' || trim((string)($this->cfg['secret'] ?? '')) === '') {
            return ['ok' => false, 'msg' => '未配置微信 AppId/Secret'];
        }
        $url = self::OAUTH_TOKEN . '?appid=' . urlencode((string)$this->cfg['app_id'])
            . '&secret=' . urlencode((string)$this->cfg['secret'])
            . '&code=' . urlencode($code) . '&grant_type=authorization_code';
        $resp = $this->get($url);
        $arr = json_decode($resp ?: '{}', true) ?: [];
        if (!empty($arr['openid'])) {
            return ['ok' => true, 'openid' => (string)$arr['openid'], 'raw' => $resp];
        }
        return ['ok' => false, 'msg' => $arr['errmsg'] ?? 'openid fetch failed', 'raw' => $resp];
    }

    /** 退款（原路退回，需商户证书；本地/沙箱返回 ok=false 由上层转人工记录） */
    public function refund(array $r): array
    {
        $params = [
            'appid' => $this->cfg['app_id'],
            'mch_id' => $this->cfg['mch_id'],
            'nonce_str' => $this->nonce(),
            'out_trade_no' => $r['order_no'],
            'out_refund_no' => $r['refund_no'],
            'total_fee' => (int)round((float)$r['total_amount'] * 100),
            'refund_fee' => (int)round((float)$r['refund_amount'] * 100),
            'refund_desc' => mb_substr($r['reason'] ?? '用户退款', 0, 80),
            'sign_type' => 'HMAC-SHA256',
        ];
        $params['sign'] = $this->calcSign($params);
        $xml = $this->toXml($params);
        $resp = $this->postXml(($this->cfg['sandbox'] ?? false) ? self::REFUND_GATEWAY_SANDBOX : self::REFUND_GATEWAY_PROD, $xml, true);
        $arr = $this->fromXml($resp);
        if (($arr['return_code'] ?? '') === 'SUCCESS' && ($arr['result_code'] ?? '') === 'SUCCESS') {
            return ['ok' => true, 'msg' => 'SUCCESS', 'raw' => $resp];
        }
        return ['ok' => false, 'msg' => $arr['return_msg'] ?? ($arr['err_code_des'] ?? 'refund failed'), 'raw' => $resp];
    }

    /** HMAC-SHA256 签名（含 key，自动剔除 sign 字段） */
    public function calcSign(array $params): string
    {
        $p = $params;
        unset($p['sign']);
        ksort($p);
        $str = '';
        foreach ($p as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $str .= $k . '=' . $v . '&';
        }
        $str .= 'key=' . $this->apiKey();
        return strtoupper(hash_hmac('sha256', $str, $this->apiKey()));
    }

    /** 异步回调验签 */
    public function verify(array $params): bool
    {
        if (empty($params['sign'])) {
            return false;
        }
        return hash_equals($this->calcSign($params), (string)$params['sign']);
    }

    private function apiKey(): string
    {
        if (!empty($this->cfg['sandbox']) && trim((string)($this->cfg['sandbox_key'] ?? '')) !== '') {
            return (string)$this->cfg['sandbox_key'];
        }
        return (string)$this->cfg['key'];
    }

    private function toXml(array $params): string
    {
        $xml = '<xml>';
        foreach ($params as $k => $v) {
            $xml .= '<' . $k . '>' . (is_numeric($v) ? $v : '<![CDATA[' . $v . ']]>' ) . '</' . $k . '>';
        }
        return $xml . '</xml>';
    }

    private function fromXml(string $xml): array
    {
        $arr = [];
        if ($xml === '') {
            return $arr;
        }
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($doc === false) {
            return $arr;
        }
        foreach ((array)$doc as $k => $v) {
            $arr[$k] = (string)$v;
        }
        return $arr;
    }

    /**
     * 是否校验证书。
     * ★ 默认**开**（安全默认）。支付/退款都是真实资金操作，中间人可篡改金额与收款方。
     *   旧代码写死 false —— 一律不校验，属支付链路上的硬伤。
     *   仅当显式配置 mall.pay_wxpay_insecure_ssl=1 时才关闭（供无 CA 的极端本地调试）。
     */
    private function verifySsl(): bool
    {
        return (string)($this->cfg['insecure_ssl'] ?? '') !== '1';
    }

    private function postXml(string $url, string $xml, bool $ssl = false): string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifySsl());
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifySsl() ? 2 : 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
        if ($ssl) {
            // 退款需商户证书（生产环境在配置目录放置 apiclient_cert.pem / apiclient_key.pem）
            $cert = (string)($this->cfg['cert_path'] ?? '');
            $key = (string)($this->cfg['key_path'] ?? '');
            if ($cert !== '' && $key !== '') {
                curl_setopt($ch, CURLOPT_SSLCERT, $cert);
                curl_setopt($ch, CURLOPT_SSLKEY, $key);
            }
        }
        $resp = (string)curl_exec($ch);
        if ($resp === '' && curl_errno($ch) !== 0) {
            error_log('[mall wxpay postXml] ' . curl_error($ch));
        }
        curl_close($ch);
        return $resp;
    }

    private function get(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifySsl());
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifySsl() ? 2 : 0);
        $resp = (string)curl_exec($ch);
        if ($resp === '' && curl_errno($ch) !== 0) {
            error_log('[mall wxpay get] ' . curl_error($ch));
        }
        curl_close($ch);
        return $resp;
    }
}
