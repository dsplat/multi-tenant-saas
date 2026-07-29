<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ibot\DTOs\IbotInboundMessage;
use MultiTenantSaas\Modules\Ibot\Jobs\ProcessIbotInboundMessage;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Models\OperatorIbotBinding;
use MultiTenantSaas\Modules\Ibot\Services\IbotBindingService;
use MultiTenantSaas\Modules\Ibot\Services\IbotGateway;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\IbotModule;

class IbotGatewayTest extends TestCase
{
    protected array $uses = [IbotModule::class];

    private IbotGateway $gateway;

    private IbotBindingService $bindingService;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        TenantContext::setTenantId('1001');

        config()->set('ai.ibot.enabled', true);

        $this->bindingService = app(IbotBindingService::class);
        $this->gateway = app(IbotGateway::class);
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

    private function inbound(string $text, string $externalId = 'tg-chat-100'): IbotInboundMessage
    {
        return new IbotInboundMessage(externalId: $externalId, text: $text);
    }

    public function test_disabled_switch_does_nothing(): void
    {
        config()->set('ai.ibot.enabled', false);
        Bus::fake();
        Http::fake();

        $ibot = $this->createIbot();

        $this->gateway->handleInbound($ibot, $this->inbound('hello'));

        Bus::assertNotDispatched(ProcessIbotInboundMessage::class);
        Http::assertNothingSent();
    }

    public function test_inactive_ibot_does_nothing(): void
    {
        Bus::fake();
        Http::fake();

        $ibot = $this->createIbot(['status' => Ibot::STATUS_DISABLED]);

        $this->gateway->handleInbound($ibot, $this->inbound('hello'));

        Bus::assertNotDispatched(ProcessIbotInboundMessage::class);
        Http::assertNothingSent();
    }

    public function test_bound_message_dispatches_job(): void
    {
        Bus::fake();
        Http::fake();

        $ibot = $this->createIbot();
        $code = $this->bindingService->generateBindCode(501, $ibot);
        $this->bindingService->consume($code, $ibot, 'tg-chat-100');

        $this->gateway->handleInbound($ibot, $this->inbound('帮我看下今天数据'));

        Bus::assertDispatched(ProcessIbotInboundMessage::class, 1);
        Http::assertNothingSent();
    }

    public function test_unbound_with_valid_start_code_creates_binding_and_replies(): void
    {
        Bus::fake();
        Http::fake(['*' => Http::response(['ok' => true])]);

        $ibot = $this->createIbot();
        $code = $this->bindingService->generateBindCode(501, $ibot);

        $this->gateway->handleInbound($ibot, $this->inbound("/start {$code}"));

        $binding = OperatorIbotBinding::where('ibot_id', $ibot->ibot_id)
            ->where('external_id', 'tg-chat-100')
            ->first();

        $this->assertNotNull($binding);
        $this->assertSame(501, (int) $binding->operator_id);
        $this->assertSame(OperatorIbotBinding::STATUS_ACTIVE, $binding->status);

        Bus::assertNotDispatched(ProcessIbotInboundMessage::class);
        Http::assertSentCount(1); // 绑定成功回执
    }

    public function test_unbound_with_bare_code_also_binds(): void
    {
        Bus::fake();
        Http::fake(['*' => Http::response(['ok' => true])]);

        $ibot = $this->createIbot();
        $code = $this->bindingService->generateBindCode(501, $ibot);

        $this->gateway->handleInbound($ibot, $this->inbound(strtolower($code)));

        $this->assertSame(1, OperatorIbotBinding::count());
    }

    public function test_unbound_without_code_gets_guidance_no_binding(): void
    {
        Bus::fake();
        Http::fake(['*' => Http::response(['ok' => true])]);

        $ibot = $this->createIbot();

        $this->gateway->handleInbound($ibot, $this->inbound('你好'));

        $this->assertSame(0, OperatorIbotBinding::count());
        Bus::assertNotDispatched(ProcessIbotInboundMessage::class);
        Http::assertSentCount(1); // 引导语
    }

    public function test_unbound_with_expired_code_gets_invalid_reply(): void
    {
        Bus::fake();
        Http::fake(['*' => Http::response(['ok' => true])]);

        $ibot = $this->createIbot();

        $this->gateway->handleInbound($ibot, $this->inbound('/start ZZZZ9999'));

        $this->assertSame(0, OperatorIbotBinding::count());
        Http::assertSentCount(1); // 无效提示
    }

    public function test_revoked_binding_treated_as_unbound(): void
    {
        Bus::fake();
        Http::fake(['*' => Http::response(['ok' => true])]);

        $ibot = $this->createIbot();
        $code = $this->bindingService->generateBindCode(501, $ibot);
        $binding = $this->bindingService->consume($code, $ibot, 'tg-chat-100');
        $binding->update(['status' => OperatorIbotBinding::STATUS_REVOKED]);

        $this->gateway->handleInbound($ibot, $this->inbound('hello'));

        Bus::assertNotDispatched(ProcessIbotInboundMessage::class);
        Http::assertSentCount(1); // 引导语
    }
}
