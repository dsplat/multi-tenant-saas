<?php

namespace MultiTenantSaas\Tests;

use Carbon\Carbon;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\SubscriptionHistory;
use MultiTenantSaas\Models\SubscriptionPlan;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\PlanChangeService;

/**
 * PlanChangeService 单元测试
 *
 * 覆盖：升级按比例补收、降级按比例退款、立即生效 vs 周期末生效、变更历史记录
 */
class PlanChangeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SubscriptionPlan::unguarded(function () {
            SubscriptionPlan::create([
                'subscription_plan_id' => 1,
                'name' => 'free',
                'display_name' => 'Free Plan',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'trial_days' => 0,
                'is_active' => true,
                'sort_order' => 0,
            ]);
            SubscriptionPlan::create([
                'subscription_plan_id' => 2,
                'name' => 'basic',
                'display_name' => 'Basic Plan',
                'price_monthly' => 99,
                'price_yearly' => 999,
                'trial_days' => 7,
                'is_active' => true,
                'sort_order' => 1,
            ]);
            SubscriptionPlan::create([
                'subscription_plan_id' => 3,
                'name' => 'pro',
                'display_name' => 'Pro Plan',
                'price_monthly' => 299,
                'price_yearly' => 2999,
                'trial_days' => 14,
                'is_active' => true,
                'sort_order' => 2,
            ]);
            SubscriptionPlan::create([
                'subscription_plan_id' => 4,
                'name' => 'inactive',
                'display_name' => 'Inactive Plan',
                'price_monthly' => 500,
                'is_active' => false,
                'sort_order' => 3,
            ]);
        });

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'PlanChange Tenant',
            'slug' => 'planchange-tenant',
            'status' => 'active',
            'subscription_plan' => 'basic',
            'subscription_plan_id' => 2,
        ]);
        Tenant::create([
            'tenant_id' => 1002,
            'name' => 'Empty Tenant',
            'slug' => 'empty-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId('1001');
    }

    // ---------- 按比例补收（升级） ----------

    public function test_calculate_proration_upgrade_returns_charge(): void
    {
        $this->subscribeTenant(1001, 2, 20, 10);

        $result = PlanChangeService::calculateProration(1001, 3, 'immediate');

        $this->assertEquals('charge', $result['direction']);
        $this->assertGreaterThan(0, $result['proration_amount']);
    }

    public function test_calculate_proration_upgrade_amount_is_proportional(): void
    {
        $this->subscribeTenant(1001, 2, 20, 10);

        $result = PlanChangeService::calculateProration(1001, 3, 'immediate');

        // (299/30 - 99/30) * 10 = 66.67
        $this->assertEqualsWithDelta(66.67, $result['proration_amount'], 0.05);
    }

    public function test_calculate_proration_upgrade_effective_at_is_now(): void
    {
        $this->subscribeTenant(1001, 2, 20, 10);

        $result = PlanChangeService::calculateProration(1001, 3, 'immediate');

        $this->assertEqualsWithDelta(0, now()->diffInSeconds($result['effective_at']), 1);
    }

    // ---------- 按比例退款（降级） ----------

    public function test_calculate_proration_downgrade_returns_credit(): void
    {
        $this->subscribeTenant(1001, 3, 20, 10);

        $result = PlanChangeService::calculateProration(1001, 2, 'immediate');

        $this->assertEquals('credit', $result['direction']);
        $this->assertGreaterThan(0, $result['proration_amount']);
    }

    public function test_calculate_proration_downgrade_amount_is_proportional(): void
    {
        $this->subscribeTenant(1001, 3, 20, 10);

        $result = PlanChangeService::calculateProration(1001, 2, 'immediate');

        // (99/30 - 299/30) * 10 = -66.67, abs = 66.67
        $this->assertEqualsWithDelta(66.67, $result['proration_amount'], 0.05);
    }

    // ---------- 周期末生效 ----------

    public function test_calculate_proration_period_end_returns_zero(): void
    {
        $this->subscribeTenant(1001, 2, 20, 10);

        $result = PlanChangeService::calculateProration(1001, 3, 'period_end');

        $this->assertEquals(0.0, $result['proration_amount']);
    }

    public function test_calculate_proration_period_end_effective_at_is_expiry(): void
    {
        $tenant = Tenant::find(1001);
        $expiresAt = now()->addDays(10);
        $tenant->subscription_expires_at = $expiresAt;
        $tenant->save();

        $result = PlanChangeService::calculateProration(1001, 3, 'period_end');

        $this->assertEqualsWithDelta(0, $expiresAt->diffInSeconds($result['effective_at']), 1);
    }

    public function test_calculate_proration_period_end_direction_is_charge(): void
    {
        $this->subscribeTenant(1001, 2, 20, 10);

        $result = PlanChangeService::calculateProration(1001, 3, 'period_end');

        $this->assertEquals('charge', $result['direction']);
    }

    // ---------- 立即生效 ----------

    public function test_change_plan_immediate_updates_plan_id(): void
    {
        $this->subscribeTenant(1001, 2, 20, 10);

        PlanChangeService::changePlan(1001, 3, 'immediate');

        $tenant = Tenant::find(1001);
        $this->assertEquals(3, (int) $tenant->subscription_plan_id);
        $this->assertEquals('pro', $tenant->subscription_plan);
    }

    public function test_change_plan_immediate_resets_billing_cycle(): void
    {
        $this->subscribeTenant(1001, 2, 20, 10);
        $originalStarted = Tenant::find(1001)->subscription_started_at;

        PlanChangeService::changePlan(1001, 3, 'immediate');

        $tenant = Tenant::find(1001);
        $this->assertGreaterThan($originalStarted, $tenant->subscription_started_at);
        $this->assertTrue($tenant->subscription_expires_at > now()->addMonth()->subDay());
    }

    public function test_change_plan_immediate_records_history_with_upgrade_action(): void
    {
        $this->subscribeTenant(1001, 2, 20, 10);

        $history = PlanChangeService::changePlan(1001, 3, 'immediate');

        $this->assertEquals('upgrade', $history->action);
        $this->assertEquals('basic', $history->from_plan);
        $this->assertEquals('pro', $history->to_plan);
        $this->assertEquals(299, (float) $history->amount);
        $this->assertGreaterThan(0, (float) $history->proration_amount);
    }

    // ---------- 周期末生效 ----------

    public function test_change_plan_period_end_updates_plan_id(): void
    {
        $this->subscribeTenant(1001, 2, 20, 10);

        PlanChangeService::changePlan(1001, 3, 'period_end');

        $tenant = Tenant::find(1001);
        $this->assertEquals(3, (int) $tenant->subscription_plan_id);
        $this->assertEquals('pro', $tenant->subscription_plan);
    }

    public function test_change_plan_period_end_preserves_billing_cycle(): void
    {
        $this->subscribeTenant(1001, 2, 20, 10);
        $originalStarted = Tenant::find(1001)->subscription_started_at;
        $originalExpires = Tenant::find(1001)->subscription_expires_at;

        PlanChangeService::changePlan(1001, 3, 'period_end');

        $tenant = Tenant::find(1001);
        $this->assertEquals($originalStarted->timestamp, $tenant->subscription_started_at->timestamp);
        $this->assertEquals($originalExpires->timestamp, $tenant->subscription_expires_at->timestamp);
    }

    public function test_change_plan_period_end_records_history_with_period_end_timing(): void
    {
        $this->subscribeTenant(1001, 2, 20, 10);
        $originalExpires = Tenant::find(1001)->subscription_expires_at;

        $history = PlanChangeService::changePlan(1001, 3, 'period_end');

        $this->assertEquals('upgrade', $history->action);
        $this->assertEquals('period_end', $history->metadata['effective_timing']);
        $this->assertEqualsWithDelta(0, $originalExpires->diffInSeconds($history->starts_at), 1);
        $this->assertEquals(0.0, (float) $history->proration_amount);
    }

    public function test_change_plan_downgrade_records_downgrade_action(): void
    {
        $this->subscribeTenant(1001, 3, 20, 10);

        $history = PlanChangeService::changePlan(1001, 2, 'immediate');

        $this->assertEquals('downgrade', $history->action);
        $this->assertEquals('pro', $history->from_plan);
        $this->assertEquals('basic', $history->to_plan);
    }

    // ---------- 异常场景 ----------

    public function test_change_plan_throws_for_same_plan(): void
    {
        $this->subscribeTenant(1001, 2, 20, 10);

        $this->expectException(\RuntimeException::class);
        PlanChangeService::changePlan(1001, 2, 'immediate');
    }

    public function test_change_plan_throws_for_inactive_plan(): void
    {
        $this->subscribeTenant(1001, 2, 20, 10);

        $this->expectException(\RuntimeException::class);
        PlanChangeService::changePlan(1001, 4, 'immediate');
    }

    public function test_change_plan_throws_for_suspended_tenant(): void
    {
        $this->subscribeTenant(1001, 2, 20, 10);
        $tenant = Tenant::find(1001);
        $tenant->status = 'suspended';
        $tenant->save();

        $this->expectException(\RuntimeException::class);
        PlanChangeService::changePlan(1001, 3, 'immediate');
    }

    public function test_change_plan_throws_for_unknown_tenant(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        PlanChangeService::changePlan(9999, 3, 'immediate');
    }

    // ---------- 变更历史记录 ----------

    public function test_get_change_history_returns_records(): void
    {
        $this->subscribeTenant(1001, 2, 20, 10);
        PlanChangeService::changePlan(1001, 3, 'immediate');
        PlanChangeService::changePlan(1001, 2, 'immediate');

        $history = PlanChangeService::getChangeHistory(1001);

        $this->assertEquals(2, $history->count());
        $this->assertContains($history->first()->action, ['upgrade', 'downgrade']);
    }

    public function test_get_change_history_returns_empty_for_new_tenant(): void
    {
        $history = PlanChangeService::getChangeHistory(1002);

        $this->assertEquals(0, $history->count());
    }

    public function test_get_change_history_isolates_by_tenant(): void
    {
        $this->subscribeTenant(1001, 2, 20, 10);
        PlanChangeService::changePlan(1001, 3, 'immediate');

        Tenant::create([
            'tenant_id' => 1003,
            'name' => 'Other Tenant',
            'slug' => 'other',
            'status' => 'active',
            'subscription_plan' => 'basic',
            'subscription_plan_id' => 2,
            'subscription_started_at' => now()->subDays(20),
            'subscription_expires_at' => now()->addDays(10),
        ]);
        TenantContext::setTenantId('1003');
        $this->subscribeTenant(1003, 2, 20, 10);
        PlanChangeService::changePlan(1003, 3, 'immediate');

        TenantContext::setTenantId('1001');
        $history1001 = PlanChangeService::getChangeHistory(1001);
        $this->assertEquals(1, $history1001->count());

        TenantContext::setTenantId('1003');
        $history1003 = PlanChangeService::getChangeHistory(1003);
        $this->assertEquals(1, $history1003->count());
    }

    // ---------- 辅助方法 ----------

    private function subscribeTenant(int $tenantId, int $planId, int $daysAgo, int $daysRemaining): void
    {
        $tenant = Tenant::find($tenantId);
        $tenant->subscription_plan_id = $planId;
        $tenant->subscription_plan = SubscriptionPlan::find($planId)->name;
        $tenant->subscription_started_at = now()->subDays($daysAgo);
        $tenant->subscription_expires_at = now()->addDays($daysRemaining);
        $tenant->status = 'active';
        $tenant->save();
    }
}
