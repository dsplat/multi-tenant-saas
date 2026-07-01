<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\Conversation;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Conversation;
use MultiTenantSaas\Models\ConversationTag;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\Conversation\TagService;
use MultiTenantSaas\Tests\TestCase;

/**
 * IMPL-004 TagService 单元测试
 *
 * 覆盖：添加、移除、批量同步、去重、列表、按标签检索会话。
 */
class TagServiceTest extends TestCase
{
    private TagService $service;

    private int $tenantId = 1001;

    private string $conversationId;

    private string $otherConversationId;

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
            User::create(['user_id' => 2001, 'name' => 'Alice', 'email' => 'alice@example.com', 'password' => bcrypt('secret')]);
        });

        TenantContext::setTenantId((string) $this->tenantId);

        $this->conversationId = (string) Conversation::create([
            'tenant_id' => $this->tenantId,
            'type' => 'group',
            'status' => 'active',
            'message_count' => 0,
        ])->conversation_id;

        $this->otherConversationId = (string) Conversation::create([
            'tenant_id' => $this->tenantId,
            'type' => 'support',
            'status' => 'active',
            'message_count' => 0,
        ])->conversation_id;

        $this->service = app(TagService::class);
    }

    public function test_add_tag_creates_record(): void
    {
        $tag = $this->service->addTag($this->tenantId, $this->conversationId, 'urgent');

        $this->assertSame('urgent', $tag->tag);
        $this->assertDatabaseHas('conversation_tags', [
            'conversation_id' => $this->conversationId,
            'tenant_id' => $this->tenantId,
            'tag' => 'urgent',
        ]);
    }

    public function test_add_tag_is_idempotent(): void
    {
        $first = $this->service->addTag($this->tenantId, $this->conversationId, 'urgent');
        $second = $this->service->addTag($this->tenantId, $this->conversationId, 'urgent');

        $this->assertSame((int) $first->conversation_tag_id, (int) $second->conversation_tag_id);
        $this->assertSame(1, ConversationTag::where('conversation_id', $this->conversationId)->where('tag', 'urgent')->count());
    }

    public function test_add_tag_trims_and_rejects_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->addTag($this->tenantId, $this->conversationId, '   ');
    }

    public function test_remove_tag(): void
    {
        $this->service->addTag($this->tenantId, $this->conversationId, 'urgent');

        $result = $this->service->removeTag($this->tenantId, $this->conversationId, 'urgent');

        $this->assertTrue($result);
        $this->assertDatabaseMissing('conversation_tags', [
            'conversation_id' => $this->conversationId,
            'tag' => 'urgent',
        ]);
    }

    public function test_sync_tags_adds_and_removes(): void
    {
        $this->service->addTag($this->tenantId, $this->conversationId, 'old');
        $this->service->addTag($this->tenantId, $this->conversationId, 'keep');

        $result = $this->service->syncTags($this->tenantId, $this->conversationId, ['keep', 'new']);

        $this->assertSame(['keep', 'new'], $result);
        $this->assertDatabaseMissing('conversation_tags', ['conversation_id' => $this->conversationId, 'tag' => 'old']);
        $this->assertDatabaseHas('conversation_tags', ['conversation_id' => $this->conversationId, 'tag' => 'keep']);
        $this->assertDatabaseHas('conversation_tags', ['conversation_id' => $this->conversationId, 'tag' => 'new']);
    }

    public function test_sync_tags_dedupes_and_filters_empty(): void
    {
        $result = $this->service->syncTags($this->tenantId, $this->conversationId, ['a', 'a', '', '  ', 'b']);

        $this->assertSame(['a', 'b'], $result);
        $this->assertSame(2, ConversationTag::where('conversation_id', $this->conversationId)->count());
    }

    public function test_list_tags(): void
    {
        $this->service->syncTags($this->tenantId, $this->conversationId, ['zeta', 'alpha']);

        $tags = $this->service->listTags($this->tenantId, $this->conversationId);

        $this->assertSame(['alpha', 'zeta'], $tags->pluck('tag')->all());
    }

    public function test_find_conversations_by_tag(): void
    {
        $this->service->addTag($this->tenantId, $this->conversationId, 'urgent');
        $this->service->addTag($this->tenantId, $this->otherConversationId, 'urgent');

        $conversations = $this->service->findConversationsByTag($this->tenantId, 'urgent');

        $this->assertCount(2, $conversations);
        $this->assertTrue($conversations->contains('conversation_id', (int) $this->conversationId));
        $this->assertTrue($conversations->contains('conversation_id', (int) $this->otherConversationId));
    }

    public function test_find_conversations_by_tag_returns_empty_when_none(): void
    {
        $this->assertCount(0, $this->service->findConversationsByTag($this->tenantId, 'nonexistent'));
    }
}
