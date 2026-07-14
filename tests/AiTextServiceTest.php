<?php

namespace MultiTenantSaas\Tests;

use Mockery;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\Models\AiPrompt;
use MultiTenantSaas\Modules\Ai\Services\AiGatewayService;
use MultiTenantSaas\Modules\Ai\Services\AiTextService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Scopes\TenantScope;
use MultiTenantSaas\Tests\Schema\AiModule;

/**
 * AiTextService 测试套件
 *
 * 覆盖：聊天补全/文本补全/向量嵌入/流式/JSON 模式（均委托 AiGatewayService）、
 * 默认模型便捷方法、提示词模板 CRUD、按名称解析（租户级覆盖系统级）、
 * 变量占位符替换、必需变量校验、按模板聊天、系统级预置模板。
 */
class AiTextServiceTest extends TestCase
{
    protected array $uses = [AiModule::class];

    protected ?AiTextService $service = null;

    /** @var Mockery\MockInterface&Mockery\LegacyMockInterface */
    protected $gatewayMock;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);

        $this->gatewayMock = Mockery::mock(AiGatewayService::class);
        $this->app->instance(AiGatewayService::class, $this->gatewayMock);

        $this->configureTextDefaults();

        $this->seedSystemPrompts();

        TenantContext::setTenantId('1001');

        $this->service = $this->app->make(AiTextService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 设置文本 AI 默认配置
     */
    protected function configureTextDefaults(): void
    {
        config(['ai.text.default_chat_model' => 'gpt-4o-mini']);
        config(['ai.text.default_completion_model' => 'gpt-4o-mini']);
        config(['ai.text.default_embedding_model' => 'text-embedding-3-small']);
        config(['ai.streaming_enabled' => true]);
    }

    /**
     * 预置 4 个系统级模板（在无租户上下文下创建，tenant_id 为 null）
     */
    protected function seedSystemPrompts(): void
    {
        TenantContext::clear();

        $presets = [
            [
                'name' => 'general_assistant',
                'category' => 'assistant',
                'system_prompt' => '你是一个通用助手。',
                'user_prompt' => '{{input}}',
                'variables' => [['name' => 'input', 'description' => '用户输入', 'required' => true]],
            ],
            [
                'name' => 'customer_service',
                'category' => 'service',
                'system_prompt' => '你是一名客服助手。',
                'user_prompt' => '{{question}}',
                'variables' => [['name' => 'question', 'description' => '问题', 'required' => true]],
            ],
            [
                'name' => 'code_assistant',
                'category' => 'development',
                'system_prompt' => '你是一名工程师。',
                'user_prompt' => '{{task}}',
                'variables' => [['name' => 'task', 'description' => '任务', 'required' => true]],
            ],
            [
                'name' => 'translation_assistant',
                'category' => 'language',
                'system_prompt' => '你是一名翻译。',
                'user_prompt' => "请将以下{{source_lang}}文本翻译为{{target_lang}}：\n\n{{text}}",
                'variables' => [
                    ['name' => 'source_lang', 'description' => '源语言', 'required' => true],
                    ['name' => 'target_lang', 'description' => '目标语言', 'required' => true],
                    ['name' => 'text', 'description' => '文本', 'required' => true],
                ],
            ],
        ];

        foreach ($presets as $preset) {
            AiPrompt::create($preset);
        }
    }

    protected function makeChatResponse(string $content = 'Hello', string $model = 'gpt-4o'): array
    {
        return [
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'model' => $model,
            'role' => 'assistant',
            'content' => $content,
            'tool_calls' => null,
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            'raw' => [],
        ];
    }

    // ----------------------------------------------------------------
    // LLM 能力委托测试
    // ----------------------------------------------------------------

    public function test_chat_delegates_to_gateway(): void
    {
        $messages = [['role' => 'user', 'content' => 'hi']];

        $this->gatewayMock
            ->shouldReceive('chat')
            ->with('gpt-4o', $messages, ['temperature' => 0.7])
            ->once()
            ->andReturn($this->makeChatResponse('answer'));

        $result = $this->service->chat('gpt-4o', $messages, ['temperature' => 0.7]);

        $this->assertSame('answer', $result['content']);
    }

    public function test_complete_delegates_to_gateway(): void
    {
        $this->gatewayMock
            ->shouldReceive('complete')
            ->with('gpt-4o', 'once upon', [])
            ->once()
            ->andReturn(['model' => 'gpt-4o', 'text' => 'a time', 'finish_reason' => 'stop', 'usage' => [], 'raw' => []]);

        $result = $this->service->complete('gpt-4o', 'once upon');

        $this->assertSame('a time', $result['text']);
    }

    public function test_embed_delegates_to_gateway_with_batch_input(): void
    {
        $input = ['hello', 'world'];

        $this->gatewayMock
            ->shouldReceive('embed')
            ->with('text-embedding-3-small', $input, [])
            ->once()
            ->andReturn([
                'model' => 'text-embedding-3-small',
                'object' => 'list',
                'data' => [
                    ['index' => 0, 'embedding' => [0.1, 0.2], 'object' => 'embedding'],
                    ['index' => 1, 'embedding' => [0.3, 0.4], 'object' => 'embedding'],
                ],
                'usage' => ['prompt_tokens' => 4],
                'raw' => [],
            ]);

        $result = $this->service->embed('text-embedding-3-small', $input);

        $this->assertCount(2, $result['data']);
        $this->assertSame([0.3, 0.4], $result['data'][1]['embedding']);
    }

    public function test_stream_chat_delegates_and_yields_chunks(): void
    {
        $messages = [['role' => 'user', 'content' => 'stream']];

        $chunks = [
            ['content' => 'hel', 'finish_reason' => null],
            ['content' => 'lo', 'finish_reason' => null],
            ['content' => '', 'finish_reason' => 'stop'],
        ];

        $this->gatewayMock
            ->shouldReceive('streamChat')
            ->with('gpt-4o', $messages, [])
            ->once()
            ->andReturnUsing(function () use ($chunks): \Generator {
                foreach ($chunks as $chunk) {
                    yield $chunk;
                }
            });

        $collected = [];
        foreach ($this->service->streamChat('gpt-4o', $messages) as $chunk) {
            $collected[] = $chunk;
        }

        $this->assertCount(3, $collected);
        $this->assertSame('lo', $collected[1]['content']);
        $this->assertSame('stop', $collected[2]['finish_reason']);
    }

    public function test_chat_json_sets_response_format_and_parses(): void
    {
        $messages = [['role' => 'user', 'content' => 'list 2 fruits']];

        $this->gatewayMock
            ->shouldReceive('chat')
            ->withArgs(function (string $model, array $msgs, array $opts): bool {
                return $model === 'gpt-4o'
                    && ($opts['response_format']['type'] ?? null) === 'json_object';
            })
            ->once()
            ->andReturn($this->makeChatResponse('{"fruits":["apple","banana"]}'));

        $result = $this->service->chatJson('gpt-4o', $messages);

        $this->assertSame(['fruits' => ['apple', 'banana']], $result['json']);
    }

    public function test_chat_json_strips_markdown_fences(): void
    {
        $this->gatewayMock
            ->shouldReceive('chat')
            ->andReturn($this->makeChatResponse("```json\n{\"ok\":true}\n```"));

        $result = $this->service->chatJson('gpt-4o', [['role' => 'user', 'content' => 'x']]);

        $this->assertSame(['ok' => true], $result['json']);
    }

    public function test_chat_json_throws_on_invalid_json(): void
    {
        $this->gatewayMock
            ->shouldReceive('chat')
            ->andReturn($this->makeChatResponse('not json'));

        $this->expectException(\RuntimeException::class);

        $this->service->chatJson('gpt-4o', [['role' => 'user', 'content' => 'x']]);
    }

    public function test_default_methods_use_configured_models(): void
    {
        $this->gatewayMock->shouldReceive('chat')->with('gpt-4o-mini', [], [])->once()->andReturn($this->makeChatResponse('c', 'gpt-4o-mini'));
        $r1 = $this->service->chatDefault([]);
        $this->assertSame('c', $r1['content']);

        $this->gatewayMock->shouldReceive('complete')->with('gpt-4o-mini', 'p', [])->once()->andReturn(['text' => 't', 'finish_reason' => 'stop', 'usage' => [], 'raw' => []]);
        $r2 = $this->service->completeDefault('p');
        $this->assertSame('t', $r2['text']);

        $this->gatewayMock->shouldReceive('embed')->with('text-embedding-3-small', 's', [])->once()->andReturn(['data' => [['index' => 0, 'embedding' => [0.1]]], 'usage' => [], 'raw' => []]);
        $r3 = $this->service->embedDefault('s');
        $this->assertCount(1, $r3['data']);
    }

    // ----------------------------------------------------------------
    // 提示词 CRUD 测试
    // ----------------------------------------------------------------

    public function test_create_prompt_auto_fills_tenant_and_defaults(): void
    {
        $prompt = $this->service->createPrompt([
            'name' => 'greeting',
            'system_prompt' => 'Be polite.',
            'user_prompt' => 'Say hi to {{name}}',
            'variables' => [['name' => 'name', 'description' => '姓名', 'required' => true]],
        ]);

        $this->assertSame(1001, (int) $prompt->tenant_id);
        $this->assertSame('general', $prompt->category);
        $this->assertSame(1, $prompt->version);
        $this->assertSame('active', $prompt->status);
        $this->assertTrue($prompt->exists);
    }

    public function test_create_prompt_rejects_empty_name(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->createPrompt(['name' => '  ', 'user_prompt' => 'x']);
    }

    public function test_create_prompt_rejects_duplicate_name_for_same_tenant(): void
    {
        $this->service->createPrompt(['name' => 'dup', 'user_prompt' => 'first']);

        $this->expectException(\RuntimeException::class);

        $this->service->createPrompt(['name' => 'dup', 'user_prompt' => 'second']);
    }

    public function test_create_prompt_allows_same_name_as_system_template_override(): void
    {
        // 系统级 general_assistant 已预置，租户可创建同名覆盖
        $override = $this->service->createPrompt([
            'name' => 'general_assistant',
            'system_prompt' => 'tenant override',
            'user_prompt' => '{{input}}',
            'variables' => [['name' => 'input', 'required' => true]],
        ]);

        $this->assertSame(1001, (int) $override->tenant_id);
        $this->assertFalse($override->isSystemLevel());
    }

    public function test_get_prompt_is_scoped_to_current_tenant(): void
    {
        $prompt = $this->service->createPrompt(['name' => 'fetchable', 'user_prompt' => 'u']);

        $this->assertSame('fetchable', $this->service->getPrompt($prompt->prompt_id)->name);

        // 切换到其他租户，应查询不到
        TenantContext::setTenantId('1002');
        $this->assertNull($this->service->getPrompt($prompt->prompt_id));
    }

    public function test_update_prompt_and_bump_version(): void
    {
        $prompt = $this->service->createPrompt(['name' => 'ver', 'user_prompt' => 'v1']);

        $updated = $this->service->updatePrompt($prompt->prompt_id, [
            'user_prompt' => 'v2',
            'bump_version' => true,
        ]);

        $this->assertSame('v2', $updated->user_prompt);
        $this->assertSame(2, $updated->version);
    }

    public function test_update_prompt_rejects_conflicting_name(): void
    {
        $this->service->createPrompt(['name' => 'orig', 'user_prompt' => 'a']);
        $other = $this->service->createPrompt(['name' => 'other', 'user_prompt' => 'b']);

        $this->expectException(\RuntimeException::class);

        $this->service->updatePrompt($other->prompt_id, ['name' => 'orig']);
    }

    public function test_delete_prompt_removes_tenant_level(): void
    {
        $prompt = $this->service->createPrompt(['name' => 'kill', 'user_prompt' => 'x']);

        $this->assertTrue($this->service->deletePrompt($prompt->prompt_id));
        $this->assertNull($this->service->getPrompt($prompt->prompt_id));
    }

    public function test_delete_prompt_rejects_system_level(): void
    {
        $system = AiPrompt::withoutGlobalScope(TenantScope::class)
            ->where('name', 'general_assistant')
            ->first();

        $this->expectException(\RuntimeException::class);

        $this->service->deletePrompt($system->prompt_id);
    }

    public function test_delete_prompt_throws_when_not_found(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->deletePrompt(999999);
    }

    // ----------------------------------------------------------------
    // 模板解析与渲染测试
    // ----------------------------------------------------------------

    public function test_find_by_name_returns_system_fallback(): void
    {
        $prompt = $this->service->findByName('general_assistant');

        $this->assertNotNull($prompt);
        $this->assertTrue($prompt->isSystemLevel());
    }

    public function test_find_by_name_prefers_tenant_override(): void
    {
        $this->service->createPrompt([
            'name' => 'general_assistant',
            'system_prompt' => 'tenant override',
            'user_prompt' => '{{input}}',
            'variables' => [['name' => 'input', 'required' => true]],
        ]);

        $prompt = $this->service->findByName('general_assistant');

        $this->assertNotNull($prompt);
        $this->assertSame(1001, (int) $prompt->tenant_id);
        $this->assertSame('tenant override', $prompt->system_prompt);
    }

    public function test_find_by_name_returns_null_when_missing(): void
    {
        $this->assertNull($this->service->findByName('non_existent_template'));
    }

    public function test_list_prompts_returns_system_and_tenant(): void
    {
        $this->service->createPrompt(['name' => 'tenant_one', 'user_prompt' => 'x']);

        $names = $this->service->listPrompts()->pluck('name')->all();

        $this->assertContains('general_assistant', $names);
        $this->assertContains('customer_service', $names);
        $this->assertContains('code_assistant', $names);
        $this->assertContains('translation_assistant', $names);
        $this->assertContains('tenant_one', $names);
    }

    public function test_list_prompts_filters_by_category(): void
    {
        $result = $this->service->listPrompts('language');

        $this->assertCount(1, $result);
        $this->assertSame('translation_assistant', $result->first()->name);
    }

    public function test_render_replaces_variables(): void
    {
        $prompt = $this->service->findByName('translation_assistant');

        $rendered = $this->service->render($prompt, [
            'source_lang' => '中文',
            'target_lang' => 'English',
            'text' => '你好',
        ]);

        $this->assertStringContainsString('以下中文文本翻译为English', $rendered['user']);
        $this->assertStringContainsString('你好', $rendered['user']);
    }

    public function test_render_supports_spaces_in_placeholders(): void
    {
        $prompt = AiPrompt::create([
            'name' => 'spaced',
            'user_prompt' => 'Hello {{ name }}!',
            'variables' => [['name' => 'name', 'required' => true]],
        ]);

        $rendered = $this->service->render($prompt, ['name' => 'Alice']);

        $this->assertSame('Hello Alice!', $rendered['user']);
    }

    public function test_render_throws_on_missing_required_variable(): void
    {
        $prompt = $this->service->findByName('general_assistant');

        $this->expectException(\RuntimeException::class);

        $this->service->render($prompt, []);
    }

    public function test_chat_with_prompt_assembles_messages_and_calls_gateway(): void
    {
        $this->gatewayMock
            ->shouldReceive('chat')
            ->withArgs(function (string $model, array $messages, array $opts): bool {
                if ($model !== 'gpt-4o') {
                    return false;
                }
                $roles = array_column($messages, 'role');
                if (! in_array('system', $roles, true)) {
                    return false;
                }
                foreach (array_column($messages, 'content') as $content) {
                    if (is_string($content) && str_contains($content, '你好，世界')) {
                        return true;
                    }
                }

                return false;
            })
            ->once()
            ->andReturn($this->makeChatResponse('translated'));

        $result = $this->service->chatWithPrompt(
            'translation_assistant',
            ['source_lang' => '中文', 'target_lang' => '英文', 'text' => '你好，世界'],
            'gpt-4o'
        );

        $this->assertSame('translated', $result['content']);
    }

    public function test_chat_with_prompt_throws_when_template_missing(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->chatWithPrompt('no_such_template', [], 'gpt-4o');
    }

    // ----------------------------------------------------------------
    // 预置系统级模板测试
    // ----------------------------------------------------------------

    public function test_four_preset_system_templates_seeded(): void
    {
        $expected = ['general_assistant', 'customer_service', 'code_assistant', 'translation_assistant'];

        foreach ($expected as $name) {
            $prompt = $this->service->findByName($name);
            $this->assertNotNull($prompt, "预置模板 {$name} 应存在");
            $this->assertTrue($prompt->isSystemLevel(), "预置模板 {$name} 应为系统级");
            $this->assertSame('active', $prompt->status);
        }
    }

    public function test_preset_templates_categories(): void
    {
        $this->assertSame('assistant', $this->service->findByName('general_assistant')->category);
        $this->assertSame('service', $this->service->findByName('customer_service')->category);
        $this->assertSame('development', $this->service->findByName('code_assistant')->category);
        $this->assertSame('language', $this->service->findByName('translation_assistant')->category);
    }
}
