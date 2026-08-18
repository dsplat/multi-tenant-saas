<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Contracts\Http\Kernel;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Models\AgentConversationMessage;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\IdentifyTenant;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\RbacModule;

/**
 * AI 小助手历史会话列表 / 删除（多会话管理）测试
 *
 * 覆盖：
 *  - GET /api/v1/ai/assistant/conversations 分页倒序、仅 assistant 通道、租户隔离
 *  - DELETE /api/v1/ai/assistant/conversations/{id} 连同消息删除
 *  - 他租户 / 非 assistant 通道会话不可删（404）
 */
class AssistantConversationsTest extends TestCase
{
    protected array $uses = [AgentModule::class, RbacModule::class];

    protected Operator $operator;

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

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);
        $user = User::create(['name' => 'Test', 'email' => 'test@test.com', 'password' => bcrypt('password')]);

        $tenantAdminRoleId = \DB::table('roles')
            ->where('name', 'tenant_admin')
            ->whereNull('tenant_id')
            ->value('role_id');

        $this->operator = Operator::create([
            'email' => $user->email,
            'name' => $user->name,
            'scope' => 'tenant',
            'is_active' => true,
        ]);

        OperatorTenant::create([
            'operator_id' => $this->operator->operator_id,
            'tenant_id' => 1001,
            'user_id' => $user->user_id,
            'role' => 'tenant_admin',
            'role_id' => $tenantAdminRoleId,
            'is_active' => true,
            'accepted_at' => now(),
        ]);

        Agent::forceCreate([
            'agent_id' => 1001,
            'tenant_id' => 1001,
            'name' => '系统小秘书',
            'role' => 'system_secretary',
            'system_prompt' => 'You are the secretary.',
            'model_config' => [],
            'enabled' => true,
        ]);
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

    protected function createConversation(int $tenantId = 1001, string $channel = 'assistant', ?string $subject = null, ?\DateTimeInterface $updatedAt = null): AgentConversation
    {
        // updated_at 直接随 forceCreate 写入：测试上下文无租户（TenantContext::getId()=null），
        // TenantScope fail-closed（WHERE 1=0）会拦掉后续 Eloquent where()->update()，
        // 旧写法两条记录 updated_at 相同导致排序断言 flaky
        $attributes = [
            'conversation_id' => random_int(1000000000000000, 9007199254740991),
            'agent_id' => 1001,
            'tenant_id' => $tenantId,
            'channel' => $channel,
            'subject' => $subject,
            'status' => 'active',
            'message_count' => 0,
        ];
        if ($updatedAt) {
            $attributes['updated_at'] = $updatedAt;
        }

        return AgentConversation::forceCreate($attributes);
    }

    // ========== 列表 ==========

    public function test_conversations_listed_by_recent_activity(): void
    {
        $old = $this->createConversation(1001, 'assistant', '上周的会话', now()->subDays(7));
        $latest = $this->createConversation(1001, 'assistant', '刚聊的会话', now()->subMinute());

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/ai/assistant/conversations');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.conversations')
            ->assertJsonPath('data.conversations.0.conversation_id', $latest->conversation_id)
            ->assertJsonPath('data.conversations.0.subject', '刚聊的会话')
            ->assertJsonPath('data.conversations.1.conversation_id', $old->conversation_id)
            ->assertJsonPath('data.meta.total', 2);
    }

    public function test_conversations_exclude_other_channels_and_tenants(): void
    {
        $this->createConversation(1001, 'assistant', '小助手会话');
        // 数字员工业务会话（非 assistant 通道）→ 不在列表
        $this->createConversation(1001, 'web', '业务会话');
        // 他租户会话（租户隔离铁律）→ 不在列表
        $this->createConversation(1002, 'assistant', '他租户会话');

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/ai/assistant/conversations');

        $response->assertOk()
            ->assertJsonCount(1, 'data.conversations')
            ->assertJsonPath('data.conversations.0.subject', '小助手会话');
    }

    public function test_conversations_paginated(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->createConversation(1001, 'assistant', "会话 {$i}", now()->subMinutes($i));
        }

        $page1 = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/ai/assistant/conversations?page=1&per_page=20');

        $page1->assertOk()
            ->assertJsonCount(20, 'data.conversations')
            ->assertJsonPath('data.meta.current_page', 1)
            ->assertJsonPath('data.meta.last_page', 2)
            ->assertJsonPath('data.meta.total', 25);

        $page2 = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/ai/assistant/conversations?page=2&per_page=20');

        $page2->assertOk()->assertJsonCount(5, 'data.conversations');
    }

    // ========== 删除 ==========

    public function test_delete_conversation_removes_messages(): void
    {
        $conversation = $this->createConversation();

        AgentConversationMessage::forceCreate([
            'conversation_id' => $conversation->conversation_id,
            'role' => 'user',
            'content' => '你好',
        ]);

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/v1/ai/assistant/conversations/{$conversation->conversation_id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('agent_conversations', ['conversation_id' => $conversation->conversation_id]);
        $this->assertDatabaseMissing('agent_conversation_messages', ['conversation_id' => $conversation->conversation_id]);
    }

    public function test_delete_rejects_other_tenant_conversation(): void
    {
        $conversation = $this->createConversation(1002);

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/v1/ai/assistant/conversations/{$conversation->conversation_id}")
            ->assertNotFound();

        $this->assertDatabaseHas('agent_conversations', ['conversation_id' => $conversation->conversation_id]);
    }

    public function test_delete_rejects_non_assistant_channel(): void
    {
        // 数字员工业务会话不允许走小助手删除口（防越权删除）
        $conversation = $this->createConversation(1001, 'web');

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/v1/ai/assistant/conversations/{$conversation->conversation_id}")
            ->assertNotFound();

        $this->assertDatabaseHas('agent_conversations', ['conversation_id' => $conversation->conversation_id]);
    }
}
