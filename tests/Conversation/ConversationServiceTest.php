<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\Conversation;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Conversation;
use MultiTenantSaas\Models\Participant;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\Conversation\ConversationService;
use MultiTenantSaas\Tests\TestCase;

class ConversationServiceTest extends TestCase
{
    private ConversationService $service;
    private int $tenantId = 1001;
    private int $userA = 2001;
    private int $userB = 2002;

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
            User::create(['user_id' => $this->userA, 'name' => 'Alice', 'email' => 'alice@example.com', 'password' => bcrypt('secret')]);
            User::create(['user_id' => $this->userB, 'name' => 'Bob', 'email' => 'bob@example.com', 'password' => bcrypt('secret')]);
        });

        TenantContext::setTenantId((string) $this->tenantId);

        $this->service = $this->app->make(ConversationService::class);
    }

    public function test_create_conversation(): void
    {
        $conversation = $this->service->createConversation($this->tenantId, 'support', [$this->userA, $this->userB]);

        $this->assertNotNull($conversation->conversation_id);
        $this->assertSame('support', $conversation->type);
        $this->assertSame('active', $conversation->status);
        $this->assertSame(0, $conversation->message_count);

        $participants = Participant::where('conversation_id', $conversation->conversation_id)->count();
        $this->assertSame(2, $participants);
    }

    public function test_get_conversation(): void
    {
        $created = $this->service->createConversation($this->tenantId, 'support', [$this->userA]);

        $found = $this->service->getConversation($this->tenantId, (string) $created->conversation_id);

        $this->assertSame($created->conversation_id, $found->conversation_id);
    }

    public function test_get_conversation_not_found_throws(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->service->getConversation($this->tenantId, '99999999');
    }

    public function test_list_conversations(): void
    {
        $this->service->createConversation($this->tenantId, 'support', [$this->userA]);
        $this->service->createConversation($this->tenantId, 'group', [$this->userB]);

        $result = $this->service->listConversations($this->tenantId, []);

        $this->assertSame(2, $result->total());
    }

    public function test_list_conversations_with_type_filter(): void
    {
        $this->service->createConversation($this->tenantId, 'support', [$this->userA]);
        $this->service->createConversation($this->tenantId, 'group', [$this->userB]);

        $result = $this->service->listConversations($this->tenantId, ['type' => 'support']);

        $this->assertSame(1, $result->total());
    }

    public function test_list_conversations_with_status_filter(): void
    {
        $conv = $this->service->createConversation($this->tenantId, 'support', [$this->userA]);
        $this->service->createConversation($this->tenantId, 'group', [$this->userB]);

        Conversation::where('conversation_id', $conv->conversation_id)->update(['status' => 'archived']);

        $result = $this->service->listConversations($this->tenantId, ['status' => 'active']);

        $this->assertSame(1, $result->total());
    }

    public function test_delete_conversation_archives(): void
    {
        $conv = $this->service->createConversation($this->tenantId, 'support', [$this->userA]);

        $result = $this->service->deleteConversation($this->tenantId, (string) $conv->conversation_id);

        $this->assertTrue($result);

        $updated = Conversation::where('conversation_id', $conv->conversation_id)->first();
        $this->assertSame('archived', $updated->status);
    }

    public function test_get_recent_conversations(): void
    {
        $this->service->createConversation($this->tenantId, 'support', [$this->userA]);
        $this->service->createConversation($this->tenantId, 'group', [$this->userA, $this->userB]);

        $recent = $this->service->getRecentConversations($this->tenantId, $this->userA);

        $this->assertCount(2, $recent);
    }

    public function test_get_recent_conversations_only_active(): void
    {
        $conv = $this->service->createConversation($this->tenantId, 'support', [$this->userA]);
        $this->service->createConversation($this->tenantId, 'group', [$this->userA]);

        Conversation::where('conversation_id', $conv->conversation_id)->update(['status' => 'archived']);

        $recent = $this->service->getRecentConversations($this->tenantId, $this->userA);

        $this->assertCount(1, $recent);
    }
}
