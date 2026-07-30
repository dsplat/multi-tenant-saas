<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Campaign\Models\CampaignPlan;
use MultiTenantSaas\Modules\Campaign\Models\CampaignTask;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\AiModule;
use MultiTenantSaas\Tests\Schema\CampaignModule;
use MultiTenantSaas\Tests\Schema\RbacModule;

/**
 * Campaign 管理 API：403（member）/ 建计划校验 422 / compile / approve 后执行 / complete
 */
class CampaignAdminControllerTest extends TestCase
{
    protected array $uses = [CampaignModule::class, AgentModule::class, AiModule::class, RbacModule::class];

    private const API = '/api/v1/tenant/campaign';

    private Operator $admin;

    private Operator $member;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'T', 'slug' => 't', 'status' => 'active']);
        TenantContext::setTenantId('1001');

        $this->admin = $this->createOperator('admin@example.com', 3);
        $this->member = $this->createOperator('member@example.com', 4);
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

    private function validPlanDoc(): array
    {
        return [
            'schema' => 'campaign.plan/v1',
            'title' => '测试计划',
            'phases' => [
                [
                    'key' => 'phase1',
                    'tasks' => [
                        [
                            'key' => 'task_a',
                            'title' => '任务A',
                            'trigger' => ['type' => 'relative', 'anchor' => 'event.starts_at', 'offset' => '-1d'],
                            'action' => ['type' => 'notify', 'channel' => 'sms'],
                            'execution_mode' => 'auto',
                        ],
                    ],
                ],
            ],
        ];
    }

    // ========== 权限 ==========

    public function test_member_gets_403(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->getJson(self::API . '/plans')
            ->assertStatus(403);
    }

    // ========== 建计划 ==========

    public function test_store_validates_plan_doc(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson(self::API . '/plans', ['plan_doc' => ['schema' => 'bad']])
            ->assertStatus(422);
    }

    public function test_store_creates_planning_plan(): void
    {
        $resp = $this->actingAs($this->admin, 'sanctum')
            ->postJson(self::API . '/plans', ['plan_doc' => $this->validPlanDoc()])
            ->assertStatus(201);

        $this->assertEquals('planning', $resp->json('data.status'));
    }

    // ========== compile ==========

    public function test_compile_creates_tasks(): void
    {
        $plan = CampaignPlan::create([
            'tenant_id' => 1001,
            'plan_doc' => $this->validPlanDoc(),
            'status' => 'planning',
            'created_by' => $this->admin->operator_id,
        ]);

        $resp = $this->actingAs($this->admin, 'sanctum')
            ->postJson(self::API . "/plans/{$plan->plan_id}/compile", [
                'anchor_times' => ['event.starts_at' => '2026-09-01 09:00'],
            ])
            ->assertOk();

        $this->assertEquals('scheduled', $resp->json('data.status'));
        $this->assertNotEmpty($resp->json('data.tasks'));
    }

    // ========== approve / reject ==========

    public function test_approve_executes_task(): void
    {
        $plan = CampaignPlan::create([
            'tenant_id' => 1001,
            'plan_doc' => $this->validPlanDoc(),
            'status' => 'running',
            'created_by' => $this->admin->operator_id,
        ]);

        $task = CampaignTask::create([
            'tenant_id' => 1001,
            'plan_id' => $plan->plan_id,
            'task_key' => 'task_a',
            'title' => '任务A',
            'trigger_type' => 'at_time',
            'action' => ['type' => 'tool', 'tool' => 'mass_push', 'args' => []],
            'execution_mode' => 'require_confirm',
            'status' => 'awaiting_confirm',
        ]);

        $resp = $this->actingAs($this->admin, 'sanctum')
            ->postJson(self::API . "/tasks/{$task->task_id}/approve")
            ->assertOk();

        // 工具执行后应为 done 或 failed（取决于 mass_push 是否注册）
        $this->assertContains($resp->json('data.status'), ['done', 'failed']);
    }

    public function test_reject_sets_skipped(): void
    {
        $plan = CampaignPlan::create([
            'tenant_id' => 1001,
            'plan_doc' => $this->validPlanDoc(),
            'status' => 'running',
            'created_by' => $this->admin->operator_id,
        ]);

        $task = CampaignTask::create([
            'tenant_id' => 1001,
            'plan_id' => $plan->plan_id,
            'task_key' => 'task_b',
            'title' => '任务B',
            'trigger_type' => 'at_time',
            'action' => [],
            'status' => 'awaiting_confirm',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson(self::API . "/tasks/{$task->task_id}/reject")
            ->assertOk();

        $this->assertEquals('skipped', $task->refresh()->status);
    }

    // ========== complete ==========

    public function test_complete_human_task(): void
    {
        $plan = CampaignPlan::create([
            'tenant_id' => 1001,
            'plan_doc' => $this->validPlanDoc(),
            'status' => 'running',
            'created_by' => $this->admin->operator_id,
        ]);

        $task = CampaignTask::create([
            'tenant_id' => 1001,
            'plan_id' => $plan->plan_id,
            'task_key' => 'human_task',
            'title' => '人工任务',
            'trigger_type' => 'at_time',
            'assignee_type' => 'human',
            'action' => [],
            'status' => 'running',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson(self::API . "/tasks/{$task->task_id}/complete", ['output' => ['note' => '已完成']])
            ->assertOk();

        $task->refresh();
        $this->assertEquals('done', $task->status);
        $this->assertEquals(['note' => '已完成'], $task->output);
    }
}
