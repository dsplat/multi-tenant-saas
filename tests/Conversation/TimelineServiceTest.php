<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\Conversation;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Conversation;
use MultiTenantSaas\Models\Message;
use MultiTenantSaas\Models\ReadState;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\Conversation\TimelineService;
use MultiTenantSaas\Tests\TestCase;

class TimelineServiceTest extends TestCase
{
    private TimelineService $service;
    private int $tenantId = 1001;
    private int $senderId = 2001;
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
        });

        TenantContext::setTenantId((string) $this->tenantId);

        $conversation = Conversation::create([
            'tenant_id' => $this->tenantId,
            'type' => 'support',
            'status' => 'active',
            'message_count' => 0,
        ]);
        $this->conversationId = (string) $conversation->conversation_id;

        $this->service = $this->app->make(TimelineService::class);
    }

    private function createMessage(string $content): Message
    {
        return Message::create([
            'tenant_id' => $this->tenantId,
            'conversation_id' => $this->conversationId,
            'sender_id' => $this->senderId,
            'sender_type' => 'user',
            'content' => $content,
            'type' => 'text',
        ]);
    }

    public function test_get_timeline_empty(): void
    {
        $timeline = $this->service->getTimeline($this->tenantId, $this->conversationId);

        $this->assertCount(0, $timeline);
    }

    public function test_get_timeline_with_messages(): void
    {
        $this->createMessage('Msg 1');
        $this->createMessage('Msg 2');
        $this->createMessage('Msg 3');

        $timeline = $this->service->getTimeline($this->tenantId, $this->conversationId);

        $this->assertCount(3, $timeline);
    }

    public function test_get_timeline_ordered_ascending(): void
    {
        $msg1 = $this->createMessage('First');
        sleep(1);
        $msg2 = $this->createMessage('Second');
        sleep(1);
        $msg3 = $this->createMessage('Third');

        $timeline = $this->service->getTimeline($this->tenantId, $this->conversationId);

        $this->assertCount(3, $timeline);
        $this->assertSame('First', $timeline[0]->content);
        $this->assertSame('Third', $timeline[2]->content);
    }

    public function test_get_timeline_with_limit(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->createMessage("Msg {$i}");
        }

        $timeline = $this->service->getTimeline($this->tenantId, $this->conversationId, null, 5);

        $this->assertCount(5, $timeline);
    }

    public function test_get_timeline_before_cursor(): void
    {
        $msg1 = $this->createMessage('First');
        sleep(1);
        $msg2 = $this->createMessage('Second');
        sleep(1);
        $msg3 = $this->createMessage('Third');

        // Get all messages
        $allMessages = $this->service->getTimeline($this->tenantId, $this->conversationId);
        $this->assertCount(3, $allMessages);

        // Use the second message's ID as cursor - should return only the first message
        $cursorId = (string) $allMessages[1]->message_id;
        $timeline = $this->service->getTimeline($this->tenantId, $this->conversationId, $cursorId);

        $this->assertLessThan(3, $timeline->count());
    }

    public function test_mark_read(): void
    {
        $msg = $this->createMessage('Test message');

        $this->service->markRead($this->tenantId, $this->conversationId, $this->senderId, (string) $msg->message_id);

        $readState = ReadState::where('conversation_id', $this->conversationId)
            ->where('user_id', $this->senderId)
            ->first();

        $this->assertNotNull($readState);
        $this->assertSame($msg->message_id, (int) $readState->last_read_message_id);
    }

    public function test_mark_read_updates_existing(): void
    {
        $msg1 = $this->createMessage('Msg 1');
        usleep(1000);
        $msg2 = $this->createMessage('Msg 2');

        $this->service->markRead($this->tenantId, $this->conversationId, $this->senderId, (string) $msg1->message_id);
        $this->service->markRead($this->tenantId, $this->conversationId, $this->senderId, (string) $msg2->message_id);

        $readState = ReadState::where('conversation_id', $this->conversationId)
            ->where('user_id', $this->senderId)
            ->first();

        $this->assertSame($msg2->message_id, (int) $readState->last_read_message_id);

        $count = ReadState::where('conversation_id', $this->conversationId)
            ->where('user_id', $this->senderId)
            ->count();
        $this->assertSame(1, $count);
    }
}
