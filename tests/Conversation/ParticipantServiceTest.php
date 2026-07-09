<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\Conversation;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Conversation;
use MultiTenantSaas\Models\Participant;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Modules\Conversation\Services\ParticipantService;
use MultiTenantSaas\Tests\TestCase;
use MultiTenantSaas\Tests\Schema\ChannelModule;

class ParticipantServiceTest extends TestCase
{
    protected array $uses = [ChannelModule::class];

    private ParticipantService $service;
    private int $tenantId = 1001;
    private int $userA = 2001;
    private int $userB = 2002;
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
            User::create(['user_id' => $this->userA, 'name' => 'Alice', 'email' => 'alice@example.com', 'password' => bcrypt('secret')]);
            User::create(['user_id' => $this->userB, 'name' => 'Bob', 'email' => 'bob@example.com', 'password' => bcrypt('secret')]);
        });

        TenantContext::setTenantId((string) $this->tenantId);

        $conversation = Conversation::create([
            'tenant_id' => $this->tenantId,
            'type' => 'support',
            'status' => 'active',
            'message_count' => 0,
        ]);
        $this->conversationId = (string) $conversation->conversation_id;

        $this->service = $this->app->make(ParticipantService::class);
    }

    public function test_add_participant(): void
    {
        $participant = $this->service->addParticipant($this->tenantId, $this->conversationId, $this->userA);

        $this->assertNotNull($participant->participant_id);
        $this->assertSame($this->conversationId, $participant->conversation_id);
        $this->assertSame($this->userA, $participant->user_id);
        $this->assertSame('member', $participant->role);
    }

    public function test_add_participant_with_role(): void
    {
        $participant = $this->service->addParticipant($this->tenantId, $this->conversationId, $this->userA, 'admin');

        $this->assertSame('admin', $participant->role);
    }

    public function test_remove_participant(): void
    {
        $this->service->addParticipant($this->tenantId, $this->conversationId, $this->userA);

        $result = $this->service->removeParticipant($this->tenantId, $this->conversationId, $this->userA);

        $this->assertTrue($result);

        $count = Participant::where('conversation_id', $this->conversationId)->count();
        $this->assertSame(0, $count);
    }

    public function test_remove_nonexistent_participant_returns_false(): void
    {
        $result = $this->service->removeParticipant($this->tenantId, $this->conversationId, 9999);

        $this->assertFalse($result);
    }

    public function test_list_participants(): void
    {
        $this->service->addParticipant($this->tenantId, $this->conversationId, $this->userA);
        $this->service->addParticipant($this->tenantId, $this->conversationId, $this->userB);

        $participants = $this->service->listParticipants($this->tenantId, $this->conversationId);

        $this->assertCount(2, $participants);
    }

    public function test_list_participants_empty(): void
    {
        $participants = $this->service->listParticipants($this->tenantId, $this->conversationId);

        $this->assertCount(0, $participants);
    }
}
