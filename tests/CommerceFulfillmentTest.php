<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\Billing\Models\CreditAccount;
use MultiTenantSaas\Modules\Commerce\Models\CommerceOrder;
use MultiTenantSaas\Modules\Commerce\Models\CommerceOrderItem;
use MultiTenantSaas\Modules\Commerce\Models\CommerceSku;
use MultiTenantSaas\Modules\Commerce\Models\ModuleEntitlement;
use MultiTenantSaas\Modules\Commerce\Services\CommerceFulfillmentService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Services\ModuleManager;
use MultiTenantSaas\Scopes\TenantScope;
use MultiTenantSaas\Tests\Schema\BillingModule;
use MultiTenantSaas\Tests\Schema\CommerceModule;
use MultiTenantSaas\Tests\Schema\EventModule;
use MultiTenantSaas\Tests\Schema\WebhookModule;

/**
 * CommerceFulfillmentService 单元测试
 *
 * 覆盖：积分包充值、模块权益+开关、单项失败补偿重试、过期权益处理
 */
class CommerceFulfillmentTest extends TestCase
{
    protected array $uses = [CommerceModule::class, BillingModule::class, EventModule::class, WebhookModule::class];

    protected CommerceFulfillmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(CommerceFulfillmentService::class);

        Tenant::create([
            'tenant_id' => 2002,
            'name' => 'Fulfill Tenant',
            'slug' => 'fulfill-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId('2002');
    }

    private function createSku(string $handler, array $payload, float $price = 10.00): CommerceSku
    {
        return CommerceSku::create([
            'name' => "SKU-{$handler}",
            'type' => $handler === 'module' ? CommerceSku::TYPE_MODULE : CommerceSku::TYPE_CREDIT_PACK,
            'role' => CommerceSku::ROLE_CONSUMER,
            'lifecycle' => 'consumable',
            'fulfill_handler' => $handler,
            'price' => $price,
            'payload' => $payload,
            'status' => CommerceSku::STATUS_ACTIVE,
        ]);
    }

    private function createPaidOrder(CommerceSku $sku, int $qty = 1): CommerceOrder
    {
        $order = CommerceOrder::create([
            'order_no' => 'CM' . uniqid(),
            'tenant_id' => 2002,
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

    public function test_credit_pack_fulfillment_recharges_tenant_account(): void
    {
        $sku = $this->createSku('credit_pack', ['credits' => 500, 'gift_credits' => 50, 'gift_expire_days' => 30]);
        $order = $this->createPaidOrder($sku, 2);

        $this->service->fulfillOrder($order);

        $this->assertEquals(CommerceOrder::STATUS_FULFILLED, $order->fresh()->status);

        $account = CreditAccount::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', 2002)
            ->whereNull('user_id')
            ->first();

        $this->assertNotNull($account);
        $this->assertEquals(1000, $account->recharge_balance);
        $this->assertEquals(100, $account->gift_balance);
    }

    public function test_credit_pack_revoke_is_forbidden(): void
    {
        $sku = $this->createSku('credit_pack', ['credits' => 100]);
        $order = $this->createPaidOrder($sku);

        $item = $order->items()->first();

        $this->expectException(DomainException::class);
        $this->service->registry()->resolve('credit_pack')->revoke($item);
    }

    public function test_module_fulfillment_creates_entitlement_and_enables_switch(): void
    {
        // coupon 模块为 tenant_toggleable + default_enabled
        $sku = $this->createSku('module', ['module_name' => 'coupon', 'duration_days' => 30]);
        $order = $this->createPaidOrder($sku);

        $this->service->fulfillOrder($order);

        $this->assertEquals(CommerceOrder::STATUS_FULFILLED, $order->fresh()->status);

        $entitlement = ModuleEntitlement::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', 2002)
            ->where('module_name', 'coupon')
            ->first();

        $this->assertNotNull($entitlement);
        $this->assertEquals(ModuleEntitlement::STATUS_ACTIVE, $entitlement->status);
        $this->assertEquals(ModuleEntitlement::SOURCE_PURCHASE, $entitlement->source);
        $this->assertNotNull($entitlement->valid_until);
        $this->assertTrue($entitlement->valid_until->isFuture());

        $this->assertTrue(app(ModuleManager::class)->isEnabledForTenant('coupon', 2002));
    }

    public function test_module_revoke_disables_switch_when_no_other_entitlement(): void
    {
        $sku = $this->createSku('module', ['module_name' => 'coupon', 'duration_days' => 30]);
        $order = $this->createPaidOrder($sku);

        $this->service->fulfillOrder($order);
        $this->assertTrue(app(ModuleManager::class)->isEnabledForTenant('coupon', 2002));

        $item = $order->items()->first();
        $this->service->registry()->resolve('module')->revoke($item);

        $entitlement = ModuleEntitlement::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', 2002)
            ->where('module_name', 'coupon')
            ->first();
        $this->assertEquals(ModuleEntitlement::STATUS_REVOKED, $entitlement->status);
        $this->assertFalse(app(ModuleManager::class)->isEnabledForTenant('coupon', 2002));
    }

    public function test_retry_failed_items(): void
    {
        $sku = $this->createSku('credit_pack', ['credits' => 100]);
        $order = $this->createPaidOrder($sku);

        // 模拟首次履约失败
        $order->items()->first()->update([
            'fulfill_status' => CommerceOrderItem::FULFILL_FAILED,
            'retry_count' => 1,
            'fail_reason' => 'mock failure',
        ]);
        $order->update(['status' => CommerceOrder::STATUS_PARTIAL_FAILED]);

        $fulfilled = $this->service->retryFailed();

        $this->assertEquals(1, $fulfilled);
        $this->assertEquals(CommerceOrder::STATUS_FULFILLED, $order->fresh()->status);
        $this->assertEquals(CommerceOrderItem::FULFILL_FULFILLED, $order->items()->first()->fulfill_status);
    }

    public function test_retry_skips_exhausted_items(): void
    {
        $sku = $this->createSku('credit_pack', ['credits' => 100]);
        $order = $this->createPaidOrder($sku);

        // payload 损坏 + 重试耗尽 → 不再重试
        $order->items()->first()->update([
            'payload_snapshot' => [],
            'fulfill_status' => CommerceOrderItem::FULFILL_FAILED,
            'retry_count' => 3,
        ]);

        $this->assertEquals(0, $this->service->retryFailed());
    }

    public function test_process_expired_entitlements(): void
    {
        ModuleEntitlement::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => 2002,
            'module_name' => 'coupon',
            'source' => ModuleEntitlement::SOURCE_PURCHASE,
            'valid_from' => now()->subDays(31),
            'valid_until' => now()->subDay(),
            'status' => ModuleEntitlement::STATUS_ACTIVE,
        ]);

        // 预置开关为启用
        app(ModuleManager::class)->enableForTenant('coupon', 2002);

        $count = $this->service->processExpiredEntitlements();

        $this->assertEquals(1, $count);

        $entitlement = ModuleEntitlement::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', 2002)
            ->where('module_name', 'coupon')
            ->first();
        $this->assertEquals(ModuleEntitlement::STATUS_EXPIRED, $entitlement->status);
        $this->assertFalse(app(ModuleManager::class)->isEnabledForTenant('coupon', 2002));
    }

    public function test_fulfill_item_failure_does_not_block_order(): void
    {
        // payload 缺少 credits → 单项失败
        $badSku = $this->createSku('credit_pack', []);
        $order = $this->createPaidOrder($badSku);

        $this->service->fulfillOrder($order);

        $this->assertEquals(CommerceOrder::STATUS_PARTIAL_FAILED, $order->fresh()->status);

        $item = $order->items()->first();
        $this->assertEquals(CommerceOrderItem::FULFILL_FAILED, $item->fulfill_status);
        $this->assertEquals(1, $item->retry_count);
        $this->assertNotEmpty($item->fail_reason);
    }
}
