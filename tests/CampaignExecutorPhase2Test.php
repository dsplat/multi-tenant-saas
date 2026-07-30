<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\HeadlessResult;
use MultiTenantSaas\Modules\Ai\Services\Agent\HeadlessAgentService;
use MultiTenantSaas\Modules\Ai\Services\TaskChain\TaskChainRunner;
use MultiTenantSaas\Modules\Campaign\Listeners\CampaignEventSubscriber;
use MultiTenantSaas\Modules\Campaign\Models\CampaignPlan;
use MultiTenantSaas\Modules\Campaign\Models\CampaignTask;
use MultiTenantSaas\Modules\Campaign\Services\CampaignTaskExecutor;
use MultiTenantSaas\Modules\Campaign\Services\PlanCompiler;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\CampaignModule;
use MultiTenantSaas\Tests\Schema\TaskChainModule;

/**
 * CampaignTaskExecutor Phase 2 单测
 *
 * - task_chain 执行（forceL2 + 循环 advance）
 * - agent_react 执行（HeadlessAgentService 桩化）
 */
class CampaignExecutorPhase2Test extends TestCase
{
    protected array $uses = [CampaignModule::class, TaskChainModule::class, AgentModule::class];

    private const TENANT = 3001;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::setTenantId((string) self::TENANT);
    }

    // ── task_chain 执行 ──

    public function test_task_chain_executes_and_completes(): void
    {
        // 注册 stub chain 和工具
        $this->registerStubChainAndTool();

        $task = $this->createTask([
            'type' => 'task_chain',
            'chain_key' => 'stub_chain',
            'args' => ['step_0' => ['brief' => '活动方案']],
        ]);

        $executor = $this->makeExecutor();
        $executor->execute($task);

        $task->refresh();
        $this->assertSame(CampaignTask::STATUS_DONE, $task->status);
        $this->assertSame('completed', $task->output['status'] ?? '');
    }

    public function test_task_chain_with_missing_chain_key_fails(): void
    {
        $task = $this->createTask([
            'type' => 'task_chain',
            // 无 chain_key
        ]);

        $executor = $this->makeExecutor();
        $executor->execute($task);

        $task->refresh();
        $this->assertSame(CampaignTask::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('chain_key', $task->output['error'] ?? '');
    }

    public function test_task_chain_unknown_chain_fails(): void
    {
        $task = $this->createTask([
            'type' => 'task_chain',
            'chain_key' => 'nonexistent_chain',
        ]);

        $executor = $this->makeExecutor();
        $executor->execute($task);

        $task->refresh();
        $this->assertSame(CampaignTask::STATUS_FAILED, $task->status);
    }

    public function test_task_chain_uses_forceL2_for_l2_tools(): void
    {
        // 注册带 L2 工具的链
        $this->app->make(ToolRegistryContract::class)->register(
            'l2_stub_tool', 'L2 Stub', 'test', ExecutorStubHandler::class,
            ['type' => 'object', 'properties' => []], 'test', 'L2',
        );

        config(['ai.task_chains.extra_chain_classes' => [ExecutorL2ChainDef::class]]);

        $task = $this->createTask([
            'type' => 'task_chain',
            'chain_key' => 'executor_l2_chain',
            'args' => ['step_0' => ['data' => 'test']],
        ]);

        $executor = $this->makeExecutor();
        $executor->execute($task);

        $task->refresh();
        // forceL2 使 L2 工具直接执行而非 waiting_confirm
        $this->assertSame(CampaignTask::STATUS_DONE, $task->status);
    }

    // ── agent_react 执行 ──

    public function test_agent_react_executes_and_completes(): void
    {
        $headless = $this->createMock(HeadlessAgentService::class);
        $headless->expects($this->once())
            ->method('execute')
            ->with('marketing', $this->anything(), self::TENANT)
            ->willReturn(new HeadlessResult(text: '方案已生成：春季营销活动', partial: false));

        $task = $this->createTask([
            'type' => 'agent_react',
            'role' => 'marketing',
            'prompt' => '请制作营销方案',
        ]);

        $executor = $this->makeExecutor(headless: $headless);
        $executor->execute($task);

        $task->refresh();
        $this->assertSame(CampaignTask::STATUS_DONE, $task->status);
        $this->assertSame('方案已生成：春季营销活动', $task->output['text']);
    }

    public function test_agent_react_partial_result_fails(): void
    {
        $headless = $this->createMock(HeadlessAgentService::class);
        $headless->method('execute')
            ->willReturn(new HeadlessResult(text: '', partial: true, error: 'LLM timeout'));

        $task = $this->createTask([
            'type' => 'agent_react',
            'role' => 'marketing',
            'prompt' => '请制作方案',
        ]);

        $executor = $this->makeExecutor(headless: $headless);
        $executor->execute($task);

        $task->refresh();
        $this->assertSame(CampaignTask::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('partial', $task->output['error'] ?? '');
    }

    public function test_agent_react_missing_role_fails(): void
    {
        $task = $this->createTask([
            'type' => 'agent_react',
            'prompt' => '缺少 role',
        ]);

        $executor = $this->makeExecutor();
        $executor->execute($task);

        $task->refresh();
        $this->assertSame(CampaignTask::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('role', $task->output['error'] ?? '');
    }

    // ── recurring 展开 ──

    public function test_recurring_expands_to_multiple_at_time_tasks(): void
    {
        $this->app->make(ToolRegistryContract::class)->register(
            'send_sms', 'Send SMS', 'test', ExecutorStubHandler::class,
            ['type' => 'object', 'properties' => []], 'core', 'L1',
        );

        $plan = CampaignPlan::create([
            'tenant_id' => self::TENANT,
            'plan_doc' => [
                'schema' => 'campaign.plan/v1',
                'title' => 'Recurring Test',
                'phases' => [
                    [
                        'key' => 'sms_phase',
                        'title' => 'SMS',
                        'tasks' => [
                            [
                                'key' => 'daily_sms',
                                'title' => '每日短信',
                                'trigger' => [
                                    'type' => 'recurring',
                                    'anchor' => 'event.starts_at',
                                    'from' => '+0d',
                                    'until' => '+2d',
                                    'interval' => '1d',
                                    'at' => '09:30',
                                ],
                                'action' => ['type' => 'tool', 'tool' => 'send_sms', 'args' => ['msg' => 'hello']],
                                'assignee' => ['type' => 'system'],
                            ],
                        ],
                    ],
                ],
            ],
            'status' => CampaignPlan::STATUS_PLANNING,
            'created_by' => 0,
        ]);

        $compiler = $this->app->make(PlanCompiler::class);
        $compiler->compile($plan, ['event.starts_at' => '2026-08-10 09:00']);

        $plan->refresh();
        $this->assertSame(CampaignPlan::STATUS_SCHEDULED, $plan->status);

        // 应展开为 daily_sms_0, daily_sms_1, daily_sms_2
        $tasks = CampaignTask::where('plan_id', $plan->plan_id)->orderBy('task_key')->get();
        $this->assertCount(3, $tasks);
        $this->assertSame('daily_sms_0', $tasks[0]->task_key);
        $this->assertSame('daily_sms_1', $tasks[1]->task_key);
        $this->assertSame('daily_sms_2', $tasks[2]->task_key);

        // 每个任务都是 at_time + 09:30
        foreach ($tasks as $task) {
            $this->assertSame('at_time', $task->trigger_type);
            $this->assertSame('09:30', $task->scheduled_at->format('H:i'));
        }

        // 日期递增
        $this->assertSame('2026-08-10', $tasks[0]->scheduled_at->format('Y-m-d'));
        $this->assertSame('2026-08-11', $tasks[1]->scheduled_at->format('Y-m-d'));
        $this->assertSame('2026-08-12', $tasks[2]->scheduled_at->format('Y-m-d'));
    }

    public function test_recurring_with_hours_interval(): void
    {
        $this->app->make(ToolRegistryContract::class)->register(
            'notify_tool', 'Notify', 'test', ExecutorStubHandler::class,
            ['type' => 'object', 'properties' => []], 'core', 'L1',
        );

        $plan = CampaignPlan::create([
            'tenant_id' => self::TENANT,
            'plan_doc' => [
                'schema' => 'campaign.plan/v1',
                'title' => 'Hour Recurring',
                'phases' => [
                    [
                        'key' => 'notify_phase',
                        'title' => 'Notify',
                        'tasks' => [
                            [
                                'key' => 'hourly_push',
                                'title' => '每小时推送',
                                'trigger' => [
                                    'type' => 'recurring',
                                    'anchor' => 'event.starts_at',
                                    'from' => '+0d',
                                    'until' => '+6h',
                                    'interval' => '3h',
                                ],
                                'action' => ['type' => 'tool', 'tool' => 'notify_tool', 'args' => []],
                                'assignee' => ['type' => 'system'],
                            ],
                        ],
                    ],
                ],
            ],
            'status' => CampaignPlan::STATUS_PLANNING,
            'created_by' => 0,
        ]);

        $compiler = $this->app->make(PlanCompiler::class);
        $compiler->compile($plan, ['event.starts_at' => '2026-08-10 08:00']);

        // 0h, 3h, 6h → 3 个任务
        $tasks = CampaignTask::where('plan_id', $plan->plan_id)->orderBy('task_key')->get();
        $this->assertCount(3, $tasks);
        $this->assertSame('2026-08-10 08:00', $tasks[0]->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('2026-08-10 11:00', $tasks[1]->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('2026-08-10 14:00', $tasks[2]->scheduled_at->format('Y-m-d H:i'));
    }

    // ── on_event 触发 ──

    public function test_on_event_triggers_matching_pending_tasks(): void
    {
        $plan = CampaignPlan::create([
            'tenant_id' => self::TENANT,
            'plan_doc' => ['schema' => 'campaign.plan/v1', 'title' => 'Event Test', 'phases' => []],
            'status' => CampaignPlan::STATUS_RUNNING,
            'created_by' => 0,
        ]);

        $task = CampaignTask::create([
            'tenant_id' => self::TENANT,
            'plan_id' => $plan->plan_id,
            'task_key' => 'on_order_paid',
            'title' => '订单支付后发短信',
            'trigger_type' => CampaignTask::TRIGGER_ON_EVENT,
            'listen_event' => StubOrderPaidEvent::class,
            'action' => ['type' => 'tool', 'tool' => 'tc_stub_tool', 'args' => []],
            'execution_mode' => 'auto',
            'status' => CampaignTask::STATUS_PENDING,
        ]);

        // 注册工具
        $this->app->make(ToolRegistryContract::class)->register(
            'tc_stub_tool', 'Stub Tool', 'test', ExecutorStubHandler::class,
            ['type' => 'object', 'properties' => []], 'core', 'L1',
        );

        // 模拟事件触发
        $subscriber = $this->app->make(CampaignEventSubscriber::class);
        $event = new StubOrderPaidEvent(self::TENANT);
        $subscriber->handleEvent($event);

        $task->refresh();
        $this->assertSame(CampaignTask::STATUS_DONE, $task->status);
    }

    public function test_on_event_does_not_trigger_other_tenant_tasks(): void
    {
        $plan = CampaignPlan::create([
            'tenant_id' => self::TENANT,
            'plan_doc' => ['schema' => 'campaign.plan/v1', 'title' => 'Event Test', 'phases' => []],
            'status' => CampaignPlan::STATUS_RUNNING,
            'created_by' => 0,
        ]);

        $task = CampaignTask::create([
            'tenant_id' => self::TENANT,
            'plan_id' => $plan->plan_id,
            'task_key' => 'on_order_paid_x',
            'title' => '不应触发',
            'trigger_type' => CampaignTask::TRIGGER_ON_EVENT,
            'listen_event' => StubOrderPaidEvent::class,
            'action' => ['type' => 'tool', 'tool' => 'tc_stub_tool', 'args' => []],
            'execution_mode' => 'auto',
            'status' => CampaignTask::STATUS_PENDING,
        ]);

        $this->app->make(ToolRegistryContract::class)->register(
            'tc_stub_tool', 'Stub Tool', 'test', ExecutorStubHandler::class,
            ['type' => 'object', 'properties' => []], 'core', 'L1',
        );

        // 不同租户的事件
        $subscriber = $this->app->make(CampaignEventSubscriber::class);
        $event = new StubOrderPaidEvent(9999); // 其他租户
        $subscriber->handleEvent($event);

        $task->refresh();
        // 不应触发
        $this->assertSame(CampaignTask::STATUS_PENDING, $task->status);
    }

    public function test_on_event_require_confirm_sets_awaiting(): void
    {
        $plan = CampaignPlan::create([
            'tenant_id' => self::TENANT,
            'plan_doc' => ['schema' => 'campaign.plan/v1', 'title' => 'Event Confirm Test', 'phases' => []],
            'status' => CampaignPlan::STATUS_RUNNING,
            'created_by' => 0,
        ]);

        $task = CampaignTask::create([
            'tenant_id' => self::TENANT,
            'plan_id' => $plan->plan_id,
            'task_key' => 'on_event_confirm',
            'title' => '需确认任务',
            'trigger_type' => CampaignTask::TRIGGER_ON_EVENT,
            'listen_event' => StubOrderPaidEvent::class,
            'action' => ['type' => 'tool', 'tool' => 'tc_stub_tool', 'args' => []],
            'execution_mode' => 'require_confirm',
            'status' => CampaignTask::STATUS_PENDING,
        ]);

        $this->app->make(ToolRegistryContract::class)->register(
            'tc_stub_tool', 'Stub Tool', 'test', ExecutorStubHandler::class,
            ['type' => 'object', 'properties' => []], 'core', 'L1',
        );

        $subscriber = $this->app->make(CampaignEventSubscriber::class);
        $event = new StubOrderPaidEvent(self::TENANT);
        $subscriber->handleEvent($event);

        $task->refresh();
        $this->assertSame(CampaignTask::STATUS_AWAITING_CONFIRM, $task->status);
    }

    // ── Helpers ──

    private function makeExecutor(?HeadlessAgentService $headless = null): CampaignTaskExecutor
    {
        $headless ??= $this->createMock(HeadlessAgentService::class);

        return new CampaignTaskExecutor(
            $this->app->make(ToolRegistryContract::class),
            new TaskChainRunner(
                $this->app->make(\MultiTenantSaas\Modules\Ai\Services\TaskChain\TaskChainRegistry::class),
                $this->app->make(ToolRegistryContract::class),
                $headless,
            ),
            $headless,
        );
    }

    private function createTask(array $action): CampaignTask
    {
        $plan = CampaignPlan::create([
            'tenant_id' => self::TENANT,
            'plan_doc' => ['schema' => 'campaign.plan/v1', 'title' => 'Test', 'phases' => []],
            'status' => CampaignPlan::STATUS_RUNNING,
            'created_by' => 0,
        ]);

        return CampaignTask::create([
            'tenant_id' => self::TENANT,
            'plan_id' => $plan->plan_id,
            'task_key' => 'test_task_' . random_int(1000, 9999),
            'title' => 'Test Task',
            'trigger_type' => CampaignTask::TRIGGER_AT_TIME,
            'scheduled_at' => now(),
            'action' => $action,
            'execution_mode' => 'auto',
            'status' => CampaignTask::STATUS_PENDING,
        ]);
    }

    private function registerStubChainAndTool(): void
    {
        $this->app->make(ToolRegistryContract::class)->register(
            'tc_stub_tool', 'Stub Tool', 'test stub', ExecutorStubHandler::class,
            ['type' => 'object', 'properties' => []], 'core', 'L1',
        );

        config(['ai.task_chains.extra_chain_classes' => [ExecutorStubChainDef::class]]);
    }
}

class ExecutorStubHandler implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return ['ok' => true, 'args' => $arguments];
    }
}

/**
 * 测试链：input（brief）→ tool（L1）
 */
class ExecutorStubChainDef
{
    public static function chains(): array
    {
        return [
            [
                'key' => 'stub_chain',
                'title' => '桩测试链',
                'description' => '两步测试链',
                'trigger_hints' => [],
                'steps' => [
                    [
                        'name' => '收集信息',
                        'type' => 'input',
                        'input_schema' => ['type' => 'object', 'properties' => ['brief' => ['type' => 'string']]],
                        'output_key' => 'brief',
                    ],
                    [
                        'name' => '执行工具',
                        'type' => 'tool',
                        'tool' => 'tc_stub_tool',
                        'args' => ['data' => '{{brief}}'],
                        'output_key' => 'result',
                    ],
                ],
            ],
        ];
    }
}

/**
 * 测试链：input → L2 tool
 */
class ExecutorL2ChainDef
{
    public static function chains(): array
    {
        return [
            [
                'key' => 'executor_l2_chain',
                'title' => 'L2 测试链',
                'steps' => [
                    [
                        'name' => '收集信息',
                        'type' => 'input',
                        'input_schema' => ['type' => 'object', 'properties' => ['data' => ['type' => 'string']]],
                        'output_key' => 'data',
                    ],
                    [
                        'name' => '执行L2工具',
                        'type' => 'tool',
                        'tool' => 'l2_stub_tool',
                        'args' => ['payload' => '{{data}}'],
                        'output_key' => 'result',
                    ],
                ],
            ],
        ];
    }
}

/**
 * 测试事件：模拟订单支付
 */
class StubOrderPaidEvent
{
    public int $tenant_id;

    public function __construct(int $tenantId)
    {
        $this->tenant_id = $tenantId;
    }
}
