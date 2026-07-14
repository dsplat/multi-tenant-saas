<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Billing\Models\SubscriptionHistory;
use MultiTenantSaas\Modules\Billing\Models\SubscriptionPlan;
use MultiTenantSaas\Modules\Billing\Services\SubscriptionService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\BillingModule;

/**
 * SubscriptionService 单元测试
 *
 * 覆盖：订阅计划列表、订阅创建（免费/付费）、订阅升级/降级、订阅取消、订阅历史记录
 */
class SubscriptionServiceTest extends TestCase
{
    protected array $uses = [BillingModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        // 创建订阅计划
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
        });

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Sub Tenant',
            'slug' => 'sub-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId('1001');
    }

    // ---------- 订阅创建 ----------

    public function test_subscribe_to_free_plan(): void
    {
        $tenant = SubscriptionService::subscribe(1001, 1, 'monthly');

        $this->assertEquals('free', $tenant->subscription_plan);
        $this->assertTrue($tenant->auto_renew);
        $this->assertNotNull($tenant->subscription_started_at);
        $this->assertNotNull($tenant->subscription_expires_at);

        $history = SubscriptionHistory::where('tenant_id', '1001')->first();
        $this->assertNotNull($history);
        $this->assertEquals('subscribe', $history->action);
    }

    public function test_subscribe_to_paid_plan_monthly(): void
    {
        $tenant = SubscriptionService::subscribe(1001, 2, 'monthly');

        $this->assertEquals('basic', $tenant->subscription_plan);
        $this->assertTrue($tenant->auto_renew);

        $expiresAt = $tenant->subscription_expires_at;
        $this->assertNotNull($expiresAt);
        $this->assertTrue($expiresAt->isFuture());
    }

    public function test_subscribe_to_paid_plan_yearly(): void
    {
        $tenant = SubscriptionService::subscribe(1001, 3, 'yearly');

        $this->assertEquals('pro', $tenant->subscription_plan);

        $expiresAt = $tenant->subscription_expires_at;
        $this->assertNotNull($expiresAt);
        // Yearly subscription should be ~1 year from now
        $this->assertTrue($expiresAt->greaterThan(now()->addMonths(11)));
    }

    public function test_subscribe_with_trial(): void
    {
        $tenant = SubscriptionService::subscribe(1001, 2, 'monthly', true);

        $this->assertEquals('basic', $tenant->subscription_plan);
        $this->assertNotNull($tenant->trial_ends_at);
        $this->assertFalse($tenant->auto_renew);

        $history = SubscriptionHistory::where('tenant_id', '1001')
            ->where('action', 'trial')
            ->first();
        $this->assertNotNull($history);
    }

    public function test_start_trial(): void
    {
        $tenant = SubscriptionService::startTrial(1001, 2);

        $this->assertEquals('basic', $tenant->subscription_plan);
        $this->assertNotNull($tenant->trial_ends_at);
    }

    public function test_subscribe_throws_for_inactive_plan(): void
    {
        SubscriptionPlan::unguarded(function () {
            SubscriptionPlan::create([
                'subscription_plan_id' => 4,
                'name' => 'inactive',
                'display_name' => 'Inactive',
                'price_monthly' => 50,
                'is_active' => false,
            ]);
        });

        $this->expectException(\RuntimeException::class);
        SubscriptionService::subscribe(1001, 4);
    }

    // ---------- 订阅取消 ----------

    public function test_cancel_subscription(): void
    {
        SubscriptionService::subscribe(1001, 2, 'monthly');

        $tenant = SubscriptionService::cancel(1001);

        $this->assertFalse($tenant->auto_renew);

        $history = SubscriptionHistory::where('tenant_id', '1001')
            ->where('action', 'cancel')
            ->first();
        $this->assertNotNull($history);
    }

    // ---------- 订阅升级/降级 ----------

    public function test_change_plan_upgrade(): void
    {
        SubscriptionService::subscribe(1001, 2, 'monthly');

        $tenant = SubscriptionService::changePlan(1001, 3, 'monthly');

        $this->assertEquals('pro', $tenant->subscription_plan);

        $history = SubscriptionHistory::where('tenant_id', '1001')
            ->where('action', 'upgrade')
            ->first();
        $this->assertNotNull($history);
    }

    public function test_change_plan_downgrade(): void
    {
        SubscriptionService::subscribe(1001, 3, 'monthly');

        $tenant = SubscriptionService::changePlan(1001, 2, 'monthly');

        $this->assertEquals('basic', $tenant->subscription_plan);

        $history = SubscriptionHistory::where('tenant_id', '1001')
            ->where('action', 'downgrade')
            ->first();
        $this->assertNotNull($history);
    }

    // ---------- 订阅历史 ----------

    public function test_get_history_returns_paginated_results(): void
    {
        SubscriptionService::subscribe(1001, 2, 'monthly');
        SubscriptionService::cancel(1001);

        $history = SubscriptionService::getHistory(1001);

        $this->assertGreaterThan(0, $history->total());
        $this->assertContains($history->items()[0]->action, ['subscribe', 'cancel']);
    }

    public function test_get_history_is_empty_for_new_tenant(): void
    {
        Tenant::create(['tenant_id' => 1002, 'name' => 'Empty', 'slug' => 'empty', 'status' => 'active']);

        $history = SubscriptionService::getHistory(1002);

        $this->assertEquals(0, $history->total());
    }

    // ---------- 当前计划查询 ----------

    public function test_get_current_plan_returns_free_by_default(): void
    {
        $plan = SubscriptionService::getCurrentPlan(1001);

        $this->assertNotNull($plan);
        $this->assertEquals('free', $plan->name);
    }

    public function test_get_current_plan_returns_subscribed_plan(): void
    {
        SubscriptionService::subscribe(1001, 2, 'monthly');

        $plan = SubscriptionService::getCurrentPlan(1001);

        $this->assertEquals('basic', $plan->name);
    }

    // ---------- 试用期检查 ----------

    public function test_is_in_trial_returns_true_during_trial(): void
    {
        $tenant = SubscriptionService::startTrial(1001, 2);

        $this->assertTrue(SubscriptionService::isInTrial($tenant));
    }

    public function test_is_in_trial_returns_false_without_trial(): void
    {
        $tenant = SubscriptionService::subscribe(1001, 2, 'monthly', false);

        $this->assertFalse(SubscriptionService::isInTrial($tenant));
    }

    // ---------- 按比例计算 ----------

    public function test_calculate_proration_returns_zero_for_same_plan(): void
    {
        $tenant = Tenant::find(1001);
        $plan = SubscriptionPlan::find(2);

        $proration = SubscriptionService::calculateProration($tenant, $plan, $plan);

        $this->assertEquals(0.0, $proration);
    }

    public function test_calculate_proration_returns_zero_for_expired_subscription(): void
    {
        $tenant = Tenant::find(1001);
        $oldPlan = SubscriptionPlan::find(2);
        $newPlan = SubscriptionPlan::find(3);

        // subscription_expires_at is null for a fresh tenant
        $proration = SubscriptionService::calculateProration($tenant, $oldPlan, $newPlan);

        $this->assertEquals(0.0, $proration);
    }
}
