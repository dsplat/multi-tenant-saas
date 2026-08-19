<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Contracts\Http\Kernel;
use Mockery;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Models\AgentConversationMessage;
use MultiTenantSaas\Modules\Ai\Services\Agent\StreamHistoryBuilder;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolRegistry;
use MultiTenantSaas\Modules\Ai\Services\AiUsageService;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\IdentifyTenant;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\RbacModule;

/**
 * 流式链路数值确定性测试：工具结果落库 → DB 历史重建 → resolve 下发
 *
 * 端到端回归目标：第一轮工具结果（含真实长数字 plan_id）落库后，
 * 第二轮 resolve 下发的 history 中必须含该结果——模型跨轮取值不靠猜。
 */
class StreamHistoryTest extends TestCase
{
    protected array $uses = [AgentModule::class, RbacModule::class];

    protected Tenant $tenant;

    protected Tenant $otherTenant;

    protected User $user;

    protected Operator $operator;

    protected Agent $agent;

    protected AgentConversation $conversation;

    /** 真实量级的长数字 ID（与生产全局 ID 同形态，回归「短数字幻觉」根因） */
    private const REAL_PLAN_ID = 7330141784569016;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.guards.sanctum.provider', null);
        $app['config']->set('ai.providers.bailian', [
            'driver' => 'openai',
            'key' => 'sk-test-key',
            'url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
            'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
            'api_key' => 'sk-test-key',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)
            ->prependMiddleware(IdentifyTenant::class);

        // builder 级单测无 HTTP 中间件，手动建立租户上下文（TenantScope 依赖）
        TenantContext::setTenantId('1001');

        $this->tenant = Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        $this->otherTenant = Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);
        $this->user = User::create(['name' => 'Test', 'email' => 'test@test.com', 'password' => bcrypt('password')]);

        $tenantAdminRoleId = \DB::table('roles')
            ->where('name', 'tenant_admin')
            ->whereNull('tenant_id')
            ->value('role_id');

        $this->operator = Operator::create([
            'email' => $this->user->email,
            'name' => $this->user->name,
            'scope' => 'tenant',
            'is_active' => true,
        ]);

        OperatorTenant::create([
            'operator_id' => $this->operator->operator_id,
            'tenant_id' => 1001,
            'user_id' => $this->user->user_id,
            'role' => 'tenant_admin',
            'role_id' => $tenantAdminRoleId,
            'is_active' => true,
            'accepted_at' => now(),
        ]);

        $this->agent = Agent::forceCreate([
            'agent_id' => 1001,
            'tenant_id' => 1001,
            'name' => 'Stream Agent',
            'role' => 'assistant',
            'system_prompt' => 'You are helpful.',
            'tools' => ['demo_tool'],
            'model_config' => [
                'preferred_provider' => 'bailian',
                'preferred_model' => 'qwen-plus',
            ],
            'enabled' => true,
        ]);

        $this->conversation = AgentConversation::create([
            'tenant_id' => 1001,
            'agent_id' => 1001,
            'channel' => 'assistant',
            'subject' => '测试会话',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function authHeaders(int $tenantId = 1001): array
    {
        $token = $this->operator->createToken('test-' . uniqid())->plainTextToken;

        return [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => (string) $tenantId,
            'Accept' => 'application/json',
        ];
    }

    protected function mockUsageServiceOk(): void
    {
        $mock = Mockery::mock(AiUsageService::class);
        $mock->shouldReceive('checkQuota')->andReturnNull();
        $mock->shouldReceive('checkBudget')->andReturnNull();
        $this->app->instance(AiUsageService::class, $mock);
    }

    protected function builder(): StreamHistoryBuilder
    {
        return app(StreamHistoryBuilder::class);
    }

    /** 同批落库保序计数器（与 MessageReportController 逐条递增秒数同口径） */
    private int $storeSeq = 0;

    /** 落库一条消息（形态与 MessageReportController / AgentToolExecutor 产物一致） */
    protected function storeMessage(array $attributes): AgentConversationMessage
    {
        return AgentConversationMessage::create(array_merge([
            'conversation_id' => (int) $this->conversation->conversation_id,
            'metadata' => ['source' => 'ai-streaming'],
            'created_at' => now()->addSeconds($this->storeSeq++),
        ], $attributes));
    }

    // ========== StreamHistoryBuilder：DB 重建 ==========

    public function test_builder_rebuilds_tool_round_with_real_ids(): void
    {
        $this->storeMessage(['role' => 'user', 'content' => '帮我做活动计划']);
        $this->storeMessage([
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [['id' => 'call_abc123', 'name' => 'activity_plan_draft', 'arguments' => ['title' => '训练营']]],
        ]);
        $this->storeMessage([
            'role' => 'tool',
            'content' => '{"plan_id":' . self::REAL_PLAN_ID . ',"status":"drafted"}',
            'tool_call_id' => 'call_abc123',
            'metadata' => ['source' => 'ai-streaming', 'tool_name' => 'activity_plan_draft'],
        ]);
        $this->storeMessage(['role' => 'assistant', 'content' => '草稿已生成']);

        $history = $this->builder()->build((int) $this->conversation->conversation_id, 1001);

        $this->assertCount(4, $history);
        $this->assertSame('user', $history[0]['role']);

        // assistant.tool_calls 平铺格式归一化为 OpenAI 标准（id 原样保留，不合成）
        $this->assertSame('call_abc123', $history[1]['tool_calls'][0]['id']);
        $this->assertSame('activity_plan_draft', $history[1]['tool_calls'][0]['function']['name']);

        // tool 结果原样回放：真实长数字 ID 随结构化历史到达下一轮模型
        $this->assertSame('tool', $history[2]['role']);
        $this->assertSame('call_abc123', $history[2]['tool_call_id']);
        $this->assertStringContainsString((string) self::REAL_PLAN_ID, $history[2]['content']);
        $this->assertArrayNotHasKey('_tool_name', $history[2]);
    }

    public function test_builder_backfills_missing_tool_call_id_by_tool_name(): void
    {
        $this->storeMessage(['role' => 'user', 'content' => '开始']);
        $this->storeMessage([
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [['id' => 'call_x9', 'name' => 'demo_tool', 'arguments' => []]],
        ]);
        // 历史缺陷形态：tool 消息缺 tool_call_id，仅有 metadata.tool_name
        $this->storeMessage([
            'role' => 'tool',
            'content' => '{"ok":true}',
            'tool_call_id' => null,
            'metadata' => ['source' => 'ai-streaming', 'tool_name' => 'demo_tool'],
        ]);

        $history = $this->builder()->build((int) $this->conversation->conversation_id, 1001);

        $this->assertCount(3, $history);
        $this->assertSame('call_x9', $history[2]['tool_call_id'], '缺 id 的 tool 消息应按工具名回填配对');
    }

    public function test_builder_removes_unanswered_tool_calls(): void
    {
        $this->storeMessage(['role' => 'user', 'content' => '开始']);
        // Node 流内已执行但结果未落库的历史形态：tool_call 无响应
        $this->storeMessage([
            'role' => 'assistant',
            'content' => '稍等',
            'tool_calls' => [['id' => 'call_lost', 'name' => 'demo_tool', 'arguments' => []]],
        ]);

        $history = $this->builder()->build((int) $this->conversation->conversation_id, 1001);

        $this->assertCount(2, $history);
        $this->assertArrayNotHasKey('tool_calls', $history[1], '无响应的 tool_call 必须剔除（协议要求成对）');
        $this->assertSame('稍等', $history[1]['content'], 'assistant 文本保留');
    }

    public function test_builder_demotes_orphan_tool_message_to_observation(): void
    {
        $this->storeMessage(['role' => 'user', 'content' => '开始']);
        $this->storeMessage([
            'role' => 'tool',
            'content' => '{"plan_id":123}',
            'tool_call_id' => 'call_ghost',
        ]);

        $history = $this->builder()->build((int) $this->conversation->conversation_id, 1001);

        $this->assertCount(2, $history);
        $this->assertSame('user', $history[1]['role'], '孤儿 tool 消息降级为 user 观察文本');
        $this->assertStringStartsWith('[工具执行结果]', $history[1]['content']);
    }

    public function test_builder_truncates_tool_content_to_budget(): void
    {
        $this->storeMessage(['role' => 'user', 'content' => '开始']);
        $this->storeMessage([
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [['id' => 'call_big', 'name' => 'demo_tool', 'arguments' => []]],
        ]);
        $this->storeMessage([
            'role' => 'tool',
            'content' => str_repeat('A', 3000),
            'tool_call_id' => 'call_big',
        ]);

        $history = $this->builder()->build((int) $this->conversation->conversation_id, 1001);

        $this->assertSame(2000, mb_strlen($history[2]['content']), '工具结果按预算护栏截断到 2000 字符');
    }

    public function test_builder_drops_leading_partial_turn(): void
    {
        // 头部残轮（截断后首条为 assistant/tool）整轮丢弃，只从 user 轮开始
        $this->storeMessage([
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [['id' => 'call_head', 'name' => 'demo_tool', 'arguments' => []]],
        ]);
        $this->storeMessage(['role' => 'tool', 'content' => '{"ok":true}', 'tool_call_id' => 'call_head']);
        $this->storeMessage(['role' => 'user', 'content' => '新一轮开始']);
        $this->storeMessage(['role' => 'assistant', 'content' => '好的']);

        $history = $this->builder()->build((int) $this->conversation->conversation_id, 1001);

        $this->assertCount(2, $history);
        $this->assertSame('user', $history[0]['role']);
        $this->assertSame('新一轮开始', $history[0]['content']);
    }

    public function test_builder_caps_history_at_40_messages(): void
    {
        for ($i = 1; $i <= 45; $i++) {
            $this->storeMessage(['role' => 'user', 'content' => "msg-{$i}"]);
        }

        $history = $this->builder()->build((int) $this->conversation->conversation_id, 1001);

        $this->assertCount(40, $history);
        $this->assertSame('msg-6', $history[0]['content'], '取最近 40 条，最旧的头部被裁掉');
        $this->assertSame('msg-45', $history[39]['content']);
    }

    public function test_builder_returns_empty_for_other_tenant(): void
    {
        $this->storeMessage(['role' => 'user', 'content' => '机密内容']);

        $history = $this->builder()->build((int) $this->conversation->conversation_id, 1002);

        $this->assertSame([], $history, '跨租户会话不得泄露历史');
    }

    // ========== MessageReportController：tool_results 落库 ==========

    public function test_message_report_stores_tool_results_after_assistant(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/messages/report', [
                'conversation_id' => $this->conversation->conversation_id,
                'agent_id' => 1001,
                'user_message' => '帮我做活动计划',
                'assistant_message' => '好的，正在生成草稿。',
                'tool_calls' => [['id' => 'call_abc123', 'name' => 'activity_plan_draft', 'arguments' => ['title' => '训练营']]],
                'tool_results' => [[
                    'tool_call_id' => 'call_abc123',
                    'tool_name' => 'activity_plan_draft',
                    'content' => '{"plan_id":' . self::REAL_PLAN_ID . ',"status":"drafted"}',
                ]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.saved', 3);

        $messages = AgentConversationMessage::where('conversation_id', $this->conversation->conversation_id)
            ->orderBy('created_at', 'asc')
            ->get();

        // 落库顺序 user → assistant → tool（协议要求 tool 紧随 tool_calls）
        $this->assertSame(['user', 'assistant', 'tool'], $messages->pluck('role')->all());

        $tool = $messages->last();
        $this->assertSame('call_abc123', $tool->tool_call_id, 'tool_call_id 直接取 LLM 原生 id（不靠猜）');
        $this->assertStringContainsString((string) self::REAL_PLAN_ID, $tool->content);
        $this->assertSame('activity_plan_draft', $tool->metadata['tool_name']);

        // message_count 含 tool 消息
        $this->assertSame(3, (int) $this->conversation->fresh()->message_count);
    }

    public function test_message_report_rejects_tool_results_without_tool_call_id(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/messages/report', [
                'conversation_id' => $this->conversation->conversation_id,
                'user_message' => '开始',
                'assistant_message' => '好的',
                'tool_results' => [
                    ['tool_name' => 'demo_tool', 'content' => '{"ok":true}'], // 缺 id：拒收
                    ['tool_call_id' => 'call_ok', 'tool_name' => 'demo_tool', 'content' => '{"ok":true}'],
                ],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.saved', 3); // user + assistant + 仅一条合法 tool

        $toolMessages = AgentConversationMessage::where('conversation_id', $this->conversation->conversation_id)
            ->where('role', 'tool')
            ->get();

        $this->assertCount(1, $toolMessages, '无 tool_call_id 的结果无法配对，不得落库污染历史');
        $this->assertSame('call_ok', $toolMessages->first()->tool_call_id);
    }

    public function test_message_report_backward_compatible_without_tool_results(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/messages/report', [
                'conversation_id' => $this->conversation->conversation_id,
                'user_message' => '你好',
                'assistant_message' => '你好，有什么可以帮你？',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.saved', 2);
    }

    // ========== 端到端：落库 → resolve 下发 history ==========

    public function test_resolve_delivers_history_with_previous_tool_result(): void
    {
        $this->mockUsageServiceOk();

        $registryMock = Mockery::mock(ToolRegistry::class);
        $registryMock->shouldReceive('get')->andReturn(null);
        $registryMock->shouldReceive('getToolDefinitions')->andReturn([]);
        $this->app->instance(ToolRegistry::class, $registryMock);

        // 第一轮：工具结果落库（模拟 Node onFinish 上报，含真实长数字 plan_id）
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/messages/report', [
                'conversation_id' => $this->conversation->conversation_id,
                'agent_id' => 1001,
                'user_message' => '帮我做活动计划',
                'assistant_message' => '草稿已生成。',
                'tool_calls' => [['id' => 'call_abc123', 'name' => 'activity_plan_draft', 'arguments' => []]],
                'tool_results' => [[
                    'tool_call_id' => 'call_abc123',
                    'tool_name' => 'activity_plan_draft',
                    'content' => '{"plan_id":' . self::REAL_PLAN_ID . ',"status":"drafted"}',
                ]],
            ])
            ->assertStatus(200);

        // 第二轮：resolve 续接会话，history 必须携带上一轮工具结果
        $history = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/resolve', [
                'agent_id' => 1001,
                'conversation_id' => $this->conversation->conversation_id,
            ])
            ->assertStatus(200)
            ->json('data.history');

        $this->assertIsArray($history);
        $this->assertNotEmpty($history);

        $toolMessages = array_values(array_filter($history, fn ($m) => ($m['role'] ?? '') === 'tool'));
        $this->assertCount(1, $toolMessages);
        $this->assertSame('call_abc123', $toolMessages[0]['tool_call_id']);
        $this->assertStringContainsString((string) self::REAL_PLAN_ID, $toolMessages[0]['content']);

        // assistant.tool_calls 与 tool 消息成对（协议合法，可直接进 LLM）
        $assistantWithCalls = array_values(array_filter($history, fn ($m) => ($m['role'] ?? '') === 'assistant' && ! empty($m['tool_calls'])));
        $this->assertCount(1, $assistantWithCalls);
        $this->assertSame('call_abc123', $assistantWithCalls[0]['tool_calls'][0]['id']);
    }

    public function test_resolve_omits_history_when_server_history_disabled(): void
    {
        config(['ai-streaming.server_history' => false]);
        $this->mockUsageServiceOk();

        $registryMock = Mockery::mock(ToolRegistry::class);
        $registryMock->shouldReceive('get')->andReturn(null);
        $registryMock->shouldReceive('getToolDefinitions')->andReturn([]);
        $this->app->instance(ToolRegistry::class, $registryMock);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/resolve', ['agent_id' => 1001])
            ->assertStatus(200)
            ->assertJsonMissingPath('data.history');
    }
}
