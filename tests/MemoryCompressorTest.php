<?php

namespace MultiTenantSaas\Tests;

use Mockery;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Models\AgentConversationMessage;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Modules\Ai\Services\Agent\MemoryCompressor;
use MultiTenantSaas\Modules\Ai\Services\Ai\AiResponse;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\AiModule;

class MemoryCompressorTest extends TestCase
{
    protected array $uses = [AgentModule::class, AiModule::class];

    protected ?MemoryCompressor $compressor = null;

    /** @var Mockery\MockInterface */
    protected $aiServiceMock;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        TenantContext::setTenantId('1001');

        $this->aiServiceMock = Mockery::mock(AiTextServiceContract::class);
        $tenantContext = $this->app->make(TenantContextContract::class);
        $this->compressor = new MemoryCompressor($this->aiServiceMock, $tenantContext);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function createAgent(): Agent
    {
        return Agent::forceCreate([
            'agent_id' => 1001,
            'tenant_id' => 1001,
            'name' => 'Test Agent',
            'role' => 'assistant',
            'system_prompt' => 'You are helpful.',
            'model_config' => ['preferred_model' => 'gpt-4o-mini'],
            'enabled' => true,
        ]);
    }

    protected function createConversation(): AgentConversation
    {
        return AgentConversation::forceCreate([
            'conversation_id' => 2001,
            'agent_id' => 1001,
            'tenant_id' => 1001,
            'channel' => 'web',
            'status' => 'active',
            'message_count' => 0,
        ]);
    }

    protected function createMessages(int $count, int $conversationId = 2001): void
    {
        for ($i = 0; $i < $count; $i++) {
            AgentConversationMessage::forceCreate([
                'conversation_id' => $conversationId,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => str_repeat('x', 200) . " message $i",
                'created_at' => now()->addSeconds($i),
            ]);
        }
    }

    public function test_compress_returns_false_when_no_messages(): void
    {
        $this->createAgent();
        $this->createConversation();

        $result = $this->compressor->compressMemory(2001);

        $this->assertFalse($result);
    }

    public function test_compress_returns_false_when_under_threshold(): void
    {
        $this->createAgent();
        $this->createConversation();
        $this->createMessages(3);

        $result = $this->compressor->compressMemory(2001, 8000);

        $this->assertFalse($result);
    }

    public function test_compress_returns_false_for_nonexistent_conversation(): void
    {
        $result = $this->compressor->compressMemory(9999);

        $this->assertFalse($result);
    }

    public function test_compress_triggers_when_over_threshold(): void
    {
        $this->createAgent();
        $this->createConversation();
        $this->createMessages(100);

        $this->aiServiceMock->shouldReceive('chat')
            ->atLeast()->once()
            ->andReturn(AiResponse::fromArray([
                'content' => 'Summary of old messages',
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
            ]));

        $result = $this->compressor->compressMemory(2001, 1000);

        $this->assertTrue($result);

        $messages = AgentConversationMessage::where('conversation_id', 2001)->get();
        $summaryMsg = $messages->first(fn ($m) => $m->role === 'system');
        $this->assertNotNull($summaryMsg);
        $this->assertEquals('Summary of old messages', $summaryMsg->content);
        $this->assertEquals('summary', $summaryMsg->metadata['type']);
    }

    public function test_truncate_context_preserves_system_messages(): void
    {
        $context = [
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => str_repeat('a', 5000)],
            ['role' => 'assistant', 'content' => str_repeat('b', 5000)],
            ['role' => 'user', 'content' => 'latest message'],
        ];

        $result = $this->compressor->truncateContext($context, 500);

        $this->assertEquals('system', $result[0]['role']);

        $last = end($result);
        $this->assertEquals('latest message', $last['content']);
    }

    public function test_truncate_context_returns_empty_for_empty_input(): void
    {
        $result = $this->compressor->truncateContext([]);

        $this->assertEquals([], $result);
    }

    public function test_truncate_context_with_no_system_messages(): void
    {
        $context = [
            ['role' => 'user', 'content' => 'message 1'],
            ['role' => 'assistant', 'content' => 'message 2'],
            ['role' => 'user', 'content' => 'latest message'],
        ];

        $result = $this->compressor->truncateContext($context, 500);

        $this->assertNotEmpty($result);
        $last = end($result);
        $this->assertEquals('latest message', $last['content']);
    }

    public function test_truncate_context_with_insufficient_budget(): void
    {
        $context = [
            ['role' => 'system', 'content' => str_repeat('x', 2000)],
            ['role' => 'user', 'content' => 'a user message'],
        ];

        $result = $this->compressor->truncateContext($context, 10);

        $this->assertCount(1, $result);
        $this->assertEquals('system', $result[0]['role']);
    }

    public function test_compress_handles_ai_summarize_failure(): void
    {
        $this->createAgent();
        $this->createConversation();
        $this->createMessages(100);

        $this->aiServiceMock->shouldReceive('chat')
            ->once()
            ->andThrow(new \RuntimeException('AI service down'));

        $result = $this->compressor->compressMemory(2001, 1000);

        $this->assertFalse($result);
    }

    public function test_compress_returns_false_when_agent_not_found(): void
    {
        $conversation = AgentConversation::forceCreate([
            'conversation_id' => 3001,
            'agent_id' => 9999,
            'tenant_id' => 1001,
            'channel' => 'web',
            'status' => 'active',
            'message_count' => 0,
        ]);

        $this->createMessages(100, 3001);

        $result = $this->compressor->compressMemory(3001, 1000);

        $this->assertFalse($result);
    }
}
