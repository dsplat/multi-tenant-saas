<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Contracts\Http\Kernel;
use Mockery;
use MultiTenantSaas\Contracts\AgentRuntimeContract;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Models\AgentConversationMessage;
use MultiTenantSaas\Modules\Ai\Services\Ai\StreamChunk;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\IdentifyTenant;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\RbacModule;

/**
 * AI 小助手会话历史（刷新恢复）测试
 *
 * 覆盖：
 *  - GET /api/v1/ai/assistant/history 返回正序历史消息
 *  - 过滤 tool 轮次与空内容的工具调用轮次
 *  - limit 截断（取最近 N 条）
 *  - 租户隔离（他租户会话 404）
 *  - SSE 首帧 meta 事件（conversation_id 下发，前端持久化续接）
 */
class AssistantHistoryTest extends TestCase
{
    protected array $uses = [AgentModule::class, RbacModule::class];

    protected Tenant $tenant;

    protected Tenant $otherTenant;

    protected User $user;

    protected Operator $operator;

    protected Agent $agent;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // 与生产一致：sanctum guard 不绑定 provider，Operator/User token 均可认证
        $app['config']->set('auth.guards.sanctum.provider', null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)
            ->prependMiddleware(IdentifyTenant::class);

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
            'name' => '系统小秘书',
            'role' => 'system_secretary',
            'system_prompt' => 'You are the secretary.',
            'model_config' => ['preferred_model' => 'gpt-4o-mini'],
            'enabled' => true,
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

    protected function createConversation(int $agentId = 1001, int $tenantId = 1001): AgentConversation
    {
        return AgentConversation::forceCreate([
            'conversation_id' => random_int(1000000000000000, 9007199254740991),
            'agent_id' => $agentId,
            'tenant_id' => $tenantId,
            'channel' => 'assistant',
            'status' => 'active',
            'message_count' => 0,
        ]);
    }

    // ========== history ==========

    public function test_history_returns_messages_in_chronological_order(): void
    {
        $conversation = $this->createConversation();

        AgentConversationMessage::forceCreate([
            'conversation_id' => $conversation->conversation_id,
            'role' => 'user',
            'content' => '你好',
            'created_at' => now()->subMinutes(2),
        ]);
        AgentConversationMessage::forceCreate([
            'conversation_id' => $conversation->conversation_id,
            'role' => 'assistant',
            'content' => '你好，我是小秘书。',
            'created_at' => now()->subMinute(),
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/ai/assistant/history?conversation_id={$conversation->conversation_id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.conversation_id', $conversation->conversation_id)
            ->assertJsonPath('data.agent_id', 1001)
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.0.role', 'user')
            ->assertJsonPath('data.messages.0.content', '你好')
            ->assertJsonPath('data.messages.1.role', 'assistant')
            ->assertJsonPath('data.messages.1.content', '你好，我是小秘书。');
    }

    public function test_history_filters_tool_rounds_and_empty_content(): void
    {
        $conversation = $this->createConversation();

        AgentConversationMessage::forceCreate([
            'conversation_id' => $conversation->conversation_id,
            'role' => 'user',
            'content' => '优惠券怎么用？',
            'created_at' => now()->subMinutes(3),
        ]);
        // 工具调用轮次（content 为空）→ 应被过滤
        AgentConversationMessage::forceCreate([
            'conversation_id' => $conversation->conversation_id,
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [['slug' => 'system_kb_search']],
            'created_at' => now()->subMinutes(2),
        ]);
        // tool 结果轮次 → 应被过滤
        AgentConversationMessage::forceCreate([
            'conversation_id' => $conversation->conversation_id,
            'role' => 'tool',
            'content' => '{"result":"..."}',
            'created_at' => now()->subMinutes(2),
        ]);
        AgentConversationMessage::forceCreate([
            'conversation_id' => $conversation->conversation_id,
            'role' => 'assistant',
            'content' => '优惠券的使用方式如下…',
            'created_at' => now()->subMinute(),
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/ai/assistant/history?conversation_id={$conversation->conversation_id}");

        $response->assertOk()
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.0.role', 'user')
            ->assertJsonPath('data.messages.1.content', '优惠券的使用方式如下…');
    }

    public function test_history_respects_limit_keeping_latest(): void
    {
        $conversation = $this->createConversation();

        for ($i = 1; $i <= 5; $i++) {
            AgentConversationMessage::forceCreate([
                'conversation_id' => $conversation->conversation_id,
                'role' => $i % 2 === 1 ? 'user' : 'assistant',
                'content' => "消息 {$i}",
                'created_at' => now()->subMinutes(10 - $i),
            ]);
        }

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/ai/assistant/history?conversation_id={$conversation->conversation_id}&limit=2");

        // 取最近 2 条，正序返回
        $response->assertOk()
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.0.content', '消息 4')
            ->assertJsonPath('data.messages.1.content', '消息 5');
    }

    public function test_history_returns_404_for_nonexistent_conversation(): void
    {
        $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/ai/assistant/history?conversation_id=9999999999999999')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_history_returns_404_for_other_tenant_conversation(): void
    {
        // 他租户会话（租户隔离铁律）
        $conversation = $this->createConversation(1001, 1002);

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/ai/assistant/history?conversation_id={$conversation->conversation_id}")
            ->assertNotFound();
    }

    public function test_history_requires_conversation_id(): void
    {
        $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/ai/assistant/history')
            ->assertStatus(422);
    }

    // ========== SSE meta 首帧 ==========

    public function test_stream_emits_meta_event_with_conversation_id_first(): void
    {
        $runtimeMock = Mockery::mock(AgentRuntimeContract::class);
        $runtimeMock->shouldReceive('runStream')->andReturn((function () {
            yield new StreamChunk(text: '你好');
            yield new StreamChunk(text: '', finishReason: 'stop');
        })());
        $this->app->instance(AgentRuntimeContract::class, $runtimeMock);

        $response = $this->withHeaders($this->authHeaders())
            ->post('/api/v1/ai/assistant', [
                'user_intent' => '你好',
                'route' => '/dashboard',
                'module' => 'Dashboard',
            ]);

        $response->assertOk();
        $content = $response->streamedContent();

        // 首帧必须是 meta 事件且携带 conversation_id
        $this->assertStringStartsWith('data: {"type":"meta"', $content);
        $this->assertStringContainsString('"conversation_id"', $content);

        // meta 中的 conversation_id 与落库会话一致
        preg_match('/"conversation_id":(\d+)/', $content, $m);
        $this->assertNotEmpty($m);
        $this->assertDatabaseHas('agent_conversations', [
            'conversation_id' => (int) $m[1],
            'tenant_id' => 1001,
            'agent_id' => 1001,
        ]);
    }

    public function test_stream_reuses_conversation_when_id_provided(): void
    {
        $conversation = $this->createConversation();

        $runtimeMock = Mockery::mock(AgentRuntimeContract::class);
        $runtimeMock->shouldReceive('runStream')
            ->with(1001, $conversation->conversation_id, Mockery::type('string'))
            ->andReturn((function () {
                yield new StreamChunk(text: 'ok', finishReason: 'stop');
            })());
        $this->app->instance(AgentRuntimeContract::class, $runtimeMock);

        $response = $this->withHeaders($this->authHeaders())
            ->post('/api/v1/ai/assistant', [
                'user_intent' => '继续',
                'conversation_id' => $conversation->conversation_id,
            ]);

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('"conversation_id":' . $conversation->conversation_id, $content);
        // 未新建会话
        $this->assertSame(1, AgentConversation::where('tenant_id', 1001)->count());
    }
}
