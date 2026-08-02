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
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentChatClient;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentContextBuilder;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentRuntime;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentToolExecutor;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\AgentResponse;
use MultiTenantSaas\Modules\Ai\Services\Ai\StreamChunk;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\AiModule;

class AgentRuntimeStreamTest extends TestCase
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
        $toolExecutor = new AgentToolExecutor($this->toolRegistryMock, $this->monitorMock);
        $contextBuilder = new AgentContextBuilder($toolExecutor, $tenantContext);
        $chatClient = new AgentChatClient($this->aiServiceMock, $this->toolRegistryMock);
        $this->runtime = new AgentRuntime(
            $toolExecutor,
            $contextBuilder,
            $chatClient,
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

    public function test_stream_text_reply_yields_chunks(): void
    {
        $this->createAgent();
        $this->createConversation();

        $this->aiServiceMock->shouldReceive('streamChat')
            ->once()
            ->andReturn((function () {
                yield new StreamChunk(text: 'Hello');
                yield new StreamChunk(text: ' world');
                yield new StreamChunk(finishReason: 'stop');
            })());

        $chunks = [];
        $generator = $this->runtime->runStream(1001, 2001, 'Hi');
        foreach ($generator as $chunk) {
            $chunks[] = $chunk;
        }

        $this->assertCount(3, $chunks);
        $this->assertEquals('Hello', $chunks[0]->text);
        $this->assertEquals(' world', $chunks[1]->text);
        $this->assertEquals('stop', $chunks[2]->finishReason);
    }

    public function test_stream_nonexistent_agent_yields_error(): void
    {
        $generator = $this->runtime->runStream(9999, 2001, 'Hi');
        $chunks = iterator_to_array($generator);

        $this->assertCount(1, $chunks);
        $this->assertEquals('error', $chunks[0]->finishReason);
    }

    public function test_stream_with_tool_calls_then_text(): void
    {
        $this->createAgent(['tools' => ['search_customer']]);
        $this->createConversation();

        $this->toolRegistryMock->shouldReceive('getToolDefinitions')
            ->andReturn([['type' => 'function', 'function' => ['name' => 'search_customer']]]);

        $this->toolRegistryMock->shouldReceive('execute')
            ->andReturn(['result' => 'John Doe']);

        $this->aiServiceMock->shouldReceive('streamChat')
            ->once()
            ->andReturn((function () {
                yield new StreamChunk(text: 'Let me search...');
                yield new StreamChunk(
                    toolCalls: [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'search_customer', 'arguments' => json_encode(['query' => 'John'])]]],
                    finishReason: 'tool_calls',
                );
            })());

        $this->aiServiceMock->shouldReceive('streamChat')
            ->once()
            ->andReturn((function () {
                yield new StreamChunk(text: 'Found John Doe.');
                yield new StreamChunk(finishReason: 'stop');
            })());

        $chunks = [];
        $generator = $this->runtime->runStream(1001, 2001, 'Find John');
        foreach ($generator as $chunk) {
            $chunks[] = $chunk;
        }

        $this->assertGreaterThanOrEqual(3, count($chunks));
        $this->assertEquals('Found John Doe.', $chunks[count($chunks) - 2]->text);
        $this->assertEquals('stop', $chunks[count($chunks) - 1]->finishReason);
    }

    public function test_stream_returns_agent_response_on_completion(): void
    {
        $this->createAgent();
        $this->createConversation();

        $this->aiServiceMock->shouldReceive('streamChat')
            ->once()
            ->andReturn((function () {
                yield new StreamChunk(text: 'Hello');
                yield new StreamChunk(finishReason: 'stop');
            })());

        $generator = $this->runtime->runStream(1001, 2001, 'Hi');

        foreach ($generator as $chunk) {
            // consume
        }

        $returnValue = $generator->getReturn();
        $this->assertInstanceOf(AgentResponse::class, $returnValue);
        $this->assertEquals('Hello', $returnValue->message);
    }

    public function test_stream_yields_heartbeat_after_tool_execution(): void
    {
        $this->createAgent(['tools' => ['search_customer']]);
        $this->createConversation();

        $this->toolRegistryMock->shouldReceive('getToolDefinitions')
            ->andReturn([['type' => 'function', 'function' => ['name' => 'search_customer']]]);

        $this->toolRegistryMock->shouldReceive('execute')
            ->andReturn(['result' => 'ok']);

        $this->aiServiceMock->shouldReceive('streamChat')
            ->once()
            ->andReturn((function () {
                yield new StreamChunk(
                    toolCalls: [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'search_customer', 'arguments' => '{}']]],
                    finishReason: 'tool_calls',
                );
            })());

        $this->aiServiceMock->shouldReceive('streamChat')
            ->once()
            ->andReturn((function () {
                yield new StreamChunk(text: 'Done.');
                yield new StreamChunk(finishReason: 'stop');
            })());

        $chunks = iterator_to_array($this->runtime->runStream(1001, 2001, 'Go'), false);

        // 工具执行后、下一轮推理前应产出心跳帧维持 SSE 连接
        $heartbeats = array_filter($chunks, fn (StreamChunk $c) => $c->isHeartbeat());
        $this->assertCount(1, $heartbeats);
        // 心跳帧不携带任何业务内容
        $heartbeat = array_values($heartbeats)[0];
        $this->assertSame('', $heartbeat->text);
        $this->assertFalse($heartbeat->hasToolCalls());
        $this->assertFalse($heartbeat->isFinished());
    }

    public function test_stream_falls_back_when_primary_fails_before_first_chunk(): void
    {
        $this->createAgent(['model_config' => [
            'preferred_model' => 'gpt-4o-mini',
            'preferred_provider' => 'openai',
            'fallback_provider' => 'bailian',
            'fallback_model' => 'deepseek-v3',
        ]]);
        $this->createConversation();

        // 主驱动：首 chunk 产出前即抛异常（连接失败场景）
        $this->aiServiceMock->shouldReceive('streamChat')
            ->once()
            ->andReturn((function () {
                throw new \RuntimeException('primary provider down');
                yield; // @phpstan-ignore-line 保持 Generator 类型
            })());

        // fallback 驱动：正常起流
        $this->aiServiceMock->shouldReceive('streamChat')
            ->once()
            ->withArgs(function (array $context, array $options) {
                return ($options['provider'] ?? null) === 'bailian'
                    && ($options['model'] ?? null) === 'deepseek-v3';
            })
            ->andReturn((function () {
                yield new StreamChunk(text: 'Fallback reply');
                yield new StreamChunk(finishReason: 'stop');
            })());

        $generator = $this->runtime->runStream(1001, 2001, 'Hi');
        $chunks = iterator_to_array($generator, false);

        $texts = array_map(fn (StreamChunk $c) => $c->text, $chunks);
        $this->assertContains('Fallback reply', $texts);
        $this->assertEquals('stop', end($chunks)->finishReason);

        $returnValue = $generator->getReturn();
        $this->assertEquals('Fallback reply', $returnValue->message);
    }

    public function test_stream_without_fallback_config_yields_error_on_primary_failure(): void
    {
        $this->createAgent(); // 无 fallback 配置
        $this->createConversation();

        $this->aiServiceMock->shouldReceive('streamChat')
            ->once()
            ->andReturn((function () {
                throw new \RuntimeException('primary provider down');
                yield; // @phpstan-ignore-line 保持 Generator 类型
            })());

        $chunks = iterator_to_array($this->runtime->runStream(1001, 2001, 'Hi'), false);

        $this->assertEquals('error', end($chunks)->finishReason);
    }
}
