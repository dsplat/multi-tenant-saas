<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Campaign\Models\CampaignPlan;
use MultiTenantSaas\Modules\Campaign\Models\CampaignTask;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Tests\Schema\CampaignModule;
use MultiTenantSaas\Tests\Schema\RbacModule;

/**
 * 活动日历（极简排期）API：
 * tasks 索引过滤 / manual-plan 创建 / quick-add(remind 映射) / PATCH 一键 done / DELETE 任务与活动
 */
class CampaignCalendarApiTest extends TestCase
{
    protected array $uses = [CampaignModule::class, RbacModule::class];

    private const API = '/api/v1/tenant/campaign';

    private Operator $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'T', 'slug' => 't', 'status' => 'active']);
        TenantContext::setTenantId('1001');

        $this->admin = $this->createOperator('admin@example.com', 3);
    }

    private function createOperator(string $email, int $roleId): Operator
    {
        $operator = Operator::create([
            'email' => $email,
            'name' => $email,
            'scope' => 'tenant',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        OperatorTenant::create([
            'operator_id' => $operator->operator_id,
            'tenant_id' => 1001,
            'role' => (string) $roleId,
            'role_id' => $roleId,
            'is_active' => true,
            'accepted_at' => now(),
        ]);

        return $operator;
    }

    private function manualPlan(string $name = '测试活动'): CampaignPlan
    {
        return CampaignPlan::create([
            'tenant_id' => 1001,
            'plan_doc' => ['schema' => 'campaign.plan/v1', 'manual' => true, 'title' => $name, 'phases' => []],
            'status' => CampaignPlan::STATUS_SCHEDULED,
            'created_by' => $this->admin->operator_id,
        ]);
    }

    // ========== manual-plan 创建 ==========

    public function test_store_manual_plan_creates_scheduled_plan(): void
    {
        $resp = $this->actingAs($this->admin, 'sanctum')
            ->postJson(self::API . '/manual-plans', ['name' => '线下课运营'])
            ->assertStatus(201);

        $this->assertEquals('scheduled', $resp->json('data.status'));
        $this->assertTrue($resp->json('data.plan_doc.manual'));
        $this->assertEquals('线下课运营', $resp->json('data.plan_doc.title'));
    }

    public function test_store_manual_plan_requires_name(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson(self::API . '/manual-plans', [])
            ->assertStatus(422);
    }

    // ========== quick-add 任务 ==========

    public function test_add_task_maps_remind_to_execution_mode(): void
    {
        $plan = $this->manualPlan();

        $resp = $this->actingAs($this->admin, 'sanctum')
            ->postJson(self::API . "/plans/{$plan->plan_id}/tasks", [
                'title' => '发布海报',
                'scheduled_at' => '2026-08-10 19:30:00',
                'remind' => true,
            ])
            ->assertStatus(201);

        $this->assertEquals('require_confirm', $resp->json('data.execution_mode'));
        $this->assertEquals('at_time', $resp->json('data.trigger_type'));
        $this->assertEquals('pending', $resp->json('data.status'));
        $this->assertStringContainsString('2026-08-10', $resp->json('data.scheduled_at'));
        $this->assertStringContainsString('19:30', $resp->json('data.scheduled_at'));
    }

    public function test_add_task_without_remind_is_auto(): void
    {
        $plan = $this->manualPlan();

        $resp = $this->actingAs($this->admin, 'sanctum')
            ->postJson(self::API . "/plans/{$plan->plan_id}/tasks", [
                'title' => '场地布置',
                'scheduled_at' => '2026-08-11 09:00:00',
            ])
            ->assertStatus(201);

        $this->assertEquals('auto', $resp->json('data.execution_mode'));
    }

    public function test_add_task_to_missing_plan_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson(self::API . '/plans/999999/tasks', [
                'title' => 'x',
                'scheduled_at' => '2026-08-11 09:00:00',
            ])
            ->assertStatus(404);
    }

    // ========== tasks 索引 ==========

    public function test_tasks_index_filters_by_plan_and_range(): void
    {
        $planA = $this->manualPlan('活动A');
        $planB = $this->manualPlan('活动B');

        CampaignTask::create(['tenant_id' => 1001, 'plan_id' => $planA->plan_id, 'task_key' => 'a1', 'title' => 'A1', 'trigger_type' => 'at_time', 'scheduled_at' => '2026-08-10 10:00:00', 'action' => [], 'status' => 'pending']);
        CampaignTask::create(['tenant_id' => 1001, 'plan_id' => $planA->plan_id, 'task_key' => 'a2', 'title' => 'A2', 'trigger_type' => 'at_time', 'scheduled_at' => '2026-09-10 10:00:00', 'action' => [], 'status' => 'pending']);
        CampaignTask::create(['tenant_id' => 1001, 'plan_id' => $planB->plan_id, 'task_key' => 'b1', 'title' => 'B1', 'trigger_type' => 'at_time', 'scheduled_at' => '2026-08-12 10:00:00', 'action' => [], 'status' => 'pending']);

        // 全局 + 8 月范围 → a1, b1（不含 9 月的 a2）
        $resp = $this->actingAs($this->admin, 'sanctum')
            ->getJson(self::API . '/tasks?from=2026-08-01&to=2026-08-31')
            ->assertOk();

        $titles = collect($resp->json('data'))->pluck('title')->all();
        $this->assertContains('A1', $titles);
        $this->assertContains('B1', $titles);
        $this->assertNotContains('A2', $titles);

        // 仅活动A → a1, a2
        $respA = $this->actingAs($this->admin, 'sanctum')
            ->getJson(self::API . "/tasks?plan_id={$planA->plan_id}")
            ->assertOk();

        $titlesA = collect($respA->json('data'))->pluck('title')->all();
        $this->assertEqualsCanonicalizing(['A1', 'A2'], $titlesA);
        $this->assertEquals('活动A', $respA->json('data.0.plan_name'));
    }

    // ========== PATCH 一键完成 ==========

    public function test_update_task_marks_done(): void
    {
        $plan = $this->manualPlan();
        $task = CampaignTask::create(['tenant_id' => 1001, 'plan_id' => $plan->plan_id, 'task_key' => 't1', 'title' => '待办', 'trigger_type' => 'at_time', 'scheduled_at' => '2026-08-10 10:00:00', 'action' => [], 'status' => 'pending']);

        $resp = $this->actingAs($this->admin, 'sanctum')
            ->patchJson(self::API . "/tasks/{$task->task_id}", ['status' => 'done'])
            ->assertOk();

        $this->assertEquals('done', $resp->json('data.status'));
        $this->assertNotNull($resp->json('data.executed_at'));
    }

    public function test_update_task_reschedules_and_toggles_remind(): void
    {
        $plan = $this->manualPlan();
        $task = CampaignTask::create(['tenant_id' => 1001, 'plan_id' => $plan->plan_id, 'task_key' => 't2', 'title' => '改期', 'trigger_type' => 'at_time', 'scheduled_at' => '2026-08-10 10:00:00', 'action' => [], 'execution_mode' => 'auto', 'status' => 'pending']);

        $resp = $this->actingAs($this->admin, 'sanctum')
            ->patchJson(self::API . "/tasks/{$task->task_id}", [
                'scheduled_at' => '2026-08-15 19:30:00',
                'remind' => true,
            ])
            ->assertOk();

        $this->assertEquals('require_confirm', $resp->json('data.execution_mode'));
        $this->assertStringContainsString('2026-08-15', $resp->json('data.scheduled_at'));
        $this->assertStringContainsString('19:30', $resp->json('data.scheduled_at'));
    }

    public function test_update_terminal_task_rejected(): void
    {
        $plan = $this->manualPlan();
        $task = CampaignTask::create(['tenant_id' => 1001, 'plan_id' => $plan->plan_id, 'task_key' => 't3', 'title' => '已完成', 'trigger_type' => 'at_time', 'scheduled_at' => '2026-08-10 10:00:00', 'action' => [], 'status' => 'done']);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson(self::API . "/tasks/{$task->task_id}", ['title' => '改不了'])
            ->assertStatus(422);
    }

    // ========== DELETE ==========

    public function test_delete_task(): void
    {
        $plan = $this->manualPlan();
        $task = CampaignTask::create(['tenant_id' => 1001, 'plan_id' => $plan->plan_id, 'task_key' => 'd1', 'title' => '删我', 'trigger_type' => 'at_time', 'scheduled_at' => '2026-08-10 10:00:00', 'action' => [], 'status' => 'pending']);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson(self::API . "/tasks/{$task->task_id}")
            ->assertOk();

        $this->assertDatabaseMissing('campaign_tasks', ['task_id' => $task->task_id]);
    }

    public function test_delete_manual_plan_cascades_tasks(): void
    {
        $plan = $this->manualPlan();
        CampaignTask::create(['tenant_id' => 1001, 'plan_id' => $plan->plan_id, 'task_key' => 'c1', 'title' => '级联', 'trigger_type' => 'at_time', 'scheduled_at' => '2026-08-10 10:00:00', 'action' => [], 'status' => 'pending']);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson(self::API . "/plans/{$plan->plan_id}")
            ->assertOk();

        $this->assertDatabaseMissing('campaign_plans', ['plan_id' => $plan->plan_id]);
        $this->assertDatabaseMissing('campaign_tasks', ['plan_id' => $plan->plan_id]);
    }

    public function test_delete_dsl_plan_rejected(): void
    {
        $plan = CampaignPlan::create([
            'tenant_id' => 1001,
            'plan_doc' => ['schema' => 'campaign.plan/v1', 'title' => 'DSL 计划', 'phases' => []],
            'status' => CampaignPlan::STATUS_SCHEDULED,
            'created_by' => $this->admin->operator_id,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson(self::API . "/plans/{$plan->plan_id}")
            ->assertStatus(422);
    }
}
