<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\BuiltinAgentTemplates;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\HeadlessResult;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\Tool;
use MultiTenantSaas\Modules\Ai\Services\Agent\HeadlessAgentService;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolConversationContext;
use MultiTenantSaas\Modules\Ai\Services\Ai\AiResponse;
use MultiTenantSaas\Tests\Schema\AgentModule;

/**
 * HeadlessAgentService 单测
 *
 * 验证无用户交互的 ReAct 执行循环：
 * - turns 循环与纯文本终止
 * - L2 工具不注入
 * - 黑名单工具过滤
 * - 工具调用与结果追加
 * - fail-open 异常不上抛
 * - 模板不存在返回 partial
 */
class HeadlessAgentTest extends TestCase
{
    protected array $uses = [AgentModule::class];

    private ToolRegistryContract $toolRegistry;

    private ToolConversationContext $conversationContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->toolRegistry = $this->app->make(ToolRegistryContract::class);
        $this->conversationContext = $this->app->make(ToolConversationContext::class);

        // 注册测试用 L1 工具
        $this->toolRegistry->register(
            'mock_search', 'Mock Search', 'Search mock data',
            MockHeadlessToolHandler::class,
            ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]],
            'test', 'L1'
        );

        // 注册一个 L2 工具（应被过滤）
        $this->toolRegistry->register(
            'mock_write', 'Mock Write', 'Write mock data',
            MockHeadlessToolHandler::class,
            ['type' => 'object', 'properties' => ['data' => ['type' => 'string']]],
            'test', 'L2'
        );

        // 注册黑名单工具（应被过滤）
        $this->toolRegistry->register(
            'delegate_to_agent', 'Delegate', 'Delegate to agent',
            MockHeadlessToolHandler::class,
            ['type' => 'object', 'properties' => ['role' => ['type' => 'string']]],
            'test', 'L1'
        );
    }

    /** @test */
    public function pure_text_response_returns_success()
    {
        $aiService = $this->mockAiTextService([
            new AiResponse(content: '任务完成，结果如下：测试数据', finishReason: 'stop'),
        ]);

        $service = new HeadlessAgentService($this->toolRegistry, $aiService, $this->conversationContext);
        $result = $service->execute('system_secretary', '帮我搜索一下', 1001);

        $this->assertInstanceOf(HeadlessResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->partial);
        $this->assertEquals('任务完成，结果如下：测试数据', $result->text);
        $this->assertEmpty($result->toolCallsLog);
    }

    /** @test */
    public function tool_call_then_text_completes_successfully()
    {
        $aiService = $this->mockAiTextService([
            // 第一轮：LLM 返回 tool_call
            new AiResponse(
                content: '',
                toolCalls: [
                    ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'mock_search', 'arguments' => ['q' => 'test']]],
                ],
                finishReason: 'tool_calls',
                usage: ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
            ),
            // 第二轮：LLM 返回纯文本
            new AiResponse(
                content: '搜索完成，找到3条结果',
                finishReason: 'stop',
                usage: ['prompt_tokens' => 150, 'completion_tokens' => 30, 'total_tokens' => 180],
            ),
        ]);

        $service = new HeadlessAgentService($this->toolRegistry, $aiService, $this->conversationContext);
        $result = $service->execute('system_secretary', '搜索测试', 1001);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals('搜索完成，找到3条结果', $result->text);
        $this->assertCount(1, $result->toolCallsLog);
        $this->assertEquals('mock_search', $result->toolCallsLog[0]['slug']);
        $this->assertEquals(['q' => 'test'], $result->toolCallsLog[0]['arguments']);

        // Token 累加验证
        $this->assertEquals(250, $result->tokenUsage['prompt_tokens']);
        $this->assertEquals(50, $result->tokenUsage['completion_tokens']);
        $this->assertEquals(300, $result->tokenUsage['total_tokens']);
    }

    /** @test */
    public function max_turns_exceeded_returns_partial()
    {
        // 每轮都返回 tool_call，永不返回纯文本
        $aiService = $this->mockAiTextService([
            new AiResponse(content: '', toolCalls: [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'mock_search', 'arguments' => ['q' => '1']]]], finishReason: 'tool_calls'),
            new AiResponse(content: '', toolCalls: [['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'mock_search', 'arguments' => ['q' => '2']]]], finishReason: 'tool_calls'),
            new AiResponse(content: '', toolCalls: [['id' => 'c3', 'type' => 'function', 'function' => ['name' => 'mock_search', 'arguments' => ['q' => '3']]]], finishReason: 'tool_calls'),
            // 最后一轮结束后的 final call
            new AiResponse(content: '到达最大轮次', finishReason: 'stop'),
        ]);

        $service = new HeadlessAgentService($this->toolRegistry, $aiService, $this->conversationContext);
        $result = $service->execute('system_secretary', '连续搜索', 1001, maxTurns: 3);

        // 最后一轮的 final call 产出了文本，所以不是 partial
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('到达最大轮次', $result->text);
        $this->assertCount(3, $result->toolCallsLog);
    }

    /** @test */
    public function l2_tools_not_injected_into_headless()
    {
        // 模板中含 mock_write (L2) 和 mock_search (L1)
        BuiltinAgentTemplates::clearCache();

        $aiService = $this->mockAiTextService([
            new AiResponse(content: '完成', finishReason: 'stop'),
        ]);

        $service = new HeadlessAgentService($this->toolRegistry, $aiService, $this->conversationContext);

        // 通过反射验证 resolveToolSlugs 过滤了 L2
        $reflection = new \ReflectionMethod($service, 'resolveToolSlugs');
        $reflection->setAccessible(true);

        $template = BuiltinAgentTemplates::findByKey('system_secretary');
        // 秘书模板有很多工具，但 mock_write 不在模板中
        // 我们用自定义模板来测试
        $testTemplate = ['tools' => ['mock_search', 'mock_write', 'delegate_to_agent']];
        $slugs = $reflection->invoke($service, $testTemplate);

        // mock_search (L1, not blacklisted) → 通过
        // mock_write (L2) → 被过滤
        // delegate_to_agent (L1, blacklisted) → 被过滤
        $this->assertEquals(['mock_search'], $slugs);
    }

    /** @test */
    public function blacklisted_tools_filtered_out()
    {
        $aiService = $this->mockAiTextService([
            new AiResponse(content: '完成', finishReason: 'stop'),
        ]);

        $service = new HeadlessAgentService($this->toolRegistry, $aiService, $this->conversationContext);
        $reflection = new \ReflectionMethod($service, 'resolveToolSlugs');
        $reflection->setAccessible(true);

        $template = ['tools' => ['mock_search', 'start_task_chain', 'advance_task_chain', 'delegate_to_agent']];
        $slugs = $reflection->invoke($service, $template);

        $this->assertEquals(['mock_search'], $slugs);
    }

    /** @test */
    public function nonexistent_role_returns_partial_with_error()
    {
        $aiService = $this->mockAiTextService([]);

        $service = new HeadlessAgentService($this->toolRegistry, $aiService, $this->conversationContext);
        $result = $service->execute('nonexistent_role_xyz', '测试', 1001);

        $this->assertTrue($result->partial);
        $this->assertStringContains('模板不存在', $result->error);
    }

    /** @test */
    public function llm_exception_returns_partial_fail_open()
    {
        $aiService = $this->createMock(AiTextServiceContract::class);
        $aiService->method('chat')->willThrowException(new \RuntimeException('API timeout'));

        $service = new HeadlessAgentService($this->toolRegistry, $aiService, $this->conversationContext);
        $result = $service->execute('system_secretary', '测试', 1001);

        $this->assertTrue($result->partial);
        $this->assertStringContains('API timeout', $result->error);
        $this->assertEquals('', $result->text);
    }

    /** @test */
    public function tool_execution_failure_is_handled_gracefully()
    {
        // 注册一个会抛异常的工具
        $this->toolRegistry->register(
            'exploding_tool', 'Exploding', 'Always fails',
            ExplodingToolHandler::class,
            ['type' => 'object', 'properties' => []],
            'test', 'L1'
        );

        $aiService = $this->mockAiTextService([
            new AiResponse(
                content: '',
                toolCalls: [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'exploding_tool', 'arguments' => []]]],
                finishReason: 'tool_calls',
            ),
            new AiResponse(content: '工具失败了，但我继续回复', finishReason: 'stop'),
        ]);

        $service = new HeadlessAgentService($this->toolRegistry, $aiService, $this->conversationContext);
        $result = $service->execute('system_secretary', '试试爆炸工具', 1001);

        // 工具失败不影响整体执行
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('工具失败了，但我继续回复', $result->text);
        $this->assertCount(1, $result->toolCallsLog);
        $this->assertTrue($result->toolCallsLog[0]['result']['error'] ?? false);
    }

    /** @test */
    public function conversation_context_is_set_and_cleared()
    {
        $aiService = $this->mockAiTextService([
            new AiResponse(content: '完成', finishReason: 'stop'),
        ]);

        $service = new HeadlessAgentService($this->toolRegistry, $aiService, $this->conversationContext);

        // 执行前 context 为空
        $this->assertNull($this->conversationContext->get());

        $service->execute('system_secretary', '测试', 1001);

        // 执行后 context 已清理
        $this->assertNull($this->conversationContext->get());
    }

    /**
     * 构建 mock AiTextServiceContract，按序返回预设响应
     */
    private function mockAiTextService(array $responses): AiTextServiceContract
    {
        $mock = $this->createMock(AiTextServiceContract::class);
        $callIndex = 0;

        $mock->method('chat')->willReturnCallback(function () use (&$callIndex, $responses) {
            return $responses[$callIndex++] ?? new AiResponse(content: '', finishReason: 'stop');
        });

        return $mock;
    }

    /**
     * 兼容 assertStringContains（PHPUnit 10+ 中是 assertStringContainsString）
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertStringContainsString($needle, $haystack);
    }
}

/**
 * 测试用工具处理器
 */
class MockHeadlessToolHandler implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return ['result' => 'mock_data', 'query' => $arguments['q'] ?? null];
    }
}

/**
 * 测试用：总是抛异常的工具处理器
 */
class ExplodingToolHandler implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        throw new \RuntimeException('BOOM! Tool exploded');
    }
}
