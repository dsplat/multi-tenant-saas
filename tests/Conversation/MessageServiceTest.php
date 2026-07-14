<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\Conversation;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\Conversation\Models\Message;
use MultiTenantSaas\Modules\Conversation\Services\MessageService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\ChannelModule;
use MultiTenantSaas\Tests\TestCase;

class MessageServiceTest extends TestCase
{
    protected array $uses = [ChannelModule::class];

    private MessageService $service;

    private int $tenantId = 1001;

    private int $senderId = 2001;

    private int $receiverId = 2002;

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
            User::create(['user_id' => $this->receiverId, 'name' => 'Receiver', 'email' => 'receiver@example.com', 'password' => bcrypt('secret')]);
        });

        TenantContext::setTenantId((string) $this->tenantId);

        $conversation = Conversation::create([
            'tenant_id' => $this->tenantId,
            'type' => 'support',
            'status' => 'active',
            'message_count' => 0,
        ]);
        $this->conversationId = (string) $conversation->conversation_id;

        $this->service = $this->app->make(MessageService::class);
    }

    public function test_send_message(): void
    {
        $message = $this->service->sendMessage(
            $this->tenantId,
            $this->conversationId,
            $this->senderId,
            'Hello World',
        );

        $this->assertNotNull($message->message_id);
        $this->assertSame('Hello World', $message->content);
        $this->assertSame('user', $message->sender_type);
        $this->assertSame('text', $message->type);
    }

    public function test_send_message_increments_count(): void
    {
        $this->service->sendMessage($this->tenantId, $this->conversationId, $this->senderId, 'Msg 1');
        $this->service->sendMessage($this->tenantId, $this->conversationId, $this->senderId, 'Msg 2');

        $conv = Conversation::where('conversation_id', $this->conversationId)->first();
        $this->assertSame(2, $conv->message_count);
    }

    public function test_get_message(): void
    {
        $sent = $this->service->sendMessage($this->tenantId, $this->conversationId, $this->senderId, 'Test');

        $found = $this->service->getMessage($this->tenantId, (string) $sent->message_id);

        $this->assertSame($sent->message_id, $found->message_id);
    }

    public function test_list_messages(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->service->sendMessage($this->tenantId, $this->conversationId, $this->senderId, "Msg {$i}");
        }

        $result = $this->service->listMessages($this->tenantId, $this->conversationId);

        $this->assertSame(5, $result->total());
    }

    public function test_revoke_message(): void
    {
        $message = $this->service->sendMessage($this->tenantId, $this->conversationId, $this->senderId, 'Revoke me');

        $result = $this->service->revokeMessage($this->tenantId, (string) $message->message_id, $this->senderId);

        $this->assertTrue($result);

        $updated = Message::where('message_id', $message->message_id)->first();
        $this->assertSame('revoked', $updated->type);
        $this->assertSame('[消息已撤回]', $updated->content);
    }

    public function test_revoke_message_wrong_user_fails(): void
    {
        $message = $this->service->sendMessage($this->tenantId, $this->conversationId, $this->senderId, 'Test');

        $this->expectException(ModelNotFoundException::class);

        $this->service->revokeMessage($this->tenantId, (string) $message->message_id, $this->receiverId);
    }

    public function test_search_messages(): void
    {
        $this->service->sendMessage($this->tenantId, $this->conversationId, $this->senderId, 'Hello World');
        $this->service->sendMessage($this->tenantId, $this->conversationId, $this->senderId, 'Goodbye');
        $this->service->sendMessage($this->tenantId, $this->conversationId, $this->senderId, 'Hello Again');

        $results = $this->service->searchMessages($this->tenantId, 'Hello');

        $this->assertCount(2, $results);
    }

    public function test_search_messages_excludes_revoked(): void
    {
        $msg = $this->service->sendMessage($this->tenantId, $this->conversationId, $this->senderId, 'Hello World');
        $this->service->revokeMessage($this->tenantId, (string) $msg->message_id, $this->senderId);

        $results = $this->service->searchMessages($this->tenantId, 'Hello');

        $this->assertCount(0, $results);
    }
}
