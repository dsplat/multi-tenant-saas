<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Models\TaskChainRun;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolConversationContext;
use MultiTenantSaas\Modules\Ai\Services\TaskChain\TaskChainRegistry;
use MultiTenantSaas\Modules\Ai\Services\TaskChain\TaskChainRunner;
use MultiTenantSaas\Modules\Ai\Services\Tool\AdvanceTaskChainTool;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\TaskChainModule;

/**
 * 预设任务链 Phase 1（docs/task-chain.md）：
 * Registry 合并/校验、Runner 全流程（input → L1 工具 → 完成）、
 * L2 步不绕确认门、中断续跑、租户隔离、失败重试。
 */
class TaskChainTest extends TestCase
{
    protected array $uses = [TaskChainModule::class, AgentModule::class];

    private const TENANT = 1001;

    private const CONVERSATION = 9001;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::setTenantId((string) self::TENANT);
        TaskChainStubHandler::reset();
    }

    private function registry(): TaskChainRegistry
    {
        return new TaskChainRegistry;
    }

    private function runner(): TaskChainRunner
    {
        return new TaskChainRunner($this->registry(), $this->app->make(ToolRegistryContract::class));
    }

    /**
     * 注册测试用桩工具（L1/L2）并配置一条「input → tool」两步链
     */
    private function setUpStubChain(string $risk = 'L1'): void
    {
        $this->app->make(ToolRegistryContract::class)->register(
            'tc_stub_tool', 'Stub Tool', 'test stub', TaskChainStubHandler::class,
            ['type' => 'object', 'properties' => []], 'core', $risk,
        );

        config(['ai.task_chains.extra_chain_classes' => [TaskChainStubChains::class]]);
    }

    // ── Registry ──

    public function test_registry_contains_builtin_demo_chain(): void
    {
        $chain = $this->registry()->find('demo_poster_flow');

        $this->assertNotNull($chain);
        $this->assertSame('input', $chain['steps'][0]['type']);
        $this->assertSame('generate_poster', $chain['steps'][1]['tool']);
        // normalize 补默认值
        $this->assertFalse($chain['steps'][0]['optional']);
    }

    public function test_registry_merges_extra_chains_and_downstream_overrides_builtin(): void
    {
        config(['ai.task_chains.extra_chain_classes' => [TaskChainOverrideChains::class]]);

        $registry = $this->registry();

        // 新链并入
        $this->assertNotNull($registry->find('downstream_chain'));
        // 同 key 下游覆盖框架内置
        $this->assertSame('下游覆盖版', $registry->find('demo_poster_flow')['title']);
    }

    public function test_registry_skips_invalid_definitions(): void
    {
        config(['ai.task_chains.extra_chain_classes' => [TaskChainInvalidChains::class]]);

        $registry = $this->registry();

        $this->assertNull($registry->find('bad_step_type'));
        $this->assertNull($registry->find('tool_without_slug'));
        // 合法链不受坏定义影响
        $this->assertNotNull($registry->find('demo_poster_flow'));
    }

    public function test_match_by_intent_uses_trigger_hints(): void
    {
        $matched = $this->registry()->matchByIntent('帮我出海报宣传新品');

        $this->assertSame('demo_poster_flow', $matched[0]['key']);
        $this->assertSame([], $this->registry()->matchByIntent('完全无关的话'));
    }

    // ── Runner：启动与全流程 ──

    public function test_start_unknown_chain_returns_error(): void
    {
        $result = $this->runner()->start('not_exists', self::TENANT, self::CONVERSATION);

        $this->assertTrue($result['error']);
    }

    public function test_full_flow_input_then_l1_tool_to_completed(): void
    {
        $this->setUpStubChain('L1');
        $runner = $this->runner();

        // 启动：step0 为 input 步 → waiting_input
        $started = $runner->start('stub_chain', self::TENANT, self::CONVERSATION);
        $this->assertSame(TaskChainRun::STATUS_WAITING_INPUT, $started['status']);
        $this->assertNotNull($started['next_action']);

        // 提交 step_input：input 步完成，tool 步就位（不自动执行）
        $afterInput = $runner->advance($started['run_id'], self::TENANT, ['brief' => '春季促销']);
        $this->assertSame(TaskChainRun::STATUS_RUNNING, $afterInput['status']);
        $this->assertSame(1, $afterInput['current_step']);
        $this->assertSame('done', $afterInput['steps'][0]['status']);
        $this->assertNull(TaskChainStubHandler::$lastArguments);

        // 再推进：L1 工具执行，占位符已解析，链完成
        $completed = $runner->advance($started['run_id'], self::TENANT);
        $this->assertSame(TaskChainRun::STATUS_COMPLETED, $completed['status']);
        $this->assertSame('done', $completed['steps'][1]['status']);
        $this->assertSame(['prompt' => '主题：春季促销'], TaskChainStubHandler::$lastArguments);
        $this->assertSame(self::TENANT, TaskChainStubHandler::$lastTenantId);
    }

    public function test_l2_tool_step_waits_for_confirm_and_is_never_executed_by_runner(): void
    {
        $this->setUpStubChain('L2');
        $runner = $this->runner();

        $started = $runner->start('stub_chain', self::TENANT, self::CONVERSATION);
        $runner->advance($started['run_id'], self::TENANT, ['brief' => 'X']);

        // 铁律：L2 步 Runner 不直接执行，置 waiting_confirm 并指引 LLM 走确认门
        $waiting = $runner->advance($started['run_id'], self::TENANT);
        $this->assertSame(TaskChainRun::STATUS_WAITING_CONFIRM, $waiting['status']);
        $this->assertStringContainsString('tc_stub_tool', $waiting['next_action']);
        $this->assertNull(TaskChainStubHandler::$lastArguments, 'Runner 绝不能直接执行 L2 工具');

        // 确认门执行完毕后回填 step_output → 完成
        $completed = $runner->advance($started['run_id'], self::TENANT, [], ['poster_url' => 'https://x/p.png']);
        $this->assertSame(TaskChainRun::STATUS_COMPLETED, $completed['status']);
        $this->assertNull(TaskChainStubHandler::$lastArguments);
    }

    public function test_unregistered_tool_fails_step_and_can_retry(): void
    {
        config(['ai.task_chains.extra_chain_classes' => [TaskChainStubChains::class]]);
        $runner = $this->runner();

        $started = $runner->start('stub_chain', self::TENANT, self::CONVERSATION);
        $runner->advance($started['run_id'], self::TENANT, ['brief' => 'X']);

        // tc_stub_tool 未注册 → failed，保留现场
        $failed = $runner->advance($started['run_id'], self::TENANT);
        $this->assertSame(TaskChainRun::STATUS_FAILED, $failed['status']);
        $this->assertSame(1, $failed['current_step']);

        // 注册工具后重试同一步 → 完成
        $this->app->make(ToolRegistryContract::class)->register(
            'tc_stub_tool', 'Stub Tool', 'test stub', TaskChainStubHandler::class,
            ['type' => 'object', 'properties' => []], 'core', 'L1',
        );
        $completed = $runner->advance($started['run_id'], self::TENANT);
        $this->assertSame(TaskChainRun::STATUS_COMPLETED, $completed['status']);
    }

    public function test_delegate_step_fails_with_phase2_hint(): void
    {
        config(['ai.task_chains.extra_chain_classes' => [TaskChainDelegateChains::class]]);
        $runner = $this->runner();

        $started = $runner->start('delegate_chain', self::TENANT, self::CONVERSATION);

        $this->assertSame(TaskChainRun::STATUS_FAILED, $started['status']);
        $this->assertStringContainsString('Phase 2', $started['next_action']);
    }

    // ── 中断续跑与隔离 ──

    public function test_unfinished_runs_scoped_by_conversation_and_tenant(): void
    {
        $this->setUpStubChain('L1');
        $runner = $this->runner();

        $mine = $runner->start('stub_chain', self::TENANT, self::CONVERSATION);
        $runner->start('stub_chain', self::TENANT, 9002); // 其他会话

        $runs = $runner->unfinishedRuns(self::TENANT, self::CONVERSATION);
        $this->assertCount(1, $runs);
        $this->assertSame($mine['run_id'], $runs[0]['run_id']);

        // 完成后不再出现在可续跑列表
        $runner->advance($mine['run_id'], self::TENANT, ['brief' => 'X']);
        $runner->advance($mine['run_id'], self::TENANT);
        $this->assertCount(0, $runner->unfinishedRuns(self::TENANT, self::CONVERSATION));
    }

    public function test_advance_rejects_run_of_other_tenant(): void
    {
        $this->setUpStubChain('L1');
        $runner = $this->runner();

        $started = $runner->start('stub_chain', self::TENANT, self::CONVERSATION);

        $result = $runner->advance($started['run_id'], 2002, ['brief' => 'X']);
        $this->assertTrue($result['error']);
    }

    // ── 工具封装 ──

    public function test_advance_tool_defaults_to_latest_unfinished_run_of_conversation(): void
    {
        $this->setUpStubChain('L1');
        $runner = $this->runner();

        $started = $runner->start('stub_chain', self::TENANT, self::CONVERSATION);

        $context = new ToolConversationContext;
        $context->set(self::CONVERSATION);
        $tool = new AdvanceTaskChainTool($runner, $context);

        // 不传 run_id：取会话最新未完成链
        $result = $tool(['step_input' => ['brief' => 'Y']], self::TENANT);
        $this->assertSame($started['run_id'], $result['run_id']);
        $this->assertSame(1, $result['current_step']);
    }

    public function test_advance_tool_errors_without_conversation_context(): void
    {
        $tool = new AdvanceTaskChainTool($this->runner(), new ToolConversationContext);

        $result = $tool([], self::TENANT);
        $this->assertTrue($result['error']);
    }

    public function test_chain_tools_not_registered_when_engine_disabled(): void
    {
        // 默认 AI_TASK_CHAINS_ENABLED=false：三个链工具未注册（AI 可选性铁律）
        $registry = $this->app->make(ToolRegistryContract::class);

        $this->assertNull($registry->get('list_task_chains'));
        $this->assertNull($registry->get('start_task_chain'));
        $this->assertNull($registry->get('advance_task_chain'));
    }
}

/**
 * 桩工具处理器：记录最近一次调用参数
 */
class TaskChainStubHandler implements ToolHandlerContract
{
    public static ?array $lastArguments = null;

    public static ?int $lastTenantId = null;

    public static function reset(): void
    {
        self::$lastArguments = null;
        self::$lastTenantId = null;
    }

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        self::$lastArguments = $arguments;
        self::$lastTenantId = $tenantId;

        return ['ok' => true];
    }
}

/**
 * 测试链：input（brief）→ tool tc_stub_tool（占位符 {{brief}}）
 */
class TaskChainStubChains
{
    public static function chains(): array
    {
        return [
            [
                'key' => 'stub_chain',
                'title' => '桩测试链',
                'description' => '两步测试链',
                'trigger_hints' => ['桩测试'],
                'steps' => [
                    [
                        'name' => '收集主题',
                        'type' => 'input',
                        'input_schema' => ['type' => 'object', 'properties' => ['brief' => ['type' => 'string']], 'required' => ['brief']],
                        'output_key' => 'brief',
                    ],
                    [
                        'name' => '执行桩工具',
                        'type' => 'tool',
                        'tool' => 'tc_stub_tool',
                        'args' => ['prompt' => '主题：{{brief}}'],
                        'output_key' => 'result',
                    ],
                ],
            ],
        ];
    }
}

/**
 * 测试链：下游覆盖内置 key + 新增链
 */
class TaskChainOverrideChains
{
    public static function chains(): array
    {
        return [
            [
                'key' => 'demo_poster_flow',
                'title' => '下游覆盖版',
                'steps' => [['name' => 's', 'type' => 'input', 'output_key' => 'x']],
            ],
            [
                'key' => 'downstream_chain',
                'title' => '下游新链',
                'steps' => [['name' => 's', 'type' => 'input', 'output_key' => 'x']],
            ],
        ];
    }
}

/**
 * 测试链：坏定义（非法 step type / tool 步缺 slug）
 */
class TaskChainInvalidChains
{
    public static function chains(): array
    {
        return [
            [
                'key' => 'bad_step_type',
                'title' => '坏类型',
                'steps' => [['name' => 's', 'type' => 'magic']],
            ],
            [
                'key' => 'tool_without_slug',
                'title' => '缺 slug',
                'steps' => [['name' => 's', 'type' => 'tool']],
            ],
        ];
    }
}

/**
 * 测试链：delegate 步（Phase 2 才支持）
 */
class TaskChainDelegateChains
{
    public static function chains(): array
    {
        return [
            [
                'key' => 'delegate_chain',
                'title' => '转派链',
                'steps' => [['name' => '转派', 'type' => 'delegate', 'agent_role' => 'sales']],
            ],
        ];
    }
}
