<?php

namespace MultiTenantSaas\Tests\Logistics;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Logistics\Services\ShipmentService;
use MultiTenantSaas\Modules\Order\Models\Order;
use MultiTenantSaas\Tests\Schema\LogisticsModule;
use MultiTenantSaas\Tests\Schema\OrderModule;
use MultiTenantSaas\Tests\Schema\PayModule;
use MultiTenantSaas\Tests\Schema\ProductModule;
use MultiTenantSaas\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Logistics 模块测试：发货登记、状态流转（pending→shipped→delivered / cancelled）
 */
class LogisticsModuleTest extends TestCase
{
    protected array $uses = [
        ProductModule::class,
        PayModule::class,
        OrderModule::class,
        LogisticsModule::class,
    ];

    protected const TENANT_ID = 3401;

    protected ShipmentService $shipmentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shipmentService = $this->app->make(ShipmentService::class);

        Tenant::create([
            'tenant_id' => self::TENANT_ID,
            'name' => 'Logistics Tenant',
            'slug' => 'logistics-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId((string) self::TENANT_ID);
    }

    /** 造一个已支付订单 */
    private function makePaidOrder(): Order
    {
        return Order::create([
            'order_id' => $this->app->make(\MultiTenantSaas\Contracts\IdGeneratorContract::class)->generate(),
            'tenant_id' => self::TENANT_ID,
            'user_id' => 5,
            'order_no' => Order::generateOrderNo(),
            'order_type' => Order::TYPE_PRODUCT,
            'total_amount' => 99,
            'pay_method' => Order::PAY_CASH,
            'status' => Order::STATUS_PAID,
            'paid_at' => now(),
        ]);
    }

    public function test_create_shipment_requires_paid_order(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->shipmentService->createShipment(self::TENANT_ID, [
            'order_no' => 'NOT_EXIST',
        ]);
    }

    public function test_create_shipment_rejects_unpaid_order(): void
    {
        $order = Order::create([
            'order_id' => $this->app->make(\MultiTenantSaas\Contracts\IdGeneratorContract::class)->generate(),
            'tenant_id' => self::TENANT_ID,
            'user_id' => 5,
            'order_no' => Order::generateOrderNo(),
            'order_type' => Order::TYPE_PRODUCT,
            'total_amount' => 99,
            'pay_method' => Order::PAY_CASH,
            'status' => Order::STATUS_PENDING,
        ]);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->shipmentService->createShipment(self::TENANT_ID, [
            'order_no' => $order->order_no,
        ]);
    }

    public function test_shipment_lifecycle_ship_then_deliver(): void
    {
        $order = $this->makePaidOrder();

        $shipment = $this->shipmentService->createShipment(self::TENANT_ID, [
            'order_no' => $order->order_no,
            'receiver_name' => '张三',
            'receiver_phone' => '13800000000',
            'receiver_address' => '测试地址',
        ]);

        $this->assertSame('pending', $shipment->status);
        $this->assertSame($order->order_id, (int) $shipment->order_id);

        $shipped = $this->shipmentService->ship(self::TENANT_ID, $shipment->shipment_id, '顺丰', 'SF123456');
        $this->assertSame('shipped', $shipped->status);
        $this->assertSame('SF123456', $shipped->tracking_no);
        $this->assertNotNull($shipped->shipped_at);

        $delivered = $this->shipmentService->deliver(self::TENANT_ID, $shipment->shipment_id);
        $this->assertSame('delivered', $delivered->status);
        $this->assertNotNull($delivered->delivered_at);

        $list = $this->shipmentService->listByOrder(self::TENANT_ID, $order->order_no);
        $this->assertCount(1, $list);
    }

    public function test_invalid_transition_throws(): void
    {
        $order = $this->makePaidOrder();

        $shipment = $this->shipmentService->createShipment(self::TENANT_ID, [
            'order_no' => $order->order_no,
        ]);

        // pending 状态不允许直接签收
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->shipmentService->deliver(self::TENANT_ID, $shipment->shipment_id);
    }

    public function test_cancel_pending_shipment(): void
    {
        $order = $this->makePaidOrder();

        $shipment = $this->shipmentService->createShipment(self::TENANT_ID, [
            'order_no' => $order->order_no,
        ]);

        $cancelled = $this->shipmentService->cancel(self::TENANT_ID, $shipment->shipment_id);
        $this->assertSame('cancelled', $cancelled->status);

        // 已取消不能再发货
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->shipmentService->ship(self::TENANT_ID, $shipment->shipment_id, null, null);
    }
}
