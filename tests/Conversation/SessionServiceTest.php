<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\Conversation;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Conversation;
use MultiTenantSaas\Models\ConversationSession;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Modules\Conversation\Services\SessionService;
use MultiTenantSaas\Tests\Schema\ChannelModule;
use MultiTenantSaas\Tests\TestCase;

/**
 * IMPL-004 会话会话服务（SessionService）单元测试
 *
 * 覆盖：连接、断开、活跃心跳、活跃列表、空闲清理。
 * 注意：此服务操作 conversation_sessions 表，与登录会话服务（user_sessions）不同。
 */
class SessionServiceTest extends TestCase
{
    protected array $uses = [ChannelModule::class];

    private SessionService $service;

    private int $tenantId = 1001;

    private int $userId = 2001;

    private int $otherUserId = 2002;

    private string $conversationId;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        User::unguarded(function () {
            User::create(['user_id' => $this->userId, 'name' => 'Alice', 'email' => 'alice@example.com', 'password' => bcrypt('secret')]);
            User::create(['user_id' => $this->otherUserId, 'name' => 'Bob', 'email' => 'bob@example.com', 'password' => bcrypt('secret')]);
        });

        TenantContext::setTenantId((string) $this->tenantId);

        /** @var Conversation $conversation */
        $conversation = Conversation::create([
            'tenant_id' => $this->tenantId,
            'type' => 'group',
            'status' => 'active',
            'message_count' => 0,
        ]);
        $this->conversationId = (string) $conversation->conversation_id;

        $this->service = app(SessionService::class);
    }

    public function test_connect_creates_active_session(): void
    {
        $session = $this->service->connect($this->tenantId, $this->conversationId, $this->userId);

        $this->assertSame(SessionService::STATUS_ACTIVE, $session->status);
        $this->assertSame($this->userId, $session->user_id);
        $this->assertNotNull($session->connected_at);
        $this->assertNotNull($session->last_active_at);
        $this->assertDatabaseHas('conversation_sessions', [
            'conversation_id' => $this->conversationId,
            'user_id' => $this->userId,
            'status' => SessionService::STATUS_ACTIVE,
        ]);
    }

    public function test_connect_returns_existing_active_session(): void
    {
        $first = $this->service->connect($this->tenantId, $this->conversationId, $this->userId);
        $second = $this->service->connect($this->tenantId, $this->conversationId, $this->userId);

        $this->assertSame((int) $first->session_id, (int) $second->session_id);
        $this->assertSame(1, ConversationSession::where('conversation_id', $this->conversationId)
            ->where('user_id', $this->userId)
            ->where('status', SessionService::STATUS_ACTIVE)
            ->count());
    }

    public function test_disconnect_sets_disconnected(): void
    {
        $session = $this->service->connect($this->tenantId, $this->conversationId, $this->userId);

        $result = $this->service->disconnect($this->tenantId, (string) $session->session_id);

        $this->assertTrue($result);
        $this->assertSame(SessionService::STATUS_DISCONNECTED, $session->fresh()->status);
    }

    public function test_update_activity_refreshes_last_active(): void
    {
        $session = $this->service->connect($this->tenantId, $this->conversationId, $this->userId);

        // 回退活跃时间
        ConversationSession::where('session_id', $session->session_id)
            ->update(['last_active_at' => now()->subMinutes(10)]);
        $stale = $session->fresh()->last_active_at;

        $result = $this->service->updateActivity($this->tenantId, (string) $session->session_id);

        $this->assertTrue($result);
        $refreshed = $session->fresh();
        $this->assertNotNull($refreshed);
        $this->assertNotNull($stale);
        $this->assertGreaterThan($stale, $refreshed->last_active_at);
    }

    public function test_get_active_sessions(): void
    {
        $this->service->connect($this->tenantId, $this->conversationId, $this->userId);
        $this->service->connect($this->tenantId, $this->conversationId, $this->otherUserId);

        $sessions = $this->service->getActiveSessions($this->tenantId, $this->conversationId);

        $this->assertCount(2, $sessions);
    }

    public function test_get_user_session_returns_null_when_absent(): void
    {
        $this->assertNull($this->service->getUserSession($this->tenantId, $this->conversationId, $this->userId));
    }

    public function test_mark_idle_sessions_marks_stale(): void
    {
        $session = $this->service->connect($this->tenantId, $this->conversationId, $this->userId);

        // 回退活跃时间超过空闲阈值
        ConversationSession::where('session_id', $session->session_id)
            ->update(['last_active_at' => now()->subMinutes(10)]);

        $affected = $this->service->markIdleSessions($this->tenantId, $this->conversationId, 5);

        $this->assertSame(1, $affected);
        $this->assertCount(0, $this->service->getActiveSessions($this->tenantId, $this->conversationId));
        $this->assertSame(SessionService::STATUS_IDLE, $session->fresh()->status);
    }

    public function test_mark_idle_sessions_keeps_recent(): void
    {
        $this->service->connect($this->tenantId, $this->conversationId, $this->userId);

        $affected = $this->service->markIdleSessions($this->tenantId, $this->conversationId, 5);

        $this->assertSame(0, $affected);
        $this->assertCount(1, $this->service->getActiveSessions($this->tenantId, $this->conversationId));
    }
}
