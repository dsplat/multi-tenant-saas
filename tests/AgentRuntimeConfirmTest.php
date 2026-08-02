<?php

namespace MultiTenantSaas\Tests;

use Mockery;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\AgentMonitorContract;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Services\Agent\ActionConfirmService;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentChatClient;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentContextBuilder;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentRuntime;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentToolExecutor;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\Tool;
use MultiTenantSaas\Modules\Ai\Services\Ai\StreamChunk;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\AiModule;

/**
 * AgentRuntime L2 风险工具拦截测试
 *
 * 覆盖：L2 工具不执行且 emit pending_confirmation / 令牌可被消费 /
 * 同轮 L1 工具照常执行 / 未注入 ActionConfirmService 时向后兼容直接执行
 */
class AgentRuntimeConfirmTest extends TestCase
{
    protected array $uses = [AgentModule::class, AiModule::class];

    /** @var Mockery\MockInterface */
    protected $aiServiceMock;

    /** @var Mockery\MockInterface */
    protected $toolRegistryMock;

    /** @var Mockery\MockInterface */
    protected $monitorMock;

    protected ActionConfirmService $actionConfirm;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        TenantContext::setTenantId('1001');

        $this->aiServiceMock = Mockery::mock(AiTextServiceContract::class);
        $this->toolRegistryMock = Mockery::mock(ToolRegistryContract::class);
        $this->monitorMock = Mockery::mock(AgentMonitorContract::class);
        $this->monitorMock->shouldReceive('logConversationTurn')->andReturnNull();
        $this->monitorMock->shouldReceive('logToolCall')->andReturnNull();

        $this->actionConfirm = new ActionConfirmService;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function makeRuntime(?ActionConfirmService $actionConfirm): AgentRuntime
    {
        $tenantContext = $this->app->make(TenantContextContract::class);
        $toolExecutor = new AgentToolExecutor($this->toolRegistryMock, $this->monitorMock, $actionConfirm);
        $contextBuilder = new AgentContextBuilder($toolExecutor, $tenantContext);
        $chatClient = new AgentChatClient($this->aiServiceMock, $this->toolRegistryMock);

        return new AgentRuntime(
            $toolExecutor,
            $contextBuilder,
            $chatClient,
            $this->toolRegistryMock,
            $this->monitorMock,
            $tenantContext,
        );
    }

    protected function createAgent(array $overrides = []): Agent
    {
        return Agent::forceCreate(array_merge([
            'agent_id' => 1001,
            'tenant_id' => 1001,
            'name' => 'Test Agent',
            'role' => 'assistant',
            'system_prompt' => 'You are a helpful assistant.',
            'model_config' => ['preferred_model' => 'gpt-4o-mini', 'preferred_provider' => 'openai', 'max_tool_calls' => 5, 'max_tokens' => 8000],
            'enabled' => true,
        ], $overrides));
    }

    protected function createConversation(int $agentId = 1001): AgentConversation
    {
        return AgentConversation::forceCreate([
            'conversation_id' => 2001,
            'agent_id' => $agentId,
            'tenant_id' => 1001,
            'channel' => 'web',
            'status' => 'active',
            'message_count' => 0,
        ]);
    }

    protected function l2Tool(string $slug = 'tag_customer'): Tool
    {
        return new Tool(
            slug: $slug,
            name: '给客户打标签',
            description: '给指定客户打上标签',
            parametersSchema: ['type' => 'object'],
            handlerClass: 'App\\Services\\Tools\\TagCustomerTool',
            category: 'customer',
            risk: Tool::RISK_L2,
        );
    }

    protected function toolCallChunk(array $toolCalls): StreamChunk
    {
        return new StreamChunk(toolCalls: $toolCalls, finishReason: 'tool_calls');
    }

    public function test_l2_tool_is_intercepted_not_executed(): void
    {
        $this->createAgent(['tools' => ['tag_customer']]);
        $this->createConversation();

        $this->toolRegistryMock->shouldReceive('getToolDefinitions')
            ->andReturn([$this->l2Tool()->toFunctionCalling()]);
        $this->toolRegistryMock->shouldReceive('get')
            ->with('tag_customer')->andReturn($this->l2Tool());
        // 核心断言：L2 工具绝不执行
        $this->toolRegistryMock->shouldReceive('execute')->never();

        $arguments = ['user_id' => 5, 'tag_names' => ['VIP']];
        $this->aiServiceMock->shouldReceive('streamChat')
            ->once()
            ->andReturn((function () use ($arguments) {
                yield new StreamChunk(text: '好的，我来打标签');
                yield $this->toolCallChunk([[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'tag_customer', 'arguments' => json_encode($arguments)],
                ]]);
            })->call($this));

        $runtime = $this->makeRuntime($this->actionConfirm);
        $generator = $runtime->runStream(1001, 2001, '帮我给客户5打VIP标签');
        $chunks = iterator_to_array($generator, false);

        // 有且仅有一个 pending_confirmation 载荷 chunk
        $pendingChunks = array_values(array_filter($chunks, fn ($c) => $c->hasPendingConfirmation()));
        $this->assertCount(1, $pendingChunks);

        $pending = $pendingChunks[0]->pendingConfirmation;
        $this->assertSame('tag_customer', $pending['tool_slug']);
        $this->assertSame('给客户打标签', $pending['tool_name']);
        $this->assertSame($arguments, $pending['arguments']);
        $this->assertSame(2001, $pending['conversation_id']);
        $this->assertSame(ActionConfirmService::TTL_SECONDS, $pending['expires_in']);

        // 本轮以 pending_confirmation 结束
        $last = $chunks[count($chunks) - 1];
        $this->assertSame('pending_confirmation', $last->finishReason);

        $response = $generator->getReturn();
        $this->assertSame('pending_confirmation', $response->finishReason);

        // 签发的令牌可被 consume（端到端闭环），载荷与工具调用一致
        $payload = $this->actionConfirm->consume($pending['token'], 1001, 2001, $pending['args_hash']);
        $this->assertSame('tag_customer', $payload['tool_slug']);
        $this->assertSame($arguments, $payload['arguments']);
        $this->assertSame('call_1', $payload['tool_call_id']);
    }

    public function test_same_turn_l1_tool_executes_while_l2_pending(): void
    {
        $this->createAgent(['tools' => ['search_customer', 'tag_customer']]);
        $this->createConversation();

        $this->toolRegistryMock->shouldReceive('getToolDefinitions')
            ->andReturn([
                ['type' => 'function', 'function' => ['name' => 'search_customer']],
                $this->l2Tool()->toFunctionCalling(),
            ]);
        // L1 工具：未注册 risk（get 返回 null）→ 走直接执行分支
        $this->toolRegistryMock->shouldReceive('get')
            ->with('search_customer')->andReturn(null);
        $this->toolRegistryMock->shouldReceive('get')
            ->with('tag_customer')->andReturn($this->l2Tool());

        // L1 执行一次，且只允许 search_customer
        $this->toolRegistryMock->shouldReceive('execute')
            ->once()
            ->with('search_customer', ['query' => 'John'], 1001)
            ->andReturn(['result' => 'John Doe']);

        $this->aiServiceMock->shouldReceive('streamChat')
            ->once()
            ->andReturn((function () {
                yield $this->toolCallChunk([
                    ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'search_customer', 'arguments' => json_encode(['query' => 'John'])]],
                    ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'tag_customer', 'arguments' => json_encode(['user_id' => 5, 'tag_names' => ['VIP']])]],
                ]);
            })->call($this));

        $runtime = $this->makeRuntime($this->actionConfirm);
        $chunks = iterator_to_array($runtime->runStream(1001, 2001, '找到John并打VIP标签'), false);

        $pendingChunks = array_values(array_filter($chunks, fn ($c) => $c->hasPendingConfirmation()));
        $this->assertCount(1, $pendingChunks);
        $this->assertSame('tag_customer', $pendingChunks[0]->pendingConfirmation['tool_slug']);
        $this->assertSame('pending_confirmation', $chunks[count($chunks) - 1]->finishReason);
    }

    public function test_without_confirm_service_l2_executes_directly(): void
    {
        $this->createAgent(['tools' => ['tag_customer']]);
        $this->createConversation();

        $this->toolRegistryMock->shouldReceive('getToolDefinitions')
            ->andReturn([$this->l2Tool()->toFunctionCalling()]);
        $this->toolRegistryMock->shouldReceive('get')->andReturn($this->l2Tool());

        // 未注入 ActionConfirmService → 向后兼容：直接执行
        $this->toolRegistryMock->shouldReceive('execute')
            ->once()
            ->andReturn(['success' => true]);

        $this->aiServiceMock->shouldReceive('streamChat')
            ->once()
            ->andReturn((function () {
                yield $this->toolCallChunk([[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'tag_customer', 'arguments' => json_encode(['user_id' => 5, 'tag_names' => ['VIP']])],
                ]]);
            })->call($this));
        $this->aiServiceMock->shouldReceive('streamChat')
            ->once()
            ->andReturn((function () {
                yield new StreamChunk(text: '已完成');
                yield new StreamChunk(finishReason: 'stop');
            })());

        $runtime = $this->makeRuntime(null);
        $chunks = iterator_to_array($runtime->runStream(1001, 2001, '打标签'), false);

        $pendingChunks = array_filter($chunks, fn ($c) => $c->hasPendingConfirmation());
        $this->assertCount(0, $pendingChunks);
        $this->assertSame('stop', $chunks[count($chunks) - 1]->finishReason);
    }
}
