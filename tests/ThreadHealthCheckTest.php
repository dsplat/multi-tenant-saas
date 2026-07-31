<?php

namespace MultiTenantSaas\Tests;

use Carbon\Carbon;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Campaign\Models\CampaignPlan;
use MultiTenantSaas\Modules\Campaign\Models\CampaignTask;
use MultiTenantSaas\Tests\Schema\CampaignModule;
use MultiTenantSaas\Tests\Schema\NotificationModule;

/**
 * thread:health-check 巡检单测（项目大脑 Phase 3）
 *
 * 纯规则巡检：逾期/失败/临近里程碑/停滞 → metadata.health（含 summary）；
 * 只扫 tracked 活跃脉络；写入不触发 updated_at。
 */
class ThreadHealthCheckTest extends TestCase
{
    protected array $uses = [CampaignModule::class, NotificationModule::class];

    private const TENANT = 5001;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::setTenantId((string) self::TENANT);
        config(['ai.campaign.enabled' => true, 'ai.brain.enabled' => true]);
    }

    private function createTrackedPlan(array $overrides = []): CampaignPlan
    {
        return CampaignPlan::create(array_merge([
            'tenant_id' => self::TENANT,
            'plan_doc' => ['schema' => 'campaign.plan/v1', 'title' => '21天训练营', 'phases' => []],
            'status' => CampaignPlan::STATUS_RUNNING,
            'metadata' => ['tracked' => true],
            'created_by' => 0,
        ], $overrides));
    }

    private function createTask(int $planId, string $key, string $status, ?Carbon $scheduledAt): CampaignTask
    {
        return CampaignTask::create([
            'tenant_id' => self::TENANT,
            'plan_id' => $planId,
            'task_key' => $key,
            'title' => $key,
            'trigger_type' => CampaignTask::TRIGGER_AT_TIME,
            'scheduled_at' => $scheduledAt,
            'action' => ['type' => 'human'],
            'status' => $status,
        ]);
    }

    public function test_writes_health_with_overdue_and_upcoming(): void
    {
        $plan = $this->createTrackedPlan();
        $this->createTask($plan->plan_id, 'overdue1', CampaignTask::STATUS_PENDING, Carbon::now()->subDay());
        $this->createTask($plan->plan_id, 'overdue2', CampaignTask::STATUS_AWAITING_CONFIRM, Carbon::now()->subHours(2));
        $this->createTask($plan->plan_id, 'upcoming', CampaignTask::STATUS_PENDING, Carbon::now()->addDay());
        $this->createTask($plan->plan_id, 'far', CampaignTask::STATUS_PENDING, Carbon::now()->addDays(10));
        $this->createTask($plan->plan_id, 'done', CampaignTask::STATUS_DONE, Carbon::now()->subDays(2));

        $this->artisan('thread:health-check')->assertSuccessful();

        $health = data_get($plan->fresh()->metadata, 'health');
        $this->assertSame(2, $health['overdue_count']);
        $this->assertSame(1, $health['upcoming_count']);
        $this->assertSame(0, $health['failed_count']);
        $this->assertSame(0, $health['stalled_days']);
        $this->assertStringContainsString('2 项任务逾期', $health['summary']);
        $this->assertStringContainsString('3 天内 1 项任务到点', $health['summary']);
        $this->assertArrayNotHasKey('alert', $health);
    }

    public function test_reports_stalled_thread_without_tasks(): void
    {
        $plan = $this->createTrackedPlan(['status' => CampaignPlan::STATUS_PLANNING]);
        // 模拟 10 天前建立跟踪、至今无任何任务
        CampaignPlan::where('plan_id', $plan->plan_id)
            ->update(['created_at' => Carbon::now()->subDays(10)]);

        $this->artisan('thread:health-check')->assertSuccessful();

        $health = data_get($plan->fresh()->metadata, 'health');
        $this->assertSame(10, $health['stalled_days']);
        $this->assertStringContainsString('已停滞 10 天', $health['summary']);
    }

    public function test_healthy_plan_reports_normal(): void
    {
        $plan = $this->createTrackedPlan();
        $this->createTask($plan->plan_id, 'future', CampaignTask::STATUS_PENDING, Carbon::now()->addDays(5));

        $this->artisan('thread:health-check')->assertSuccessful();

        $this->assertSame('进展正常', data_get($plan->fresh()->metadata, 'health.summary'));
    }

    public function test_skips_untracked_and_inactive_plans(): void
    {
        $untracked = $this->createTrackedPlan(['metadata' => null]);
        $closed = $this->createTrackedPlan(['status' => CampaignPlan::STATUS_CLOSED]);

        $this->artisan('thread:health-check')->assertSuccessful();

        $this->assertNull(data_get($untracked->fresh()->metadata, 'health'));
        $this->assertNull(data_get($closed->fresh()->metadata, 'health'));
    }

    public function test_persist_does_not_touch_updated_at(): void
    {
        $plan = $this->createTrackedPlan();
        $original = Carbon::now()->subDays(3)->startOfSecond();
        CampaignPlan::where('plan_id', $plan->plan_id)
            ->update(['created_at' => $original, 'updated_at' => $original]);

        $this->artisan('thread:health-check')->assertSuccessful();

        $fresh = $plan->fresh();
        $this->assertNotNull(data_get($fresh->metadata, 'health'));
        $this->assertTrue($fresh->updated_at->equalTo($original), '巡检写入不应刷新 updated_at');
    }

    public function test_disabled_brain_skips(): void
    {
        config(['ai.brain.enabled' => false]);

        $plan = $this->createTrackedPlan();
        $this->createTask($plan->plan_id, 'overdue', CampaignTask::STATUS_PENDING, Carbon::now()->subDay());

        $this->artisan('thread:health-check')->assertSuccessful();

        $this->assertNull(data_get($plan->fresh()->metadata, 'health'));
    }
}
