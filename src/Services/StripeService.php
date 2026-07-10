<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Models\TenantSetting;

/**
 * Stripe 支付适配服务
 *
 * 基于 Stripe Checkout Sessions / Payment Intents
 *
 * 流程：
 *  1. createCheckoutSession：后端创建 Checkout Session，返回 url
 *  2. 用户在 Stripe 托管页面完成支付
 *  3. Stripe 跳回 return_url；同时异步 webhook 通知交易状态
 *
 * 租户级配置：复用 PayService::getConfig($tenantId, 'stripe')
 */
class StripeService
{
    /**
     * Stripe API 基础地址
     */
    protected const BASE_URL = 'https://api.stripe.com';

    /**
     * 获取 Stripe secret key
     *
     * @param  int  $tenantId  租户 ID
     *
     * @throws \RuntimeException
     */
    protected function getSecretKey(int $tenantId): string
    {
        $key = TenantSetting::get($tenantId, 'payment', 'stripe_secret_key', '');

        if (empty($key)) {
            throw new \RuntimeException(trans('payment.driver_not_configured', ['driver' => 'stripe', 'tenant' => $tenantId]));
        }

        return $key;
    }

    /**
     * 创建 Checkout Session
     *
     * @param  int  $tenantId  租户 ID
     * @param  float  $amount  金额（元）
     * @param  string  $orderNo  框架侧订单号
     * @param  string  $description  订单描述
     * @param  string  $currency  货币代码（默认 CNY）
     * @return array{session_id: string, session_url: string}
     *
     * @throws \RuntimeException
     */
    public function createCheckoutSession(int $tenantId, float $amount, string $orderNo, string $description = '', string $currency = 'CNY'): array
    {
        $secretKey = $this->getSecretKey($tenantId);
        $returnUrl = TenantSetting::get($tenantId, 'payment', 'stripe_return_url', '');

        $payload = [
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($currency),
                    'product_data' => ['name' => $description ?: trans('payment.default_subject')],
                    'unit_amount' => intval($amount * 100),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => $returnUrl . '?order_no=' . $orderNo . '&status=success',
            'cancel_url' => $returnUrl . '?order_no=' . $orderNo . '&status=cancel',
            'client_reference_id' => $orderNo,
        ];

        $resp = Http::withToken($secretKey)
            ->asForm()
            ->post(self::BASE_URL . '/v1/checkout/sessions', $payload);

        if (! $resp->successful()) {
            Log::error('[StripeService] createCheckoutSession failed', ['order_no' => $orderNo, 'resp' => $resp->body()]);
            throw new \RuntimeException(trans('payment.stripe_create_failed') . ': ' . $resp->body());
        }

        $data = $resp->json();

        return [
            'session_id' => $data['id'] ?? '',
            'session_url' => $data['url'] ?? '',
        ];
    }

    /**
     * 创建 Payment Intent（用于自定义集成）
     *
     * @return array{client_secret: string, payment_intent_id: string}
     */
    public function createPaymentIntent(int $tenantId, float $amount, string $currency = 'CNY'): array
    {
        $secretKey = $this->getSecretKey($tenantId);

        $resp = Http::withToken($secretKey)
            ->asForm()
            ->post(self::BASE_URL . '/v1/payment_intents', [
                'amount' => intval($amount * 100),
                'currency' => strtolower($currency),
                'automatic_payment_methods' => ['enabled' => 'true'],
            ]);

        if (! $resp->successful()) {
            throw new \RuntimeException(trans('payment.stripe_intent_failed') . ': ' . $resp->body());
        }

        $data = $resp->json();

        return [
            'client_secret' => $data['client_secret'] ?? '',
            'payment_intent_id' => $data['id'] ?? '',
        ];
    }

    /**
     * 退款（全额或部分）
     *
     * @param  int  $tenantId  租户 ID
     * @param  string  $paymentIntentId  Stripe payment intent ID
     * @param  float  $amount  退款金额（0 表示全额）
     */
    public function refund(int $tenantId, string $paymentIntentId, float $amount = 0): array
    {
        $secretKey = $this->getSecretKey($tenantId);

        $payload = ['payment_intent' => $paymentIntentId];
        if ($amount > 0) {
            $payload['amount'] = intval($amount * 100);
        }

        $resp = Http::withToken($secretKey)
            ->asForm()
            ->post(self::BASE_URL . '/v1/refunds', $payload);

        if (! $resp->successful()) {
            throw new \RuntimeException(trans('payment.stripe_refund_failed') . ': ' . $resp->body());
        }

        $data = $resp->json();

        return [
            'refund_id' => $data['id'] ?? '',
            'status' => $data['status'] ?? '',
            'amount' => ($data['amount'] ?? 0) / 100,
            'raw' => $data,
        ];
    }

    /**
     * 处理 Webhook 通知（需先验证签名）
     *
     * @param  int  $tenantId  租户 ID
     * @param  array  $payload  Webhook 载荷
     * @param  string  $signatureHeader  Stripe-Signature 头
     * @return array{event_type: string, order_no: string|null, status: string}
     *
     * @throws \RuntimeException 签名校验失败
     */
    public function handleWebhook(int $tenantId, array $payload, string $signatureHeader): array
    {
        if (! $this->verifyWebhookSignature($tenantId, $payload, $signatureHeader)) {
            throw new \RuntimeException(trans('payment.stripe_signature_invalid'));
        }

        $eventType = $payload['type'] ?? '';
        $data = $payload['data']['object'] ?? [];

        $orderNo = $data['client_reference_id'] ?? $data['metadata']['order_no'] ?? null;
        $status = match ($eventType) {
            'checkout.session.completed' => 'paid',
            'payment_intent.succeeded' => 'paid',
            'charge.refunded' => 'refunded',
            'payment_intent.payment_failed' => 'failed',
            default => 'unknown',
        };

        return [
            'event_type' => $eventType,
            'order_no' => $orderNo,
            'status' => $status,
        ];
    }

    /**
     * 验证 Webhook 签名
     */
    protected function verifyWebhookSignature(int $tenantId, array $payload, string $signatureHeader): bool
    {
        $webhookSecret = TenantSetting::get($tenantId, 'payment', 'stripe_webhook_secret', '');

        if (empty($webhookSecret)) {
            throw new \RuntimeException(trans('payment.stripe_webhook_secret_not_configured'));
        }

        // Stripe 签名格式：t=...,v1=...
        $elements = explode(',', $signatureHeader);
        $timestamp = null;
        $signatures = [];
        foreach ($elements as $element) {
            [$key, $value] = explode('=', $element, 2);
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if (! $timestamp || empty($signatures)) {
            return false;
        }

        $signedPayload = $timestamp . '.' . json_encode($payload);
        $expectedSignature = hash_hmac('sha256', $signedPayload, $webhookSecret);

        return in_array($expectedSignature, $signatures, true);
    }
}
