<?php
declare(strict_types=1);
/**
 * 支付宝支付驱动
 * - PC 电脑网站支付（alipay.trade.page.pay，RSA2 = SHA256withRSA）
 * - 手机网站支付（alipay.trade.wap.pay，RSA2，QUICK_WAP_WAY）—— 移动端浏览器
 * - 退款（alipay.trade.refund，原路退回）
 * - 生产/沙箱网关自动切换；私钥签名、支付宝公钥验签。
 * - 仅负责「构造跳转 / 验签 / 调接口」，不含业务落库（落库统一走 Mall::payApplyNotify / Mall::refundOrder）。
 * - 未配置商户号时由商城「沙箱模拟」接管，本地即可端到端测试。
 */
namespace MallPay;

class Alipay
{
    public const GATEWAY_PROD = 'https://openapi.alipay.com/gateway.do';
    public const GATEWAY_SANDBOX = 'https://openapi.alipaydev.com/gateway.do';

    /** @param array{app_id?:string,private_key?:string,public_key?:string,sandbox?:bool,gateway?:string} $cfg */
    public function __construct(private array $cfg)
    {
    }

    public function configured(): bool
    {
        return trim((string)($this->cfg['app_id'] ?? '')) !== ''
            && trim((string)($this->cfg['private_key'] ?? '')) !== ''
            && trim((string)($this->cfg['public_key'] ?? '')) !== '';
    }

    public function gateway(): string
    {
        if (!empty($this->cfg['gateway'])) {
            return (string)$this->cfg['gateway'];
        }
        return !empty($this->cfg['sandbox']) ? self::GATEWAY_SANDBOX : self::GATEWAY_PROD;
    }

    /**
     * 构造电脑网站支付跳转 URL（GET）。
     * @param array{order_no:string,pay_price:float,subject:string,body?:string} $order
     */
    public function pagePayUrl(array $order, string $notifyUrl, string $returnUrl): string
    {
        $params = $this->commonParams('alipay.trade.page.pay', $notifyUrl, $returnUrl) + [
            'biz_content' => $this->biz([
                'out_trade_no' => $order['order_no'],
                'product_code' => 'FAST_INSTANT_TRADE_PAY',
                'total_amount' => number_format((float)$order['pay_price'], 2, '.', ''),
                'subject' => mb_substr($order['subject'], 0, 256),
                'body' => mb_substr($order['body'] ?? '', 0, 512),
            ]),
        ];
        $params['sign'] = $this->sign($params);
        return $this->gateway() . '?' . http_build_query($params);
    }

    /**
     * 构造手机网站支付跳转 URL（GET，移动端浏览器）。
     * @param array{order_no:string,pay_price:float,subject:string,body?:string} $order
     */
    public function wapPayUrl(array $order, string $notifyUrl, string $returnUrl): string
    {
        $params = $this->commonParams('alipay.trade.wap.pay', $notifyUrl, $returnUrl) + [
            'biz_content' => $this->biz([
                'out_trade_no' => $order['order_no'],
                'product_code' => 'QUICK_WAP_WAY',
                'total_amount' => number_format((float)$order['pay_price'], 2, '.', ''),
                'subject' => mb_substr($order['subject'], 0, 256),
                'body' => mb_substr($order['body'] ?? '', 0, 512),
            ]),
        ];
        $params['sign'] = $this->sign($params);
        return $this->gateway() . '?' . http_build_query($params);
    }

    /**
     * 退款（原路退回）。返回 ['ok'=>bool,'msg'=>...]。
     * @param array{order_no:string,refund_no:string,refund_amount:float,reason?:string} $r
     */
    public function refund(array $r): array
    {
        $params = $this->commonParams('alipay.trade.refund', '', '') + [
            'biz_content' => $this->biz([
                'out_trade_no' => $r['order_no'],
                'refund_amount' => number_format((float)$r['refund_amount'], 2, '.', ''),
                'out_request_no' => $r['refund_no'],
                'refund_reason' => mb_substr($r['reason'] ?? '用户退款', 0, 256),
            ]),
        ];
        $params['sign'] = $this->sign($params);
        $resp = $this->get($this->gateway() . '?' . http_build_query($params));
        $arr = json_decode($resp ?: '{}', true) ?: [];
        $node = $arr['alipay_trade_refund_response'] ?? [];
        if (($node['code'] ?? '') === '10000') {
            return ['ok' => true, 'msg' => $node['msg'] ?? 'SUCCESS', 'raw' => $resp];
        }
        return ['ok' => false, 'msg' => $node['sub_msg'] ?? ($node['msg'] ?? 'refund failed'), 'raw' => $resp];
    }

    /** 公共请求参数（不含 biz_content / sign） */
    private function commonParams(string $method, string $notifyUrl, string $returnUrl): array
    {
        $p = [
            'app_id' => $this->cfg['app_id'],
            'method' => $method,
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
        ];
        if ($notifyUrl !== '') { $p['notify_url'] = $notifyUrl; }
        if ($returnUrl !== '') { $p['return_url'] = $returnUrl; }
        return $p;
    }

    private function biz(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /** RSA2 签名（排序、拼接 k=v&、SHA256withRSA、base64） */
    public function sign(array $params): string
    {
        unset($params['sign']);
        ksort($params);
        $str = '';
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $str .= $k . '=' . $v . '&';
        }
        $str = rtrim($str, '&');
        $key = $this->pem((string)$this->cfg['private_key'], 'RSA PRIVATE KEY');
        openssl_sign($str, $sign, $key, OPENSSL_ALGO_SHA256);
        return base64_encode($sign);
    }

    /** 支付宝异步/同步回调验签（支付宝公钥） */
    public function verify(array $params): bool
    {
        if (empty($params['sign'])) {
            return false;
        }
        $sign = (string)$params['sign'];
        $p = $params;
        unset($p['sign'], $p['sign_type']);
        ksort($p);
        $str = '';
        foreach ($p as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $str .= $k . '=' . $v . '&';
        }
        $str = rtrim($str, '&');
        $key = $this->pem((string)$this->cfg['public_key'], 'PUBLIC KEY');
        return openssl_verify($str, base64_decode($sign), $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /** 规整为 PEM（已带 BEGIN 则原样返回；裸 base64 按类型包裹） */
    private function pem(string $key, string $type): string
    {
        $raw = str_replace(["\r", "\n", ' '], '', $key);
        if (str_starts_with($raw, '-----BEGIN')) {
            return $key; // 已是完整 PEM，原样返回
        }
        return "-----BEGIN $type-----\n" . chunk_split($raw, 64, "\n") . "-----END $type-----\n";
    }

    /**
     * 是否校验证书。
     * ★ 默认**开**（安全默认）。支付/退款是真实资金操作，中间人可篡改金额与收款方。
     *   仅当显式配置 mall.pay_alipay_insecure_ssl=1 时才关闭（无 CA 的极端本地调试）。
     */
    private function verifySsl(): bool
    {
        return (string)($this->cfg['insecure_ssl'] ?? '') !== '1';
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
            error_log('[mall alipay get] ' . curl_error($ch));
        }
        curl_close($ch);
        return $resp;
    }
}
