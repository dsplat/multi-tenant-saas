<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\Commerce\Models\CommerceOrder;
use MultiTenantSaas\Modules\Commerce\Models\CommerceOrderItem;
use MultiTenantSaas\Modules\Commerce\Models\CommerceSku;
use MultiTenantSaas\Modules\Commerce\Services\CommerceOrderService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\BillingModule;
use MultiTenantSaas\Tests\Schema\CommerceModule;
use MultiTenantSaas\Tests\Schema\EventModule;
use MultiTenantSaas\Tests\Schema\WebhookModule;

/**
 * CommerceOrderService 单元测试
 *
 * 覆盖：下单校验、payload 快照、金额计算、零元单直接履约、取消订单
 */
class CommerceOrderServiceTest extends TestCase
{
    protected array $uses = [CommerceModule::class, BillingModule::class, EventModule::class, WebhookModule::class];

    protected CommerceOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(CommerceOrderService::class);

        Tenant::create([
            'tenant_id' => 2001,
            'name' => 'Commerce Tenant',
            'slug' => 'commerce-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId('2001');
    }

    private function createSku(array $overrides = []): CommerceSku
    {
        return CommerceSku::create(array_merge([
            'name' => 'AI 积分包 1000',
            'type' => CommerceSku::TYPE_CREDIT_PACK,
            'role' => CommerceSku::ROLE_CONSUMER,
            'lifecycle' => 'consumable',
            'fulfill_handler' => 'credit_pack',
            'price' => 100.00,
            'payload' => ['credits' => 1000],
            'refundable' => false,
            'status' => CommerceSku::STATUS_ACTIVE,
        ], $overrides));
    }

    public function test_place_order_with_payload_snapshot_and_amount(): void
    {
        $sku = $this->createSku();

        $order = $this->service->placeOrder(901, [
            ['sku_id' => $sku->sku_id, 'qty' => 2],
        ]);

        $this->assertEquals('200.00', (string) $order->amount);
        $this->assertEquals(CommerceOrder::STATUS_PENDING, $order->status);
        $this->assertEquals(2001, $order->tenant_id);
        $this->assertEquals(901, $order->operator_id);
        $this->assertStringStartsWith('CM', $order->order_no);

        $item = $order->items()->first();
        $this->assertNotNull($item);
        $this->assertEquals(2, $item->qty);
        $this->assertEquals(['credits' => 1000], $item->payload_snapshot);
        $this->assertEquals(CommerceOrderItem::FULFILL_PENDING, $item->fulfill_status);
    }

    public function test_place_order_rejects_inactive_sku(): void
    {
        $sku = $this->createSku(['status' => CommerceSku::STATUS_DRAFT]);

        $this->expectException(DomainException::class);
        $this->service->placeOrder(901, [['sku_id' => $sku->sku_id]]);
    }

    public function test_place_order_rejects_missing_sku(): void
    {
        $this->expectException(DomainException::class);
        $this->service->placeOrder(901, [['sku_id' => 99999]]);
    }

    public function test_place_order_rejects_unregistered_handler(): void
    {
        $sku = $this->createSku(['type' => CommerceSku::TYPE_CONTENT_PACK, 'fulfill_handler' => 'ghost_handler']);

        $this->expectException(DomainException::class);
        $this->service->placeOrder(901, [['sku_id' => $sku->sku_id]]);
    }

    public function test_zero_amount_order_fulfilled_immediately(): void
    {
        $sku = $this->createSku(['price' => 0]);

        $order = $this->service->placeOrder(901, [['sku_id' => $sku->sku_id]]);

        $this->assertEquals(CommerceOrder::STATUS_FULFILLED, $order->status);
        $this->assertNotNull($order->paid_at);

        $item = $order->items()->first();
        $this->assertEquals(CommerceOrderItem::FULFILL_FULFILLED, $item->fulfill_status);
    }

    public function test_cancel_pending_order(): void
    {
        $sku = $this->createSku();
        $order = $this->service->placeOrder(901, [['sku_id' => $sku->sku_id]]);

        $this->service->cancel($order);

        $this->assertEquals(CommerceOrder::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_cancel_rejects_non_pending_order(): void
    {
        $sku = $this->createSku(['price' => 0]);
        $order = $this->service->placeOrder(901, [['sku_id' => $sku->sku_id]]);

        $this->expectException(DomainException::class);
        $this->service->cancel($order);
    }
}
