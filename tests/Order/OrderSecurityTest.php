<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\Order;

use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Order\Http\Controllers\OrderController;
use MultiTenantSaas\Modules\Order\Models\Order;
use MultiTenantSaas\Modules\Order\Services\OrderService;
use MultiTenantSaas\Modules\Product\Services\ProductService;
use MultiTenantSaas\Modules\Product\Services\SkuService;
use MultiTenantSaas\Tests\Schema\OrderModule;
use MultiTenantSaas\Tests\Schema\ProductModule;
use MultiTenantSaas\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 订单安全测试（缺陷审计 C1/C2/C3 回归）：
 * - C1：pay-notify 必须携带 X-Pay-Notify-Key 共享密钥（未配置/错误均拒绝）
 * - C2：C 端 User 仅可支付本人订单（Operator 代付不校归属）
 * - C3：退款为运营专属操作（非 Operator 拒绝）
 */
class OrderSecurityTest extends TestCase
{
    protected array $uses = [ProductModule::class, OrderModule::class];

    protected const TENANT_ID = 3401;

    protected OrderService $orderService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderService = $this->app->make(OrderService::class);

        Tenant::create([
            'tenant_id' => self::TENANT_ID,
            'name' => 'Security Tenant',
            'slug' => 'security-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId((string) self::TENANT_ID);
    }

    /** 建一个 pending 现金订单 */
    private function makePendingOrder(int $userId): Order
    {
        $product = $this->app->make(ProductService::class)->create(self::TENANT_ID, [
            'name' => '安全测试商品',
            'price' => 10,
        ]);
        $sku = $this->app->make(SkuService::class)->create(self::TENANT_ID, [
            'product_id' => $product->product_id,
            'name' => '默认规格',
            'price' => 10,
            'stock' => 10,
        ]);

        return $this->orderService->createOrder(self::TENANT_ID, $userId, [
            'order_type' => Order::TYPE_PRODUCT,
            'pay_method' => Order::PAY_CASH,
            'items' => [['sku_id' => $sku->sku_id, 'quantity' => 1]],
        ]);
    }

    private function controller(): OrderController
    {
        return $this->app->make(OrderController::class);
    }

    private function notifyRequest(array $payload, ?string $key = null): Request
    {
        $request = Request::create('/orders/pay-notify', 'POST', $payload);
        if ($key !== null) {
            $request->headers->set('X-Pay-Notify-Key', $key);
        }

        return $request;
    }

    // ========== C1：pay-notify 共享密钥 ==========

    public function test_pay_notify_rejected_when_key_not_configured(): void
    {
        config(['order.pay_notify_key' => null]);

        $order = $this->makePendingOrder(1);

        $response = $this->controller()->payNotify(
            $this->notifyRequest(['order_no' => $order->order_no, 'transaction_id' => 'TXN-1'])
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_pay_notify_rejected_with_wrong_key(): void
    {
        config(['order.pay_notify_key' => 'secret-key']);

        $order = $this->makePendingOrder(1);

        $response = $this->controller()->payNotify(
            $this->notifyRequest(['order_no' => $order->order_no], 'wrong-key')
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_pay_notify_confirms_with_valid_key(): void
    {
        config(['order.pay_notify_key' => 'secret-key']);

        $order = $this->makePendingOrder(1);

        $response = $this->controller()->payNotify(
            $this->notifyRequest(['order_no' => $order->order_no, 'transaction_id' => 'TXN-OK'], 'secret-key')
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    // ========== C2：pay 订单归属 ==========

    public function test_pay_rejected_when_order_belongs_to_other_user(): void
    {
        $order = $this->makePendingOrder(7);

        $this->expectException(AccessDeniedHttpException::class);
        $this->orderService->initiatePayment(self::TENANT_ID, $order->order_no, null, 999);
    }

    public function test_pay_allowed_for_owner(): void
    {
        $order = $this->makePendingOrder(7);

        // 免费订单走虚拟支付即时确认（不依赖现金网关）
        $order->update(['total_amount' => 0]);

        $result = $this->orderService->initiatePayment(self::TENANT_ID, $order->order_no, null, 7);

        $this->assertTrue($result['paid']);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_pay_without_actor_skips_ownership_check(): void
    {
        $order = $this->makePendingOrder(7);
        $order->update(['total_amount' => 0]);

        // actorUserId=null（运营代付场景）不校归属
        $result = $this->orderService->initiatePayment(self::TENANT_ID, $order->order_no, null, null);

        $this->assertTrue($result['paid']);
    }

    // ========== C3：refund 限 Operator ==========

    public function test_refund_rejected_for_user(): void
    {
        $order = $this->makePendingOrder(1);
        $order->update(['status' => Order::STATUS_PAID, 'paid_at' => now()]);

        $user = User::create([
            'user_id' => 1,
            'name' => 'Refund User',
            'email' => 'refund-user@test.com',
            'password' => 'secret',
        ]);

        $request = Request::create("/orders/{$order->order_no}/refund", 'POST');
        $request->setUserResolver(fn () => $user);

        $response = $this->controller()->refund($request, $order->order_no);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_refund_allowed_for_operator(): void
    {
        $order = $this->makePendingOrder(1);
        $order->update(['status' => Order::STATUS_PAID, 'paid_at' => now()]);

        $operator = Operator::create([
            'email' => 'refund-operator@test.com',
            'name' => 'Refund Operator',
            'scope' => 'tenant',
            'is_active' => true,
        ]);

        $request = Request::create("/orders/{$order->order_no}/refund", 'POST');
        $request->setUserResolver(fn () => $operator);

        $response = $this->controller()->refund($request, $order->order_no);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
    }
}
