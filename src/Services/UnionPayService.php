<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Models\TenantSetting;

/**
 * 银联支付适配服务
 *
 * 基于中国银联在线支付网关（ACS / 后台通知）
 *
 * 流程：
 *  1. createOrder：后端构造表单参数并生成签名，前端自动 POST 到银联网关
 *  2. 用户在银联页面完成支付
 *  3. 银联异步通知 notify_url，同步跳回 return_url
 *
 * 租户级配置：复用 PayService::getConfig($tenantId, 'unionpay')
 *
 * 注意：真实生产部署需使用银联提供的 SDK 与证书签名；
 * 本实现提供基础参数构造与签名占位，签名细节由派生项目填充。
 */
class UnionPayService
{
    /**
     * 网关地址
     */
    protected function baseUrl(string $mode): string
    {
        return $mode === 'production'
            ? 'https://gateway.95516.com'
            : 'https://gateway.test.95516.com';
    }

    /**
     * 创建银联支付订单（返回前端表单提交所需参数）
     *
     * @param  int  $tenantId  租户 ID
     * @param  float  $amount  金额（元）
     * @param  string  $orderNo  框架侧订单号
     * @param  string  $subject  订单描述
     * @return array{params: array<string,string>, gateway_url: string}
     *
     * @throws \RuntimeException
     */
    public function createOrder(int $tenantId, float $amount, string $orderNo, string $subject = ''): array
    {
        $merId = TenantSetting::get($tenantId, 'payment', 'unionpay_mer_id', '');
        $mode = TenantSetting::get($tenantId, 'payment', 'unionpay_mode', 'test');
        $notifyUrl = TenantSetting::get($tenantId, 'payment', 'unionpay_notify_url', '');
        $returnUrl = TenantSetting::get($tenantId, 'payment', 'unionpay_return_url', '');

        if (empty($merId)) {
            throw new \RuntimeException(trans('payment.driver_not_configured', ['driver' => 'unionpay', 'tenant' => $tenantId]));
        }

        $params = [
            'version' => '5.1.0',
            'encoding' => 'UTF-8',
            'txnType' => '01',           // 消费
            'txnSubType' => '01',        // 自助消费
            'bizType' => '000201',       // B2C 网关支付
            'channelType' => '07',       // PC
            'merId' => $merId,
            'orderId' => $orderNo,
            'txnTime' => now()->format('YmdHis'),
            'txnAmt' => intval($amount * 100),
            'currencyCode' => '156',
            'frontUrl' => $returnUrl,
            'backUrl' => $notifyUrl,
            'orderDesc' => $subject ?: trans('payment.default_subject'),
            'reqReserved' => $orderNo,
        ];

        $params['signature'] = $this->sign($tenantId, $params);
        $params['signMethod'] = '01';   // RSA

        return [
            'params' => $params,
            'gateway_url' => $this->baseUrl($mode) . '/gateway/api/frontTransReq.do',
        ];
    }

    /**
     * 查询订单状态
     *
     * @param  int  $tenantId  租户 ID
     * @param  string  $orderNo  订单号
     * @param  string  $txnTime  原始下单时间（YmdHis）
     */
    public function queryOrder(int $tenantId, string $orderNo, string $txnTime): array
    {
        $merId = TenantSetting::get($tenantId, 'payment', 'unionpay_mer_id', '');
        $mode = TenantSetting::get($tenantId, 'payment', 'unionpay_mode', 'test');

        $params = [
            'version' => '5.1.0',
            'encoding' => 'UTF-8',
            'txnType' => '00',
            'txnSubType' => '00',
            'bizType' => '000000',
            'channelType' => '07',
            'merId' => $merId,
            'orderId' => $orderNo,
            'txnTime' => $txnTime,
        ];

        $params['signature'] = $this->sign($tenantId, $params);
        $params['signMethod'] = '01';

        $resp = Http::asForm()->post($this->baseUrl($mode) . '/gateway/api/queryTrans.do', $params);

        if (! $resp->successful()) {
            throw new \RuntimeException(trans('payment.unionpay_query_failed') . ': ' . $resp->body());
        }

        parse_str($resp->body(), $data);

        return [
            'order_no' => $orderNo,
            'status' => $this->mapStatus($data['respCode'] ?? ''),
            'query_id' => $data['queryId'] ?? '',
            'raw' => $data,
        ];
    }

    /**
     * 退款
     *
     * @param  int  $tenantId  租户 ID
     * @param  string  $orderNo  原订单号
     * @param  string  $queryId  银联原始交易 queryId
     * @param  string  $txnTime  原交易时间（YmdHis）
     * @param  float  $amount  退款金额（0 表示全额）
     */
    public function refund(int $tenantId, string $orderNo, string $queryId, string $txnTime, float $amount = 0): array
    {
        $merId = TenantSetting::get($tenantId, 'payment', 'unionpay_mer_id', '');
        $mode = TenantSetting::get($tenantId, 'payment', 'unionpay_mode', 'test');
        $notifyUrl = TenantSetting::get($tenantId, 'payment', 'unionpay_notify_url', '');

        $refundNo = 'RFD' . date('YmdHis') . rand(1000, 9999);

        $params = [
            'version' => '5.1.0',
            'encoding' => 'UTF-8',
            'txnType' => '04',          // 退货
            'txnSubType' => '00',
            'bizType' => '000000',
            'channelType' => '07',
            'merId' => $merId,
            'origQryId' => $queryId,
            'orderId' => $refundNo,
            'txnTime' => now()->format('YmdHis'),
            'txnAmt' => $amount > 0 ? intval($amount * 100) : 0,
            'backUrl' => $notifyUrl,
            'reqReserved' => $orderNo,
        ];

        $params['signature'] = $this->sign($tenantId, $params);
        $params['signMethod'] = '01';

        $resp = Http::asForm()->post($this->baseUrl($mode) . '/gateway/api/visualizationTransReq.do', $params);

        if (! $resp->successful()) {
            throw new \RuntimeException(trans('payment.unionpay_refund_failed') . ': ' . $resp->body());
        }

        parse_str($resp->body(), $data);

        return [
            'refund_no' => $refundNo,
            'status' => $this->mapStatus($data['respCode'] ?? ''),
            'raw' => $data,
        ];
    }

    /**
     * 处理异步通知
     *
     * @param  int  $tenantId  租户 ID
     * @param  array  $payload  通知参数
     * @return array{order_no: string, status: string, transaction_id: string}
     */
    public function handleNotify(int $tenantId, array $payload): array
    {
        // 验证签名
        if (! $this->verifySignature($tenantId, $payload)) {
            throw new \RuntimeException(trans('payment.unionpay_signature_invalid'));
        }

        $respCode = $payload['respCode'] ?? '';

        return [
            'order_no' => $payload['orderId'] ?? '',
            'status' => $this->mapStatus($respCode),
            'transaction_id' => $payload['queryId'] ?? '',
        ];
    }

    /**
     * 签名（占位实现，真实部署需使用银联证书签名）
     *
     * @param  int  $tenantId  租户 ID
     * @param  array  $params  待签名参数
     * @return string Base64 签名
     */
    protected function sign(int $tenantId, array $params): string
    {
        $certPath = TenantSetting::get($tenantId, 'payment', 'unionpay_cert_path', '');
        $certPassword = TenantSetting::get($tenantId, 'payment', 'unionpay_cert_password', '');

        if (empty($certPath) || ! file_exists($certPath)) {
            Log::warning('[UnionPayService] cert not found, signing skipped', ['path' => $certPath]);

            return '';
        }

        // 占位：真实签名应使用 openssl_pkcs7_sign 或银联 SDK
        ksort($params);
        $data = http_build_query($params);

        $p12 = file_get_contents($certPath);
        openssl_pkcs12_read($p12, $certs, $certPassword);

        if (empty($certs['pkey'])) {
            return '';
        }

        openssl_sign($data, $signature, $certs['pkey'], OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    /**
     * 验证签名（使用银联公钥证书验签）
     *
     * @param  int  $tenantId  租户 ID
     * @param  array  $params  通知参数
     */
    protected function verifySignature(int $tenantId, array $params): bool
    {
        $certPath = TenantSetting::get($tenantId, 'payment', 'unionpay_verify_cert_path', '');
        if (empty($certPath) || ! file_exists($certPath)) {
            Log::warning('[UnionPayService] verify cert not found, signature verification failed', ['path' => $certPath]);

            return false;
        }

        $signature = base64_decode($params['signature'] ?? '');
        if (empty($signature)) {
            return false;
        }

        $paramsWithoutSign = $params;
        unset($paramsWithoutSign['signature'], $paramsWithoutSign['signMethod']);
        ksort($paramsWithoutSign);
        $data = http_build_query($paramsWithoutSign);

        $certContent = file_get_contents($certPath);
        $cert = openssl_x509_read($certContent);
        if (! $cert) {
            Log::error('[UnionPayService] failed to read verify certificate');

            return false;
        }

        $result = openssl_verify($data, $signature, $cert, OPENSSL_ALGO_SHA256);
        openssl_x509_free($cert);

        return $result === 1;
    }

    /**
     * 银联 respCode 映射到框架状态
     */
    protected function mapStatus(string $respCode): string
    {
        return match ($respCode) {
            '00' => 'paid',
            '03', '04', '05' => 'pending',
            '01', '02' => 'failed',
            default => 'unknown',
        };
    }
}
