<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TenantSetting;
use MultiTenantSaas\Services\UnionPayService;

/**
 * UnionPayService 单元测试
 *
 * 覆盖：支付创建、RSA 签名验证
 */
class UnionPayServiceTest extends TestCase
{
    private const TENANT_ID = 1001;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => self::TENANT_ID,
            'name' => 'UnionPay Tenant',
            'slug' => 'unionpay-tenant',
            'status' => 'active',
        ]);

        TenantSetting::set(self::TENANT_ID, 'payment', 'unionpay_mer_id', '777290000012345');
        TenantSetting::set(self::TENANT_ID, 'payment', 'unionpay_mode', 'test');
        TenantSetting::set(self::TENANT_ID, 'payment', 'unionpay_notify_url', 'https://example.com/notify');
        TenantSetting::set(self::TENANT_ID, 'payment', 'unionpay_return_url', 'https://example.com/return');
    }

    // ---------- 支付创建 ----------

    public function test_create_order_returns_params_and_gateway_url(): void
    {
        $service = app(UnionPayService::class);

        $result = $service->createOrder(self::TENANT_ID, 100.00, 'ORD-UP-001', 'Test Product');

        $this->assertArrayHasKey('params', $result);
        $this->assertArrayHasKey('gateway_url', $result);
        $this->assertEquals('https://gateway.test.95516.com/gateway/api/frontTransReq.do', $result['gateway_url']);

        $params = $result['params'];
        $this->assertEquals('5.1.0', $params['version']);
        $this->assertEquals('777290000012345', $params['merId']);
        $this->assertEquals('ORD-UP-001', $params['orderId']);
        $this->assertEquals(10000, $params['txnAmt']);
        $this->assertEquals('01', $params['signMethod']);
        $this->assertArrayHasKey('signature', $params);
    }

    public function test_create_order_throws_when_mer_id_not_configured(): void
    {
        TenantSetting::remove(self::TENANT_ID, 'payment', 'unionpay_mer_id');

        $service = app(UnionPayService::class);

        $this->expectException(\RuntimeException::class);
        $service->createOrder(self::TENANT_ID, 100.00, 'ORD-UP-NOKEY', 'Test');
    }

    public function test_create_order_signature_is_empty_when_cert_not_configured(): void
    {
        $service = app(UnionPayService::class);

        $result = $service->createOrder(self::TENANT_ID, 50.00, 'ORD-UP-NOCERT', 'Test');

        $this->assertEquals('', $result['params']['signature']);
    }

    // ---------- 订单查询 ----------

    public function test_query_order_returns_status(): void
    {
        Http::fake([
            'gateway.test.95516.com/gateway/api/queryTrans.do' => Http::response(
                'respCode=00&queryId=UP-QUERY-001&orderId=ORD-UP-001',
                200
            ),
        ]);

        $service = app(UnionPayService::class);

        $result = $service->queryOrder(self::TENANT_ID, 'ORD-UP-001', now()->format('YmdHis'));

        $this->assertEquals('ORD-UP-001', $result['order_no']);
        $this->assertEquals('paid', $result['status']);
        $this->assertEquals('UP-QUERY-001', $result['query_id']);
    }

    public function test_query_order_throws_on_api_error(): void
    {
        Http::fake([
            'gateway.test.95516.com/gateway/api/queryTrans.do' => Http::response('error', 500),
        ]);

        $service = app(UnionPayService::class);

        $this->expectException(\RuntimeException::class);
        $service->queryOrder(self::TENANT_ID, 'ORD-UP-FAIL', now()->format('YmdHis'));
    }

    // ---------- 退款 ----------

    public function test_refund_succeeds(): void
    {
        Http::fake([
            'gateway.test.95516.com/gateway/api/visualizationTransReq.do' => Http::response(
                'respCode=00&queryId=UP-REFUND-001',
                200
            ),
        ]);

        $service = app(UnionPayService::class);

        $result = $service->refund(self::TENANT_ID, 'ORD-UP-001', 'UP-QID-001', now()->format('YmdHis'), 50.00);

        $this->assertEquals('paid', $result['status']);
        $this->assertNotEmpty($result['refund_no']);
    }

    public function test_refund_throws_on_api_error(): void
    {
        Http::fake([
            'gateway.test.95516.com/gateway/api/visualizationTransReq.do' => Http::response('error', 500),
        ]);

        $service = app(UnionPayService::class);

        $this->expectException(\RuntimeException::class);
        $service->refund(self::TENANT_ID, 'ORD-UP-001', 'UP-QID-001', now()->format('YmdHis'), 50.00);
    }

    // ---------- 异步通知验签 ----------

    public function test_handle_notify_throws_when_verify_cert_not_configured(): void
    {
        $service = app(UnionPayService::class);

        $this->expectException(\RuntimeException::class);
        $service->handleNotify(self::TENANT_ID, [
            'orderId' => 'ORD-UP-001',
            'respCode' => '00',
            'queryId' => 'UP-001',
            'signature' => 'fake_sig',
        ]);
    }

    public function test_handle_notify_throws_with_empty_signature(): void
    {
        TenantSetting::set(self::TENANT_ID, 'payment', 'unionpay_verify_cert_path', '/nonexistent/cert.pem');

        $service = app(UnionPayService::class);

        $this->expectException(\RuntimeException::class);
        $service->handleNotify(self::TENANT_ID, [
            'orderId' => 'ORD-UP-001',
            'respCode' => '00',
            'queryId' => 'UP-001',
        ]);
    }

    // ---------- 状态映射 ----------

    public function test_map_status_maps_response_codes(): void
    {
        $service = app(UnionPayService::class);

        $cases = [
            ['respCode' => '00', 'expected' => 'paid'],
            ['respCode' => '03', 'expected' => 'pending'],
            ['respCode' => '04', 'expected' => 'pending'],
            ['respCode' => '05', 'expected' => 'pending'],
            ['respCode' => '01', 'expected' => 'failed'],
            ['respCode' => '02', 'expected' => 'failed'],
            ['respCode' => '99', 'expected' => 'unknown'],
        ];

        $index = 0;
        Http::fake(function () use (&$index, $cases) {
            $code = $cases[$index]['respCode'] ?? '99';
            $index++;

            return Http::response("respCode={$code}&queryId=Q-{$code}&orderId=ORD-MAP-001", 200);
        });

        foreach ($cases as $case) {
            $result = $service->queryOrder(self::TENANT_ID, 'ORD-MAP-001', now()->format('YmdHis'));
            $this->assertEquals($case['expected'], $result['status'], "respCode {$case['respCode']} should map to {$case['expected']}");
        }
    }
}
