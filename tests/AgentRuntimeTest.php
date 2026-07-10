<?php

namespace MultiTenantSaas\Tests;

use Mockery;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\AgentMonitorContract;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentRuntime;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\AgentResponse;
use MultiTenantSaas\Modules\Ai\Services\Ai\AiResponse;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\AiModule;

class AgentRuntimeTest extends TestCase
{
    protected array $uses = [AgentModule::class, AiModule::class];

    protected ?AgentRuntime $runtime = null;

    /** @var Mockery\MockInterface */
    protected $aiServiceMock;

    /** @var Mockery\MockInterface */
    protected $toolRegistryMock;

    /** @var Mockery\MockInterface */
    protected $monitorMock;

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

        $tenantContext = $this->app->make(TenantContextContract::class);
        $this->runtime = new AgentRuntime(
            $this->aiServiceMock,
            $this->toolRegistryMock,
            $this->monitorMock,
            $tenantContext,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
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

    public function test_text_reply_returns_agent_response(): void
    {
        $this->createAgent();
        $this->createConversation();

        $this->aiServiceMock->shouldReceive('chat')
            ->once()
            ->andReturn(AiResponse::fromArray([
                'content' => 'Hello! How can I help you?',
                'finish_reason' => 'stop',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 8, 'total_tokens' => 18],
            ]));

        $response = $this->runtime->run(1001, 2001, 'Hello');

        $this->assertInstanceOf(AgentResponse::class, $response);
        $this->assertEquals('Hello! How can I help you?', $response->message);
        $this->assertEquals('stop', $response->finishReason);
        $this->assertEquals([], $response->toolCalls);
    }

    public function test_nonexistent_agent_returns_error(): void
    {
        $response = $this->runtime->run(9999, 2001, 'Hello');

        $this->assertEquals('error', $response->finishReason);
        $this->assertStringContainsString('9999', $response->error);
    }

    public function test_single_tool_call_then_text(): void
    {
        $this->createAgent(['tools' => ['search_customer']]);
        $this->createConversation();

        $this->toolRegistryMock->shouldReceive('getToolDefinitions')
            ->with(['search_customer'])
            ->andReturn([['type' => 'function', 'function' => ['name' => 'search_customer']]]);

        $this->toolRegistryMock->shouldReceive('execute')
            ->with('search_customer', Mockery::type('array'), 1001)
            ->andReturn(['name' => 'John Doe']);

        $this->aiServiceMock->shouldReceive('chat')
            ->once()
            ->andReturn(AiResponse::fromArray([
                'content' => '',
                'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'search_customer', 'arguments' => json_encode(['query' => 'John'])]]],
                'finish_reason' => 'tool_calls',
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30],
            ]));

        $this->aiServiceMock->shouldReceive('chat')
            ->once()
            ->andReturn(AiResponse::fromArray([
                'content' => 'Found John Doe.',
                'finish_reason' => 'stop',
                'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 5, 'total_tokens' => 35],
            ]));

        $response = $this->runtime->run(1001, 2001, 'Find John');

        $this->assertEquals('Found John Doe.', $response->message);
        $this->assertEquals('stop', $response->finishReason);
    }

    public function test_max_tool_calls_forced_summary(): void
    {
        $this->createAgent([
            'tools' => ['search_customer'],
            'model_config' => ['preferred_model' => 'gpt-4o-mini', 'preferred_provider' => 'openai', 'max_tool_calls' => 2, 'max_tokens' => 8000],
        ]);
        $this->createConversation();

        $this->toolRegistryMock->shouldReceive('getToolDefinitions')
            ->andReturn([['type' => 'function', 'function' => ['name' => 'search_customer']]]);

        $this->toolRegistryMock->shouldReceive('execute')
            ->andReturn(['result' => 'ok']);

        $this->aiServiceMock->shouldReceive('chat')
            ->times(2)
            ->andReturn(AiResponse::fromArray([
                'content' => '',
                'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'search_customer', 'arguments' => json_encode(['query' => 'test'])]]],
                'finish_reason' => 'tool_calls',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]));

        $response = $this->runtime->run(1001, 2001, 'Search');

        $this->assertEquals('max_tool_calls', $response->finishReason);
        $this->assertStringContainsString('上限', $response->message);
    }

    public function test_ai_service_failure_returns_error(): void
    {
        $this->createAgent();

        $this->aiServiceMock->shouldReceive('chat')
            ->andThrow(new \RuntimeException('Service unavailable'));

        $response = $this->runtime->run(1001, 2001, 'Hello');

        $this->assertEquals('error', $response->finishReason);
        $this->assertStringContainsString('不可用', $response->message);
    }

    public function test_token_usage_accumulated_across_turns(): void
    {
        $this->createAgent(['tools' => ['search_customer']]);
        $this->createConversation();

        $this->toolRegistryMock->shouldReceive('getToolDefinitions')
            ->andReturn([['type' => 'function', 'function' => ['name' => 'search_customer']]]);

        $this->toolRegistryMock->shouldReceive('execute')
            ->andReturn(['result' => 'ok']);

        $this->aiServiceMock->shouldReceive('chat')
            ->once()
            ->andReturn(AiResponse::fromArray([
                'content' => '',
                'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'search_customer', 'arguments' => json_encode(['q' => 'a'])]]],
                'finish_reason' => 'tool_calls',
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            ]));

        $this->aiServiceMock->shouldReceive('chat')
            ->once()
            ->andReturn(AiResponse::fromArray([
                'content' => 'Done.',
                'finish_reason' => 'stop',
                'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 20, 'total_tokens' => 100],
            ]));

        $response = $this->runtime->run(1001, 2001, 'Search');

        $this->assertEquals(180, $response->tokenUsage['prompt_tokens']);
        $this->assertEquals(70, $response->tokenUsage['completion_tokens']);
        $this->assertEquals(250, $response->tokenUsage['total_tokens']);
    }
}
