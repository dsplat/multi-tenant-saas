<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\Models\AgentConversationMessage;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Models\OperatorIbotBinding;
use MultiTenantSaas\Modules\Ibot\Services\IbotNotifier;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\IbotModule;

class IbotNotifierTest extends TestCase
{
    protected array $uses = [IbotModule::class, AgentModule::class];

    private IbotNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        TenantContext::setTenantId('1001');

        config()->set('ai.ibot.enabled', true);

        $this->notifier = app(IbotNotifier::class);
    }

    private function createIbot(array $overrides = []): Ibot
    {
        return Ibot::forceCreate(array_merge([
            'ibot_id' => 3001,
            'tenant_id' => 1001,
            'channel_type' => Ibot::CHANNEL_TELEGRAM,
            'transport' => Ibot::TRANSPORT_LONGCONN,
            'name' => 'Test Bot',
            'credentials' => ['bot_token' => 'tg-token', 'bot_username' => 'test_bot'],
            'status' => Ibot::STATUS_ACTIVE,
        ], $overrides));
    }

    private function createBinding(Ibot $ibot, array $overrides = []): OperatorIbotBinding
    {
        return OperatorIbotBinding::forceCreate(array_merge([
            'tenant_id' => 1001,
            'operator_id' => 501,
            'ibot_id' => $ibot->ibot_id,
            'external_id' => 'tg-chat-100',
            'status' => OperatorIbotBinding::STATUS_ACTIVE,
        ], $overrides));
    }

    public function test_disabled_switch_returns_false(): void
    {
        config()->set('ai.ibot.enabled', false);
        Http::fake();

        $this->createBinding($this->createIbot());

        $this->assertFalse($this->notifier->notifyOperator(501, '系统通知'));
        Http::assertNothingSent();
    }

    public function test_no_binding_returns_false(): void
    {
        Http::fake();

        $this->createIbot();

        $this->assertFalse($this->notifier->notifyOperator(501, '系统通知'));
        Http::assertNothingSent();
    }

    public function test_single_active_binding_is_implicit_default(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->createBinding($this->createIbot());

        $this->assertTrue($this->notifier->notifyOperator(501, '系统通知'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/bottg-token/sendMessage')
            && $request['chat_id'] === 'tg-chat-100');
    }

    public function test_explicit_default_wins_over_other_bindings(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $ibotA = $this->createIbot();
        $ibotB = $this->createIbot(['ibot_id' => 3002, 'name' => 'Other Bot']);

        $this->createBinding($ibotA, ['external_id' => 'tg-chat-100']);
        $this->createBinding($ibotB, ['external_id' => 'tg-chat-200', 'is_default_channel' => true]);

        $this->assertTrue($this->notifier->notifyOperator(501, '系统通知'));

        Http::assertSent(fn ($request) => $request['chat_id'] === 'tg-chat-200');
        Http::assertSentCount(1);
    }

    public function test_multiple_bindings_without_default_not_guessed(): void
    {
        Http::fake();

        $ibotA = $this->createIbot();
        $ibotB = $this->createIbot(['ibot_id' => 3002, 'name' => 'Other Bot']);

        $this->createBinding($ibotA, ['external_id' => 'tg-chat-100']);
        $this->createBinding($ibotB, ['external_id' => 'tg-chat-200']);

        // 宁可降级（database/mail 兜底）也不猜测推送目标
        $this->assertFalse($this->notifier->notifyOperator(501, '系统通知'));
        Http::assertNothingSent();
    }

    public function test_revoked_binding_ignored(): void
    {
        Http::fake();

        $this->createBinding($this->createIbot(), ['status' => OperatorIbotBinding::STATUS_REVOKED]);

        $this->assertFalse($this->notifier->notifyOperator(501, '系统通知'));
        Http::assertNothingSent();
    }

    public function test_inactive_ibot_returns_false(): void
    {
        Http::fake();

        $this->createBinding($this->createIbot(['status' => Ibot::STATUS_DISABLED]));

        $this->assertFalse($this->notifier->notifyOperator(501, '系统通知'));
        Http::assertNothingSent();
    }

    public function test_success_appends_conversation_message_with_metadata(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->createBinding($this->createIbot(), ['conversation_id' => 7001]);

        $this->assertTrue($this->notifier->notifyOperator(501, '系统通知', ['notification' => 'FooNotice']));

        $message = AgentConversationMessage::where('conversation_id', 7001)->first();

        $this->assertNotNull($message);
        $this->assertSame('assistant', $message->role);
        $this->assertSame('系统通知', $message->content);
        $this->assertSame('ibot_notification', $message->metadata['source']);
        $this->assertSame('FooNotice', $message->metadata['notification']);
    }

    public function test_binding_without_conversation_skips_persist(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->createBinding($this->createIbot());

        $this->assertTrue($this->notifier->notifyOperator(501, '系统通知'));
        $this->assertSame(0, AgentConversationMessage::count());
    }

    public function test_send_failure_returns_false_without_persist(): void
    {
        Http::fake(['*' => Http::response(['ok' => false], 500)]);

        $this->createBinding($this->createIbot(), ['conversation_id' => 7001]);

        $this->assertFalse($this->notifier->notifyOperator(501, '系统通知'));
        $this->assertSame(0, AgentConversationMessage::count());
    }

    public function test_works_with_default_tenant_id_configured(): void
    {
        // 回归：生产 .env 配 DEFAULT_TENANT_ID 时 CLI 恒有租户上下文，
        // allowUnscoped 失效，通知解析必须硬豁免 TenantScope
        config()->set('tenancy.default_tenant_id', '9999');
        TenantContext::clear();

        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->createBinding($this->createIbot());

        $this->assertTrue($this->notifier->notifyOperator(501, '系统通知'));
    }
}
