<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\SupplyProvisionerContract;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\Commerce\Handlers\ContentPackFulfillmentHandler;
use MultiTenantSaas\Modules\Commerce\Models\CommerceOrder;
use MultiTenantSaas\Modules\Commerce\Models\CommerceOrderItem;
use MultiTenantSaas\Modules\Commerce\Models\CommerceSku;
use MultiTenantSaas\Modules\Commerce\Models\SupplyGrant;
use MultiTenantSaas\Modules\Commerce\Services\CommerceFulfillmentService;
use MultiTenantSaas\Modules\Commerce\Services\SupplyProvisionerRegistry;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Scopes\TenantScope;
use MultiTenantSaas\Tests\Schema\BillingModule;
use MultiTenantSaas\Tests\Schema\CommerceModule;
use MultiTenantSaas\Tests\Schema\EventModule;
use MultiTenantSaas\Tests\Schema\WebhookModule;

/**
 * 供给类履约测试（Phase 2）
 *
 * 覆盖：content_pack/mall_supply 授权发放、结算参数锁定、产物回填、
 * Provisioner 未注册/失败处理、revoke、过期回收、停供/恢复
 */
class CommerceSupplyGrantTest extends TestCase
{
    protected array $uses = [CommerceModule::class, BillingModule::class, EventModule::class, WebhookModule::class];

    protected CommerceFulfillmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(CommerceFulfillmentService::class);

        Tenant::create([
            'tenant_id' => 2004,
            'name' => 'Supply Tenant',
            'slug' => 'supply-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId('2004');

        FakeSupplyProvisioner::$calls = [];
    }

    private function registerProvisioner(bool $failProvision = false): void
    {
        FakeSupplyProvisioner::$failProvision = $failProvision;
        $this->app->make(SupplyProvisionerRegistry::class)->register(FakeSupplyProvisioner::class);
    }

    private function createSupplySku(string $type, array $payload = []): CommerceSku
    {
        return CommerceSku::create([
            'name' => "SKU-{$type}",
            'type' => $type,
            'role' => CommerceSku::ROLE_SUPPLY,
            'lifecycle' => 'grant',
            'fulfill_handler' => $type,
            'price' => 99.00,
            'payload' => $payload,
            'status' => CommerceSku::STATUS_ACTIVE,
        ]);
    }

    private function createPaidOrderWithItem(CommerceSku $sku, int $qty = 1): CommerceOrder
    {
        $order = CommerceOrder::create([
            'order_no' => 'CM' . uniqid(),
            'tenant_id' => 2004,
            'amount' => $sku->price * $qty,
            'status' => CommerceOrder::STATUS_PAID,
            'paid_at' => now(),
            'operator_id' => 901,
        ]);

        CommerceOrderItem::create([
            'order_id' => $order->order_id,
            'sku_id' => $sku->sku_id,
            'qty' => $qty,
            'unit_price' => $sku->price,
            'fulfill_status' => CommerceOrderItem::FULFILL_PENDING,
            'retry_count' => 0,
            'payload_snapshot' => $sku->payload,
        ]);

        return $order;
    }

    private function grantsForOrder(CommerceOrder $order)
    {
        return SupplyGrant::withoutGlobalScope(TenantScope::class)
            ->where('source_order_id', $order->order_id)
            ->get();
    }

    public function test_content_pack_fulfillment_creates_grant_with_locked_settlement(): void
    {
        $this->registerProvisioner();

        $sku = $this->createSupplySku(CommerceSku::TYPE_CONTENT_PACK, [
            'duration_days' => 30,
            'settlement' => ['mode' => 'prepay', 'supply_price' => 50.0],
        ]);
        $order = $this->createPaidOrderWithItem($sku);

        $this->service->fulfillOrder($order);

        $this->assertEquals(CommerceOrder::STATUS_FULFILLED, $order->fresh()->status);

        $grants = $this->grantsForOrder($order);
        $this->assertCount(1, $grants);

        $grant = $grants->first();
        $this->assertEquals(SupplyGrant::STATUS_ACTIVE, $grant->status);
        $this->assertEquals(2004, $grant->tenant_id);
        $this->assertEquals(['mode' => 'prepay', 'supply_price' => 50.0], $grant->settlement);
        $this->assertTrue($grant->valid_until->isFuture());
        $this->assertEquals(['content_id' => 'fake-content'], $grant->instance_payload);
        $this->assertContains(['method' => 'provisionContent', 'grant_id' => $grant->grant_id], FakeSupplyProvisioner::$calls);
    }

    public function test_mall_supply_fulfillment_creates_grant(): void
    {
        $this->registerProvisioner();

        $sku = $this->createSupplySku(CommerceSku::TYPE_MALL_SUPPLY, [
            'settlement' => ['mode' => 'share', 'share_ratio' => 0.2],
        ]);
        $order = $this->createPaidOrderWithItem($sku);

        $this->service->fulfillOrder($order);

        $grant = $this->grantsForOrder($order)->first();
        $this->assertNotNull($grant);
        $this->assertNull($grant->valid_until); // 未配 duration_days = 永久
        $this->assertEquals(['points_product_id' => 'fake-product'], $grant->instance_payload);
        $this->assertContains(['method' => 'provisionMallSku', 'grant_id' => $grant->grant_id], FakeSupplyProvisioner::$calls);
    }

    public function test_qty_creates_multiple_grants(): void
    {
        $this->registerProvisioner();

        $sku = $this->createSupplySku(CommerceSku::TYPE_CONTENT_PACK);
        $order = $this->createPaidOrderWithItem($sku, 3);

        $this->service->fulfillOrder($order);

        $this->assertCount(3, $this->grantsForOrder($order));
    }

    public function test_fulfill_fails_without_registered_provisioner(): void
    {
        // 不注册 Provisioner（框架独立部署时供给类无法落地）
        $sku = $this->createSupplySku(CommerceSku::TYPE_CONTENT_PACK);
        $order = $this->createPaidOrderWithItem($sku);

        $this->service->fulfillOrder($order);

        $item = $order->items()->first();
        $this->assertEquals(CommerceOrderItem::FULFILL_FAILED, $item->fulfill_status);
        $this->assertStringContainsString('SupplyProvisioner', $item->fail_reason);
        $this->assertCount(0, $this->grantsForOrder($order));
    }

    public function test_provision_failure_rolls_back_grant(): void
    {
        $this->registerProvisioner(failProvision: true);

        $sku = $this->createSupplySku(CommerceSku::TYPE_CONTENT_PACK);
        $order = $this->createPaidOrderWithItem($sku);

        $this->service->fulfillOrder($order);

        $item = $order->items()->first();
        $this->assertEquals(CommerceOrderItem::FULFILL_FAILED, $item->fulfill_status);
        $this->assertCount(0, $this->grantsForOrder($order), 'provision 失败不应残留半成品 grant');
    }

    public function test_revoke_marks_grants_revoked_and_deprovisions(): void
    {
        $this->registerProvisioner();

        $sku = $this->createSupplySku(CommerceSku::TYPE_CONTENT_PACK);
        $order = $this->createPaidOrderWithItem($sku);
        $this->service->fulfillOrder($order);

        $item = $order->items()->first();
        app(ContentPackFulfillmentHandler::class)->revoke($item);

        $grant = $this->grantsForOrder($order)->first();
        $this->assertEquals(SupplyGrant::STATUS_REVOKED, $grant->status);
        $this->assertContains(['method' => 'deprovision', 'grant_id' => $grant->grant_id], FakeSupplyProvisioner::$calls);
    }

    public function test_process_expired_grants(): void
    {
        $this->registerProvisioner();

        $sku = $this->createSupplySku(CommerceSku::TYPE_CONTENT_PACK);

        $grant = SupplyGrant::create([
            'tenant_id' => 2004,
            'sku_id' => $sku->sku_id,
            'status' => SupplyGrant::STATUS_ACTIVE,
            'valid_from' => now()->subDays(40),
            'valid_until' => now()->subDay(),
        ]);

        $processed = $this->service->processExpiredGrants();

        $this->assertEquals(1, $processed);
        $this->assertEquals(SupplyGrant::STATUS_EXPIRED, $grant->fresh()->status);
        $this->assertContains(['method' => 'deprovision', 'grant_id' => $grant->grant_id], FakeSupplyProvisioner::$calls);
    }

    public function test_suspend_and_resume_grant(): void
    {
        $this->registerProvisioner();

        $sku = $this->createSupplySku(CommerceSku::TYPE_MALL_SUPPLY);

        $grant = SupplyGrant::create([
            'tenant_id' => 2004,
            'sku_id' => $sku->sku_id,
            'status' => SupplyGrant::STATUS_ACTIVE,
        ]);

        $this->service->suspendGrant($grant);
        $this->assertEquals(SupplyGrant::STATUS_SUSPENDED, $grant->fresh()->status);

        $this->expectException(DomainException::class);
        $this->service->suspendGrant($grant->fresh()); // 已停供不可再停
    }

    public function test_resume_only_from_suspended(): void
    {
        $sku = $this->createSupplySku(CommerceSku::TYPE_MALL_SUPPLY);

        $grant = SupplyGrant::create([
            'tenant_id' => 2004,
            'sku_id' => $sku->sku_id,
            'status' => SupplyGrant::STATUS_SUSPENDED,
        ]);

        $this->service->resumeGrant($grant);
        $this->assertEquals(SupplyGrant::STATUS_ACTIVE, $grant->fresh()->status);

        $this->expectException(DomainException::class);
        $this->service->resumeGrant($grant->fresh()); // 非停供状态不可恢复
    }
}

/**
 * 测试用落地器：记录调用，供断言委托链
 */
class FakeSupplyProvisioner implements SupplyProvisionerContract
{
    public static array $calls = [];

    public static bool $failProvision = false;

    public function provisionContent(SupplyGrant $grant, CommerceOrderItem $item): array
    {
        if (self::$failProvision) {
            throw new \RuntimeException('provision content failed');
        }

        self::$calls[] = ['method' => 'provisionContent', 'grant_id' => $grant->grant_id];

        return ['content_id' => 'fake-content'];
    }

    public function provisionMallSku(SupplyGrant $grant, CommerceOrderItem $item): array
    {
        if (self::$failProvision) {
            throw new \RuntimeException('provision mall sku failed');
        }

        self::$calls[] = ['method' => 'provisionMallSku', 'grant_id' => $grant->grant_id];

        return ['points_product_id' => 'fake-product'];
    }

    public function deprovision(SupplyGrant $grant): void
    {
        self::$calls[] = ['method' => 'deprovision', 'grant_id' => $grant->grant_id];
    }
}
