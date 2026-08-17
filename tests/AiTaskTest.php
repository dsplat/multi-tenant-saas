<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Modules\Ai\Jobs\ExecuteAiTaskJob;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Models\AgentConversationMessage;
use MultiTenantSaas\Modules\Ai\Models\AiTask;
use MultiTenantSaas\Modules\Ai\Services\Ai\AiResponse;
use MultiTenantSaas\Modules\Ai\Services\AiTask\AiTaskHandlerContract;
use MultiTenantSaas\Modules\Ai\Services\AiTask\AiTaskHandlerRegistry;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolConversationContext;
use MultiTenantSaas\Modules\Campaign\Services\Tools\CampaignPlanDraftTaskHandler;
use MultiTenantSaas\Modules\Campaign\Services\Tools\CampaignPlanDraftTool;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\AiModule;
use MultiTenantSaas\Tests\Schema\CampaignModule;

/**
 * AI 长任务跟踪机制（task/queue + Node 流内轮询）：
 * IdGenerator 主键铁律、Job 状态机、handler 分发、断连兜底落库、
 * campaign_plan_draft 任务化提交（await_task 协议）。
 */
class AiTaskTest extends TestCase
{
    protected array $uses = [AiModule::class, AgentModule::class, CampaignModule::class];

    private const TENANT = 1001;

    private const OTHER_TENANT = 2002;

    private const CONVERSATION = 9001;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::setTenantId((string) self::TENANT);
        AiTaskStubHandler::reset();
    }

    private function createTask(array $overrides = []): AiTask
    {
        return AiTask::create(array_merge([
            'tenant_id' => self::TENANT,
            'type' => 'stub_task',
            'status' => AiTask::STATUS_PENDING,
            'payload' => ['foo' => 'bar'],
        ], $overrides));
    }

    private function registry(): AiTaskHandlerRegistry
    {
        return $this->app->make(AiTaskHandlerRegistry::class);
    }

    // ---------- 主键铁律 ----------

    public function test_task_id_uses_id_generator_not_auto_increment(): void
    {
        $task = $this->createTask();

        $this->assertIsInt($task->task_id);
        $this->assertGreaterThanOrEqual(1000000000000000, $task->task_id);
        $this->assertLessThanOrEqual(9007199254740991, $task->task_id); // JS 安全上限
        $this->assertFalse($task->getIncrementing());

        // 两个任务 ID 随机无序，非连续自增
        $task2 = $this->createTask();
        $this->assertNotSame($task->task_id + 1, $task2->task_id);
    }

    // ---------- Job 状态机 ----------

    public function test_job_executes_handler_and_marks_completed(): void
    {
        $this->registry()->register('stub_task', AiTaskStubHandler::class);
        $task = $this->createTask();

        ExecuteAiTaskJob::dispatch((int) $task->task_id, self::TENANT);

        $task->refresh();
        $this->assertSame(AiTask::STATUS_COMPLETED, $task->status);
        $this->assertSame(['ok' => true, 'echo' => ['foo' => 'bar']], $task->result);
        $this->assertSame(1, $task->attempts);
        $this->assertNotNull($task->completed_at);
    }

    public function test_job_marks_failed_when_handler_throws(): void
    {
        $this->registry()->register('stub_task', AiTaskThrowingHandler::class);
        $task = $this->createTask();

        ExecuteAiTaskJob::dispatch((int) $task->task_id, self::TENANT);

        $task->refresh();
        $this->assertSame(AiTask::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('handler boom', (string) $task->error);
        $this->assertNotNull($task->completed_at);
    }

    public function test_job_marks_failed_when_type_unregistered(): void
    {
        $task = $this->createTask(['type' => 'no_such_handler']);

        ExecuteAiTaskJob::dispatch((int) $task->task_id, self::TENANT);

        $task->refresh();
        $this->assertSame(AiTask::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('未注册处理器', (string) $task->error);
    }

    public function test_job_skips_terminal_task_idempotently(): void
    {
        $this->registry()->register('stub_task', AiTaskStubHandler::class);
        $task = $this->createTask([
            'status' => AiTask::STATUS_COMPLETED,
            'result' => ['ok' => true],
        ]);

        ExecuteAiTaskJob::dispatch((int) $task->task_id, self::TENANT);

        $this->assertSame(0, AiTaskStubHandler::$invocations, '终态任务不得重复执行 handler');
    }

    public function test_job_skips_task_of_other_tenant(): void
    {
        $this->registry()->register('stub_task', AiTaskStubHandler::class);
        $task = $this->createTask(['tenant_id' => self::OTHER_TENANT]);

        // 当前租户上下文为 TENANT，跨租户任务经 BelongsToTenant 作用域不可见
        ExecuteAiTaskJob::dispatch((int) $task->task_id, self::TENANT);

        $this->assertSame(0, AiTaskStubHandler::$invocations);
    }

    // ---------- 断连兜底 ----------

    public function test_abandoned_task_persists_fallback_assistant_message(): void
    {
        $this->registry()->register('stub_task', AiTaskStubHandler::class);
        AgentConversation::create([
            'conversation_id' => self::CONVERSATION,
            'agent_id' => 1,
            'tenant_id' => self::TENANT,
        ]);

        AiTaskStubHandler::$result = ['ok' => true, 'summary' => '后台任务完成的摘要'];
        $task = $this->createTask([
            'conversation_id' => self::CONVERSATION,
            'metadata' => ['abandoned' => true],
        ]);

        ExecuteAiTaskJob::dispatch((int) $task->task_id, self::TENANT);

        $message = AgentConversationMessage::where('conversation_id', self::CONVERSATION)->first();
        $this->assertNotNull($message);
        $this->assertSame('assistant', $message->role);
        $this->assertSame('后台任务完成的摘要', $message->content);
        $this->assertSame('ai_task', $message->metadata['source'] ?? null);
    }

    public function test_online_task_does_not_persist_fallback_message(): void
    {
        $this->registry()->register('stub_task', AiTaskStubHandler::class);
        AgentConversation::create([
            'conversation_id' => self::CONVERSATION,
            'agent_id' => 1,
            'tenant_id' => self::TENANT,
        ]);

        // 未标记 abandoned（Node 仍在轮询）：落库由 messages/report 负责，避免重复
        $task = $this->createTask(['conversation_id' => self::CONVERSATION]);

        ExecuteAiTaskJob::dispatch((int) $task->task_id, self::TENANT);

        $this->assertSame(0, AgentConversationMessage::where('conversation_id', self::CONVERSATION)->count());
    }

    // ---------- campaign_plan_draft 任务化 ----------

    public function test_campaign_plan_draft_tool_submits_await_task(): void
    {
        $this->registry()->register('campaign_plan_draft', CampaignPlanDraftTaskHandler::class);
        $this->bindLlmStub();

        $this->app->make(ToolConversationContext::class)->set(self::CONVERSATION);

        $tool = $this->app->make(CampaignPlanDraftTool::class);
        $result = $tool(['user_input' => '策划七夕活动'], self::TENANT);

        // 工具返回 await_task 协议载荷
        $this->assertSame('await_task', $result['action'] ?? null);
        $this->assertNotEmpty($result['task_id'] ?? null);

        // queue sync 下任务已同步执行完毕：plan_doc 生成并落库结果
        $task = AiTask::find((int) $result['task_id']);
        $this->assertSame(AiTask::STATUS_COMPLETED, $task->status);
        $this->assertSame('campaign_plan_draft', $task->type);
        $this->assertSame(self::CONVERSATION, (int) $task->conversation_id);
        $this->assertNotEmpty($task->result['plan_id'] ?? null);
        $this->assertSame('七夕甜蜜企划', $task->result['plan_doc_preview']['title'] ?? null);
        $this->assertNotEmpty($task->result['summary'] ?? null);
    }

    public function test_campaign_plan_draft_tool_requires_user_input(): void
    {
        $tool = $this->app->make(CampaignPlanDraftTool::class);
        $result = $tool([], self::TENANT);

        $this->assertTrue($result['error'] ?? false);
        $this->assertSame(0, AiTask::count(), '快速失败不得创建任务');
    }

    /**
     * 绑定 LLM 桩：返回合法 plan_doc JSON（绕过真实网关调用）
     */
    private function bindLlmStub(): void
    {
        $planDoc = json_encode([
            'schema' => 'campaign.plan/v1',
            'title' => '七夕甜蜜企划',
            'phases' => [
                ['key' => 'warmup', 'title' => '预热', 'tasks' => []],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $stub = $this->createMock(AiTextServiceContract::class);
        $stub->method('chat')->willReturn(new AiResponse(content: $planDoc));

        $this->app->instance(AiTextServiceContract::class, $stub);
    }
}

/**
 * 测试桩 handler：回显 payload
 */
class AiTaskStubHandler implements AiTaskHandlerContract
{
    public static int $invocations = 0;

    public static array $result = [];

    public static function reset(): void
    {
        static::$invocations = 0;
        static::$result = [];
    }

    public function handle(AiTask $task): array
    {
        static::$invocations++;

        return static::$result !== [] ? static::$result : ['ok' => true, 'echo' => (array) $task->payload];
    }
}

/**
 * 测试桩 handler：固定抛异常
 */
class AiTaskThrowingHandler implements AiTaskHandlerContract
{
    public function handle(AiTask $task): array
    {
        throw new \RuntimeException('handler boom');
    }
}
