<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Models\AiTask;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolConversationContext;
use MultiTenantSaas\Modules\Ai\Services\Ai\AiResponse;
use MultiTenantSaas\Modules\Ai\Services\AiTask\AiTaskHandlerRegistry;
use MultiTenantSaas\Modules\Campaign\Models\CampaignPlan;
use MultiTenantSaas\Modules\Campaign\Models\CampaignTask;
use MultiTenantSaas\Modules\Campaign\Services\PlanCompiler;
use MultiTenantSaas\Modules\Campaign\Services\Tools\CampaignPlanCommitTool;
use MultiTenantSaas\Modules\Campaign\Services\Tools\CampaignPlanDraftTaskHandler;
use MultiTenantSaas\Modules\Campaign\Services\Tools\CampaignPlanDraftTool;
use MultiTenantSaas\Modules\Campaign\Services\Tools\CampaignStatusTool;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\AiModule;
use MultiTenantSaas\Tests\Schema\CampaignModule;

/**
 * Campaign 三工具单测（docs/event-plan.md Phase 1：B3 + B4 + B5）
 *
 * - draft：任务化长工具——工具毫秒级提交 AiTask 返回 await_task，
 *   重模型生成在 CampaignPlanDraftTaskHandler（queue sync 下同步执行）
 * - commit：编译验证 + 状态流转
 * - status：查询返回结构
 */
class CampaignPlanToolTest extends TestCase
{
    protected array $uses = [CampaignModule::class, AgentModule::class, AiModule::class];

    private const TENANT = 2001;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::setTenantId((string) self::TENANT);
    }

    // ── Draft 工具（任务化：提交 AiTask → handler 后台执行）──

    public function test_draft_creates_plan_from_playbook(): void
    {
        $this->setUpDraftTask($this->mockAiForDraft($this->validPlanDoc()));
        $tool = $this->app->make(CampaignPlanDraftTool::class);

        $submit = $tool([
            'playbook_key' => 'demo_sms_sequence',
            'user_input' => '做一个三天短信序列活动',
        ], self::TENANT);

        $this->assertSame('await_task', $submit['action']);

        $task = AiTask::find((int) $submit['task_id']);
        $this->assertSame(AiTask::STATUS_COMPLETED, $task->status);
        $result = $task->result;
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

        $this->setUpDraftTask($this->mockAiForDraft($this->validPlanDoc('修订版活动')));
        $tool = $this->app->make(CampaignPlanDraftTool::class);

        $submit = $tool([
            'plan_id' => $plan->plan_id,
            'user_input' => '把短信改为推送',
        ], self::TENANT);

        $task = AiTask::find((int) $submit['task_id']);
        $this->assertSame(AiTask::STATUS_COMPLETED, $task->status);
        $this->assertSame($plan->plan_id, $task->result['plan_id']);

        // 验证 DB 已更新
        $plan->refresh();
        $this->assertSame('修订版活动', $plan->plan_doc['title']);
    }

    public function test_draft_fails_without_user_input(): void
    {
        $tool = $this->app->make(CampaignPlanDraftTool::class);

        $result = $tool([], self::TENANT);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('user_input', $result['message']);
        $this->assertSame(0, AiTask::count(), '快速失败不得创建任务');
    }

    public function test_draft_llm_failure_marks_task_failed(): void
    {
        // fail-open 语义迁入任务层：LLM 失败 → 任务 failed + error，
        // Node 轮询后作为工具错误交还 LLM（不打断流）
        $aiService = $this->createMock(AiTextServiceContract::class);
        $aiService->method('chat')->willThrowException(new \RuntimeException('API down'));

        $this->setUpDraftTask($aiService);
        $tool = $this->app->make(CampaignPlanDraftTool::class);

        $submit = $tool(['user_input' => '测试'], self::TENANT);
        $this->assertSame('await_task', $submit['action']);

        $task = AiTask::find((int) $submit['task_id']);
        $this->assertSame(AiTask::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('失败', (string) $task->error);
    }

    public function test_draft_surfaces_validation_errors_for_self_healing(): void
    {
        // LLM 生成 action.type=tool 但缺 tool slug → draft 阶段即时暴露校验问题供 LLM 修订
        $badDoc = $this->validPlanDoc();
        $badDoc['phases'][0]['tasks'][0]['action'] = ['type' => 'tool'];
        $this->setUpDraftTask($this->mockAiForDraft($badDoc));
        $tool = $this->app->make(CampaignPlanDraftTool::class);

        $submit = $tool(['user_input' => '做一个活动'], self::TENANT);

        $task = AiTask::find((int) $submit['task_id']);
        $this->assertSame(AiTask::STATUS_COMPLETED, $task->status);
        $result = $task->result;
        $this->assertNotEmpty($result['validation_errors']);
        $this->assertStringContainsString('缺少 tool slug', implode('；', $result['validation_errors']));
        $this->assertArrayHasKey('hint', $result);
    }

    public function test_draft_reports_required_anchors(): void
    {
        // draft 即告知 commit 时需提供的全部锚点
        $this->setUpDraftTask($this->mockAiForDraft($this->validPlanDoc()));
        $tool = $this->app->make(CampaignPlanDraftTool::class);

        $submit = $tool(['user_input' => '做一个活动'], self::TENANT);

        $task = AiTask::find((int) $submit['task_id']);
        $this->assertSame(['event.starts_at'], $task->result['required_anchors']);
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
        // 定稿后必带下一步指引（营销内容准备），不得依赖模型自由发挥
        $this->assertNotEmpty($result['next_action']);
        $this->assertStringContainsString('营销内容准备', $result['next_action']);

        // 验证 DB 任务
        $tasks = CampaignTask::where('plan_id', $plan->plan_id)->get();
        $this->assertCount(1, $tasks);
        $this->assertSame(CampaignTask::STATUS_PENDING, $tasks->first()->status);
    }

    public function test_commit_returns_all_missing_anchors_at_once(): void
    {
        // 多锚点缺失时一次性全部列出，LLM 一轮补齐避免多次往返
        $this->registerStubTool('send_sms');
        $doc = $this->validPlanDoc();
        $doc['phases'][0]['tasks'][] = [
            'key' => 'stream_notice',
            'title' => '直播提醒',
            'trigger' => ['type' => 'relative', 'anchor' => 'kickoff_stream', 'offset' => '-1h'],
            'action' => ['type' => 'human'],
            'execution_mode' => 'auto',
        ];
        $plan = CampaignPlan::create([
            'tenant_id' => self::TENANT,
            'plan_doc' => $doc,
            'status' => CampaignPlan::STATUS_PLANNING,
            'created_by' => 0,
        ]);

        $tool = new CampaignPlanCommitTool($this->app->make(PlanCompiler::class));

        $result = $tool(['plan_id' => $plan->plan_id, 'anchor_times' => []], self::TENANT);

        $this->assertTrue($result['error']);
        $this->assertEqualsCanonicalizing(['event.starts_at', 'kickoff_stream'], $result['missing_anchors']);

        // 计划仍在 planning，补齐锚点后可重新提交
        $plan->refresh();
        $this->assertSame(CampaignPlan::STATUS_PLANNING, $plan->status);
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

    /**
     * 跨轮历史纯文本丢失 draft 结果的 plan_id，模型幻觉传短数字（如 1）：
     * commit 应从当前会话最近成功的 draft 任务结果机械兑底出真实计划
     */
    public function test_commit_falls_back_to_latest_draft_result_when_plan_id_invalid(): void
    {
        $this->registerStubTool('send_sms');

        $plan = CampaignPlan::create([
            'tenant_id' => self::TENANT,
            'plan_doc' => $this->validPlanDoc(),
            'status' => CampaignPlan::STATUS_PLANNING,
            'created_by' => 0,
        ]);

        AiTask::create([
            'tenant_id' => self::TENANT,
            'conversation_id' => 777,
            'type' => 'campaign_plan_draft',
            'status' => AiTask::STATUS_COMPLETED,
            'payload' => [],
            'result' => ['plan_id' => $plan->plan_id],
        ]);

        $this->app->make(ToolConversationContext::class)->set(777);

        $tool = $this->app->make(CampaignPlanCommitTool::class);
        $result = $tool([
            'plan_id' => 1, // 幻觉编号
            'anchor_times' => ['event.starts_at' => '2026-09-01 09:00'],
        ], self::TENANT);

        $this->assertFalse($result['error'] ?? false);
        $this->assertSame((int) $plan->plan_id, (int) $result['plan_id']);
        $this->assertSame('scheduled', $result['status']);
    }

    /**
     * 无会话上下文且无可兑底 draft：错误附 planning 计划清单引导 LLM 自愈重试
     */
    public function test_commit_error_lists_planning_plans_when_no_fallback(): void
    {
        $plan = CampaignPlan::create([
            'tenant_id' => self::TENANT,
            'plan_doc' => $this->validPlanDoc(),
            'status' => CampaignPlan::STATUS_PLANNING,
            'created_by' => 0,
        ]);

        $tool = new CampaignPlanCommitTool($this->app->make(PlanCompiler::class));
        $result = $tool(['plan_id' => 1], self::TENANT);

        $this->assertTrue($result['error']);
        $this->assertNotEmpty($result['planning_plans']);
        $this->assertSame((int) $plan->plan_id, $result['planning_plans'][0]['plan_id']);
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

    /**
     * 任务化接线：注册 campaign_plan_draft 任务 handler + 绑定 LLM 桩
     * （queue sync 下 dispatch 同步执行，断言直接读 AiTask 终态）
     */
    private function setUpDraftTask(AiTextServiceContract $aiService): void
    {
        $this->app->instance(AiTextServiceContract::class, $aiService);
        $this->app->make(AiTaskHandlerRegistry::class)->register(
            'campaign_plan_draft',
            CampaignPlanDraftTaskHandler::class
        );
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

    /**
     * 瞬时失败（超时/网络/5xx）自动重试一次：任务终态应为 completed，
     * 而不是把第一次失败暴露给用户（前端「先失败后成功」现象的回归防线）
     */
    public function test_draft_retries_on_transient_llm_failure(): void
    {
        $mock = $this->createMock(AiTextServiceContract::class);
        $calls = 0;
        $mock->method('chat')->willReturnCallback(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('Connection timed out');
            }

            return new AiResponse(content: json_encode($this->validPlanDoc(), JSON_UNESCAPED_UNICODE), finishReason: 'stop');
        });

        $this->setUpDraftTask($mock);
        $tool = $this->app->make(CampaignPlanDraftTool::class);

        $submit = $tool(['user_input' => '做一个七夕会员活动'], self::TENANT);

        $task = AiTask::find((int) $submit['task_id']);
        $this->assertSame(AiTask::STATUS_COMPLETED, $task->status);
        $this->assertSame(2, $calls);
    }

    /**
     * 内部生成调用必须显式放宽超时（平台默认 AI_TIMEOUT 生产 30s 不够），
     * 否则重模型生成超时致任务 failed
     */
    public function test_draft_llm_call_uses_extended_timeout(): void
    {
        $captured = null;
        $mock = $this->createMock(AiTextServiceContract::class);
        $mock->method('chat')->willReturnCallback(function (array $messages, array $options) use (&$captured) {
            $captured = $options;

            return new AiResponse(content: json_encode($this->validPlanDoc(), JSON_UNESCAPED_UNICODE), finishReason: 'stop');
        });

        $this->setUpDraftTask($mock);
        $tool = $this->app->make(CampaignPlanDraftTool::class);
        $tool(['user_input' => '做一个七夕会员活动'], self::TENANT);

        $this->assertNotNull($captured);
        $this->assertGreaterThanOrEqual(120, $captured['timeout'] ?? 0);
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
