<?php

namespace MultiTenantSaas\Tests\Order;

use Illuminate\Support\Facades\Event;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Order\Contracts\OrderFulfillmentHandlerContract;
use MultiTenantSaas\Modules\Order\Events\OrderPaid;
use MultiTenantSaas\Modules\Order\Events\OrderRefunded;
use MultiTenantSaas\Modules\Order\Models\ConsumptionRecord;
use MultiTenantSaas\Modules\Order\Models\Order;
use MultiTenantSaas\Modules\Order\Services\FulfillmentRegistry;
use MultiTenantSaas\Modules\Order\Services\OrderService;
use MultiTenantSaas\Modules\Pay\Contracts\VirtualPayChannelContract;
use MultiTenantSaas\Modules\Pay\Services\VirtualPayChannelRegistry;
use MultiTenantSaas\Modules\Product\Models\ProductSku;
use MultiTenantSaas\Modules\Product\Services\ProductService;
use MultiTenantSaas\Modules\Product\Services\SkuService;
use MultiTenantSaas\Tests\Schema\OrderModule;
use MultiTenantSaas\Tests\Schema\PayModule;
use MultiTenantSaas\Tests\Schema\ProductModule;
use MultiTenantSaas\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Order 模块测试：下单、虚拟支付确认、履约分发、消费流水、退款
 */
class OrderModuleTest extends TestCase
{
    protected array $uses = [ProductModule::class, PayModule::class, OrderModule::class];

    protected const TENANT_ID = 3201;

    protected OrderService $orderService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderService = $this->app->make(OrderService::class);

        Tenant::create([
            'tenant_id' => self::TENANT_ID,
            'name' => 'Order Tenant',
            'slug' => 'order-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId((string) self::TENANT_ID);
    }

    /** 建一个自建 SKU（含积分价与库存） */
    private function makeSku(int $stock = 10): ProductSku
    {
        $product = $this->app->make(ProductService::class)->create(self::TENANT_ID, [
            'name' => '订单测试商品',
            'price' => 50,
        ]);

        return $this->app->make(SkuService::class)->create(self::TENANT_ID, [
            'product_id' => $product->product_id,
            'name' => '默认规格',
            'price' => 50,
            'points_price' => 500,
            'stock' => $stock,
        ]);
    }

    private function registerFakeChannel(int $balance): OrderFakeChannel
    {
        $channel = new OrderFakeChannel($balance);
        $this->app->make(VirtualPayChannelRegistry::class)->register($channel);

        return $channel;
    }

    public function test_create_cash_order_totals_and_items(): void
    {
        $sku = $this->makeSku();

        $order = $this->orderService->createOrder(self::TENANT_ID, 1, [
            'order_type' => Order::TYPE_PRODUCT,
            'pay_method' => Order::PAY_CASH,
            'items' => [['sku_id' => $sku->sku_id, 'quantity' => 2]],
        ]);

        $this->assertSame(Order::STATUS_PENDING, $order->status);
        $this->assertEquals(100.00, (float) $order->total_amount);
        $this->assertSame(0, (int) $order->points_amount);
        $this->assertCount(1, $order->items);
        // 订单级主实体兜底：全 SKU 行 → entity_type='sku'（行级 entity 已收敛移除）
        $this->assertSame('sku', (string) $order->entity_type);
    }

    public function test_virtual_payment_confirms_and_dispatches_paid_event(): void
    {
        Event::fake([OrderPaid::class]);

        $channel = $this->registerFakeChannel(1000);
        $sku = $this->makeSku();

        $fulfillHandler = new OrderFakeFulfillmentHandler('sku');
        $this->app->make(FulfillmentRegistry::class)->register($fulfillHandler);

        $order = $this->orderService->createOrder(self::TENANT_ID, 7, [
            'pay_method' => Order::PAY_POINTS,
            'items' => [['sku_id' => $sku->sku_id, 'quantity' => 1]],
        ]);

        $this->assertEquals(0.0, (float) $order->total_amount);
        $this->assertSame(500, (int) $order->points_amount);

        $result = $this->orderService->initiatePayment(self::TENANT_ID, $order->order_no);
        $this->assertTrue($result['paid']);

        $order = $order->fresh();
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertNotNull($order->paid_at);

        // 虚拟资产扣减 + 库存扣减 + 履约 + 消费流水
        $this->assertSame(500, $channel->balance);
        $this->assertSame(9, (int) $sku->fresh()->stock);
        $this->assertSame([$order->order_no], $fulfillHandler->calls);
        $this->assertSame(1, ConsumptionRecord::where('order_id', $order->order_id)->count());

        Event::assertDispatched(OrderPaid::class, function (OrderPaid $e) use ($order) {
            return $e->orderNo === $order->order_no && $e->buyerUserId === 7;
        });
    }

    public function test_fulfillment_registry_dispatches_by_entity_type(): void
    {
        $this->registerFakeChannel(1000);

        $handler = new OrderFakeFulfillmentHandler('external');
        $this->app->make(FulfillmentRegistry::class)->register($handler);

        $order = $this->orderService->createOrder(self::TENANT_ID, 7, [
            'order_type' => Order::TYPE_COURSE,
            'pay_method' => Order::PAY_POINTS,
            'entity_type' => 'external',
            'entity_id' => 'ext-1',
            'items' => [[
                'item_name' => '外部直给行',
                'points_unit_price' => 100,
                'quantity' => 1,
            ]],
        ]);

        $this->orderService->confirmPayment($order->order_no);

        $this->assertSame([$order->order_no], $handler->calls);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_points_order_requires_points_price(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);

        $this->orderService->createOrder(self::TENANT_ID, 1, [
            'pay_method' => Order::PAY_POINTS,
            'items' => [['item_name' => '无积分价', 'unit_price' => 10]],
        ]);
    }

    public function test_refund_virtual_order_restores_stock_and_points(): void
    {
        Event::fake([OrderRefunded::class]);

        $channel = $this->registerFakeChannel(1000);
        $sku = $this->makeSku();

        $order = $this->orderService->createOrder(self::TENANT_ID, 7, [
            'pay_method' => Order::PAY_POINTS,
            'items' => [['sku_id' => $sku->sku_id, 'quantity' => 1]],
        ]);
        $this->orderService->initiatePayment(self::TENANT_ID, $order->order_no);
        $this->assertSame(500, $channel->balance);
        $this->assertSame(9, (int) $sku->fresh()->stock);

        $refunded = $this->orderService->refundOrder(self::TENANT_ID, $order->order_no, '用户申请');

        $this->assertSame(Order::STATUS_REFUNDED, $refunded->status);
        $this->assertNotNull($refunded->refunded_at);
        $this->assertSame(1000, $channel->balance);
        $this->assertSame(10, (int) $sku->fresh()->stock);

        Event::assertDispatched(OrderRefunded::class, function (OrderRefunded $e) use ($order) {
            return $e->orderNo === $order->order_no && $e->pointsAmount === 500;
        });
    }

    public function test_refund_pending_order_rejected(): void
    {
        $sku = $this->makeSku();

        $order = $this->orderService->createOrder(self::TENANT_ID, 1, [
            'pay_method' => Order::PAY_CASH,
            'items' => [['sku_id' => $sku->sku_id, 'quantity' => 1]],
        ]);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->orderService->refundOrder(self::TENANT_ID, $order->order_no);
    }
}

/** 测试用虚拟渠道 */
class OrderFakeChannel implements VirtualPayChannelContract
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

/** 测试用履约 Handler */
class OrderFakeFulfillmentHandler implements OrderFulfillmentHandlerContract
{
    public array $calls = [];

    public function __construct(private string $type) {}

    public function entityType(): string
    {
        return $this->type;
    }

    public function fulfill(Order $order, mixed $item): void
    {
        $this->calls[] = $order->order_no;
    }
}
