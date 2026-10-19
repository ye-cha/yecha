<?php
declare(strict_types=1);

/**
 * Sms —— 短信验证码发送（可插拔厂商适配）
 *
 * 支持的厂商（按 option sms.provider 选择）：
 *   aliyun   阿里云短信（Dysmsapi 2017-05-25，HMAC-SHA1 签名）
 *   tencent  腾讯云短信（sms 2021-01-11，TC3-HMAC-SHA256 签名）
 *   mock     仅用于本地/联调：不真发，把码写入日志并存入 option（**生产禁用**）
 *
 * 配置项（ye_options）：
 *   sms.enabled   1/0
 *   sms.provider  aliyun|tencent|mock
 *   sms.key       AccessKeyId / SecretId      （敏感，走 yc_opt 加密）
 *   sms.secret    AccessKeySecret / SecretKey  （敏感，走 yc_opt 加密）
 *   sms.sign      短信签名，如「51普洱茶网」
 *   sms.tpl       模板 ID（阿里云 TemplateCode / 腾讯云 TemplateId）
 *   sms.region    腾讯云地域，默认 ap-guangzhou
 *   sms.endpoint  可选，覆盖默认端点
 *
 * 安全：mock 厂商在生产域名下**强制不可用**（与 dev 登录同一套思路：显式开关 + 来源限制）
 */
final class Sms
{
    public static function provider(): string
    {
        return strtolower(trim((string)get_option('sms.provider', '')));
    }

    public static function isMock(): bool
    {
        return self::provider() === 'mock';
    }

    /** mock 是否允许：必须显式开启且来源为本机/内网（防止生产误用） */
    public static function mockAllowed(): bool
    {
        if ((string)get_option('sms.mock_allow', '0') !== '1') { return false; }
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') { return false; }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return in_array($ip, ['::1'], true);
        }
        $n = ip2long($ip);
        if ($n === false) { return false; }
        $in = static function (string $cidr) use ($n): bool {
            [$s, $b] = explode('/', $cidr);
            $sn = ip2long($s);
            if ($sn === false) { return false; }
            $m = (int)$b === 0 ? 0 : (-1 << (32 - (int)$b));
            return (($n & $m) === ($sn & $m));
        };
        return $in('127.0.0.0/8') || $in('10.0.0.0/8') || $in('172.16.0.0/12') || $in('192.168.0.0/16');
    }

    /**
     * 发送验证码短信。
     * @return bool|string true 成功，字符串为错误信息
     */
    public static function sendCode(string $phone, string $code, int $minutes, string $site): bool|string
    {
        $phone = trim($phone);
        if ($phone === '') { return '手机号为空'; }
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) { return '手机号格式不正确'; }

        $p = self::provider();
        return match ($p) {
            'mock'    => self::mockAllowed() ? self::sendMock($phone, $code, $site) : '短信 mock 未启用或来源不允许',
            'aliyun'  => self::sendAliyun($phone, $code, $minutes, $site),
            'tencent' => self::sendTencent($phone, $code, $minutes),
            default   => $p === '' ? '未配置短信厂商' : ('不支持的短信厂商: ' . $p),
        };
    }

    /* ================= 阿里云 ================= */

    private static function sendAliyun(string $phone, string $code, int $minutes, string $site): bool|string
    {
        $key = trim(yc_opt('sms.key', ''));
        $secret = trim(yc_opt('sms.secret', ''));
        $sign = trim((string)get_option('sms.sign', ''));
        $tpl = trim((string)get_option('sms.tpl', ''));
        if ($key === '' || $secret === '' || $sign === '' || $tpl === '') {
            return '阿里云短信配置不完整（需 key/secret/sign/tpl）';
        }

        // 参数：模板需含 ${code}（及可选 ${site}）
        $params = ['code' => $code];
        $paramsJson = json_encode($params, JSON_UNESCAPED_UNICODE);

        $api = [
            'Action' => 'SendSms',
            'Version' => '2017-05-25',
            'RegionId' => 'cn-hangzhou',
            'PhoneNumbers' => $phone,
            'SignName' => $sign,
            'TemplateCode' => $tpl,
            'TemplateParam' => $paramsJson,
            'Format' => 'JSON',
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureVersion' => '1.0',
            'SignatureNonce' => bin2hex(random_bytes(8)),
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        ksort($api);
        $canon = '';
        foreach ($api as $k => $v) {
            $canon .= '&' . self::pe($k) . '=' . self::pe((string)$v);
        }
        $canon = ltrim($canon, '&');
        $sts = 'GET&' . self::pe('/') . '&' . self::pe($canon);
        $sig = base64_encode(hash_hmac('sha1', $sts, $secret . '&', true));
        $url = 'https://dysmsapi.aliyuncs.com/?Signature=' . self::pe($sig) . '&' . $canon;

        $r = self::httpGet($url);
        if ($r === null) { return '请求阿里云失败'; }
        $j = json_decode($r, true);
        if (!is_array($j)) { return '阿里云返回非 JSON'; }
        if (($j['Code'] ?? '') === 'OK') { return true; }
        return '阿里云错误: ' . (string)($j['Code'] ?? '?') . ' ' . (string)($j['Message'] ?? '');
    }

    /* ================= 腾讯云 ================= */

    private static function sendTencent(string $phone, string $code, int $minutes): bool|string
    {
        $key = trim(yc_opt('sms.key', ''));      // SecretId
        $secret = trim(yc_opt('sms.secret', '')); // SecretKey
        $sign = trim((string)get_option('sms.sign', ''));   // 短信签名内容（SmsSdkAppId 需另配）
        $tpl = trim((string)get_option('sms.tpl', ''));
        $appid = trim((string)get_option('sms.appid', ''));
        $region = trim((string)get_option('sms.region', 'ap-guangzhou')) ?: 'ap-guangzhou';
        if ($key === '' || $secret === '' || $tpl === '' || $appid === '') {
            return '腾讯云短信配置不完整（需 key/secret/appid/tpl）';
        }

        $host = 'sms.tencentcloudapi.com';
        $service = 'sms';
        $action = 'SendSms';
        $version = '2021-01-11';
        $payload = json_encode([
            'PhoneNumberSet' => ['+86' . $phone],
            'SmsSdkAppId' => $appid,
            'SignName' => $sign !== '' ? $sign : trim((string)get_option('sms.sign', '')),
            'TemplateId' => $tpl,
            'TemplateParamSet' => [$code, (string)$minutes],
        ], JSON_UNESCAPED_UNICODE);

        $ts = time();
        $date = gmdate('Y-m-d', $ts);
        $hashedPayload = hash('sha256', (string)$payload);
        $canonical = "POST\n/\n\ncontent-type:application/json; charset=utf-8\nhost:{$host}\n\ncontent-type;host\n{$hashedPayload}";
        $toSign = "TC3-HMAC-SHA256\n{$ts}\n{$date}/{$service}/tc3_request\n" . hash('sha256', $canonical);
        $kDate = hash_hmac('sha256', $date, 'TC3' . $secret, true);
        $kService = hash_hmac('sha256', $service, $kDate, true);
        $kSigning = hash_hmac('sha256', 'tc3_request', $kService, true);
        $sig = hash_hmac('sha256', $toSign, $kSigning);
        $auth = "TC3-HMAC-SHA256 Credential={$key}/{$date}/{$service}/tc3_request, "
              . "SignedHeaders=content-type;host, Signature={$sig}";

        $r = self::httpPost('https://' . $host, (string)$payload, [
            'Authorization: ' . $auth,
            'Content-Type: application/json; charset=utf-8',
            'Host: ' . $host,
            'X-TC-Action: ' . $action,
            'X-TC-Version: ' . $version,
            'X-TC-Timestamp: ' . $ts,
            'X-TC-Region: ' . $region,
        ]);
        if ($r === null) { return '请求腾讯云失败'; }
        $j = json_decode($r, true);
        if (!is_array($j)) { return '腾讯云返回非 JSON'; }
        $resp = $j['Response'] ?? [];
        if (isset($resp['Error'])) {
            return '腾讯云错误: ' . (string)($resp['Error']['Code'] ?? '?') . ' ' . (string)($resp['Error']['Message'] ?? '');
        }
        $st = $resp['SendStatusSet'][0]['Code'] ?? '';
        return $st === 'Ok' ? true : ('腾讯云发送状态: ' . (string)$st);
    }

    /* ================= mock（仅联调） ================= */

    private static function sendMock(string $phone, string $code, string $site): bool|string
    {
        set_option('sms.mock_last', json_encode([
            'phone' => $phone, 'code' => $code, 'at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE));
        @error_log('[sms mock] ' . $phone . ' code=' . $code);
        return true;
    }

    /* ================= 工具 ================= */

    private static function pe(string $s): string
    {
        return str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], rawurlencode($s));
    }

    private static function httpGet(string $url): ?string
    {
        $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true],
                                      'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $r = @file_get_contents($url, false, $ctx);
        return $r === false ? null : $r;
    }

    private static function httpPost(string $url, string $body, array $headers): ?string
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers),
            'content' => $body, 'timeout' => 15, 'ignore_errors' => true,
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $r = @file_get_contents($url, false, $ctx);
        return $r === false ? null : $r;
    }
}
