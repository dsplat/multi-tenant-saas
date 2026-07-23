<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Modules\Billing\Services\PayPalService;
use MultiTenantSaas\Modules\Billing\Services\PayService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Tests\Schema\WebhookModule;

/**
 * PayPalService 单元测试
 *
 * 覆盖：支付创建、退款处理、Webhook 签名验证
 *
 * 注意：PayPalService::getAccessToken 调用 PayService::exportPaymentConfig
 * 该方法在当前代码中不存在，测试通过 Mockery 部分模拟绕过此调用。
 */
class PayPalServiceTest extends TestCase
{
    protected PayService $payService;

    protected array $uses = [WebhookModule::class];

    private const TENANT_ID = 1001;

    private const ACCESS_TOKEN = 'pp_access_token_mock';

    protected function setUp(): void
    {
        parent::setUp();

        $this->payService = $this->app->make(PayService::class);


        Tenant::create([
            'tenant_id' => self::TENANT_ID,
            'name' => 'PayPal Tenant',
            'slug' => 'paypal-tenant',
            'status' => 'active',
        ]);

        TenantSetting::set(self::TENANT_ID, 'payment', 'paypal_client_id', 'client_id_mock');
        TenantSetting::set(self::TENANT_ID, 'payment', 'paypal_client_secret', 'client_secret_mock');
        TenantSetting::set(self::TENANT_ID, 'payment', 'paypal_mode', 'sandbox');
        TenantSetting::set(self::TENANT_ID, 'payment', 'paypal_return_url', 'https://example.com/return');
        TenantSetting::set(self::TENANT_ID, 'payment', 'paypal_cancel_url', 'https://example.com/cancel');
        TenantSetting::set(self::TENANT_ID, 'payment', 'paypal_webhook_id', 'webhook_id_mock');
    }

    /**
     * 创建 PayPalService 的部分模拟，绕过 getAccessToken 中对 PayService::exportPaymentConfig 的调用
     */
    private function makeServiceWithMockToken(): PayPalService
    {
        $mock = \Mockery::mock(PayPalService::class)->makePartial();
        $mock->shouldReceive('getAccessToken')->andReturn(self::ACCESS_TOKEN);

        return $mock;
    }

    // ---------- 支付创建 ----------

    public function test_create_order_returns_paypal_order_id_and_approval_url(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'PAYPAL-ORD-001',
                'links' => [
                    ['rel' => 'approve', 'href' => 'https://sandbox.paypal.com/approve/PAYPAL-ORD-001'],
                    ['rel' => 'self', 'href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/PAYPAL-ORD-001'],
                ],
            ], 201),
        ]);

        $service = $this->makeServiceWithMockToken();

        $result = $service->createOrder(self::TENANT_ID, 49.99, 'ORD-PP-001', 'Test Product');

        $this->assertEquals('PAYPAL-ORD-001', $result['paypal_order_id']);
        $this->assertEquals('https://sandbox.paypal.com/approve/PAYPAL-ORD-001', $result['approval_url']);
    }

    public function test_create_order_throws_on_api_error(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response(['error' => 'invalid'], 400),
        ]);

        $service = $this->makeServiceWithMockToken();

        $this->expectException(\RuntimeException::class);
        $service->createOrder(self::TENANT_ID, 49.99, 'ORD-PP-ERR', 'Test');
    }

    // ---------- 退款 ----------

    public function test_refund_full_amount_succeeds(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v2/payments/captures/*/refund' => Http::response([
                'id' => 'PP-REFUND-001',
                'status' => 'COMPLETED',
            ], 201),
        ]);

        $service = $this->makeServiceWithMockToken();

        $result = $service->refund(self::TENANT_ID, 'CAPTURE-001');

        $this->assertEquals('PP-REFUND-001', $result['refund_id']);
        $this->assertEquals('COMPLETED', $result['status']);
    }

    public function test_refund_partial_amount_succeeds(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v2/payments/captures/*/refund' => Http::response([
                'id' => 'PP-REFUND-002',
                'status' => 'PENDING',
            ], 201),
        ]);

        $service = $this->makeServiceWithMockToken();

        $result = $service->refund(self::TENANT_ID, 'CAPTURE-002', 20.00);

        $this->assertEquals('PP-REFUND-002', $result['refund_id']);
        $this->assertEquals('PENDING', $result['status']);
    }

    public function test_refund_throws_on_api_error(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v2/payments/captures/*/refund' => Http::response(['error' => 'denied'], 400),
        ]);

        $service = $this->makeServiceWithMockToken();

        $this->expectException(\RuntimeException::class);
        $service->refund(self::TENANT_ID, 'CAPTURE-003');
    }

    // ---------- Webhook 签名验证 ----------

    public function test_handle_webhook_with_valid_verification(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'SUCCESS',
            ], 200),
        ]);

        $payload = [
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'reference_id' => 'ORD-PP-001',
            ],
        ];

        $headers = [
            'paypal-auth-algo' => 'SHA256withRSA',
            'paypal-cert-url' => 'https://cert.paypal.com/cert.pem',
            'paypal-transmission-id' => 'trans-001',
            'paypal-transmission-sig' => 'sig-001',
            'paypal-transmission-time' => '2026-06-27T00:00:00Z',
        ];

        $service = $this->makeServiceWithMockToken();

        $result = $service->handleWebhook(self::TENANT_ID, $payload, $headers);

        $this->assertEquals('PAYMENT.CAPTURE.COMPLETED', $result['event_type']);
        $this->assertEquals('ORD-PP-001', $result['order_no']);
        $this->assertEquals('paid', $result['status']);
    }

    public function test_handle_webhook_throws_on_invalid_verification(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'FAILURE',
            ], 200),
        ]);

        $service = $this->makeServiceWithMockToken();

        $this->expectException(\RuntimeException::class);
        $service->handleWebhook(self::TENANT_ID, ['event_type' => 'TEST'], [
            'paypal-auth-algo' => 'test',
            'paypal-cert-url' => 'test',
            'paypal-transmission-id' => 'test',
            'paypal-transmission-sig' => 'test',
            'paypal-transmission-time' => 'test',
        ]);
    }

    public function test_handle_webhook_throws_when_webhook_id_not_configured(): void
    {
        TenantSetting::remove(self::TENANT_ID, 'payment', 'paypal_webhook_id');

        $service = $this->makeServiceWithMockToken();

        $this->expectException(\RuntimeException::class);
        $service->handleWebhook(self::TENANT_ID, [], []);
    }

    public function test_handle_webhook_maps_event_types(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'SUCCESS',
            ], 200),
        ]);

        $eventTypes = [
            'CHECKOUT.ORDER.APPROVED' => 'approved',
            'PAYMENT.CAPTURE.COMPLETED' => 'paid',
            'PAYMENT.CAPTURE.REFUNDED' => 'refunded',
            'PAYMENT.CAPTURE.DENIED' => 'failed',
            'UNKNOWN.EVENT' => 'unknown',
        ];

        $service = $this->makeServiceWithMockToken();

        foreach ($eventTypes as $eventType => $expectedStatus) {
            $payload = ['event_type' => $eventType, 'resource' => []];

            $result = $service->handleWebhook(self::TENANT_ID, $payload, [
                'paypal-auth-algo' => 'test',
                'paypal-cert-url' => 'test',
                'paypal-transmission-id' => 'test',
                'paypal-transmission-sig' => 'test',
                'paypal-transmission-time' => 'test',
            ]);

            $this->assertEquals($expectedStatus, $result['status'], "Event type {$eventType} should map to {$expectedStatus}");
        }
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
