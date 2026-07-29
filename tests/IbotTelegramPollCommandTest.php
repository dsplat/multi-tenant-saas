<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\IbotModule;

class IbotTelegramPollCommandTest extends TestCase
{
    protected array $uses = [IbotModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);

        config()->set('ai.ibot.enabled', true);

        Ibot::forceCreate([
            'ibot_id' => 3001,
            'tenant_id' => 1001,
            'channel_type' => Ibot::CHANNEL_TELEGRAM,
            'transport' => Ibot::TRANSPORT_LONGCONN,
            'name' => 'Test Bot',
            'credentials' => ['bot_token' => 'tg-token', 'bot_username' => 'test_bot'],
            'status' => Ibot::STATUS_ACTIVE,
        ]);
    }

    public function test_disabled_switch_exits_without_polling(): void
    {
        config()->set('ai.ibot.enabled', false);
        Http::fake();

        $this->artisan('ibot:telegram-poll', ['--once' => true])->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_polls_active_telegram_ibots(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => []])]);

        $this->artisan('ibot:telegram-poll', ['--once' => true])->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/bottg-token/getUpdates'));
    }

    public function test_discovers_ibots_even_with_default_tenant_configured(): void
    {
        // 回归：下游生产配置 tenancy.default_tenant_id 后，CLI 上下文被兜底租户
        // 短路，allowUnscoped 失效——发现查询必须硬豁免 TenantScope。
        config()->set('tenancy.default_tenant_id', '9999');
        Http::fake(['*' => Http::response(['ok' => true, 'result' => []])]);

        $this->artisan('ibot:telegram-poll', ['--once' => true])->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/bottg-token/getUpdates'));
    }
}
