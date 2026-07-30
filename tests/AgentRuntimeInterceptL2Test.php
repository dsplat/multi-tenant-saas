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
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentRuntime;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\Tool;
use MultiTenantSaas\Modules\Ai\Services\Ai\AiResponse;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\AiModule;

/**
 * AgentRuntime 非流式 run() L2 拦截（intercept_l2 opt-in）
 *
 * 覆盖：intercept_l2=false 现状不变 / true 时 L2 不执行且返回 pending 载荷 /
 * L1 照常 / 自定义 TTL 透传
 */
class AgentRuntimeInterceptL2Test extends TestCase
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

        Tenant::create(['tenant_id' => 1001, 'name' => 'T', 'slug' => 't', 'status' => 'active']);
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

    private function makeRuntime(?ActionConfirmService $actionConfirm = null): AgentRuntime
    {
        return new AgentRuntime(
            $this->aiServiceMock,
            $this->toolRegistryMock,
            $this->monitorMock,
            $this->app->make(TenantContextContract::class),
            null,
            null,
            $actionConfirm ?? $this->actionConfirm,
        );
    }

    private function createAgentAndConversation(): void
    {
        Agent::forceCreate([
            'agent_id' => 1001,
            'tenant_id' => 1001,
            'name' => 'Test',
            'role' => 'assistant',
            'system_prompt' => 'You help.',
            'model_config' => ['preferred_model' => 'gpt-4o-mini', 'preferred_provider' => 'openai', 'max_tool_calls' => 5, 'max_tokens' => 8000],
            'tools' => ['tag_customer'],
            'enabled' => true,
        ]);

        AgentConversation::forceCreate([
            'conversation_id' => 2001,
            'agent_id' => 1001,
            'tenant_id' => 1001,
            'channel' => 'ibot',
            'status' => 'active',
            'message_count' => 0,
        ]);
    }

    private function l2Tool(): Tool
    {
        return new Tool(
            slug: 'tag_customer',
            name: '给客户打标签',
            description: '给指定客户打上标签',
            parametersSchema: ['type' => 'object'],
            handlerClass: 'App\\Services\\Tools\\TagCustomerTool',
            category: 'customer',
            risk: Tool::RISK_L2,
        );
    }

    private function aiResponseWithL2ToolCall(): AiResponse
    {
        return AiResponse::fromArray([
            'content' => '好的，我来打标签',
            'tool_calls' => [[
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => 'tag_customer', 'arguments' => ['user_id' => 5, 'tag_names' => ['VIP']]],
            ]],
            'finish_reason' => 'tool_calls',
            'model' => 'gpt-4o-mini',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]);
    }

    // ========== intercept_l2 = true ==========

    public function test_intercept_l2_true_returns_pending_without_executing(): void
    {
        $this->createAgentAndConversation();

        $this->toolRegistryMock->shouldReceive('getToolDefinitions')->andReturn([]);
        $this->toolRegistryMock->shouldReceive('get')->with('tag_customer')->andReturn($this->l2Tool());
        // 核心断言：L2 工具绝不执行
        $this->toolRegistryMock->shouldReceive('execute')->never();

        $this->aiServiceMock->shouldReceive('chat')->once()->andReturn($this->aiResponseWithL2ToolCall());

        $runtime = $this->makeRuntime();
        $response = $runtime->run(1001, 2001, '帮我给客户5打VIP标签', ['intercept_l2' => true]);

        $this->assertSame('pending_confirmation', $response->finishReason);
        $this->assertCount(1, $response->pendingConfirmations);

        $pending = $response->pendingConfirmations[0];
        $this->assertSame('tag_customer', $pending['tool_slug']);
        $this->assertSame('给客户打标签', $pending['tool_name']);
        $this->assertSame(['user_id' => 5, 'tag_names' => ['VIP']], $pending['arguments']);
        $this->assertArrayHasKey('token', $pending);
        $this->assertArrayHasKey('args_hash', $pending);
    }

    public function test_intercept_l2_true_custom_ttl(): void
    {
        $this->createAgentAndConversation();

        $this->toolRegistryMock->shouldReceive('getToolDefinitions')->andReturn([]);
        $this->toolRegistryMock->shouldReceive('get')->with('tag_customer')->andReturn($this->l2Tool());
        $this->toolRegistryMock->shouldReceive('execute')->never();

        $this->aiServiceMock->shouldReceive('chat')->once()->andReturn($this->aiResponseWithL2ToolCall());

        $runtime = $this->makeRuntime();
        $response = $runtime->run(1001, 2001, '打标签', ['intercept_l2' => true, 'confirm_ttl' => 600]);

        $pending = $response->pendingConfirmations[0];
        $this->assertSame(600, $pending['expires_in']);
    }

    // ========== intercept_l2 = false（默认）==========

    public function test_intercept_l2_false_executes_l2_directly(): void
    {
        $this->createAgentAndConversation();

        $this->toolRegistryMock->shouldReceive('getToolDefinitions')->andReturn([]);
        $this->toolRegistryMock->shouldReceive('get')->with('tag_customer')->andReturn($this->l2Tool());
        // 默认行为：L2 直接执行
        $this->toolRegistryMock->shouldReceive('execute')
            ->once()
            ->with('tag_customer', ['user_id' => 5, 'tag_names' => ['VIP']], 1001)
            ->andReturn(['success' => true]);

        // 第一次返回工具调用，第二次返回文本（工具执行后续答）
        $this->aiServiceMock->shouldReceive('chat')->once()->andReturn($this->aiResponseWithL2ToolCall());
        $this->aiServiceMock->shouldReceive('chat')->once()->andReturn(AiResponse::fromArray([
            'content' => '已打标签',
            'finish_reason' => 'stop',
            'model' => 'gpt-4o-mini',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]));

        $runtime = $this->makeRuntime();
        $response = $runtime->run(1001, 2001, '帮我给客户5打VIP标签');

        $this->assertSame('stop', $response->finishReason);
        $this->assertEmpty($response->pendingConfirmations);
    }

    // ========== 令牌端到端闭环 ==========

    public function test_issued_token_can_be_consumed(): void
    {
        $this->createAgentAndConversation();

        $this->toolRegistryMock->shouldReceive('getToolDefinitions')->andReturn([]);
        $this->toolRegistryMock->shouldReceive('get')->with('tag_customer')->andReturn($this->l2Tool());
        $this->toolRegistryMock->shouldReceive('execute')->never();

        $this->aiServiceMock->shouldReceive('chat')->once()->andReturn($this->aiResponseWithL2ToolCall());

        $runtime = $this->makeRuntime();
        $response = $runtime->run(1001, 2001, '打标签', ['intercept_l2' => true]);

        $pending = $response->pendingConfirmations[0];

        // consume 闭环
        $payload = $this->actionConfirm->consume($pending['token'], 1001, 2001, $pending['args_hash']);
        $this->assertSame('tag_customer', $payload['tool_slug']);
        $this->assertSame('call_1', $payload['tool_call_id']);
    }
}
