<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Modules\Billing\Services\StripeService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Tests\Schema\WebhookModule;

/**
 * StripeService 单元测试
 *
 * 覆盖：Checkout Session 创建、Payment Intent 创建、退款处理、Webhook 签名验证
 */
class StripeServiceTest extends TestCase
{
    protected array $uses = [WebhookModule::class];

    private const TENANT_ID = 1001;

    private const STRIPE_SECRET = 'sk_test_secret_key';

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => self::TENANT_ID,
            'name' => 'Stripe Tenant',
            'slug' => 'stripe-tenant',
            'status' => 'active',
        ]);

        TenantSetting::set(self::TENANT_ID, 'payment', 'stripe_secret_key', self::STRIPE_SECRET);
        TenantSetting::set(self::TENANT_ID, 'payment', 'stripe_return_url', 'https://example.com/return');
        TenantSetting::set(self::TENANT_ID, 'payment', 'stripe_webhook_secret', self::WEBHOOK_SECRET);
    }

    // ---------- Checkout Session ----------

    public function test_create_checkout_session_returns_session_id_and_url(): void
    {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_test_12345',
                'url' => 'https://checkout.stripe.com/pay/cs_test_12345',
            ], 200),
        ]);

        $service = app(StripeService::class);

        $result = $service->createCheckoutSession(self::TENANT_ID, 99.50, 'ORD-STR-001', 'Test Product');

        $this->assertEquals('cs_test_12345', $result['session_id']);
        $this->assertEquals('https://checkout.stripe.com/pay/cs_test_12345', $result['session_url']);
    }

    public function test_create_checkout_session_throws_on_api_error(): void
    {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response(['error' => 'invalid'], 400),
        ]);

        $service = app(StripeService::class);

        $this->expectException(\RuntimeException::class);
        $service->createCheckoutSession(self::TENANT_ID, 99.50, 'ORD-STR-ERR', 'Test');
    }

    public function test_create_checkout_session_throws_when_secret_not_configured(): void
    {
        TenantSetting::remove(self::TENANT_ID, 'payment', 'stripe_secret_key');

        $service = app(StripeService::class);

        $this->expectException(\RuntimeException::class);
        $service->createCheckoutSession(self::TENANT_ID, 99.50, 'ORD-STR-NOKEY', 'Test');
    }

    // ---------- Payment Intent ----------

    public function test_create_payment_intent_returns_client_secret(): void
    {
        Http::fake([
            'api.stripe.com/v1/payment_intents' => Http::response([
                'id' => 'pi_test_67890',
                'client_secret' => 'pi_test_67890_secret_abc',
            ], 200),
        ]);

        $service = app(StripeService::class);

        $result = $service->createPaymentIntent(self::TENANT_ID, 200.00);

        $this->assertEquals('pi_test_67890', $result['payment_intent_id']);
        $this->assertEquals('pi_test_67890_secret_abc', $result['client_secret']);
    }

    public function test_create_payment_intent_throws_on_api_error(): void
    {
        Http::fake([
            'api.stripe.com/v1/payment_intents' => Http::response(['error' => 'card_declined'], 402),
        ]);

        $service = app(StripeService::class);

        $this->expectException(\RuntimeException::class);
        $service->createPaymentIntent(self::TENANT_ID, 200.00);
    }

    // ---------- 退款 ----------

    public function test_refund_full_amount_succeeds(): void
    {
        Http::fake([
            'api.stripe.com/v1/refunds' => Http::response([
                'id' => 're_test_11111',
                'status' => 'succeeded',
                'amount' => 5000,
            ], 200),
        ]);

        $service = app(StripeService::class);

        $result = $service->refund(self::TENANT_ID, 'pi_test_67890');

        $this->assertEquals('re_test_11111', $result['refund_id']);
        $this->assertEquals('succeeded', $result['status']);
        $this->assertEquals(50.00, $result['amount']);
    }

    public function test_refund_partial_amount_succeeds(): void
    {
        Http::fake([
            'api.stripe.com/v1/refunds' => function ($request) {
                $body = $request->data();

                return Http::response([
                    'id' => 're_test_22222',
                    'status' => 'succeeded',
                    'amount' => $body['amount'] ?? 0,
                ], 200);
            },
        ]);

        $service = app(StripeService::class);

        $result = $service->refund(self::TENANT_ID, 'pi_test_67890', 30.00);

        $this->assertEquals('re_test_22222', $result['refund_id']);
        $this->assertEquals(30.00, $result['amount']);
    }

    public function test_refund_throws_on_api_error(): void
    {
        Http::fake([
            'api.stripe.com/v1/refunds' => Http::response(['error' => 'already_refunded'], 400),
        ]);

        $service = app(StripeService::class);

        $this->expectException(\RuntimeException::class);
        $service->refund(self::TENANT_ID, 'pi_test_67890');
    }

    // ---------- Webhook 签名验证 ----------

    public function test_handle_webhook_with_valid_signature(): void
    {
        $payload = [
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'client_reference_id' => 'ORD-STR-001',
                ],
            ],
        ];

        $timestamp = time();
        $signedPayload = $timestamp . '.' . json_encode($payload);
        $signature = hash_hmac('sha256', $signedPayload, self::WEBHOOK_SECRET);
        $signatureHeader = "t={$timestamp},v1={$signature}";

        $service = app(StripeService::class);

        $result = $service->handleWebhook(self::TENANT_ID, $payload, $signatureHeader);

        $this->assertEquals('checkout.session.completed', $result['event_type']);
        $this->assertEquals('ORD-STR-001', $result['order_no']);
        $this->assertEquals('paid', $result['status']);
    }

    public function test_handle_webhook_with_invalid_signature_throws(): void
    {
        $payload = ['type' => 'checkout.session.completed', 'data' => ['object' => []]];

        $service = app(StripeService::class);

        $this->expectException(\RuntimeException::class);
        $service->handleWebhook(self::TENANT_ID, $payload, 't=123,v1=invalid_signature');
    }

    public function test_handle_webhook_throws_when_secret_not_configured(): void
    {
        TenantSetting::remove(self::TENANT_ID, 'payment', 'stripe_webhook_secret');

        $service = app(StripeService::class);

        $this->expectException(\RuntimeException::class);
        $service->handleWebhook(self::TENANT_ID, [], 't=123,v1=fake');
    }

    public function test_handle_webhook_maps_event_types(): void
    {
        $eventTypes = [
            'checkout.session.completed' => 'paid',
            'payment_intent.succeeded' => 'paid',
            'charge.refunded' => 'refunded',
            'payment_intent.payment_failed' => 'failed',
            'unknown.event' => 'unknown',
        ];

        $service = app(StripeService::class);

        foreach ($eventTypes as $eventType => $expectedStatus) {
            $payload = ['type' => $eventType, 'data' => ['object' => []]];

            $timestamp = time();
            $signedPayload = $timestamp . '.' . json_encode($payload);
            $signature = hash_hmac('sha256', $signedPayload, self::WEBHOOK_SECRET);
            $signatureHeader = "t={$timestamp},v1={$signature}";

            $result = $service->handleWebhook(self::TENANT_ID, $payload, $signatureHeader);

            $this->assertEquals($expectedStatus, $result['status'], "Event type {$eventType} should map to {$expectedStatus}");
        }
    }
}
