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
use MultiTenantSaas\Modules\Ai\Models\AgentConversationMessage;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentChatClient;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentContextBuilder;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentRuntime;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentToolExecutor;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\AiModule;

/**
 * getConversationContext 的 tool_calls 归一化 + 配对修复测试
 *
 * 背景：Node 流式引擎落库的 tool_calls 曾为展示用平铺格式 {name, arguments}，
 * 原样透传严格校验的 LLM API 会 400（tool_calls.0.function Field required）。
 * 归一化须转 OpenAI 标准格式，且 assistant.tool_calls 与 tool 消息严格成对。
 */
class AgentContextNormalizeTest extends TestCase
{
    protected array $uses = [AgentModule::class, AiModule::class];

    protected ?AgentRuntime $runtime = null;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        TenantContext::setTenantId('1001');

        $monitor = Mockery::mock(AgentMonitorContract::class);
        $monitor->shouldReceive('logConversationTurn')->andReturnNull();
        $monitor->shouldReceive('logToolCall')->andReturnNull();

        $tenantContext = $this->app->make(TenantContextContract::class);
        $toolExecutor = new AgentToolExecutor(Mockery::mock(ToolRegistryContract::class), $monitor);
        $contextBuilder = new AgentContextBuilder($toolExecutor, $tenantContext);
        $chatClient = new AgentChatClient(Mockery::mock(AiTextServiceContract::class), Mockery::mock(ToolRegistryContract::class));
        $this->runtime = new AgentRuntime(
            $toolExecutor,
            $contextBuilder,
            $chatClient,
            Mockery::mock(ToolRegistryContract::class),
            $monitor,
            $tenantContext,
        );

        Agent::forceCreate([
            'agent_id' => 1001,
            'tenant_id' => 1001,
            'name' => 'Test Agent',
            'role' => 'assistant',
            'system_prompt' => 'You are a helpful assistant.',
            'model_config' => ['preferred_model' => 'gpt-4o-mini', 'preferred_provider' => 'openai'],
            'enabled' => true,
        ]);

        AgentConversation::forceCreate([
            'conversation_id' => 2001,
            'agent_id' => 1001,
            'tenant_id' => 1001,
            'channel' => 'web',
            'status' => 'active',
            'message_count' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function addMessage(string $role, string $content, ?array $toolCalls = null, array $metadata = [], int $secondsOffset = 0): AgentConversationMessage
    {
        return AgentConversationMessage::create([
            'conversation_id' => 2001,
            'role' => $role,
            'content' => $content,
            'tool_calls' => $toolCalls,
            'tool_call_id' => $metadata['tool_call_id'] ?? null,
            'metadata' => $metadata,
            'created_at' => now()->addSeconds($secondsOffset),
        ]);
    }

    public function test_flat_tool_calls_normalized_to_openai_format(): void
    {
        // Node 平铺格式落库 + confirmAction 落库的 tool 结果（无 tool_call_id，靠工具名配对）
        $this->addMessage('user', '帮我定稿计划');
        $this->addMessage('assistant', '好的，我来提交定稿。', [
            ['name' => 'campaign_plan_commit', 'arguments' => ['plan_id' => 42]],
        ], [], 1);
        $this->addMessage('tool', '{"status":"scheduled"}', null, ['tool_name' => 'campaign_plan_commit'], 2);

        $context = $this->runtime->getConversationContext(2001);

        $assistant = collect($context)->firstWhere('role', 'assistant');
        $this->assertNotNull($assistant);
        $this->assertCount(1, $assistant['tool_calls']);

        $call = $assistant['tool_calls'][0];
        $this->assertSame('function', $call['type']);
        $this->assertSame('campaign_plan_commit', $call['function']['name']);
        $this->assertIsString($call['function']['arguments']);
        $this->assertSame(['plan_id' => 42], json_decode($call['function']['arguments'], true));
        $this->assertNotEmpty($call['id']);

        // tool 消息回填了配对 id
        $tool = collect($context)->firstWhere('role', 'tool');
        $this->assertNotNull($tool);
        $this->assertSame($call['id'], $tool['tool_call_id']);
        $this->assertArrayNotHasKey('_tool_name', $tool);
    }

    public function test_unresponded_tool_calls_are_dropped(): void
    {
        // Node 多步展平：流内已解决的 L1 调用（无 tool 响应落库）须剔除，仅保留有响应的 L2
        $this->addMessage('user', '策划并定稿');
        $this->addMessage('assistant', '已完成策划，提交定稿。', [
            ['name' => 'campaign_plan_draft', 'arguments' => ['user_input' => '21天训练营']],
            ['name' => 'campaign_plan_commit', 'arguments' => ['plan_id' => 42]],
        ], [], 1);
        $this->addMessage('tool', '{"status":"scheduled"}', null, ['tool_name' => 'campaign_plan_commit'], 2);

        $context = $this->runtime->getConversationContext(2001);

        $assistant = collect($context)->firstWhere('role', 'assistant');
        $this->assertCount(1, $assistant['tool_calls']);
        $this->assertSame('campaign_plan_commit', $assistant['tool_calls'][0]['function']['name']);
    }

    public function test_assistant_without_any_tool_response_loses_tool_calls(): void
    {
        // 全部 tool_call 无响应 → 去掉 tool_calls 字段，保留文本（协议合法）
        $this->addMessage('user', '查一下');
        $this->addMessage('assistant', '我查询了相关信息，结果如下…', [
            ['name' => 'system_kb_search', 'arguments' => ['query' => 'campaign']],
        ], [], 1);
        $this->addMessage('user', '继续', null, [], 2);

        $context = $this->runtime->getConversationContext(2001);

        $assistant = collect($context)->firstWhere('role', 'assistant');
        $this->assertArrayNotHasKey('tool_calls', $assistant);
    }

    public function test_orphan_tool_message_downgraded_to_user(): void
    {
        // 前方无 assistant.tool_calls 的孤儿 tool 消息 → 降级为 user 观察文本
        $this->addMessage('user', '你好');
        $this->addMessage('tool', '{"cancelled":true}', null, ['tool_name' => 'thread_track'], 1);

        $context = $this->runtime->getConversationContext(2001);

        $this->assertNull(collect($context)->firstWhere('role', 'tool'));
        $downgraded = collect($context)->first(
            fn ($msg) => $msg['role'] === 'user' && str_contains($msg['content'], '[工具执行结果]'),
        );
        $this->assertNotNull($downgraded);
        $this->assertStringContainsString('cancelled', $downgraded['content']);
    }

    public function test_standard_format_with_real_ids_passes_through(): void
    {
        // Node 修复后落库带 LLM 原生 id 的平铺格式 + 令牌携带 tool_call_id 的 tool 消息：精确配对
        $this->addMessage('user', '定稿');
        $this->addMessage('assistant', '提交定稿。', [
            ['id' => 'call_abc123', 'name' => 'campaign_plan_commit', 'arguments' => ['plan_id' => 7]],
        ], [], 1);
        $this->addMessage('tool', '{"status":"scheduled"}', null, [
            'tool_name' => 'campaign_plan_commit',
            'tool_call_id' => 'call_abc123',
        ], 2);

        $context = $this->runtime->getConversationContext(2001);

        $assistant = collect($context)->firstWhere('role', 'assistant');
        $this->assertSame('call_abc123', $assistant['tool_calls'][0]['id']);
        $this->assertSame('campaign_plan_commit', $assistant['tool_calls'][0]['function']['name']);

        $tool = collect($context)->firstWhere('role', 'tool');
        $this->assertSame('call_abc123', $tool['tool_call_id']);
    }

    public function test_openai_standard_stored_format_kept_intact(): void
    {
        // PHP 非流式链路落库的标准格式（含 function 嵌套）原样保留，仅补 arguments 字符串化
        $this->addMessage('user', '查客户');
        $this->addMessage('assistant', '', [
            ['id' => 'call_x1', 'type' => 'function', 'function' => ['name' => 'search_customer', 'arguments' => ['keyword' => '张三']]],
        ], [], 1);
        $this->addMessage('tool', '{"found":1}', null, ['tool_name' => 'search_customer', 'tool_call_id' => 'call_x1'], 2);

        $context = $this->runtime->getConversationContext(2001);

        $assistant = collect($context)->firstWhere('role', 'assistant');
        $call = $assistant['tool_calls'][0];
        $this->assertSame('call_x1', $call['id']);
        $this->assertIsString($call['function']['arguments']);
        $this->assertSame(['keyword' => '张三'], json_decode($call['function']['arguments'], true));
    }
}
