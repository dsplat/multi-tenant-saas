<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\Order;

use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Order\Models\Order;
use MultiTenantSaas\Modules\Order\Models\OrderEntityRelation;
use MultiTenantSaas\Modules\Order\Services\OrderService;
use MultiTenantSaas\Modules\Order\Support\EntityTypes;
use MultiTenantSaas\Modules\Order\Support\OrderRelationTypes;
use MultiTenantSaas\Modules\Product\Services\ProductService;
use MultiTenantSaas\Modules\Product\Services\SkuService;
use MultiTenantSaas\Tests\Schema\OrderModule;
use MultiTenantSaas\Tests\Schema\ProductModule;
use MultiTenantSaas\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 订单实体收敛测试（方案 B 三层职责分离）：
 * - orders 仅存主实体（无 secondary 字段）
 * - order_items 为纯交易明细（无行级 entity）
 * - order_entity_relations 承载次要实体归因（白名单校验）
 */
class OrderEntityConvergenceTest extends TestCase
{
    protected array $uses = [ProductModule::class, OrderModule::class];

    protected const TENANT_ID = 3301;

    protected OrderService $orderService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderService = $this->app->make(OrderService::class);

        Tenant::create([
            'tenant_id' => self::TENANT_ID,
            'name' => 'Convergence Tenant',
            'slug' => 'convergence-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId((string) self::TENANT_ID);
    }

    public function test_schema_converged_no_secondary_and_no_row_level_entity(): void
    {
        // orders：仅保留主实体字段，secondary 已移除
        $this->assertTrue(Schema::hasColumn('orders', 'entity_type'));
        $this->assertTrue(Schema::hasColumn('orders', 'entity_id'));
        $this->assertFalse(Schema::hasColumn('orders', 'secondary_entity_type'));
        $this->assertFalse(Schema::hasColumn('orders', 'secondary_entity_id'));

        // order_items：纯交易明细，行级 entity 已移除
        $this->assertFalse(Schema::hasColumn('order_items', 'entity_type'));
        $this->assertFalse(Schema::hasColumn('order_items', 'entity_id'));

        // order_entity_relations：次要实体归因表存在
        $this->assertTrue(Schema::hasTable('order_entity_relations'));
    }

    public function test_entity_relations_persisted_with_whitelist_default(): void
    {
        $order = $this->orderService->createOrder(self::TENANT_ID, 5, [
            'order_type' => Order::TYPE_PRODUCT,
            'pay_method' => Order::PAY_CASH,
            'entity_type' => EntityTypes::PRODUCT,
            'entity_id' => '888',
            'items' => [['item_name' => '主商品', 'unit_price' => 10, 'quantity' => 1]],
            'entity_relations' => [
                [
                    'entity_type' => EntityTypes::ACTIVITY,
                    'entity_id' => '777',
                    'relation_type' => OrderRelationTypes::PROMOTION,
                    'share_amount' => 3.5,
                ],
                [
                    // relation_type 缺省 → related
                    'entity_type' => EntityTypes::COURSE,
                    'entity_id' => '666',
                ],
            ],
        ]);

        $this->assertSame(2, OrderEntityRelation::where('order_id', $order->order_id)->count());

        $promotion = OrderEntityRelation::where('order_id', $order->order_id)
            ->where('relation_type', OrderRelationTypes::PROMOTION)->first();
        $this->assertSame('activity', $promotion->entity_type);
        $this->assertSame('777', $promotion->entity_id);
        $this->assertEquals(3.5, (float) $promotion->share_amount);

        $default = OrderEntityRelation::where('order_id', $order->order_id)
            ->where('entity_id', '666')->first();
        $this->assertSame(OrderRelationTypes::RELATED, $default->relation_type);

        // 关系可从订单侧加载
        $this->assertSame(2, $order->fresh()->entityRelations()->count());
    }

    public function test_invalid_relation_type_rejected(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);

        $this->orderService->createOrder(self::TENANT_ID, 5, [
            'pay_method' => Order::PAY_CASH,
            'items' => [['item_name' => 'x', 'unit_price' => 1, 'quantity' => 1]],
            'entity_relations' => [[
                'entity_type' => EntityTypes::ACTIVITY,
                'entity_id' => '1',
                'relation_type' => 'not_a_valid_type',
            ]],
        ]);
    }

    public function test_invalid_relation_entity_type_rejected(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);

        $this->orderService->createOrder(self::TENANT_ID, 5, [
            'pay_method' => Order::PAY_CASH,
            'items' => [['item_name' => 'x', 'unit_price' => 1, 'quantity' => 1]],
            'entity_relations' => [[
                'entity_type' => 'ghost_entity',
                'entity_id' => '1',
            ]],
        ]);
    }

    public function test_sku_only_order_falls_back_to_sku_primary_entity(): void
    {
        $product = $this->app->make(ProductService::class)->create(self::TENANT_ID, [
            'name' => '兜底商品',
            'price' => 20,
        ]);
        $sku = $this->app->make(SkuService::class)->create(self::TENANT_ID, [
            'product_id' => $product->product_id,
            'name' => '默认规格',
            'price' => 20,
            'points_price' => 200,
            'stock' => 5,
        ]);

        $order = $this->orderService->createOrder(self::TENANT_ID, 5, [
            'pay_method' => Order::PAY_CASH,
            'items' => [['sku_id' => $sku->sku_id, 'quantity' => 1]],
        ]);

        // 未显式传 entity_type 且全 SKU 行 → 订单级兜底 'sku'（履约分发键）
        $this->assertSame(EntityTypes::SKU, $order->entity_type);
        $this->assertSame((string) $sku->sku_id, (string) $order->entity_id);
    }
}
