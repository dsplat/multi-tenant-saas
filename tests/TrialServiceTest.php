<?php

namespace MultiTenantSaas\Tests;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\SubscriptionPlan;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\TrialService;
use MultiTenantSaas\Tests\Schema\BillingModule;

class TrialServiceTest extends TestCase
{
    protected array $uses = [BillingModule::class];

    private TrialService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TrialService;

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
            'name' => 'Trial Tenant A',
            'slug' => 'trial-tenant-a',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);
        Tenant::create([
            'tenant_id' => 1002,
            'name' => 'Trial Tenant B',
            'slug' => 'trial-tenant-b',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId(1001);
    }

    // ---------- 试用期启动 ----------

    public function test_start_trial_default_days(): void
    {
        $tenant = TrialService::startTrial(1001, 1);

        $this->assertNotNull($tenant->trial_ends_at);
        $this->assertEqualsWithDelta(14, now()->diffInDays(Carbon::parse($tenant->trial_ends_at)), 1);
    }

    public function test_start_trial_custom_days(): void
    {
        $tenant = TrialService::startTrial(1001, 1, 30);

        $this->assertEqualsWithDelta(30, now()->diffInDays(Carbon::parse($tenant->trial_ends_at)), 1);
    }

    public function test_start_trial_plan_based_days(): void
    {
        $tenant = TrialService::startTrial(1001, 2);

        $this->assertNotNull($tenant->trial_ends_at);
        $this->assertEqualsWithDelta(7, now()->diffInDays(Carbon::parse($tenant->trial_ends_at)), 1);
    }

    public function test_start_trial_pro_plan_fourteen_days(): void
    {
        $tenant = TrialService::startTrial(1001, 3);

        $this->assertEqualsWithDelta(14, now()->diffInDays(Carbon::parse($tenant->trial_ends_at)), 1);
    }

    public function test_start_trial_sets_trial_ends_at(): void
    {
        $tenant = Tenant::find(1001);
        $this->assertNull($tenant->trial_ends_at);

        $tenant = TrialService::startTrial(1001, 1);

        $this->assertNotNull($tenant->trial_ends_at);
        $this->assertTrue(Carbon::parse($tenant->trial_ends_at)->isFuture());
    }

    public function test_start_trial_throws_for_unknown_tenant(): void
    {
        $this->expectException(ModelNotFoundException::class);
        TrialService::startTrial(9999, 1);
    }

    // ---------- 试用延长 ----------

    public function test_extend_trial(): void
    {
        TrialService::startTrial(1001, 1, 7);

        $tenant = TrialService::extendTrial(1001, 7);

        $this->assertTrue(Carbon::parse($tenant->trial_ends_at)->isFuture());
    }

    public function test_extend_trial_marks_extended(): void
    {
        TrialService::startTrial(1001, 1, 7);

        $tenant = TrialService::extendTrial(1001, 7);

        $this->assertTrue($tenant->trial_extended);
    }

    public function test_extend_trial_adds_days(): void
    {
        $tenant = TrialService::startTrial(1001, 1, 7);
        $originalEnd = Carbon::parse($tenant->trial_ends_at);

        $tenant = TrialService::extendTrial(1001, 5);
        $newEnd = Carbon::parse($tenant->trial_ends_at);

        $this->assertEquals(5, $originalEnd->diffInDays($newEnd));
    }

    public function test_extend_trial_throws_without_active_trial(): void
    {
        $this->expectException(\RuntimeException::class);
        TrialService::extendTrial(1001, 7);
    }

    // ---------- 状态查询 ----------

    public function test_is_in_trial_true_during_trial(): void
    {
        TrialService::startTrial(1001, 1, 14);

        $tenant = Tenant::find(1001);
        $this->assertTrue(TrialService::isInTrial($tenant));
    }

    public function test_is_in_trial_false_without_trial(): void
    {
        $tenant = Tenant::find(1001);
        $this->assertFalse(TrialService::isInTrial($tenant));
    }

    public function test_is_in_trial_false_when_expired(): void
    {
        $tenant = Tenant::find(1001);
        $tenant->trial_ends_at = now()->subDay();
        $tenant->save();

        $this->assertFalse(TrialService::isInTrial($tenant));
    }

    public function test_get_trial_status_during_trial(): void
    {
        TrialService::startTrial(1001, 1, 14);

        $status = TrialService::getTrialStatus(1001);

        $this->assertTrue($status['in_trial']);
        $this->assertFalse($status['is_extended']);
        $this->assertGreaterThan(0, $status['days_remaining']);
        $this->assertNotNull($status['trial_ends_at']);
    }

    public function test_get_trial_status_without_trial(): void
    {
        $status = TrialService::getTrialStatus(1001);

        $this->assertFalse($status['in_trial']);
        $this->assertEquals(0, $status['days_remaining']);
        $this->assertFalse($status['is_extended']);
    }

    // ---------- 到期提醒 ----------

    public function test_process_expiring_trials_sends_notifications(): void
    {
        $this->setTrialEndsAt(1001, now()->addDays(3));

        $count = $this->service->processExpiringTrials();

        $this->assertEquals(1, $count);
        $this->assertNotNull(Tenant::find(1001)->trial_notification_sent_at);
    }

    public function test_process_expiring_trials_3_day_threshold(): void
    {
        $this->setTrialEndsAt(1001, now()->addDays(3));

        $count = $this->service->processExpiringTrials();

        $this->assertEquals(1, $count);
    }

    public function test_process_expiring_trials_1_day_threshold(): void
    {
        $this->setTrialEndsAt(1001, now()->addDay());

        $count = $this->service->processExpiringTrials();

        $this->assertEquals(1, $count);
    }

    public function test_process_expiring_trials_same_day_threshold(): void
    {
        $this->setTrialEndsAt(1001, now()->endOfDay());

        $count = $this->service->processExpiringTrials();

        $this->assertEquals(1, $count);
    }

    public function test_process_expiring_trials_skips_far_future(): void
    {
        $this->setTrialEndsAt(1001, now()->addDays(10));

        $count = $this->service->processExpiringTrials();

        $this->assertEquals(0, $count);
        $this->assertNull(Tenant::find(1001)->trial_notification_sent_at);
    }

    public function test_process_expiring_trials_skips_already_notified(): void
    {
        $tenant = Tenant::find(1001);
        $tenant->trial_ends_at = now()->addDays(3);
        $tenant->trial_notification_sent_at = now();
        $tenant->save();

        $count = $this->service->processExpiringTrials();

        $this->assertEquals(0, $count);
    }

    public function test_process_expiring_trials_skips_without_trial(): void
    {
        $count = $this->service->processExpiringTrials();

        $this->assertEquals(0, $count);
    }

    public function test_process_expiring_trials_handles_multiple_tenants(): void
    {
        $this->setTrialEndsAt(1001, now()->addDays(3));
        $this->setTrialEndsAt(1002, now()->addDay());

        $count = $this->service->processExpiringTrials();

        $this->assertEquals(2, $count);
    }

    // ---------- 到期处理 ----------

    public function test_process_expired_trials_suspends_tenant(): void
    {
        $this->setTrialEndsAt(1001, now()->subDay());

        $count = $this->service->processExpiredTrials();

        $this->assertEquals(1, $count);
        $this->assertEquals('suspended', Tenant::find(1001)->status);
    }

    public function test_process_expired_trials_returns_count(): void
    {
        $this->setTrialEndsAt(1001, now()->subDay());
        $this->setTrialEndsAt(1002, now()->subDay());

        $count = $this->service->processExpiredTrials();

        $this->assertEquals(2, $count);
    }

    public function test_process_expired_trials_skips_active_trials(): void
    {
        TrialService::startTrial(1001, 1, 14);

        $count = $this->service->processExpiredTrials();

        $this->assertEquals(0, $count);
        $this->assertEquals('active', Tenant::find(1001)->status);
    }

    public function test_process_expired_trials_skips_without_trial(): void
    {
        $count = $this->service->processExpiredTrials();

        $this->assertEquals(0, $count);
    }

    public function test_process_expired_trials_clears_trial_ends_at(): void
    {
        $this->setTrialEndsAt(1001, now()->subDay());

        $this->service->processExpiredTrials();

        $this->assertNull(Tenant::find(1001)->trial_ends_at);
    }

    // ---------- 辅助方法 ----------

    private function setTrialEndsAt(int $tenantId, $endsAt): void
    {
        $tenant = Tenant::find($tenantId);
        $tenant->trial_ends_at = $endsAt;
        $tenant->save();
    }
}
