<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 短信发送服务
 *
 * driver=log   → 仅写日志（本地/测试默认）
 * driver=mtedu → 调用 mtedu VPC 内网短信网关
 */
class SmsService
{
    /**
     * 发送验证码短信，成功返回传入的 $code，失败返回 false。
     */
    public static function send(string $phone, string $code, string $type = 'register'): string|false
    {
        $driver = config('services.sms.driver', 'log');

        return static::sendUsingDriver($driver, $phone, $code, $type);
    }

    public static function sendUsingDriver(string $driver, string $phone, string $code, string $type = 'register'): string|false
    {
        $driver = trim($driver);

        return match ($driver) {
            'ww' => static::sendViaWw($phone, $code, $type),
            'mtedu' => static::sendViaMtedu($phone, $code, $type),
            default => static::sendViaLog($phone, $code, $type),
        };
    }

    // ----------------------------------------
    // Private drivers
    // ----------------------------------------

    private static function sendViaWw(string $phone, string $code, string $type): string|false
    {
        $endpoint = (string) config('services.sms.ww_endpoint');
        $account = (string) config('services.sms.ww_account');
        $password = (string) config('services.sms.ww_password');
        $corpid = (string) config('services.sms.ww_corpid');
        $productId = (string) config('services.sms.ww_product_id');
        $sign = (string) config('services.sms.ww_sign', '馒头商学院');
        $smsg = '【' . $sign . "】您的验证码是{$code}，5分钟内有效，请勿泄露。";

        if ($endpoint === '' || $account === '' || $password === '' || $productId === '') {
            Log::error('SmsService ww config missing', [
                'phone' => $phone,
                'type' => $type,
                'account_len' => strlen($account),
                'endpoint' => $endpoint,
            ]);

            return false;
        }

        try {
            $response = Http::asForm()->timeout((int) config('services.sms.ww_timeout', 10))->post($endpoint, [
                'sname' => $account,
                'spwd' => $password,
                'scorpid' => $corpid,
                'sprdid' => $productId,
                'sdst' => $phone,
                'smsg' => $smsg,
            ]);

            if (! $response->successful()) {
                Log::error('SmsService ww HTTP error', [
                    'phone' => $phone,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            $state = static::extractXmlValue($response->body(), 'State');
            $msgId = static::extractXmlValue($response->body(), 'MsgID');
            $msgState = static::extractXmlValue($response->body(), 'MsgState');

            if ($state === '0') {
                Log::error('SmsService ww send ok', [
                    'phone' => static::maskPhone($phone),
                    'type' => $type,
                    'msg_id' => $msgId,
                    'sign' => config('services.sms.ww_sign'),
                    'smsg_preview' => mb_substr($smsg ?? '', 0, 20),
                ]);

                return $code;
            }

            Log::error('SmsService ww send failed', [
                'phone' => static::maskPhone($phone),
                'type' => $type,
                'state' => $state,
                'msg_id' => $msgId,
                'msg_state' => $msgState,
                'raw_body' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('SmsService ww exception', [
                'phone' => static::maskPhone($phone),
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private static function sendViaMtedu(string $phone, string $code, string $type): string|false
    {
        $endpoint = config('services.sms.mtedu_endpoint');

        try {
            $payload = [
                'phone'   => $phone,
                'message' => trans("sms.verification_code", ["code" => $code]),
                'msgtype' => 0,   // 0=纯文本，1=模板（模板模式可能导致网关自产随机码）
                'code'    => $code,
                'type'    => $type,
            ];

            $response = Http::asJson()->timeout(5)->post($endpoint, $payload);

            $body = $response->json();

            Log::info('SmsService mtedu response', [
                'phone'    => static::maskPhone($phone),
                'type'     => $type,
                'code_sent' => $code,
                'http_status' => $response->status(),
                'response' => $body,
            ]);

            if ($response->successful()) {
                if (isset($body['status']) && $body['status'] == 1) {
                    // 若网关在 data.code 返回其自产验证码，优先使用；否则用我们的
                    $actualCode = $body['data']['code'] ?? $body['code'] ?? $code;

                    if ($actualCode !== $code) {
                        Log::warning('SmsService mtedu: gateway overrode code', [
                            'phone'         => static::maskPhone($phone),
                            'our_code'      => $code,
                            'gateway_code'  => $actualCode,
                        ]);
                    }

                    return (string) $actualCode;
                }

                Log::warning('SmsService mtedu send failed', [
                    'phone'    => static::maskPhone($phone),
                    'type'     => $type,
                    'response' => $body,
                ]);

                return false;
            }

            Log::error('SmsService mtedu HTTP error', [
                'phone'  => $phone,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('SmsService mtedu exception', [
                'phone'   => static::maskPhone($phone),
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private static function sendViaLog(string $phone, string $code, string $type): string
    {
        Log::info('SmsService [log driver] send code', [
            'phone' => static::maskPhone($phone),
            'code' => $code,
            'type' => $type,
        ]);

        return $code;
    }

    private static function extractXmlValue(string $xml, string $tag): ?string
    {
        if (preg_match('/<'.preg_quote($tag, '/').'>(.*?)<\/'.preg_quote($tag, '/').'>/s', $xml, $matches) !== 1) {
            return null;
        }

        return trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    private static function maskPhone(string $phone): string
    {
        if (strlen($phone) !== 11) {
            return $phone;
        }

        return substr($phone, 0, 3).'****'.substr($phone, -4);
    }
}
