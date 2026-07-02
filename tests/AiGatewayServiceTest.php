<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\AiModelAlias;
use MultiTenantSaas\Models\AiRequest;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\Ai\OpenAiProvider;
use MultiTenantSaas\Services\Ai\ZhipuProvider;
use MultiTenantSaas\Services\AiGatewayService;
use MultiTenantSaas\Tests\Schema\AiModule;

/**
 * AiGatewayService 完整测试套件
 *
 * 覆盖：对话补全、文本补全、向量嵌入、流式对话、模型路由（别名/枚举/原始名）、
 * 提供商解析与缓存、速率限制、重试策略、请求日志（创建/终结/清洗/摘要）、
 * 参数校验、配置开关。
 */
class AiGatewayServiceTest extends TestCase
{
    protected array $uses = [AiModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'AI Tenant', 'slug' => 'ai-tenant', 'status' => 'active']);
        Tenant::create(['tenant_id' => 1002, 'name' => 'Other Tenant', 'slug' => 'other-tenant', 'status' => 'active']);

        TenantContext::setTenantId('1001');

        $this->configureAiDefaults();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 设置 AI 网关默认配置（各测试可按需覆盖）
     */
    protected function configureAiDefaults(): void
    {
        config(['ai.streaming_enabled' => true]);
        config(['ai.rate_limit.enabled' => false]);
        config(['ai.rate_limit.max_requests_per_minute' => 60]);
        config(['ai.retry.attempts' => 2]);
        config(['ai.retry.delay_ms' => 0]);
        config(['ai.log.enable' => true]);
        config(['ai.default_provider' => 'openai']);
    }

    /**
     * 将 OpenAiProvider 绑定为 Mockery mock
     */
    protected function bindOpenAiMock(): Mockery\MockInterface&Mockery\LegacyMockInterface
    {
        $mock = Mockery::mock(OpenAiProvider::class);
        $this->app->instance(OpenAiProvider::class, $mock);

        return $mock;
    }

    /**
     * 将 ZhipuProvider 绑定为 Mockery mock
     */
    protected function bindZhipuMock(): Mockery\MockInterface&Mockery\LegacyMockInterface
    {
        $mock = Mockery::mock(ZhipuProvider::class);
        $this->app->instance(ZhipuProvider::class, $mock);

        return $mock;
    }

    protected function makeChatResponse(string $model = 'gpt-4o'): array
    {
        return [
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => $model,
            'role' => 'assistant',
            'content' => 'Hello from AI',
            'tool_calls' => null,
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            'raw' => ['id' => 'chatcmpl-123'],
        ];
    }

    protected function makeCompletionResponse(string $model = 'gpt-4o'): array
    {
        return [
            'id' => 'cmpl-123',
            'object' => 'text_completion',
            'model' => $model,
            'text' => 'completion text',
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 3],
            'raw' => [],
        ];
    }

    protected function makeEmbedResponse(string $model = 'text-embedding-3-small'): array
    {
        return [
            'model' => $model,
            'object' => 'list',
            'data' => [
                ['index' => 0, 'embedding' => [0.1, 0.2, 0.3], 'object' => 'embedding'],
            ],
            'usage' => ['prompt_tokens' => 5],
            'raw' => [],
        ];
    }

    // ======================================================================
    // chat() — 参数校验
    // ======================================================================

    public function test_chat_throws_when_messages_empty(): void
    {
        $this->expectException(\RuntimeException::class);

        $service = app(AiGatewayService::class);
        $service->chat('gpt-4o', []);
    }

    // ======================================================================
    // chat() — 正常调用与日志
    // ======================================================================

    public function test_chat_with_enum_model_calls_provider_and_returns_response(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')
            ->once()
            ->with('gpt-4o', $messages, [])
            ->andReturn($this->makeChatResponse());

        $service = app(AiGatewayService::class);
        $result = $service->chat('gpt-4o', $messages);

        $this->assertEquals('Hello from AI', $result['content']);
        $this->assertEquals('gpt-4o', $result['model']);
        $this->assertSame(10, $result['usage']['prompt_tokens']);
    }

    public function test_chat_creates_success_log_on_success(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')->once()->andReturn($this->makeChatResponse());

        $service = app(AiGatewayService::class);
        $service->chat('gpt-4o', $messages);

        $log = AiRequest::first();

        $this->assertNotNull($log);
        $this->assertEquals(AiRequest::STATUS_SUCCESS, $log->status);
        $this->assertEquals('gpt-4o', $log->model);
        $this->assertEquals('openai', $log->provider);
        $this->assertSame(10, $log->input_tokens);
        $this->assertSame(5, $log->output_tokens);
        $this->assertNotNull($log->response_time_ms);
        $this->assertNull($log->error_message);
    }

    public function test_chat_creates_failed_log_on_provider_error(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')
            ->twice()
            ->andThrow(new \RuntimeException('API down'));

        $service = app(AiGatewayService::class);

        try {
            $service->chat('gpt-4o', $messages);
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            // 预期异常
        }

        $log = AiRequest::first();

        $this->assertNotNull($log);
        $this->assertEquals(AiRequest::STATUS_FAILED, $log->status);
        $this->assertSame(0, $log->input_tokens);
        $this->assertSame(0, $log->output_tokens);
        $this->assertStringContainsString('API down', $log->error_message);
    }

    // ======================================================================
    // chat() — 重试策略
    // ======================================================================

    public function test_chat_retries_then_succeeds(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];

        $callCount = 0;
        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new \RuntimeException('transient error');
                }

                return $this->makeChatResponse();
            });

        $service = app(AiGatewayService::class);
        $result = $service->chat('gpt-4o', $messages);

        $this->assertEquals(2, $callCount);
        $this->assertEquals('Hello from AI', $result['content']);

        $log = AiRequest::first();
        $this->assertEquals(AiRequest::STATUS_SUCCESS, $log->status);
    }

    public function test_chat_throws_after_all_retries_exhausted(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')
            ->times(2)
            ->andThrow(new \RuntimeException('persistent error'));

        $service = app(AiGatewayService::class);

        $this->expectException(\RuntimeException::class);

        $service->chat('gpt-4o', $messages);
    }

    // ======================================================================
    // chat() — 模型路由（别名 / 枚举 / 原始名）
    // ======================================================================

    public function test_chat_resolves_alias_to_actual_model(): void
    {
        AiModelAlias::create([
            'alias' => 'fast-chat',
            'actual_model' => 'gpt-4o-mini',
            'provider' => 'openai',
            'type' => 'text',
            'is_active' => true,
            'is_deprecated' => false,
        ]);

        $messages = [['role' => 'user', 'content' => 'Hi']];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')
            ->once()
            ->with('gpt-4o-mini', $messages, [])
            ->andReturn($this->makeChatResponse('gpt-4o-mini'));

        $service = app(AiGatewayService::class);
        $result = $service->chat('fast-chat', $messages);

        $this->assertEquals('gpt-4o-mini', $result['model']);

        $log = AiRequest::first();
        $this->assertEquals('gpt-4o-mini', $log->model);
    }

    public function test_chat_with_deprecated_alias_throws(): void
    {
        AiModelAlias::create([
            'alias' => 'old-model',
            'actual_model' => 'gpt-4-turbo',
            'provider' => 'openai',
            'type' => 'text',
            'is_active' => true,
            'is_deprecated' => true,
        ]);

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')->never();

        $this->expectException(\RuntimeException::class);

        $service = app(AiGatewayService::class);
        $service->chat('old-model', [['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_chat_with_inactive_alias_falls_through_to_enum_or_raw(): void
    {
        AiModelAlias::create([
            'alias' => 'inactive-alias',
            'actual_model' => 'gpt-4o-mini',
            'provider' => 'openai',
            'type' => 'text',
            'is_active' => false,
            'is_deprecated' => false,
        ]);

        $messages = [['role' => 'user', 'content' => 'Hi']];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')
            ->once()
            ->with('inactive-alias', $messages, [])
            ->andReturn($this->makeChatResponse('inactive-alias'));

        $service = app(AiGatewayService::class);
        $result = $service->chat('inactive-alias', $messages);

        $this->assertEquals('inactive-alias', $result['model']);
    }

    public function test_chat_with_raw_model_falls_back_to_default_provider(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hi']];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')
            ->once()
            ->with('custom-model-xyz', $messages, [])
            ->andReturn($this->makeChatResponse('custom-model-xyz'));

        $service = app(AiGatewayService::class);
        $result = $service->chat('custom-model-xyz', $messages);

        $this->assertEquals('custom-model-xyz', $result['model']);

        $log = AiRequest::first();
        $this->assertEquals('openai', $log->provider);
    }

    public function test_chat_with_alias_null_provider_uses_default(): void
    {
        AiModelAlias::create([
            'alias' => 'no-provider-alias',
            'actual_model' => 'gpt-4o',
            'provider' => null,
            'type' => 'text',
            'is_active' => true,
            'is_deprecated' => false,
        ]);

        $messages = [['role' => 'user', 'content' => 'Hi']];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')
            ->once()
            ->with('gpt-4o', $messages, [])
            ->andReturn($this->makeChatResponse());

        $service = app(AiGatewayService::class);
        $service->chat('no-provider-alias', $messages);

        $log = AiRequest::first();
        $this->assertEquals('openai', $log->provider);
    }

    public function test_chat_with_alias_provider_override_uses_zhipu(): void
    {
        AiModelAlias::create([
            'alias' => 'glm-alias',
            'actual_model' => 'glm-4',
            'provider' => 'zhipu',
            'type' => 'text',
            'is_active' => true,
            'is_deprecated' => false,
        ]);

        $messages = [['role' => 'user', 'content' => 'Hi']];

        $provider = $this->bindZhipuMock();
        $provider->shouldReceive('chatCompletion')
            ->once()
            ->with('glm-4', $messages, [])
            ->andReturn($this->makeChatResponse('glm-4'));

        $service = app(AiGatewayService::class);
        $service->chat('glm-alias', $messages);

        $log = AiRequest::first();
        $this->assertEquals('zhipu', $log->provider);
    }

    // ======================================================================
    // resolveProvider() — 未注册提供商
    // ======================================================================

    public function test_unregistered_provider_throws_provider_not_implemented(): void
    {
        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')->never();

        $this->expectException(\RuntimeException::class);

        $service = app(AiGatewayService::class);
        $service->chat('claude-3-5-sonnet', [['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_multiple_calls_on_same_service_reuse_provider_cache(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hi']];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')
            ->twice()
            ->andReturn($this->makeChatResponse(), $this->makeChatResponse());

        $service = app(AiGatewayService::class);
        $service->chat('gpt-4o', $messages);
        $service->chat('gpt-4o', $messages);

        $this->assertEquals(2, AiRequest::count());
    }

    // ======================================================================
    // complete() — 文本补全
    // ======================================================================

    public function test_complete_throws_when_prompt_empty(): void
    {
        $this->expectException(\RuntimeException::class);

        $service = app(AiGatewayService::class);
        $service->complete('gpt-4o', '');
    }

    public function test_complete_throws_when_prompt_whitespace_only(): void
    {
        $this->expectException(\RuntimeException::class);

        $service = app(AiGatewayService::class);
        $service->complete('gpt-4o', '   ');
    }

    public function test_complete_returns_response_and_logs(): void
    {
        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('textCompletion')
            ->once()
            ->with('gpt-4o', 'Hello world', [])
            ->andReturn($this->makeCompletionResponse());

        $service = app(AiGatewayService::class);
        $result = $service->complete('gpt-4o', 'Hello world');

        $this->assertEquals('completion text', $result['text']);
        $this->assertSame(8, $result['usage']['prompt_tokens']);

        $log = AiRequest::first();
        $this->assertNotNull($log);
        $this->assertEquals(AiRequest::STATUS_SUCCESS, $log->status);
        $this->assertStringContainsString('Hello world', $log->prompt_summary);
    }

    // ======================================================================
    // embed() — 向量嵌入
    // ======================================================================

    public function test_embed_throws_when_string_input_empty(): void
    {
        $this->expectException(\RuntimeException::class);

        $service = app(AiGatewayService::class);
        $service->embed('text-embedding-3-small', '');
    }

    public function test_embed_throws_when_array_input_empty(): void
    {
        $this->expectException(\RuntimeException::class);

        $service = app(AiGatewayService::class);
        $service->embed('text-embedding-3-small', []);
    }

    public function test_embed_with_string_input_succeeds(): void
    {
        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('embeddings')
            ->once()
            ->with('text-embedding-3-small', 'hello text', [])
            ->andReturn($this->makeEmbedResponse());

        $service = app(AiGatewayService::class);
        $result = $service->embed('text-embedding-3-small', 'hello text');

        $this->assertCount(1, $result['data']);
        $this->assertSame(5, $result['usage']['prompt_tokens']);

        $log = AiRequest::first();
        $this->assertNotNull($log);
        $this->assertEquals(AiRequest::STATUS_SUCCESS, $log->status);
        $this->assertStringContainsString('hello text', $log->prompt_summary);
    }

    public function test_embed_with_array_input_succeeds(): void
    {
        $input = ['first text', 'second text'];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('embeddings')
            ->once()
            ->with('text-embedding-3-small', $input, [])
            ->andReturn($this->makeEmbedResponse());

        $service = app(AiGatewayService::class);
        $result = $service->embed('text-embedding-3-small', $input);

        $this->assertCount(1, $result['data']);

        $log = AiRequest::first();
        $this->assertStringContainsString('first text', $log->prompt_summary);
    }

    // ======================================================================
    // streamChat() — 流式对话
    // ======================================================================

    public function test_stream_chat_throws_when_streaming_disabled(): void
    {
        config(['ai.streaming_enabled' => false]);

        $service = app(AiGatewayService::class);
        $generator = $service->streamChat('gpt-4o', [['role' => 'user', 'content' => 'Hi']]);

        $this->expectException(\RuntimeException::class);

        foreach ($generator as $chunk) {
            // 流式未启用，生成器体抛出异常，不会到达此处
        }
    }

    public function test_stream_chat_throws_when_messages_empty(): void
    {
        $service = app(AiGatewayService::class);
        $generator = $service->streamChat('gpt-4o', []);

        $this->expectException(\RuntimeException::class);

        foreach ($generator as $chunk) {
            // 消息为空，生成器体抛出异常，不会到达此处
        }
    }

    public function test_stream_chat_yields_chunks_and_logs_success(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hi']];

        $chunks = [
            ['content' => 'Hello', 'role' => 'assistant'],
            ['content' => ' world', 'role' => null],
        ];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('streamChatCompletion')
            ->once()
            ->with('gpt-4o', $messages, [])
            ->andReturnUsing(function () use ($chunks) {
                foreach ($chunks as $chunk) {
                    yield $chunk;
                }
            });

        $service = app(AiGatewayService::class);
        $generator = $service->streamChat('gpt-4o', $messages);

        $collected = [];
        foreach ($generator as $chunk) {
            $collected[] = $chunk;
        }

        $this->assertCount(2, $collected);
        $this->assertEquals('Hello', $collected[0]['content']);
        $this->assertEquals(' world', $collected[1]['content']);

        $log = AiRequest::first();
        $this->assertNotNull($log);
        $this->assertEquals(AiRequest::STATUS_SUCCESS, $log->status);
    }

    public function test_stream_chat_logs_failure_on_provider_error(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hi']];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('streamChatCompletion')
            ->once()
            ->andReturnUsing(function () {
                throw new \RuntimeException('stream error');
                yield;
            });

        $service = app(AiGatewayService::class);
        $generator = $service->streamChat('gpt-4o', $messages);

        try {
            foreach ($generator as $chunk) {
                // 触发生成器执行
            }
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('stream error', $e->getMessage());
        }

        $log = AiRequest::first();
        $this->assertNotNull($log);
        $this->assertEquals(AiRequest::STATUS_FAILED, $log->status);
        $this->assertStringContainsString('stream error', $log->error_message);
    }

    // ======================================================================
    // 速率限制
    // ======================================================================

    public function test_rate_limit_disabled_allows_call(): void
    {
        config(['ai.rate_limit.enabled' => false]);

        $messages = [['role' => 'user', 'content' => 'Hi']];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')->once()->andReturn($this->makeChatResponse());

        $service = app(AiGatewayService::class);
        $service->chat('gpt-4o', $messages);

        $this->assertEquals(1, AiRequest::count());
    }

    public function test_rate_limit_enabled_allows_under_limit(): void
    {
        config(['ai.rate_limit.enabled' => true]);
        config(['ai.rate_limit.max_requests_per_minute' => 60]);

        $messages = [['role' => 'user', 'content' => 'Hi']];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')->once()->andReturn($this->makeChatResponse());

        $service = app(AiGatewayService::class);
        $service->chat('gpt-4o', $messages);

        $this->assertEquals(1, AiRequest::count());
    }

    public function test_rate_limit_exceeded_throws(): void
    {
        config(['ai.rate_limit.enabled' => true]);
        config(['ai.rate_limit.max_requests_per_minute' => 1]);

        $messages = [['role' => 'user', 'content' => 'Hi']];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')->once()->andReturn($this->makeChatResponse());

        $service = app(AiGatewayService::class);

        $service->chat('gpt-4o', $messages);

        $this->expectException(\RuntimeException::class);
        $service->chat('gpt-4o', $messages);
    }

    // ======================================================================
    // 日志配置与内容
    // ======================================================================

    public function test_log_disabled_creates_no_record(): void
    {
        config(['ai.log.enable' => false]);

        $messages = [['role' => 'user', 'content' => 'Hi']];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')->once()->andReturn($this->makeChatResponse());

        $service = app(AiGatewayService::class);
        $service->chat('gpt-4o', $messages);

        $this->assertEquals(0, AiRequest::count());
    }

    public function test_log_sanitizes_sensitive_options(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hi']];

        $options = [
            'api_key' => 'sk-secret',
            'authorization' => 'Bearer xxx',
            'headers' => ['X-Custom' => 'val'],
            'temperature' => 0.7,
        ];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')
            ->once()
            ->with('gpt-4o', $messages, $options)
            ->andReturn($this->makeChatResponse());

        $service = app(AiGatewayService::class);
        $service->chat('gpt-4o', $messages, $options);

        $log = AiRequest::first();
        $metadata = $log->metadata;

        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('options', $metadata);
        $this->assertArrayNotHasKey('api_key', $metadata['options']);
        $this->assertArrayNotHasKey('authorization', $metadata['options']);
        $this->assertArrayNotHasKey('headers', $metadata['options']);
        $this->assertArrayHasKey('temperature', $metadata['options']);
        $this->assertSame(0.7, $metadata['options']['temperature']);
    }

    public function test_log_prompt_summary_uses_last_user_message(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are helpful'],
            ['role' => 'user', 'content' => 'First question'],
            ['role' => 'assistant', 'content' => 'Answer'],
            ['role' => 'user', 'content' => 'Second question'],
        ];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')->once()->andReturn($this->makeChatResponse());

        $service = app(AiGatewayService::class);
        $service->chat('gpt-4o', $messages);

        $log = AiRequest::first();
        $this->assertEquals('Second question', $log->prompt_summary);
    }

    public function test_log_prompt_summary_falls_back_to_last_message_when_no_user(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'System prompt'],
            ['role' => 'assistant', 'content' => 'Assistant reply'],
        ];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')->once()->andReturn($this->makeChatResponse());

        $service = app(AiGatewayService::class);
        $service->chat('gpt-4o', $messages);

        $log = AiRequest::first();
        $this->assertEquals('Assistant reply', $log->prompt_summary);
    }

    public function test_log_truncates_long_prompt_summary(): void
    {
        $longContent = str_repeat('a', 300);

        $messages = [['role' => 'user', 'content' => $longContent]];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')->once()->andReturn($this->makeChatResponse());

        $service = app(AiGatewayService::class);
        $service->chat('gpt-4o', $messages);

        $log = AiRequest::first();
        $this->assertLessThan(strlen($longContent), strlen($log->prompt_summary));
        $this->assertStringEndsWith('...', $log->prompt_summary);
    }

    public function test_log_user_id_is_null_when_not_authenticated(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hi']];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')->once()->andReturn($this->makeChatResponse());

        $service = app(AiGatewayService::class);
        $service->chat('gpt-4o', $messages);

        $log = AiRequest::first();
        $this->assertNull($log->user_id);
    }

    public function test_log_user_id_set_when_authenticated(): void
    {
        $user = User::create([
            'name' => 'Auth User',
            'email' => 'auth@example.com',
            'password' => 'password',
        ]);

        $this->actingAs($user, 'sanctum');

        $messages = [['role' => 'user', 'content' => 'Hi']];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')->once()->andReturn($this->makeChatResponse());

        $service = app(AiGatewayService::class);
        $service->chat('gpt-4o', $messages);

        $log = AiRequest::first();
        $this->assertEquals((int) $user->user_id, $log->user_id);
    }

    public function test_log_tenant_id_auto_filled_from_context(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hi']];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')->once()->andReturn($this->makeChatResponse());

        $service = app(AiGatewayService::class);
        $service->chat('gpt-4o', $messages);

        $log = AiRequest::first();
        $this->assertSame(1001, $log->tenant_id);
    }

    public function test_log_cost_is_zero_by_default(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hi']];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')->once()->andReturn($this->makeChatResponse());

        $service = app(AiGatewayService::class);
        $service->chat('gpt-4o', $messages);

        $log = AiRequest::first();
        $this->assertSame(0.0, (float) $log->cost);
    }

    // ======================================================================
    // 配置开关
    // ======================================================================

    public function test_default_provider_config_affects_raw_model_resolution(): void
    {
        config(['ai.default_provider' => 'zhipu']);

        $messages = [['role' => 'user', 'content' => 'Hi']];

        $provider = $this->bindZhipuMock();
        $provider->shouldReceive('chatCompletion')
            ->once()
            ->with('raw-custom-model', $messages, [])
            ->andReturn($this->makeChatResponse('raw-custom-model'));

        $service = app(AiGatewayService::class);
        $service->chat('raw-custom-model', $messages);

        $log = AiRequest::first();
        $this->assertEquals('zhipu', $log->provider);
    }

    public function test_retry_attempts_config_controls_retry_count(): void
    {
        config(['ai.retry.attempts' => 3]);

        $messages = [['role' => 'user', 'content' => 'Hi']];

        $provider = $this->bindOpenAiMock();
        $provider->shouldReceive('chatCompletion')
            ->times(3)
            ->andThrow(new \RuntimeException('fail'));

        $service = app(AiGatewayService::class);

        $this->expectException(\RuntimeException::class);
        $service->chat('gpt-4o', $messages);
    }
}
