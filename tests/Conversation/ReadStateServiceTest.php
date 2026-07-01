<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\Conversation;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Models\Conversation;
use MultiTenantSaas\Models\Participant;
use MultiTenantSaas\Models\ReadState;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\Conversation\ReadStateService;
use MultiTenantSaas\Tests\TestCase;

/**
 * IMPL-004 ReadStateService 单元测试
 *
 * 覆盖：标记已读、未读数查询、原子递增未读、批量重置。
 */
class ReadStateServiceTest extends TestCase
{
    private ReadStateService $service;

    private int $tenantId = 1001;

    private int $senderId = 2001;

    private int $userA = 2002;

    private int $userB = 2003;

    private string $conversationId;

    private string $messageId;

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
            User::create(['user_id' => $this->senderId, 'name' => 'Sender', 'email' => 'sender@example.com', 'password' => bcrypt('secret')]);
            User::create(['user_id' => $this->userA, 'name' => 'Alice', 'email' => 'alice@example.com', 'password' => bcrypt('secret')]);
            User::create(['user_id' => $this->userB, 'name' => 'Bob', 'email' => 'bob@example.com', 'password' => bcrypt('secret')]);
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

        foreach ([$this->senderId, $this->userA, $this->userB] as $userId) {
            Participant::create([
                'tenant_id' => $this->tenantId,
                'conversation_id' => $this->conversationId,
                'user_id' => $userId,
                'role' => 'member',
                'joined_at' => now(),
            ]);
        }

        // 用一个 16 位数字 message_id 作为已读位置占位
        $this->messageId = (string) app(IdGeneratorContract::class)->generate();

        $this->service = app(ReadStateService::class);
    }

    public function test_mark_read_creates_state_and_resets_unread(): void
    {
        // 预置一条未读数 = 3 的状态
        ReadState::create([
            'tenant_id' => $this->tenantId,
            'conversation_id' => $this->conversationId,
            'user_id' => $this->userA,
            'unread_count' => 3,
        ]);

        $state = $this->service->markRead($this->tenantId, $this->conversationId, $this->userA, $this->messageId);

        $this->assertSame(0, $state->unread_count);
        $this->assertSame($this->messageId, (string) $state->last_read_message_id);
        $this->assertNotNull($state->last_read_at);
    }

    public function test_mark_read_updates_existing_state(): void
    {
        $first = $this->service->markRead($this->tenantId, $this->conversationId, $this->userA, '1000000000000001');
        $second = $this->service->markRead($this->tenantId, $this->conversationId, $this->userA, $this->messageId);

        $this->assertSame((int) $first->read_state_id, (int) $second->read_state_id);
        $this->assertSame($this->messageId, (string) $second->last_read_message_id);
    }

    public function test_get_unread_count_returns_zero_without_state(): void
    {
        $this->assertSame(0, $this->service->getUnreadCount($this->tenantId, $this->conversationId, $this->userA));
    }

    public function test_increment_unread_for_others_increments_participants(): void
    {
        $affected = $this->service->incrementUnreadForOthers($this->tenantId, $this->conversationId, $this->senderId);

        $this->assertSame(2, $affected);
        $this->assertSame(1, $this->service->getUnreadCount($this->tenantId, $this->conversationId, $this->userA));
        $this->assertSame(1, $this->service->getUnreadCount($this->tenantId, $this->conversationId, $this->userB));
        $this->assertSame(0, $this->service->getUnreadCount($this->tenantId, $this->conversationId, $this->senderId));
    }

    public function test_increment_unread_for_others_skips_sender(): void
    {
        $this->service->incrementUnreadForOthers($this->tenantId, $this->conversationId, $this->senderId);
        $this->service->incrementUnreadForOthers($this->tenantId, $this->conversationId, $this->senderId);

        // sender 始终为 0
        $this->assertSame(0, $this->service->getUnreadCount($this->tenantId, $this->conversationId, $this->senderId));
        // 其余参与者累加为 2
        $this->assertSame(2, $this->service->getUnreadCount($this->tenantId, $this->conversationId, $this->userA));
    }

    public function test_increment_unread_for_others_creates_missing_state_rows(): void
    {
        // 预置 userA 已有状态（应被原子递增）
        ReadState::create([
            'tenant_id' => $this->tenantId,
            'conversation_id' => $this->conversationId,
            'user_id' => $this->userA,
            'unread_count' => 5,
        ]);

        $this->service->incrementUnreadForOthers($this->tenantId, $this->conversationId, $this->senderId);

        $this->assertSame(6, $this->service->getUnreadCount($this->tenantId, $this->conversationId, $this->userA));
        $this->assertSame(1, $this->service->getUnreadCount($this->tenantId, $this->conversationId, $this->userB));
    }

    public function test_get_unread_counts_for_user(): void
    {
        $this->service->incrementUnreadForOthers($this->tenantId, $this->conversationId, $this->senderId);

        $list = $this->service->getUnreadCountsForUser($this->tenantId, $this->userA);

        $this->assertCount(1, $list);
        $this->assertSame($this->conversationId, (string) $list->first()->conversation_id);
    }

    public function test_reset_unread_for_conversation(): void
    {
        $this->service->incrementUnreadForOthers($this->tenantId, $this->conversationId, $this->senderId);

        $affected = $this->service->resetUnreadForConversation($this->tenantId, $this->conversationId);

        $this->assertSame(2, $affected);
        $this->assertSame(0, ReadState::where('conversation_id', $this->conversationId)->sum('unread_count'));
    }
}
