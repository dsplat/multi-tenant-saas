<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Models\OperatorIbotBinding;
use MultiTenantSaas\Modules\Ibot\Notifications\IbotNotificationChannel;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Notification\Notifications\GeneralNotification;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\IbotModule;

class IbotNotificationChannelTest extends TestCase
{
    protected array $uses = [IbotModule::class, AgentModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        TenantContext::setTenantId('1001');

        config()->set('ai.ibot.enabled', true);
    }

    private function createBoundOperator(): void
    {
        $ibot = Ibot::forceCreate([
            'ibot_id' => 3001,
            'tenant_id' => 1001,
            'channel_type' => Ibot::CHANNEL_TELEGRAM,
            'transport' => Ibot::TRANSPORT_LONGCONN,
            'name' => 'Test Bot',
            'credentials' => ['bot_token' => 'tg-token', 'bot_username' => 'test_bot'],
            'status' => Ibot::STATUS_ACTIVE,
        ]);

        OperatorIbotBinding::forceCreate([
            'tenant_id' => 1001,
            'operator_id' => 501,
            'ibot_id' => $ibot->ibot_id,
            'external_id' => 'tg-chat-100',
            'status' => OperatorIbotBinding::STATUS_ACTIVE,
        ]);
    }

    private function operatorNotifiable(?int $operatorId = 501): object
    {
        return new class($operatorId)
        {
            public ?string $email = null;

            public function __construct(public ?int $operator_id) {}
        };
    }

    public function test_general_notification_via_includes_ibot_for_operator(): void
    {
        $channels = (new GeneralNotification('标题', '内容'))->via($this->operatorNotifiable());

        $this->assertContains('ibot', $channels);
        $this->assertContains('database', $channels);
    }

    public function test_general_notification_via_excludes_ibot_when_disabled(): void
    {
        config()->set('ai.ibot.enabled', false);

        $channels = (new GeneralNotification('标题', '内容'))->via($this->operatorNotifiable());

        $this->assertNotContains('ibot', $channels);
    }

    public function test_general_notification_via_excludes_ibot_for_non_operator(): void
    {
        // User 等无 operator_id 的 notifiable 不走 ibot 通道
        $user = new class
        {
            public ?string $email = null;
        };

        $channels = (new GeneralNotification('标题', '内容'))->via($user);

        $this->assertNotContains('ibot', $channels);
    }

    public function test_channel_pushes_general_notification_text(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->createBoundOperator();

        app(IbotNotificationChannel::class)->send(
            $this->operatorNotifiable(),
            new GeneralNotification('审批提醒', '有一条待办需要处理', 'info', 'https://example.com/todo'),
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sendMessage')
                && $request['chat_id'] === 'tg-chat-100'
                && str_contains($request['text'], '审批提醒')
                && str_contains($request['text'], '有一条待办需要处理')
                && str_contains($request['text'], 'https://example.com/todo');
        });
    }

    public function test_channel_skips_notifiable_without_operator_id(): void
    {
        Http::fake();

        $this->createBoundOperator();

        app(IbotNotificationChannel::class)->send(
            $this->operatorNotifiable(null),
            new GeneralNotification('标题', '内容'),
        );

        Http::assertNothingSent();
    }

    public function test_channel_degrades_silently_without_binding(): void
    {
        Http::fake();

        app(IbotNotificationChannel::class)->send(
            $this->operatorNotifiable(),
            new GeneralNotification('标题', '内容'),
        );

        Http::assertNothingSent();
    }
}
