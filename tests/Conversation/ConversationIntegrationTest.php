<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\Conversation;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Mention;
use MultiTenantSaas\Models\Message;
use MultiTenantSaas\Models\ReadState;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\Conversation\ConversationService;
use MultiTenantSaas\Services\Conversation\MentionService;
use MultiTenantSaas\Services\Conversation\ReadStateService;
use MultiTenantSaas\Services\Conversation\SessionService;
use MultiTenantSaas\Services\Conversation\TagService;
use MultiTenantSaas\Tests\TestCase;
use MultiTenantSaas\Tests\Schema\ChannelModule;

/**
 * IMPL-004 集成测试：验证 Mention / ReadState / Session / Tag 四个服务
 * 与 ConversationService（共享会话与参与者数据）的交互。
 *
 * 所有服务经服务容器解析，覆盖 TenancyServiceProvider 的绑定。
 */
class ConversationIntegrationTest extends TestCase
{
    protected array $uses = [ChannelModule::class];

    private int $tenantId = 1001;

    private int $senderId = 2001;

    private int $userA = 2002;

    private int $userB = 2003;

    private ConversationService $conversationService;

    private MentionService $mentionService;

    private ReadStateService $readStateService;

    private SessionService $sessionService;

    private TagService $tagService;

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

        $this->conversationService = app(ConversationService::class);
        $this->mentionService = app(MentionService::class);
        $this->readStateService = app(ReadStateService::class);
        $this->sessionService = app(SessionService::class);
        $this->tagService = app(TagService::class);
    }

    public function test_full_conversation_flow_across_services(): void
    {
        // 1) ConversationService 创建会话与参与者
        $conversation = $this->conversationService->createConversation(
            $this->tenantId,
            'group',
            [$this->senderId, $this->userA, $this->userB]
        );
        $conversationId = (string) $conversation->conversation_id;

        // 2) SessionService：发送者接入会话
        $session = $this->sessionService->connect($this->tenantId, $conversationId, $this->senderId);
        $this->assertSame(SessionService::STATUS_ACTIVE, $session->status);
        $this->assertCount(1, $this->sessionService->getActiveSessions($this->tenantId, $conversationId));

        // 3) 发送一条含 @userA 提及的消息（直接经模型写入，避免 MessageService 事件分发缺陷）
        /** @var Message $message */
        $message = Message::create([
            'tenant_id' => $this->tenantId,
            'conversation_id' => $conversationId,
            'sender_id' => $this->senderId,
            'sender_type' => 'user',
            'content' => "please review @{$this->userA}",
            'type' => 'text',
        ]);
        $messageId = (string) $message->message_id;

        // 4) MentionService：从内容解析并创建提及
        $mentionCount = $this->mentionService->createMentionsFromContent(
            $this->tenantId,
            $messageId,
            $this->senderId,
            $message->content
        );
        $this->assertSame(1, $mentionCount);
        $this->assertTrue(
            $this->mentionService->getMentionsForMessage($this->tenantId, $messageId)
                ->contains('user_id', $this->userA)
        );

        // 5) ReadStateService：为其余参与者递增未读
        $affected = $this->readStateService->incrementUnreadForOthers(
            $this->tenantId,
            $conversationId,
            $this->senderId
        );
        $this->assertSame(2, $affected);
        $this->assertSame(1, $this->readStateService->getUnreadCount($this->tenantId, $conversationId, $this->userA));
        $this->assertSame(1, $this->readStateService->getUnreadCount($this->tenantId, $conversationId, $this->userB));
        $this->assertSame(0, $this->readStateService->getUnreadCount($this->tenantId, $conversationId, $this->senderId));

        // 6) userA 阅读后未读归零
        $this->readStateService->markRead($this->tenantId, $conversationId, $this->userA, $messageId);
        $this->assertSame(0, $this->readStateService->getUnreadCount($this->tenantId, $conversationId, $this->userA));

        // 7) MentionService：标记已通知
        $this->mentionService->markAllNotifiedForUser($this->tenantId, $this->userA);
        $this->assertSame(0, Mention::where('user_id', $this->userA)->where('is_notified', false)->count());

        // 8) TagService：同步标签并按标签检索
        $tags = $this->tagService->syncTags($this->tenantId, $conversationId, ['vip', 'follow-up']);
        $this->assertSame(['follow-up', 'vip'], $tags);

        $found = $this->tagService->findConversationsByTag($this->tenantId, 'vip');
        $this->assertCount(1, $found);
        $this->assertSame((int) $conversationId, (int) $found->first()->conversation_id);

        // 9) SessionService：发送者断开
        $this->sessionService->disconnect($this->tenantId, (string) $session->session_id);
        $this->assertCount(0, $this->sessionService->getActiveSessions($this->tenantId, $conversationId));

        // 10) ReadStateService：会话归档时重置未读
        $this->readStateService->resetUnreadForConversation($this->tenantId, $conversationId);
        $this->assertSame(0, ReadState::where('conversation_id', $conversationId)->sum('unread_count'));
    }
}
