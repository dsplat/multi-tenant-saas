<?php

namespace MultiTenantSaas\Modules\Billing\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;

/**
 * PayPal 支付适配服务
 *
 * 基于 PayPal REST API v2 (Checkout Orders)
 *
 * 流程：
 *  1. createOrder：后端创建订单，返回 approval link
 *  2. 用户跳转到 PayPal 授权
 *  3. captureOrder：用户回到 return_url 后，后端调用 capture 完成交易
 *  4. webhook：PayPal 异步通知交易状态
 *
 * 租户级配置：复用 PayService::getConfig($tenantId, 'paypal')
 */
class PayPalService
{
    /**
     * PayPal API 基础地址
     */
    protected function baseUrl(string $mode): string
    {
        return $mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * 获取 OAuth access_token
     *
     * @param  int  $tenantId  租户 ID
     *
     * @throws \RuntimeException
     */
    public function getAccessToken(int $tenantId): string
    {
        $config = PayService::exportPaymentConfig($tenantId)['paypal'] ?? [];

        if (empty($config['client_id']) || empty($config['client_secret'])) {
            throw new \RuntimeException(trans('payment.driver_not_configured', ['driver' => 'paypal', 'tenant' => $tenantId]));
        }

        // 真实密钥需从 TenantSetting 取（export 时被掩码）
        $clientId = TenantSetting::get($tenantId, 'payment', 'paypal_client_id', '');
        $clientSecret = TenantSetting::get($tenantId, 'payment', 'paypal_client_secret', '');
        $mode = TenantSetting::get($tenantId, 'payment', 'paypal_mode', 'sandbox');

        $cacheKey = "paypal:token:{$tenantId}";

        return Cache::remember($cacheKey, 3000, function () use ($clientId, $clientSecret, $mode) {
            $resp = Http::withBasicAuth($clientId, $clientSecret)
                ->asForm()
                ->post($this->baseUrl($mode) . '/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);

            if (! $resp->successful()) {
                throw new \RuntimeException(trans('payment.paypal_token_failed') . ': ' . $resp->body());
            }

            return $resp->json('access_token');
        });
    }

    /**
     * 创建 PayPal 订单
     *
     * @param  int  $tenantId  租户 ID
     * @param  float  $amount  金额
     * @param  string  $orderNo  框架侧订单号
     * @param  string  $description  订单描述
     * @return array{paypal_order_id: string, approval_url: string}
     *
     * @throws \RuntimeException
     */
    public function createOrder(int $tenantId, float $amount, string $orderNo, string $description = ''): array
    {
        $mode = TenantSetting::get($tenantId, 'payment', 'paypal_mode', 'sandbox');
        $returnUrl = TenantSetting::get($tenantId, 'payment', 'paypal_return_url', '');
        $cancelUrl = TenantSetting::get($tenantId, 'payment', 'paypal_cancel_url', '');

        $token = $this->getAccessToken($tenantId);

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $orderNo,
                'description' => $description ?: trans('payment.default_subject'),
                'amount' => [
                    'currency_code' => 'USD',
                    'value' => number_format($amount, 2, '.', ''),
                ],
            ]],
            'application_context' => [
                'return_url' => $returnUrl . '?order_no=' . $orderNo . '&tenant_id=' . $tenantId,
                'cancel_url' => $cancelUrl . '?order_no=' . $orderNo,
                'user_action' => 'PAY_NOW',
            ],
        ];

        $resp = Http::withToken($token)
            ->post($this->baseUrl($mode) . '/v2/checkout/orders', $payload);

        if (! $resp->successful()) {
            Log::error('[PayPalService] createOrder failed', ['order_no' => $orderNo, 'resp' => $resp->body()]);
            throw new \RuntimeException(trans('payment.paypal_create_failed') . ': ' . $resp->body());
        }

        $data = $resp->json();
        $approvalLink = collect($data['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? '';

        return [
            'paypal_order_id' => $data['id'] ?? '',
            'approval_url' => $approvalLink,
        ];
    }

    /**
     * 捕获订单付款（用户授权后调用）
     *
     * @param  int  $tenantId  租户 ID
     * @param  string  $paypalOrderId  PayPal 订单 ID
     *
     * @throws \RuntimeException
     */
    public function captureOrder(int $tenantId, string $paypalOrderId): array
    {
        $mode = TenantSetting::get($tenantId, 'payment', 'paypal_mode', 'sandbox');
        $token = $this->getAccessToken($tenantId);

        $resp = Http::withToken($token)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($this->baseUrl($mode) . '/v2/checkout/orders/' . $paypalOrderId . '/capture');

        if (! $resp->successful()) {
            throw new \RuntimeException(trans('payment.paypal_capture_failed') . ': ' . $resp->body());
        }

        $data = $resp->json();
        $status = $data['status'] ?? '';

        return [
            'paypal_order_id' => $paypalOrderId,
            'status' => $status === 'COMPLETED' ? 'paid' : 'pending',
            'transaction_id' => collect($data['purchase_units'][0]['payments']['captures'] ?? [])->first()['id'] ?? '',
            'raw' => $data,
        ];
    }

    /**
     * 退款（全额或部分）
     *
     * @param  int  $tenantId  租户 ID
     * @param  string  $captureId  PayPal capture ID
     * @param  float  $amount  退款金额（0 表示全额）
     */
    public function refund(int $tenantId, string $captureId, float $amount = 0): array
    {
        $mode = TenantSetting::get($tenantId, 'payment', 'paypal_mode', 'sandbox');
        $token = $this->getAccessToken($tenantId);

        $payload = $amount > 0 ? [
            'amount' => [
                'currency_code' => 'USD',
                'value' => number_format($amount, 2, '.', ''),
            ],
        ] : [];

        $resp = Http::withToken($token)
            ->post($this->baseUrl($mode) . '/v2/payments/captures/' . $captureId . '/refund', $payload);

        if (! $resp->successful()) {
            throw new \RuntimeException(trans('payment.paypal_refund_failed') . ': ' . $resp->body());
        }

        $data = $resp->json();

        return [
            'refund_id' => $data['id'] ?? '',
            'status' => $data['status'] ?? '',
            'raw' => $data,
        ];
    }

    /**
     * 处理 Webhook 通知
     *
     * @param  int  $tenantId  租户 ID
     * @param  array  $payload  Webhook 载荷
     * @param  array  $headers  请求头（需包含 PayPal 签名头）
     * @return array{event_type: string, order_no: string|null, status: string}
     */
    public function handleWebhook(int $tenantId, array $payload, array $headers = []): array
    {
        if (! $this->verifyWebhookSignature($tenantId, $payload, $headers)) {
            throw new \RuntimeException(trans('payment.paypal_signature_invalid'));
        }

        $eventType = $payload['event_type'] ?? '';
        $resource = $payload['resource'] ?? [];

        $orderNo = $resource['supplementary_data']['related_ids']['order_id'] ?? $resource['reference_id'] ?? null;
        $status = match ($eventType) {
            'CHECKOUT.ORDER.APPROVED' => 'approved',
            'PAYMENT.CAPTURE.COMPLETED' => 'paid',
            'PAYMENT.CAPTURE.REFUNDED' => 'refunded',
            'PAYMENT.CAPTURE.DENIED' => 'failed',
            default => 'unknown',
        };

        return [
            'event_type' => $eventType,
            'order_no' => $orderNo,
            'status' => $status,
        ];
    }

    /**
     * 验证 Webhook 签名（调用 PayPal verify-webhook-signature API）
     *
     * @param  int  $tenantId  租户 ID
     * @param  array  $payload  Webhook 载荷
     * @param  array  $headers  请求头
     */
    protected function verifyWebhookSignature(int $tenantId, array $payload, array $headers): bool
    {
        $webhookId = TenantSetting::get($tenantId, 'payment', 'paypal_webhook_id', '');
        if (empty($webhookId)) {
            throw new \RuntimeException(trans('payment.paypal_webhook_not_configured'));
        }

        $mode = TenantSetting::get($tenantId, 'payment', 'paypal_mode', 'sandbox');
        $token = $this->getAccessToken($tenantId);

        $verifyPayload = [
            'auth_algo' => $headers['paypal-auth-algo'] ?? $headers['PAYPAL-AUTH-ALGO'] ?? '',
            'cert_url' => $headers['paypal-cert-url'] ?? $headers['PAYPAL-CERT-URL'] ?? '',
            'transmission_id' => $headers['paypal-transmission-id'] ?? $headers['PAYPAL-TRANSMISSION-ID'] ?? '',
            'transmission_sig' => $headers['paypal-transmission-sig'] ?? $headers['PAYPAL-TRANSMISSION-SIG'] ?? '',
            'transmission_time' => $headers['paypal-transmission-time'] ?? $headers['PAYPAL-TRANSMISSION-TIME'] ?? '',
            'webhook_id' => $webhookId,
            'webhook_event' => $payload,
        ];

        try {
            $resp = Http::withToken($token)
                ->post($this->baseUrl($mode) . '/v1/notifications/verify-webhook-signature', $verifyPayload);

            return $resp->successful() && ($resp->json('verification_status') === 'SUCCESS');
        } catch (\Throwable $e) {
            Log::warning('[PayPalService] webhook verification failed: ' . $e->getMessage());

            return false;
        }
    }
}
