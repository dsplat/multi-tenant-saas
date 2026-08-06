<?php

namespace MultiTenantSaas\Tests\Pay;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Pay\Contracts\VirtualPayChannelContract;
use MultiTenantSaas\Modules\Pay\Services\SalesConfigService;
use MultiTenantSaas\Modules\Pay\Services\TradePayService;
use MultiTenantSaas\Modules\Pay\Services\VirtualPayChannelRegistry;
use MultiTenantSaas\Tests\Schema\PayModule;
use MultiTenantSaas\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Pay 模块测试：SalesConfig、三支付路径拆分、虚拟渠道 Registry
 */
class PayModuleTest extends TestCase
{
    protected array $uses = [PayModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => 3101,
            'name' => 'Pay Tenant',
            'slug' => 'pay-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId('3101');
    }

    /** 内存虚拟渠道（模拟积分） */
    private function registerFakeChannel(int $balance): FakeVirtualChannel
    {
        $channel = new FakeVirtualChannel($balance);
        $this->app->make(VirtualPayChannelRegistry::class)->register($channel);

        return $channel;
    }

    public function test_sales_config_defaults_and_update(): void
    {
        $service = $this->app->make(SalesConfigService::class);

        $config = $service->getConfig(3101);
        $this->assertFalse($config['mixed_pay_enabled']);
        $this->assertSame(100, $config['points_to_cash_ratio']);
        $this->assertSame(50, $config['max_points_deduct_ratio']);

        $updated = $service->updateConfig(3101, [
            'mixed_pay_enabled' => true,
            'points_to_cash_ratio' => 200,
        ]);
        $this->assertTrue($updated['mixed_pay_enabled']);
        $this->assertSame(200, $updated['points_to_cash_ratio']);

        // 每租户一行，再次更新不新增
        $service->updateConfig(3101, ['max_points_deduct_ratio' => 30]);
        $this->assertSame(1, \MultiTenantSaas\Modules\Pay\Models\SalesConfig::count());
    }

    public function test_split_payment_cash(): void
    {
        $service = $this->app->make(TradePayService::class);

        [$cash, $points] = $service->splitPayment(3101, 1, 'cash', 100.55, 1000);
        $this->assertEquals(100.55, $cash);
        $this->assertSame(0, $points);
    }

    public function test_split_payment_points(): void
    {
        $service = $this->app->make(TradePayService::class);

        [$cash, $points] = $service->splitPayment(3101, 1, 'points', 100, 800);
        $this->assertEquals(0.0, $cash);
        $this->assertSame(800, $points);
    }

    public function test_split_payment_mixed_with_deduct_cap(): void
    {
        // 开启混合支付：100 积分=1 元，最高抵扣 50%
        $this->app->make(SalesConfigService::class)->updateConfig(3101, ['mixed_pay_enabled' => true]);
        $this->registerFakeChannel(100000);

        $service = $this->app->make(TradePayService::class);

        // 想用 10000 积分抵 100 元，但上限 50 元 → 实抵 50 元、消耗 5000 积分、现金补差 50
        [$cash, $points] = $service->splitPayment(3101, 1, 'mixed', 100.0, 0, 10000);
        $this->assertEquals(50.0, $cash);
        $this->assertSame(5000, $points);
    }

    public function test_mixed_pay_requires_enabled_config(): void
    {
        $this->registerFakeChannel(100000);
        $service = $this->app->make(TradePayService::class);

        $this->expectException(UnprocessableEntityHttpException::class);
        $service->splitPayment(3101, 1, 'mixed', 100.0, 0, 100);
    }

    public function test_consume_virtual_without_channel_throws(): void
    {
        $service = $this->app->make(TradePayService::class);

        $this->expectException(UnprocessableEntityHttpException::class);
        $service->consumeVirtual(3101, 1, 100, 'ORD_TEST');
    }

    public function test_consume_and_refund_virtual_via_channel(): void
    {
        $channel = $this->registerFakeChannel(500);
        $service = $this->app->make(TradePayService::class);

        $service->consumeVirtual(3101, 1, 300, 'ORD_A');
        $this->assertSame(200, $channel->balance);

        $service->refundVirtual(3101, 1, 300, 'ORD_A');
        $this->assertSame(500, $channel->balance);
    }

    public function test_consume_virtual_insufficient_balance(): void
    {
        $this->registerFakeChannel(10);
        $service = $this->app->make(TradePayService::class);

        $this->expectException(UnprocessableEntityHttpException::class);
        $service->consumeVirtual(3101, 1, 100, 'ORD_B');
    }
}

/** 测试用虚拟渠道 */
class FakeVirtualChannel implements VirtualPayChannelContract
{
    public function __construct(public int $balance) {}

    public function name(): string
    {
        return 'points';
    }

    public function getBalance(int $tenantId, int $userId): int
    {
        return $this->balance;
    }

    public function consume(int $tenantId, int $userId, int $amount, string $orderNo): void
    {
        if ($this->balance < $amount) {
            throw new UnprocessableEntityHttpException('Insufficient virtual balance');
        }
        $this->balance -= $amount;
    }

    public function refund(int $tenantId, int $userId, int $amount, string $orderNo): void
    {
        $this->balance += $amount;
    }
}
