<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Ai\Services\Ai\AiResponse;
use MultiTenantSaas\Modules\Campaign\Models\CampaignPlan;
use MultiTenantSaas\Modules\Campaign\Models\CampaignTask;
use MultiTenantSaas\Modules\Campaign\Services\PlanCompiler;
use MultiTenantSaas\Modules\Campaign\Services\PlaybookRegistry;
use MultiTenantSaas\Modules\Campaign\Services\Tools\CampaignPlanCommitTool;
use MultiTenantSaas\Modules\Campaign\Services\Tools\CampaignPlanDraftTool;
use MultiTenantSaas\Modules\Campaign\Services\Tools\CampaignStatusTool;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\CampaignModule;

/**
 * Campaign 三工具单测（docs/event-plan.md Phase 1：B3 + B4 + B5）
 *
 * - draft：LLM 桩化验证存 DB + 修订
 * - commit：编译验证 + 状态流转
 * - status：查询返回结构
 */
class CampaignPlanToolTest extends TestCase
{
    protected array $uses = [CampaignModule::class, AgentModule::class];

    private const TENANT = 2001;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::setTenantId((string) self::TENANT);
    }

    // ── Draft 工具 ──

    public function test_draft_creates_plan_from_playbook(): void
    {
        $planDoc = $this->validPlanDoc();
        $aiService = $this->mockAiForDraft($planDoc);
        $tool = $this->makeDraftTool($aiService);

        $result = $tool([
            'playbook_key' => 'demo_sms_sequence',
            'user_input' => '做一个三天短信序列活动',
        ], self::TENANT);

        $this->assertFalse($result['error'] ?? false);
        $this->assertGreaterThan(0, $result['plan_id']);
        $this->assertSame(1, $result['plan_doc_preview']['phases_count']);

        // 验证 DB 中已存
        $plan = CampaignPlan::find($result['plan_id']);
        $this->assertNotNull($plan);
        $this->assertSame(CampaignPlan::STATUS_PLANNING, $plan->status);
        $this->assertSame('demo_sms_sequence', $plan->playbook_key);
    }

    public function test_draft_revises_existing_plan(): void
    {
        // 先创建一个 planning 计划
        $plan = CampaignPlan::create([
            'tenant_id' => self::TENANT,
            'plan_doc' => $this->validPlanDoc(),
            'status' => CampaignPlan::STATUS_PLANNING,
            'playbook_key' => null,
            'created_by' => 0,
        ]);

        $revisedDoc = $this->validPlanDoc('修订版活动');
        $aiService = $this->mockAiForDraft($revisedDoc);
        $tool = $this->makeDraftTool($aiService);

        $result = $tool([
            'plan_id' => $plan->plan_id,
            'user_input' => '把短信改为推送',
        ], self::TENANT);

        $this->assertFalse($result['error'] ?? false);
        $this->assertSame($plan->plan_id, $result['plan_id']);

        // 验证 DB 已更新
        $plan->refresh();
        $this->assertSame('修订版活动', $plan->plan_doc['title']);
    }

    public function test_draft_fails_without_user_input(): void
    {
        $aiService = $this->createMock(AiTextServiceContract::class);
        $tool = $this->makeDraftTool($aiService);

        $result = $tool([], self::TENANT);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('user_input', $result['message']);
    }

    public function test_draft_llm_failure_returns_error_not_exception(): void
    {
        $aiService = $this->createMock(AiTextServiceContract::class);
        $aiService->method('chat')->willThrowException(new \RuntimeException('API down'));

        $tool = $this->makeDraftTool($aiService);

        $result = $tool(['user_input' => '测试'], self::TENANT);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('失败', $result['message']);
    }

    public function test_draft_surfaces_validation_errors_for_self_healing(): void
    {
        // LLM 生成 action.type=tool 但缺 tool slug → draft 阶段即时暴露校验问题供 LLM 修订
        $badDoc = $this->validPlanDoc();
        $badDoc['phases'][0]['tasks'][0]['action'] = ['type' => 'tool'];
        $aiService = $this->mockAiForDraft($badDoc);
        $tool = $this->makeDraftTool($aiService);

        $result = $tool(['user_input' => '做一个活动'], self::TENANT);

        $this->assertFalse($result['error'] ?? false);
        $this->assertNotEmpty($result['validation_errors']);
        $this->assertStringContainsString('缺少 tool slug', implode('；', $result['validation_errors']));
        $this->assertArrayHasKey('hint', $result);
    }

    // ── Commit 工具 ──

    public function test_commit_compiles_plan_and_creates_tasks(): void
    {
        // 注册 planDoc 中引用的工具
        $this->registerStubTool('send_sms');

        $plan = CampaignPlan::create([
            'tenant_id' => self::TENANT,
            'plan_doc' => $this->validPlanDoc(),
            'status' => CampaignPlan::STATUS_PLANNING,
            'created_by' => 0,
        ]);

        $tool = new CampaignPlanCommitTool($this->app->make(PlanCompiler::class));

        $result = $tool([
            'plan_id' => $plan->plan_id,
            'anchor_times' => ['event.starts_at' => '2026-09-01 09:00'],
        ], self::TENANT);

        $this->assertFalse($result['error'] ?? false);
        $this->assertSame('scheduled', $result['status']);
        $this->assertSame(1, $result['tasks_count']);
        $this->assertNotEmpty($result['timeline_preview']);
        $this->assertSame('sms_d1', $result['timeline_preview'][0]['key']);

        // 验证 DB 任务
        $tasks = CampaignTask::where('plan_id', $plan->plan_id)->get();
        $this->assertCount(1, $tasks);
        $this->assertSame(CampaignTask::STATUS_PENDING, $tasks->first()->status);
    }

    public function test_commit_rejects_non_planning_plan(): void
    {
        $plan = CampaignPlan::create([
            'tenant_id' => self::TENANT,
            'plan_doc' => $this->validPlanDoc(),
            'status' => CampaignPlan::STATUS_SCHEDULED,
            'created_by' => 0,
        ]);

        $tool = new CampaignPlanCommitTool($this->app->make(PlanCompiler::class));

        $result = $tool(['plan_id' => $plan->plan_id], self::TENANT);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('planning', $result['message']);
    }

    public function test_commit_returns_validation_errors(): void
    {
        // schema 错误 + 有 phases 但 task 引用了未注册工具
        $badDoc = [
            'schema' => 'campaign.plan/v1',
            'phases' => [
                [
                    'key' => 'p1',
                    'tasks' => [
                        [
                            'key' => 'bad_task',
                            'title' => '坏任务',
                            'trigger' => ['type' => 'at_time', 'time' => '2026-09-01'],
                            'action' => ['type' => 'tool', 'tool' => 'nonexistent_xyz_tool'],
                            'execution_mode' => 'auto',
                        ],
                    ],
                ],
            ],
        ];
        $plan = CampaignPlan::create([
            'tenant_id' => self::TENANT,
            'plan_doc' => $badDoc,
            'status' => CampaignPlan::STATUS_PLANNING,
            'created_by' => 0,
        ]);

        $tool = new CampaignPlanCommitTool($this->app->make(PlanCompiler::class));

        $result = $tool(['plan_id' => $plan->plan_id], self::TENANT);

        $this->assertTrue($result['error']);
        $this->assertNotEmpty($result['validation_errors'] ?? []);
    }

    // ── Status 工具 ──

    public function test_status_returns_plan_and_tasks(): void
    {
        $plan = CampaignPlan::create([
            'tenant_id' => self::TENANT,
            'plan_doc' => ['title' => '测试计划', 'phases' => []],
            'status' => CampaignPlan::STATUS_SCHEDULED,
            'created_by' => 0,
        ]);

        CampaignTask::create([
            'tenant_id' => self::TENANT,
            'plan_id' => $plan->plan_id,
            'task_key' => 'task_a',
            'title' => '任务 A',
            'trigger_type' => CampaignTask::TRIGGER_AT_TIME,
            'scheduled_at' => '2026-09-01 09:00:00',
            'action' => ['type' => 'tool', 'tool' => 'send_sms'],
            'status' => CampaignTask::STATUS_PENDING,
        ]);

        CampaignTask::create([
            'tenant_id' => self::TENANT,
            'plan_id' => $plan->plan_id,
            'task_key' => 'task_b',
            'title' => '任务 B',
            'trigger_type' => CampaignTask::TRIGGER_AT_TIME,
            'scheduled_at' => '2026-09-02 09:00:00',
            'action' => ['type' => 'human'],
            'status' => CampaignTask::STATUS_AWAITING_CONFIRM,
        ]);

        $tool = new CampaignStatusTool;

        $result = $tool(['plan_id' => $plan->plan_id], self::TENANT);

        $this->assertFalse($result['error'] ?? false);
        $this->assertSame('测试计划', $result['plan']['title']);
        $this->assertSame('scheduled', $result['plan']['status']);
        $this->assertCount(2, $result['tasks']);
        $this->assertSame(1, $result['pending_confirms']);
        $this->assertSame('task_a', $result['tasks'][0]['key']);
    }

    public function test_status_rejects_other_tenant(): void
    {
        $plan = CampaignPlan::create([
            'tenant_id' => 9999,
            'plan_doc' => ['title' => '他人计划', 'phases' => []],
            'status' => CampaignPlan::STATUS_PLANNING,
            'created_by' => 0,
        ]);

        $tool = new CampaignStatusTool;

        $result = $tool(['plan_id' => $plan->plan_id], self::TENANT);

        $this->assertTrue($result['error']);
    }

    // ── Helpers ──

    private function makeDraftTool(AiTextServiceContract $aiService): CampaignPlanDraftTool
    {
        return new CampaignPlanDraftTool($aiService, new PlaybookRegistry, $this->app->make(PlanCompiler::class));
    }

    private function validPlanDoc(string $title = '测试活动'): array
    {
        return [
            'schema' => 'campaign.plan/v1',
            'title' => $title,
            'phases' => [
                [
                    'key' => 'notify',
                    'title' => '通知阶段',
                    'tasks' => [
                        [
                            'key' => 'sms_d1',
                            'title' => 'D+1 短信',
                            'trigger' => ['type' => 'relative', 'anchor' => 'event.starts_at', 'offset' => '+1d', 'at' => '09:00'],
                            'action' => ['type' => 'tool', 'tool' => 'send_sms', 'args' => ['template' => '提醒']],
                            'execution_mode' => 'auto',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function mockAiForDraft(array $planDoc): AiTextServiceContract
    {
        $mock = $this->createMock(AiTextServiceContract::class);
        $mock->method('chat')->willReturn(
            new AiResponse(content: json_encode($planDoc, JSON_UNESCAPED_UNICODE), finishReason: 'stop')
        );

        return $mock;
    }

    private function registerStubTool(string $slug): void
    {
        $this->app->make(ToolRegistryContract::class)->register(
            $slug, "Stub {$slug}", "Stub tool for testing",
            CampaignStubToolHandler::class,
            ['type' => 'object', 'properties' => []], 'test', 'L1',
        );
    }
}

class CampaignStubToolHandler implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return ['ok' => true, 'slug' => 'stub'];
    }
}
