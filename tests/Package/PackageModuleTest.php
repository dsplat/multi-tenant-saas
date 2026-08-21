<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\Package;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Order\Contracts\OrderFulfillmentHandlerContract;
use MultiTenantSaas\Modules\Order\Models\Order;
use MultiTenantSaas\Modules\Order\Services\FulfillmentRegistry;
use MultiTenantSaas\Modules\Order\Services\OrderService;
use MultiTenantSaas\Modules\Order\Support\EntityTypes;
use MultiTenantSaas\Modules\Pay\Contracts\VirtualPayChannelContract;
use MultiTenantSaas\Modules\Pay\Services\VirtualPayChannelRegistry;
use MultiTenantSaas\Modules\Product\Models\PackageItem;
use MultiTenantSaas\Modules\Product\Models\Product;
use MultiTenantSaas\Modules\Product\Services\Fulfillment\PackageFulfillmentHandler;
use MultiTenantSaas\Modules\Product\Services\PackageService;
use MultiTenantSaas\Tests\Schema\OrderModule;
use MultiTenantSaas\Tests\Schema\PayModule;
use MultiTenantSaas\Tests\Schema\ProductModule;
use MultiTenantSaas\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Package 组合实体测试：组成 CRUD、订单实体绑定、履约递归拆解、防环
 */
class PackageModuleTest extends TestCase
{
    protected array $uses = [ProductModule::class, PayModule::class, OrderModule::class];

    protected const TENANT_ID = 5101;

    protected PackageService $packageService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packageService = $this->app->make(PackageService::class);

        Tenant::create([
            'tenant_id' => self::TENANT_ID,
            'name' => 'Package Tenant',
            'slug' => 'package-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId((string) self::TENANT_ID);
    }

    private function makePackage(string $name = '测试大礼包'): Product
    {
        return $this->packageService->create(self::TENANT_ID, [
            'name' => $name,
            'price' => 199,
        ]);
    }

    // ========== 组成管理 ==========

    public function test_create_package_locks_type_and_manages_items(): void
    {
        $package = $this->makePackage();
        $this->assertSame(Product::TYPE_PACKAGE, $package->type);

        $item = $this->packageService->addItem(self::TENANT_ID, $package->product_id, [
            'item_type' => EntityTypes::COURSE,
            'item_id' => '9001',
            'quantity' => 1,
        ]);
        $this->packageService->addItem(self::TENANT_ID, $package->product_id, [
            'item_type' => EntityTypes::PRODUCT,
            'item_id' => '9002',
        ]);

        $this->assertCount(2, $this->packageService->listItems(self::TENANT_ID, $package->product_id));
        $this->assertSame('9001', $item->item_id);

        // 重复组成拒绝
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->packageService->addItem(self::TENANT_ID, $package->product_id, [
            'item_type' => EntityTypes::COURSE,
            'item_id' => '9001',
        ]);
    }

    public function test_add_item_rejects_package_type_and_invalid_type(): void
    {
        $package = $this->makePackage();

        try {
            $this->packageService->addItem(self::TENANT_ID, $package->product_id, [
                'item_type' => EntityTypes::PACKAGE,
                'item_id' => '1',
            ]);
            $this->fail('package 组成不应允许直接引用 package');
        } catch (UnprocessableEntityHttpException $e) {
            $this->assertStringContainsString('package', $e->getMessage());
        }

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->packageService->addItem(self::TENANT_ID, $package->product_id, [
            'item_type' => 'not_in_whitelist',
            'item_id' => '1',
        ]);
    }

    public function test_non_package_product_rejected_as_package(): void
    {
        $product = $this->app->make(\MultiTenantSaas\Modules\Product\Services\ProductService::class)
            ->create(self::TENANT_ID, ['name' => '普通商品', 'price' => 10]);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->packageService->getPackage(self::TENANT_ID, $product->product_id);
    }

    // ========== 订单实体绑定 + 履约递归拆解 ==========

    public function test_package_order_dispatches_leaf_fulfillment_recursively(): void
    {
        $channel = new PackageFakeChannel(1000);
        $this->app->make(VirtualPayChannelRegistry::class)->register($channel);

        $courseHandler = new PackageFakeLeafHandler('course');
        $productHandler = new PackageFakeLeafHandler('product');
        $registry = $this->app->make(FulfillmentRegistry::class);
        $registry->register($courseHandler);
        $registry->register($productHandler);
        $registry->register($this->app->make(PackageFulfillmentHandler::class));

        $package = $this->makePackage();
        $this->packageService->addItem(self::TENANT_ID, $package->product_id, [
            'item_type' => EntityTypes::COURSE,
            'item_id' => '701',
        ]);
        $this->packageService->addItem(self::TENANT_ID, $package->product_id, [
            'item_type' => EntityTypes::PRODUCT,
            'item_id' => '702',
        ]);

        $order = $this->app->make(OrderService::class)->createOrder(self::TENANT_ID, 7, [
            'pay_method' => Order::PAY_POINTS,
            'entity_type' => EntityTypes::PACKAGE,
            'entity_id' => (string) $package->product_id,
            'items' => [[
                'item_name' => $package->name,
                'points_unit_price' => 500,
                'quantity' => 1,
            ]],
        ]);

        $this->assertSame(EntityTypes::PACKAGE, $order->entity_type);

        $this->app->make(OrderService::class)->confirmPayment($order->order_no);

        // 叶子履约逐项分发，身份取组成项 item_id（非订单级 entity_id）
        $this->assertSame(['701'], $courseHandler->entityIds);
        $this->assertSame(['702'], $productHandler->entityIds);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_package_cycle_reference_is_guarded(): void
    {
        $channel = new PackageFakeChannel(1000);
        $this->app->make(VirtualPayChannelRegistry::class)->register($channel);

        $courseHandler = new PackageFakeLeafHandler('course');
        $registry = $this->app->make(FulfillmentRegistry::class);
        $registry->register($courseHandler);
        $registry->register($this->app->make(PackageFulfillmentHandler::class));

        // 构造互相引用的 package 环（绕过 addItem 的 package 禁写校验，直插模型）
        $packageA = $this->makePackage('环A');
        $packageB = $this->makePackage('环B');

        PackageItem::create([
            'tenant_id' => self::TENANT_ID,
            'package_id' => $packageA->product_id,
            'item_type' => EntityTypes::PACKAGE,
            'item_id' => (string) $packageB->product_id,
        ]);
        PackageItem::create([
            'tenant_id' => self::TENANT_ID,
            'package_id' => $packageB->product_id,
            'item_type' => EntityTypes::PACKAGE,
            'item_id' => (string) $packageA->product_id,
        ]);
        PackageItem::create([
            'tenant_id' => self::TENANT_ID,
            'package_id' => $packageB->product_id,
            'item_type' => EntityTypes::COURSE,
            'item_id' => '777',
        ]);

        $order = $this->app->make(OrderService::class)->createOrder(self::TENANT_ID, 7, [
            'pay_method' => Order::PAY_POINTS,
            'entity_type' => EntityTypes::PACKAGE,
            'entity_id' => (string) $packageA->product_id,
            'items' => [['item_name' => '环A', 'points_unit_price' => 100, 'quantity' => 1]],
        ]);

        $this->app->make(OrderService::class)->confirmPayment($order->order_no);

        // 环引用被 visited 防住，叶子只履约一次，无死循环
        $this->assertSame(['777'], $courseHandler->entityIds);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }
}

/** 测试用虚拟渠道 */
class PackageFakeChannel implements VirtualPayChannelContract
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

/** 测试用叶子履约 Handler（记录解析出的实体 ID） */
class PackageFakeLeafHandler implements OrderFulfillmentHandlerContract
{
    public array $entityIds = [];

    public function __construct(private string $type) {}

    public function entityType(): string
    {
        return $this->type;
    }

    public function fulfill(Order $order, mixed $item): void
    {
        $this->entityIds[] = is_object($item) && isset($item->item_id)
            ? (string) $item->item_id
            : (string) $order->entity_id;
    }

    public function revoke(Order $order, mixed $item): void
    {
        // 测试用叶子 handler 无持久权益，默认无副作用
    }
}
