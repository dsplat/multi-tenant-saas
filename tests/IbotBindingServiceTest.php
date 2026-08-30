<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Models\OperatorIbotBinding;
use MultiTenantSaas\Modules\Ibot\Services\IbotBindingService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\IbotModule;

class IbotBindingServiceTest extends TestCase
{
    protected array $uses = [IbotModule::class];

    private IbotBindingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        TenantContext::setTenantId('1001');

        $this->service = new IbotBindingService;
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

    public function test_generate_and_consume_creates_active_binding(): void
    {
        $ibot = $this->createIbot();

        $code = $this->service->generateBindCode(501, $ibot);

        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $code);

        $binding = $this->service->consume($code, $ibot, 'tg-chat-100');

        $this->assertNotNull($binding);
        $this->assertSame(501, (int) $binding->operator_id);
        $this->assertSame('tg-chat-100', $binding->external_id);
        $this->assertSame('tg-chat-100', $binding->external_name);
        $this->assertSame(OperatorIbotBinding::STATUS_ACTIVE, $binding->status);
    }

    public function test_consume_persists_external_name(): void
    {
        $ibot = $this->createIbot();

        $code = $this->service->generateBindCode(501, $ibot);
        $binding = $this->service->consume($code, $ibot, 'luoyaoliang', '罗岳良');

        $this->assertNotNull($binding);
        $this->assertSame('罗岳良', $binding->external_name);

        // 换设备重绑（同 operator）姓名同步更新
        $again = $this->service->consume($this->service->generateBindCode(501, $ibot), $ibot, 'tg-chat-200', '罗岳良2');
        $this->assertSame('罗岳良2', $again->external_name);
        $this->assertSame((int) $binding->binding_id, (int) $again->binding_id);
    }

    public function test_rebind_after_revoke_allows_other_operator(): void
    {
        $ibot = $this->createIbot();

        // operator 501 绑定 tg-chat-100 后解绑（revoked）
        $first = $this->service->consume($this->service->generateBindCode(501, $ibot), $ibot, 'tg-chat-100');
        $first->update(['status' => OperatorIbotBinding::STATUS_REVOKED]);

        // operator 502 重绑同一 IM 账号 → 允许（revoked 不互斥），记录转交并激活
        $second = $this->service->consume($this->service->generateBindCode(502, $ibot), $ibot, 'tg-chat-100', '新成员');

        $this->assertNotNull($second);
        $this->assertSame((int) $first->binding_id, (int) $second->binding_id);
        $this->assertSame(502, (int) $second->operator_id);
        $this->assertSame('新成员', $second->external_name);
        $this->assertSame(OperatorIbotBinding::STATUS_ACTIVE, $second->status);
        $this->assertSame(1, OperatorIbotBinding::count());
    }

    public function test_invalid_code_returns_null(): void
    {
        $ibot = $this->createIbot();

        $this->assertNull($this->service->consume('NOTEXIST', $ibot, 'tg-chat-100'));
    }

    public function test_code_is_single_use(): void
    {
        $ibot = $this->createIbot();
        $code = $this->service->generateBindCode(501, $ibot);

        $this->assertNotNull($this->service->consume($code, $ibot, 'tg-chat-100'));
        $this->assertNull($this->service->consume($code, $ibot, 'tg-chat-100'));
    }

    public function test_code_rejected_on_different_ibot(): void
    {
        $ibotA = $this->createIbot();
        $ibotB = $this->createIbot(['ibot_id' => 3002, 'name' => 'Other Bot']);

        $code = $this->service->generateBindCode(501, $ibotA);

        // 为 A 生成的码不能用于 B（防跨 bot 重放）
        $this->assertNull($this->service->consume($code, $ibotB, 'tg-chat-100'));
    }

    public function test_rebind_same_operator_updates_external_id(): void
    {
        $ibot = $this->createIbot();

        $first = $this->service->consume($this->service->generateBindCode(501, $ibot), $ibot, 'tg-chat-100');
        $second = $this->service->consume($this->service->generateBindCode(501, $ibot), $ibot, 'tg-chat-200');

        $this->assertNotNull($second);
        $this->assertSame((int) $first->binding_id, (int) $second->binding_id);
        $this->assertSame('tg-chat-200', $second->external_id);
        $this->assertSame(1, OperatorIbotBinding::count());
    }

    public function test_occupied_external_id_rejected(): void
    {
        $ibot = $this->createIbot();

        // operator 501 先占用 tg-chat-100
        $this->service->consume($this->service->generateBindCode(501, $ibot), $ibot, 'tg-chat-100');

        // operator 502 试图绑定同一会话 → 拒绝
        $code = $this->service->generateBindCode(502, $ibot);

        $this->assertNull($this->service->consume($code, $ibot, 'tg-chat-100'));
    }
}
