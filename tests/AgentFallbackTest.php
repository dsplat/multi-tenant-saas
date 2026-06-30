<?php

namespace MultiTenantSaas\Tests;

use Mockery;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\AgentMonitorContract;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Models\Agent;
use MultiTenantSaas\Models\AgentConversation;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\Agent\AgentRuntime;
use MultiTenantSaas\Services\Ai\AiResponse;

class AgentFallbackTest extends TestCase
{
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
            'system_prompt' => 'You are helpful.',
            'model_config' => [
                'preferred_model' => 'gpt-4o-mini',
                'preferred_provider' => 'openai',
                'fallback_model' => 'gpt-4o',
                'fallback_provider' => 'openai',
                'max_tool_calls' => 5,
                'max_tokens' => 8000,
            ],
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

    public function test_fallback_activated_on_primary_failure(): void
    {
        $this->createAgent();
        $this->createConversation();

        $this->aiServiceMock->shouldReceive('chat')
            ->once()
            ->with(Mockery::type('array'), Mockery::on(fn ($opts) => ($opts['model'] ?? '') === 'gpt-4o-mini'))
            ->andThrow(new \RuntimeException('Provider unavailable'));

        $this->aiServiceMock->shouldReceive('chat')
            ->once()
            ->with(Mockery::type('array'), Mockery::on(fn ($opts) => ($opts['model'] ?? '') === 'gpt-4o'))
            ->andReturn(AiResponse::fromArray([
                'content' => 'Fallback response',
                'finish_reason' => 'stop',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]));

        $response = $this->runtime->run(1001, 2001, 'Hello');

        $this->assertEquals('Fallback response', $response->message);
    }

    public function test_both_primary_and_fallback_fail_returns_error(): void
    {
        $this->createAgent();
        $this->createConversation();

        $this->aiServiceMock->shouldReceive('chat')
            ->times(2)
            ->andThrow(new \RuntimeException('Service down'));

        $response = $this->runtime->run(1001, 2001, 'Hello');

        $this->assertEquals('error', $response->finishReason);
        $this->assertStringContainsString('不可用', $response->message);
    }

    public function test_no_fallback_config_returns_error_on_failure(): void
    {
        $this->createAgent([
            'model_config' => [
                'preferred_model' => 'gpt-4o-mini',
                'preferred_provider' => 'openai',
                'max_tool_calls' => 5,
            ],
        ]);
        $this->createConversation();

        $this->aiServiceMock->shouldReceive('chat')
            ->once()
            ->andThrow(new \RuntimeException('Provider down'));

        $response = $this->runtime->run(1001, 2001, 'Hello');

        $this->assertEquals('error', $response->finishReason);
        $this->assertStringContainsString('不可用', $response->message);
    }

    public function test_tool_failure_returns_error_to_ai(): void
    {
        $this->createAgent(['tools' => ['search_customer']]);
        $this->createConversation();

        $this->toolRegistryMock->shouldReceive('getToolDefinitions')
            ->andReturn([['type' => 'function', 'function' => ['name' => 'search_customer']]]);

        $this->toolRegistryMock->shouldReceive('execute')
            ->andThrow(new \RuntimeException('Database connection failed'));

        $this->aiServiceMock->shouldReceive('chat')
            ->once()
            ->andReturn(AiResponse::fromArray([
                'content' => '',
                'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'search_customer', 'arguments' => json_encode(['q' => 'test'])]]],
                'finish_reason' => 'tool_calls',
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
            ]));

        $this->aiServiceMock->shouldReceive('chat')
            ->once()
            ->andReturn(AiResponse::fromArray([
                'content' => 'Sorry, search is unavailable.',
                'finish_reason' => 'stop',
                'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 8],
            ]));

        $response = $this->runtime->run(1001, 2001, 'Search John');

        $this->assertEquals('Sorry, search is unavailable.', $response->message);
        $this->assertEquals('stop', $response->finishReason);
    }
}
