<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\AuditLog;
use MultiTenantSaas\Models\PaymentOrder;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\DunningService;
use MultiTenantSaas\Tests\Schema\BillingModule;
use MultiTenantSaas\Tests\Schema\EventModule;

/**
 * DunningService 单元测试
 *
 * 覆盖：重试策略递增间隔、宽限期内不暂停、超过宽限期后暂停、事件记录
 */
class DunningServiceTest extends TestCase
{
    protected array $uses = [BillingModule::class, EventModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Dunning Tenant A',
            'slug' => 'dunning-tenant-a',
            'status' => 'active',
            'subscription_plan' => 'basic',
            'auto_renew' => true,
        ]);
        Tenant::create([
            'tenant_id' => 1002,
            'name' => 'Dunning Tenant B',
            'slug' => 'dunning-tenant-b',
            'status' => 'active',
            'subscription_plan' => 'pro',
            'auto_renew' => true,
        ]);

        TenantContext::setTenantId('1001');
    }

    // ---------- 无失败订单 ----------

    public function test_process_failed_payment_returns_none_without_failed_order(): void
    {
        $result = DunningService::processFailedPayment(1001);

        $this->assertEquals('none', $result['action']);
        $this->assertNull($result['next_retry_at']);
    }

    // ---------- 重试策略递增间隔 ----------

    public function test_process_failed_payment_first_retry_uses_1_day_interval(): void
    {
        $this->createFailedOrder(1001);

        $result = DunningService::processFailedPayment(1001);

        $this->assertEquals('retry', $result['action']);
        $this->assertNotNull($result['next_retry_at']);
        $this->assertEqualsWithDelta(1, now()->diffInDays($result['next_retry_at']), 0.01);
    }

    public function test_process_failed_payment_second_retry_uses_3_day_interval(): void
    {
        $this->createFailedOrder(1001, ['retry_count' => 1]);

        $result = DunningService::processFailedPayment(1001);

        $this->assertEquals('retry', $result['action']);
        $this->assertEqualsWithDelta(3, now()->diffInDays($result['next_retry_at']), 0.01);
    }

    public function test_process_failed_payment_third_retry_uses_7_day_interval(): void
    {
        $this->createFailedOrder(1001, ['retry_count' => 2]);

        $result = DunningService::processFailedPayment(1001);

        $this->assertEquals('retry', $result['action']);
        $this->assertEqualsWithDelta(7, now()->diffInDays($result['next_retry_at']), 0.01);
    }

    // ---------- 宽限期内不暂停 ----------

    public function test_process_failed_payment_returns_retry_within_grace_period(): void
    {
        $this->createFailedOrder(1001, ['retry_count' => 0]);

        $result = DunningService::processFailedPayment(1001);

        $this->assertEquals('retry', $result['action']);
        $this->assertEquals('active', Tenant::find(1001)->status);
    }

    public function test_process_failed_payment_keeps_active_during_retries(): void
    {
        $this->createFailedOrder(1001, ['retry_count' => 1]);

        DunningService::processFailedPayment(1001);

        $this->assertEquals('active', Tenant::find(1001)->status);
    }

    // ---------- 超过宽限期后暂停 ----------

    public function test_process_failed_payment_returns_suspend_after_max_retries(): void
    {
        $this->createFailedOrder(1001, ['retry_count' => DunningService::DEFAULT_MAX_RETRIES]);

        $result = DunningService::processFailedPayment(1001);

        $this->assertEquals('suspend', $result['action']);
        $this->assertNull($result['next_retry_at']);
    }

    public function test_suspend_tenant_sets_status_suspended(): void
    {
        DunningService::suspendTenant(1001);

        $this->assertEquals('suspended', Tenant::find(1001)->status);
    }

    public function test_suspend_tenant_disables_auto_renew(): void
    {
        DunningService::suspendTenant(1001);

        $this->assertFalse(Tenant::find(1001)->auto_renew);
    }

    public function test_suspend_tenant_skips_already_suspended(): void
    {
        $tenant = Tenant::find(1001);
        $tenant->status = 'suspended';
        $tenant->save();

        DunningService::suspendTenant(1001);

        $this->assertEquals('suspended', Tenant::find(1001)->status);
        $this->assertEquals(0, AuditLog::where('action', 'tenant_suspended')->count());
    }

    public function test_suspend_tenant_skips_unknown_tenant(): void
    {
        DunningService::suspendTenant(9999);

        $this->assertEquals(0, AuditLog::where('action', 'tenant_suspended')->count());
    }

    // ---------- 事件记录 ----------

    public function test_process_failed_payment_increments_retry_count_in_extra(): void
    {
        $order = $this->createFailedOrder(1001, ['retry_count' => 1]);

        DunningService::processFailedPayment(1001);

        $order->refresh();
        $this->assertEquals(2, $order->extra['retry_count']);
    }

    public function test_process_failed_payment_records_next_retry_at_in_extra(): void
    {
        $order = $this->createFailedOrder(1001);

        $result = DunningService::processFailedPayment(1001);

        $order->refresh();
        $this->assertNotEmpty($order->extra['next_retry_at']);
        $this->assertEquals(
            $result['next_retry_at']->toDateTimeString(),
            $order->extra['next_retry_at']
        );
    }

    public function test_process_failed_payment_sets_dunning_status_retrying(): void
    {
        $order = $this->createFailedOrder(1001);

        DunningService::processFailedPayment(1001);

        $order->refresh();
        $this->assertEquals('retrying', $order->extra['dunning_status']);
    }

    public function test_suspend_tenant_records_audit_log(): void
    {
        DunningService::suspendTenant(1001);

        $log = AuditLog::where('action', 'tenant_suspended')->first();

        $this->assertNotNull($log);
        $this->assertEquals('tenant', $log->resource_type);
        $this->assertEquals(1001, $log->resource_id);
        $this->assertEquals('active', $log->old_values['status']);
        $this->assertEquals('suspended', $log->new_values['status']);
    }

    // ---------- 状态查询 ----------

    public function test_get_dunning_status_active_without_failed_order(): void
    {
        $status = DunningService::getDunningStatus(1001);

        $this->assertEquals('active', $status['status']);
        $this->assertEquals(0, $status['retry_count']);
        $this->assertEquals(DunningService::DEFAULT_MAX_RETRIES, $status['max_retries']);
        $this->assertEquals(DunningService::DEFAULT_GRACE_PERIOD_DAYS, $status['grace_period_days']);
        $this->assertNull($status['next_retry_at']);
    }

    public function test_get_dunning_status_retrying_with_failed_order(): void
    {
        $this->createFailedOrder(1001, ['retry_count' => 1, 'next_retry_at' => now()->addDays(3)->toDateTimeString()]);

        $status = DunningService::getDunningStatus(1001);

        $this->assertEquals('retrying', $status['status']);
        $this->assertEquals(1, $status['retry_count']);
        $this->assertNotNull($status['next_retry_at']);
    }

    public function test_get_dunning_status_suspended_for_suspended_tenant(): void
    {
        $tenant = Tenant::find(1001);
        $tenant->status = 'suspended';
        $tenant->save();

        $status = DunningService::getDunningStatus(1001);

        $this->assertEquals('suspended', $status['status']);
    }

    public function test_get_dunning_status_returns_none_for_unknown_tenant(): void
    {
        $status = DunningService::getDunningStatus(9999);

        $this->assertEquals('none', $status['status']);
    }

    // ---------- 辅助方法 ----------

    private function createFailedOrder(int $tenantId, array $extra = []): PaymentOrder
    {
        return PaymentOrder::create([
            'tenant_id' => $tenantId,
            'order_no' => 'FAILED-' . $tenantId . '-' . uniqid(),
            'driver' => 'wechat',
            'amount' => 99,
            'description' => 'failed order',
            'status' => 'failed',
            'extra' => $extra,
        ]);
    }
}
