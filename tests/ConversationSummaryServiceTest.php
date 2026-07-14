<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\Conversation\Models\Message;
use MultiTenantSaas\Modules\Conversation\Services\ConversationSummaryService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\ChannelModule;

class ConversationSummaryServiceTest extends TestCase
{
    protected array $uses = [ChannelModule::class];

    private ConversationSummaryService $service;

    private Tenant $tenant;

    private User $user;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ConversationSummaryService;

        $this->tenant = Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        User::unguarded(function () {
            $this->user = User::create([
                'user_id' => 2001,
                'name' => 'Alice',
                'email' => 'alice@example.com',
                'password' => bcrypt('secret'),
            ]);
        });

        TenantContext::setTenantId((string) $this->tenant->tenant_id);

        $this->conversation = Conversation::create([
            'conversation_id' => 3001,
            'tenant_id' => $this->tenant->tenant_id,
            'created_by' => $this->user->user_id,
            'type' => 'support',
            'status' => 'active',
            'message_count' => 0,
        ]);
    }

    public function test_generate_summary_with_messages(): void
    {
        Message::create([
            'message_id' => 4001,
            'tenant_id' => $this->tenant->tenant_id,
            'conversation_id' => $this->conversation->conversation_id,
            'sender_id' => $this->user->user_id,
            'sender_type' => 'user',
            'content' => '我有一个问题需要解决',
            'type' => 'text',
        ]);

        Message::create([
            'message_id' => 4002,
            'tenant_id' => $this->tenant->tenant_id,
            'conversation_id' => $this->conversation->conversation_id,
            'sender_id' => 0,
            'sender_type' => 'agent',
            'content' => '好的，请描述您的问题',
            'type' => 'text',
        ]);

        $result = $this->service->generateSummary([
            'conversation_id' => (string) $this->conversation->conversation_id,
            'tenant_id' => $this->tenant->tenant_id,
        ]);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('2 条消息', $result);
        $this->assertStringContainsString('参与者:', $result);
        $this->assertStringContainsString('关键内容:', $result);

        $this->conversation->refresh();
        $this->assertNotNull($this->conversation->summary);
        $this->assertNotNull($this->conversation->summary_updated_at);
    }

    public function test_generate_summary_empty_conversation(): void
    {
        $result = $this->service->generateSummary([
            'conversation_id' => (string) $this->conversation->conversation_id,
            'tenant_id' => $this->tenant->tenant_id,
        ]);

        $this->assertIsString($result);
        $this->assertStringContainsString('0 条消息', $result);
    }

    public function test_generate_summary_filters_revoked_messages(): void
    {
        Message::create([
            'message_id' => 4003,
            'tenant_id' => $this->tenant->tenant_id,
            'conversation_id' => $this->conversation->conversation_id,
            'sender_id' => $this->user->user_id,
            'sender_type' => 'user',
            'content' => '正常消息',
            'type' => 'text',
        ]);

        Message::create([
            'message_id' => 4004,
            'tenant_id' => $this->tenant->tenant_id,
            'conversation_id' => $this->conversation->conversation_id,
            'sender_id' => $this->user->user_id,
            'sender_type' => 'user',
            'content' => '[消息已撤回]',
            'type' => 'revoked',
        ]);

        $result = $this->service->generateSummary([
            'conversation_id' => (string) $this->conversation->conversation_id,
            'tenant_id' => $this->tenant->tenant_id,
        ]);

        $this->assertStringContainsString('1 条消息', $result);
    }

    public function test_generate_summary_throws_on_missing_conversation_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->generateSummary(['tenant_id' => $this->tenant->tenant_id]);
    }

    public function test_generate_summary_throws_on_missing_tenant_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->generateSummary([
            'conversation_id' => (string) $this->conversation->conversation_id,
        ]);
    }

    public function test_update_summary(): void
    {
        Message::create([
            'message_id' => 4005,
            'tenant_id' => $this->tenant->tenant_id,
            'conversation_id' => $this->conversation->conversation_id,
            'sender_id' => $this->user->user_id,
            'sender_type' => 'user',
            'content' => '升级方案已经确认完成',
            'type' => 'text',
        ]);

        $result = $this->service->updateSummary(
            (string) $this->conversation->conversation_id,
            ['tenant_id' => $this->tenant->tenant_id],
        );

        $this->assertTrue($result);

        $this->conversation->refresh();
        $this->assertNotNull($this->conversation->summary);
    }

    public function test_update_summary_throws_on_empty_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->updateSummary('', ['tenant_id' => $this->tenant->tenant_id]);
    }

    public function test_get_summary_returns_content(): void
    {
        $this->conversation->update([
            'summary' => '测试摘要内容',
            'summary_updated_at' => now(),
        ]);

        $result = $this->service->getSummary((string) $this->conversation->conversation_id);

        $this->assertSame('测试摘要内容', $result);
    }

    public function test_get_summary_returns_null_for_no_summary(): void
    {
        $result = $this->service->getSummary((string) $this->conversation->conversation_id);

        $this->assertNull($result);
    }

    public function test_get_summary_throws_on_empty_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->getSummary('');
    }

    public function test_process_conversation_data(): void
    {
        $messages = [
            ['content' => '你好', 'sender_type' => 'user', 'type' => 'text'],
            ['content' => '我有一个紧急问题', 'sender_type' => 'user', 'type' => 'text'],
            ['content' => '请描述一下', 'sender_type' => 'agent', 'type' => 'text'],
        ];

        $result = $this->service->processConversationData($messages);

        $this->assertArrayHasKey('participants', $result);
        $this->assertArrayHasKey('key_points', $result);
        $this->assertArrayHasKey('message_count', $result);
        $this->assertSame(3, $result['message_count']);
        $this->assertContains('user', $result['participants']);
        $this->assertContains('agent', $result['participants']);
    }

    public function test_sanitize_content(): void
    {
        $result = $this->service->sanitizeContent('  <b>hello</b>  world  ');
        $this->assertSame('hello world', $result);
    }

    public function test_is_key_point_with_keyword(): void
    {
        $this->assertTrue($this->service->isKeyPoint('我有一个问题'));
        $this->assertTrue($this->service->isKeyPoint('紧急情况'));
        $this->assertFalse($this->service->isKeyPoint('你好'));
    }

    public function test_is_key_point_with_long_content(): void
    {
        $longContent = str_repeat('这是一段很长的内容。', 10);
        $this->assertTrue($this->service->isKeyPoint($longContent));
    }

    public function test_build_summary(): void
    {
        $data = [
            'participants' => ['user', 'agent'],
            'key_points' => ['问题已解决'],
            'message_count' => 5,
        ];

        $result = $this->service->buildSummary($data);

        $this->assertStringContainsString('5 条消息', $result);
        $this->assertStringContainsString('user, agent', $result);
        $this->assertStringContainsString('问题已解决', $result);
    }

    public function test_full_lifecycle(): void
    {
        Message::create([
            'message_id' => 4010,
            'tenant_id' => $this->tenant->tenant_id,
            'conversation_id' => $this->conversation->conversation_id,
            'sender_id' => $this->user->user_id,
            'sender_type' => 'user',
            'content' => '请问如何修复这个问题？',
            'type' => 'text',
        ]);

        Message::create([
            'message_id' => 4011,
            'tenant_id' => $this->tenant->tenant_id,
            'conversation_id' => $this->conversation->conversation_id,
            'sender_id' => 0,
            'sender_type' => 'agent',
            'content' => '解决方案如下：请升级到最新版本',
            'type' => 'text',
        ]);

        $summary = $this->service->generateSummary([
            'conversation_id' => (string) $this->conversation->conversation_id,
            'tenant_id' => $this->tenant->tenant_id,
        ]);

        $this->assertNotEmpty($summary);

        $fetched = $this->service->getSummary((string) $this->conversation->conversation_id);
        $this->assertSame($summary, $fetched);

        Message::create([
            'message_id' => 4012,
            'tenant_id' => $this->tenant->tenant_id,
            'conversation_id' => $this->conversation->conversation_id,
            'sender_id' => $this->user->user_id,
            'sender_type' => 'user',
            'content' => '谢谢，问题已经确认完成',
            'type' => 'text',
        ]);

        $updated = $this->service->updateSummary(
            (string) $this->conversation->conversation_id,
            ['tenant_id' => $this->tenant->tenant_id],
        );

        $this->assertTrue($updated);

        $newSummary = $this->service->getSummary((string) $this->conversation->conversation_id);
        $this->assertNotSame($summary, $newSummary);
        $this->assertStringContainsString('3 条消息', $newSummary);
    }
}
