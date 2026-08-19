<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\ThreadAssetProbeContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\ActivityPlan\Models\ActivityPlan;
use MultiTenantSaas\Modules\ActivityPlan\Models\ActivityTask;
use MultiTenantSaas\Modules\ActivityPlan\Services\PlanCompiler;
use MultiTenantSaas\Modules\ActivityPlan\Services\Tools\ActivityPlanCommitTool;
use MultiTenantSaas\Modules\ActivityPlan\Services\Tools\ThreadReviewTool;
use MultiTenantSaas\Modules\ActivityPlan\Services\Tools\ThreadTrackTool;
use MultiTenantSaas\Modules\ActivityPlan\Services\Tools\ThreadUntrackTool;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\ActivityPlanModule;

/**
 * 工作脉络三工具单测（项目大脑 Phase 2）
 *
 * - thread_review：脉络快照聚合（计划/任务/资产探测/关联会话）
 * - thread_track：建立跟踪（既有计划标记 / 无计划创建轻量载体）
 * - thread_untrack：取消跟踪
 * - activity_plan_commit：定稿自动置 tracked（天然脉络）
 */
class ThreadToolsTest extends TestCase
{
    protected array $uses = [ActivityPlanModule::class, AgentModule::class];

    private const TENANT = 3001;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::setTenantId((string) self::TENANT);
    }

    // ── thread_review ──

    public function test_review_returns_thread_snapshot_by_anchor(): void
    {
        $plan = ActivityPlan::create([
            'tenant_id' => self::TENANT,
            'anchor_type' => 'event',
            'anchor_id' => 88,
            'plan_doc' => ['schema' => 'activity.plan/v1', 'title' => '21天训练营', 'phases' => []],
            'status' => ActivityPlan::STATUS_RUNNING,
            'metadata' => ['tracked' => true, 'health' => ['summary' => '已停滞 3 天']],
            'created_by' => 0,
        ]);
        ActivityTask::create([
            'tenant_id' => self::TENANT, 'plan_id' => $plan->plan_id,
            'task_key' => 't1', 'title' => '策划', 'trigger_type' => ActivityTask::TRIGGER_AT_TIME,
            'scheduled_at' => now()->subDays(3), 'action' => ['type' => 'human'],
            'status' => ActivityTask::STATUS_DONE,
        ]);
        ActivityTask::create([
            'tenant_id' => self::TENANT, 'plan_id' => $plan->plan_id,
            'task_key' => 't2', 'title' => '群发', 'trigger_type' => ActivityTask::TRIGGER_AT_TIME,
            'scheduled_at' => now()->subDay(), 'action' => ['type' => 'human'],
            'status' => ActivityTask::STATUS_PENDING,
        ]);

        $result = (new ThreadReviewTool)(['anchor_type' => 'event', 'anchor_id' => 88], self::TENANT);

        $this->assertFalse($result['error'] ?? false);
        $this->assertSame(['type' => 'event', 'id' => 88], $result['thread']['anchor']);
        $this->assertTrue($result['thread']['tracked']);
        $this->assertNull($result['note']);
        $this->assertCount(1, $result['plans']);
        $snapshot = $result['plans'][0];
        $this->assertSame('21天训练营', $snapshot['title']);
        $this->assertSame(2, $snapshot['tasks_total']);
        $this->assertSame(1, $snapshot['tasks_by_status']['done']);
        $this->assertCount(1, $snapshot['overdue_tasks']);
        $this->assertSame('t2', $snapshot['overdue_tasks'][0]['key']);
        $this->assertSame('已停滞 3 天', $snapshot['health']['summary']);
    }

    public function test_review_reports_empty_thread(): void
    {
        $result = (new ThreadReviewTool)(['anchor_type' => 'event', 'anchor_id' => 777], self::TENANT);

        $this->assertFalse($result['error'] ?? false);
        $this->assertSame([], $result['plans']);
        $this->assertFalse($result['thread']['tracked']);
        $this->assertNotNull($result['note']);
    }

    public function test_review_aggregates_asset_probe_facts(): void
    {
        config(['ai.brain.asset_probes' => [ThreadStubAssetProbe::class]]);

        $result = (new ThreadReviewTool)(['anchor_type' => 'event', 'anchor_id' => 88], self::TENANT);

        $this->assertSame(['linked' => false], $result['assets']['poster']);
        $this->assertSame(['count' => 2], $result['assets']['coupon']);
    }

    public function test_review_includes_related_conversations(): void
    {
        ActivityPlan::create([
            'tenant_id' => self::TENANT,
            'anchor_type' => 'event',
            'anchor_id' => 88,
            'plan_doc' => ['schema' => 'activity.plan/v1', 'title' => '21天训练营', 'phases' => []],
            'status' => ActivityPlan::STATUS_PLANNING,
            'created_by' => 0,
        ]);
        AgentConversation::create([
            'tenant_id' => self::TENANT,
            'agent_id' => 1,
            'channel' => 'assistant',
            'subject' => '讨论21天训练营的策划',
            'status' => 'active',
            'summary' => '用户希望九月开营',
        ]);

        $result = (new ThreadReviewTool)(['anchor_type' => 'event', 'anchor_id' => 88], self::TENANT);

        $this->assertCount(1, $result['conversations']);
        $this->assertSame('讨论21天训练营的策划', $result['conversations'][0]['subject']);
    }

    public function test_review_requires_locator(): void
    {
        $result = (new ThreadReviewTool)([], self::TENANT);

        $this->assertTrue($result['error']);
    }

    // ── thread_track / thread_untrack ──

    public function test_track_marks_existing_plan(): void
    {
        $plan = ActivityPlan::create([
            'tenant_id' => self::TENANT,
            'anchor_type' => 'event',
            'anchor_id' => 88,
            'plan_doc' => ['schema' => 'activity.plan/v1', 'title' => '21天训练营', 'phases' => []],
            'status' => ActivityPlan::STATUS_PLANNING,
            'created_by' => 0,
        ]);

        $result = (new ThreadTrackTool)(['anchor_type' => 'event', 'anchor_id' => 88], self::TENANT);

        $this->assertFalse($result['error'] ?? false);
        $this->assertSame($plan->plan_id, $result['plan_id']);
        $this->assertFalse($result['created']);
        $this->assertTrue((bool) data_get($plan->fresh()->metadata, 'tracked'));
    }

    public function test_track_creates_lightweight_plan_for_bare_anchor(): void
    {
        $result = (new ThreadTrackTool)([
            'anchor_type' => 'customer',
            'anchor_id' => 55,
            'title' => '大客户跟进',
            'note' => '每周回访',
        ], self::TENANT);

        $this->assertFalse($result['error'] ?? false);
        $this->assertTrue($result['created']);

        $plan = ActivityPlan::find($result['plan_id']);
        $this->assertSame('customer', $plan->anchor_type);
        $this->assertSame(55, (int) $plan->anchor_id);
        $this->assertSame(ActivityPlan::STATUS_PLANNING, $plan->status);
        $this->assertSame('大客户跟进', $plan->plan_doc['title']);
        $this->assertSame('每周回访', $plan->plan_doc['tracking_note']);
        $this->assertTrue((bool) data_get($plan->metadata, 'tracked'));
    }

    public function test_track_rejects_missing_plan(): void
    {
        $result = (new ThreadTrackTool)(['plan_id' => 999999], self::TENANT);

        $this->assertTrue($result['error']);
    }

    public function test_untrack_clears_tracked_flag(): void
    {
        $plan = ActivityPlan::create([
            'tenant_id' => self::TENANT,
            'plan_doc' => ['schema' => 'activity.plan/v1', 'title' => '训练营', 'phases' => []],
            'status' => ActivityPlan::STATUS_RUNNING,
            'metadata' => ['tracked' => true],
            'created_by' => 0,
        ]);

        $result = (new ThreadUntrackTool)(['plan_id' => $plan->plan_id], self::TENANT);

        $this->assertFalse($result['error'] ?? false);
        $this->assertFalse((bool) data_get($plan->fresh()->metadata, 'tracked'));
    }

    // ── commit 自动跟踪 ──

    public function test_commit_marks_plan_tracked(): void
    {
        $this->app->make(ToolRegistryContract::class)->register(
            'send_sms', 'Stub send_sms', 'Stub tool for testing',
            ThreadStubToolHandler::class,
            ['type' => 'object', 'properties' => []], 'test', 'L1',
        );

        $plan = ActivityPlan::create([
            'tenant_id' => self::TENANT,
            'plan_doc' => [
                'schema' => 'activity.plan/v1',
                'title' => '测试活动',
                'phases' => [[
                    'key' => 'notify',
                    'tasks' => [[
                        'key' => 'sms_d1',
                        'title' => 'D+1 短信',
                        'trigger' => ['type' => 'relative', 'anchor' => 'event.starts_at', 'offset' => '+1d', 'at' => '09:00'],
                        'action' => ['type' => 'tool', 'tool' => 'send_sms'],
                        'execution_mode' => 'auto',
                    ]],
                ]],
            ],
            'status' => ActivityPlan::STATUS_PLANNING,
            'created_by' => 0,
        ]);

        $tool = new ActivityPlanCommitTool($this->app->make(PlanCompiler::class));
        $result = $tool([
            'plan_id' => $plan->plan_id,
            'anchor_times' => ['event.starts_at' => '2026-09-01 09:00'],
        ], self::TENANT);

        $this->assertFalse($result['error'] ?? false);
        $this->assertTrue((bool) data_get($plan->fresh()->metadata, 'tracked'));
    }
}

class ThreadStubAssetProbe implements ThreadAssetProbeContract
{
    public function supports(string $anchorType): bool
    {
        return $anchorType === 'event';
    }

    public function probe(string $anchorType, int $anchorId, int $tenantId): array
    {
        return ['poster' => ['linked' => false], 'coupon' => ['count' => 2]];
    }
}

class ThreadStubToolHandler implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return ['ok' => true];
    }
}
