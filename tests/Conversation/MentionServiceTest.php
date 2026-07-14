<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\Conversation;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\PermissionDeniedException;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\Conversation\Models\Mention;
use MultiTenantSaas\Modules\Conversation\Models\Message;
use MultiTenantSaas\Modules\Conversation\Services\MentionService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\ChannelModule;
use MultiTenantSaas\Tests\TestCase;

/**
 * IMPL-004 MentionService 单元测试
 *
 * 覆盖：批量创建提及、从内容解析提及、去重、查询、标记已通知。
 */
class MentionServiceTest extends TestCase
{
    protected array $uses = [ChannelModule::class];

    private MentionService $service;

    private int $tenantId = 1001;

    private int $senderId = 2001;

    private int $userA = 2002;

    private int $userB = 2003;

    private string $messageId;

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

        /** @var Message $message */
        $message = Message::create([
            'tenant_id' => $this->tenantId,
            'conversation_id' => $conversation->conversation_id,
            'sender_id' => $this->senderId,
            'sender_type' => 'user',
            'content' => 'hello',
            'type' => 'text',
        ]);

        $this->messageId = (string) $message->message_id;
        $this->conversationId = (string) $conversation->conversation_id;
        $this->service = app(MentionService::class);
    }

    public function test_create_mentions_creates_records_for_valid_users(): void
    {
        $count = $this->service->createMentions($this->tenantId, $this->messageId, [$this->userA, $this->userB]);

        $this->assertSame(2, $count);
        $this->assertDatabaseHas('mentions', [
            'message_id' => $this->messageId,
            'user_id' => $this->userA,
            'tenant_id' => $this->tenantId,
            'is_notified' => 0,
        ]);
        $this->assertDatabaseHas('mentions', [
            'message_id' => $this->messageId,
            'user_id' => $this->userB,
        ]);
    }

    public function test_create_mentions_skips_nonexistent_users(): void
    {
        $count = $this->service->createMentions($this->tenantId, $this->messageId, [$this->userA, 99999]);

        $this->assertSame(1, $count);
        $this->assertDatabaseMissing('mentions', ['message_id' => $this->messageId, 'user_id' => 99999]);
    }

    public function test_create_mentions_is_idempotent(): void
    {
        $first = $this->service->createMentions($this->tenantId, $this->messageId, [$this->userA, $this->userB]);
        $second = $this->service->createMentions($this->tenantId, $this->messageId, [$this->userA, $this->userB]);

        $this->assertSame(2, $first);
        $this->assertSame(0, $second);
        $this->assertSame(2, Mention::where('message_id', $this->messageId)->count());
    }

    public function test_create_mentions_from_content_parses_numeric_ids(): void
    {
        $count = $this->service->createMentionsFromContent(
            $this->tenantId,
            $this->messageId,
            $this->senderId,
            "hey @{$this->userA} please review @{$this->userB}"
        );

        $this->assertSame(2, $count);
    }

    public function test_create_mentions_from_content_excludes_sender(): void
    {
        $count = $this->service->createMentionsFromContent(
            $this->tenantId,
            $this->messageId,
            $this->senderId,
            "self mention @{$this->senderId} and @{$this->userA}"
        );

        $this->assertSame(1, $count);
        $this->assertDatabaseMissing('mentions', ['message_id' => $this->messageId, 'user_id' => $this->senderId]);
    }

    public function test_create_mentions_from_content_handles_null_and_empty_content(): void
    {
        $this->assertSame(0, $this->service->createMentionsFromContent($this->tenantId, $this->messageId, $this->senderId, null));
        $this->assertSame(0, $this->service->createMentionsFromContent($this->tenantId, $this->messageId, $this->senderId, ''));
        $this->assertSame(0, $this->service->createMentionsFromContent($this->tenantId, $this->messageId, $this->senderId, 'no mentions here'));
    }

    public function test_get_mentions_for_message(): void
    {
        $this->service->createMentions($this->tenantId, $this->messageId, [$this->userA, $this->userB]);

        $mentions = $this->service->getMentionsForMessage($this->tenantId, $this->messageId);

        $this->assertCount(2, $mentions);
        $this->assertTrue($mentions->contains('user_id', $this->userA));
        $this->assertTrue($mentions->contains('user_id', $this->userB));
    }

    public function test_get_mentions_for_user(): void
    {
        $this->service->createMentions($this->tenantId, $this->messageId, [$this->userA, $this->userB]);

        $mentions = $this->service->getMentionsForUser($this->tenantId, $this->userA);

        $this->assertCount(1, $mentions);
        $this->assertSame($this->userA, $mentions->first()->user_id);
    }

    public function test_mark_notified_single(): void
    {
        $this->service->createMentions($this->tenantId, $this->messageId, [$this->userA]);

        $result = $this->service->markNotified($this->tenantId, $this->messageId, $this->userA);

        $this->assertTrue($result);
        $mention = Mention::where('message_id', $this->messageId)->where('user_id', $this->userA)->first();
        $this->assertNotNull($mention);
        $this->assertTrue((bool) $mention->is_notified);
    }

    public function test_mark_all_notified_for_user(): void
    {
        // 在两条不同消息上提及同一用户
        $this->service->createMentions($this->tenantId, $this->messageId, [$this->userA]);

        /** @var Message $message2 */
        $message2 = Message::create([
            'tenant_id' => $this->tenantId,
            'conversation_id' => $this->conversationId,
            'sender_id' => $this->senderId,
            'sender_type' => 'user',
            'content' => 'second',
            'type' => 'text',
        ]);
        $this->service->createMentions($this->tenantId, (string) $message2->message_id, [$this->userA]);

        $affected = $this->service->markAllNotifiedForUser($this->tenantId, $this->userA);

        $this->assertSame(2, $affected);
        $this->assertSame(0, Mention::where('user_id', $this->userA)->where('is_notified', false)->count());
    }

    public function test_cross_tenant_access_is_blocked_by_context_guard(): void
    {
        // setUp 已将上下文设为当前已认证租户（1001）
        $this->expectException(PermissionDeniedException::class);

        // 以另一个租户ID调用应被守卫拦截（fail-closed），不触达任何跨租户数据
        $this->service->createMentions(99999, $this->messageId, [$this->userA]);
    }
}
