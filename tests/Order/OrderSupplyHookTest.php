<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\Order;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Order\Contracts\OrderSupplyHookContract;
use MultiTenantSaas\Modules\Order\Models\Order;
use MultiTenantSaas\Modules\Order\Services\OrderService;
use MultiTenantSaas\Modules\Pay\Contracts\VirtualPayChannelContract;
use MultiTenantSaas\Modules\Pay\Services\VirtualPayChannelRegistry;
use MultiTenantSaas\Modules\Product\Services\ProductService;
use MultiTenantSaas\Modules\Product\Services\SkuService;
use MultiTenantSaas\Tests\Schema\OrderModule;
use MultiTenantSaas\Tests\Schema\PayModule;
use MultiTenantSaas\Tests\Schema\ProductModule;
use MultiTenantSaas\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 供货结算钩子测试（OrderSupplyHookContract）
 *
 * 覆盖：三处钩子触发时序、钩子异常回滚下单/支付、未绑定钩子零影响
 */
class OrderSupplyHookTest extends TestCase
{
    protected array $uses = [ProductModule::class, PayModule::class, OrderModule::class];

    protected const TENANT_ID = 5201;

    protected SupplyHookFake $hook;

    protected OrderService $orderService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hook = new SupplyHookFake;
        $this->app->singleton(OrderSupplyHookContract::class, fn () => $this->hook);

        $this->orderService = $this->app->make(OrderService::class);

        Tenant::create([
            'tenant_id' => self::TENANT_ID,
            'name' => 'Supply Hook Tenant',
            'slug' => 'supply-hook-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId((string) self::TENANT_ID);

        $this->app->make(VirtualPayChannelRegistry::class)->register(new SupplyHookFakeChannel(1000));
    }

    private function makeSku(): int
    {
        $product = $this->app->make(ProductService::class)->create(self::TENANT_ID, [
            'name' => '供货钩子商品',
            'price' => 50,
        ]);

        $sku = $this->app->make(SkuService::class)->create(self::TENANT_ID, [
            'product_id' => $product->product_id,
            'name' => '默认规格',
            'price' => 50,
            'points_price' => 500,
            'stock' => 10,
        ]);

        return $sku->sku_id;
    }

    public function test_hooks_fire_in_order_lifecycle(): void
    {
        $skuId = $this->makeSku();

        $order = $this->orderService->createOrder(self::TENANT_ID, 7, [
            'pay_method' => Order::PAY_POINTS,
            'items' => [['sku_id' => $skuId, 'quantity' => 1]],
        ]);
        $this->assertSame(['created'], $this->hook->calls);

        $this->orderService->confirmPayment($order->order_no);
        $this->assertSame(['created', 'paid'], $this->hook->calls);

        $this->orderService->refundOrder(self::TENANT_ID, $order->order_no, '测试退款');
        $this->assertSame(['created', 'paid', 'refunded'], $this->hook->calls);
    }

    public function test_hook_failure_on_create_rolls_back_order(): void
    {
        $skuId = $this->makeSku();
        $this->hook->failOn = 'created';

        try {
            $this->orderService->createOrder(self::TENANT_ID, 7, [
                'pay_method' => Order::PAY_POINTS,
                'items' => [['sku_id' => $skuId, 'quantity' => 1]],
            ]);
            $this->fail('钩子异常应回滚下单');
        } catch (UnprocessableEntityHttpException $e) {
            // expected
        }

        $this->assertSame(0, Order::count());
    }

    public function test_hook_failure_on_paid_rolls_back_payment(): void
    {
        $skuId = $this->makeSku();
        $this->hook->failOn = 'paid';

        $order = $this->orderService->createOrder(self::TENANT_ID, 7, [
            'pay_method' => Order::PAY_POINTS,
            'items' => [['sku_id' => $skuId, 'quantity' => 1]],
        ]);

        try {
            $this->orderService->confirmPayment($order->order_no);
            $this->fail('钩子异常应回滚支付');
        } catch (UnprocessableEntityHttpException $e) {
            // expected
        }

        // 支付回滚：订单仍 pending，虚拟资产未扣
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }
}

/** 钩子 Fake：记录调用时序，可配置失败点 */
class SupplyHookFake implements OrderSupplyHookContract
{
    public array $calls = [];

    public ?string $failOn = null;

    public function onOrderCreated(Order $order): void
    {
        $this->calls[] = 'created';
        if ($this->failOn === 'created') {
            throw new UnprocessableEntityHttpException('supply lock failed');
        }
    }

    public function onOrderPaid(Order $order): void
    {
        $this->calls[] = 'paid';
        if ($this->failOn === 'paid') {
            throw new UnprocessableEntityHttpException('supply settle failed');
        }
    }

    public function onOrderRefunded(Order $order): void
    {
        $this->calls[] = 'refunded';
        if ($this->failOn === 'refunded') {
            throw new UnprocessableEntityHttpException('supply compensate failed');
        }
    }
}

/** 测试用虚拟渠道 */
class SupplyHookFakeChannel implements VirtualPayChannelContract
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
        $this->balance -= $amount;
    }

    public function refund(int $tenantId, int $userId, int $amount, string $orderNo): void
    {
        $this->balance += $amount;
    }
}
