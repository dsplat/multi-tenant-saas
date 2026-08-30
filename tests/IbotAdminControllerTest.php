<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Models\OperatorIbotBinding;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Modules\WechatWork\Models\WechatWorkAuthorization;
use MultiTenantSaas\Tests\Schema\IbotModule;
use MultiTenantSaas\Tests\Schema\RbacModule;
use MultiTenantSaas\Tests\Schema\WechatWorkModule;

/**
 * ibot 管理 API：CRUD、凭证脱敏与局部合并、权限 403、删除保护
 */
class IbotAdminControllerTest extends TestCase
{
    protected array $uses = [IbotModule::class, RbacModule::class, WechatWorkModule::class];

    private const API = '/api/v1/tenant/ibot/ibots';

    private Operator $admin;

    private Operator $member;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        TenantContext::setTenantId('1001');

        $this->admin = $this->createOperator('admin@example.com', 3);   // tenant_admin：有 setting.update
        $this->member = $this->createOperator('member@example.com', 4); // member：仅 setting.view
    }

    private function createOperator(string $email, int $roleId): Operator
    {
        $operator = Operator::create([
            'email' => $email,
            'name' => $email,
            'scope' => 'tenant',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        OperatorTenant::create([
            'operator_id' => $operator->operator_id,
            'tenant_id' => 1001,
            'role' => (string) $roleId,
            'role_id' => $roleId,
            'is_active' => true,
            'accepted_at' => now(),
        ]);

        return $operator;
    }

    private function createIbot(array $overrides = []): Ibot
    {
        return Ibot::forceCreate(array_merge([
            'tenant_id' => 1001,
            'channel_type' => Ibot::CHANNEL_WECHAT_WORK,
            'transport' => Ibot::TRANSPORT_WEBHOOK,
            'name' => 'WW Bot',
            'credentials' => [
                'corp_id' => 'wwcorp123',
                'corp_secret' => 'secret-abcd',
                'agent_id' => '1000002',
                'token' => 'tok-1234',
                'encoding_aes_key' => str_repeat('k', 43),
            ],
            'status' => Ibot::STATUS_ACTIVE,
        ], $overrides));
    }

    // ========== 权限 ==========

    public function test_member_without_setting_update_gets_403(): void
    {
        $response = $this->actingAs($this->member, 'sanctum')->getJson(self::API);

        $response->assertStatus(403);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson(self::API)->assertStatus(401);
    }

    // ========== 列表与脱敏 ==========

    public function test_index_masks_credentials_and_never_returns_plaintext(): void
    {
        $this->createIbot();

        $response = $this->actingAs($this->admin, 'sanctum')->getJson(self::API);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $item = $response->json('data.0');

        $this->assertSame('wechat_work', $item['channel_type']);
        $this->assertEqualsCanonicalizing(
            ['corp_id', 'corp_secret', 'agent_id', 'token', 'encoding_aes_key'],
            $item['configured_fields']
        );
        // 掩码 = **** + 尾 4 位，不含明文
        $this->assertSame('****abcd', $item['credentials_masked']['corp_secret']);
        $this->assertStringNotContainsString('secret-abcd', $response->getContent());
        // 企微返回按请求域名拼的回调 URL
        $this->assertStringContainsString("/api/v1/ibot/webhook/wechat-work/{$item['ibot_id']}", $item['webhook_url']);
    }

    public function test_index_is_tenant_scoped(): void
    {
        Tenant::create(['tenant_id' => 2002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);
        $this->createIbot(['tenant_id' => 2002, 'name' => 'Other Tenant Bot']);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson(self::API);

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    // ========== 创建 ==========

    public function test_store_creates_ibot_with_whitelisted_credentials(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson(self::API, [
            'channel_type' => 'telegram',
            'name' => 'TG 小助手',
            'credentials' => [
                'bot_token' => '123:abcdef',
                'bot_username' => 'my_bot',
                'evil_field' => 'should-be-dropped',
            ],
        ]);

        $response->assertStatus(201)->assertJson(['success' => true]);

        $ibot = Ibot::withoutGlobalScopes()->find($response->json('data.ibot_id'));
        $this->assertSame(1001, (int) $ibot->tenant_id);
        $this->assertSame(Ibot::TRANSPORT_WEBHOOK, $ibot->transport);
        $this->assertSame(Ibot::STATUS_ACTIVE, $ibot->status);
        $this->assertSame('123:abcdef', $ibot->credentials['bot_token']);
        $this->assertArrayNotHasKey('evil_field', $ibot->credentials);
        // telegram 不返回回调 URL（long polling，无 webhook 路由）
        $this->assertNull($response->json('data.webhook_url'));
    }

    public function test_store_rejects_unsupported_channel_type(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson(self::API, [
            'channel_type' => 'feishu',
            'name' => 'Bot',
            'credentials' => ['x' => 'y'],
        ]);

        $response->assertStatus(422);
    }

    public function test_store_wechat_work_inherits_suite_authorization(): void
    {
        // 租户已在「企业微信配置」页完成套件授权 → 创建 ibot 自动带出 corp_id/agent_id
        WechatWorkAuthorization::forceCreate([
            'authorization_id' => 7001,
            'tenant_id' => 1001,
            'service_provider_id' => 9001,
            'corp_id' => 'ww-suite-corp',
            'agent_id' => '1000002',
            'permanent_code' => 'enc-perm-code',
            'status' => WechatWorkAuthorization::STATUS_AUTHORIZED,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson(self::API, [
            'channel_type' => 'wechat_work',
            'name' => 'Suite Bot',
            'credentials' => [
                'token' => 'tok-1234',
                'encoding_aes_key' => str_repeat('k', 43),
            ],
        ]);

        $response->assertStatus(201)->assertJson(['success' => true]);

        $ibot = Ibot::withoutGlobalScopes()->find($response->json('data.ibot_id'));
        $this->assertSame('ww-suite-corp', $ibot->credentials['corp_id']);
        $this->assertSame(1000002, (int) $ibot->agent_id);
        // 代开发模式不落 corp_secret（permanent_code 充当 secret，走 suite 轨）
        $this->assertArrayNotHasKey('corp_secret', $ibot->credentials);
    }

    public function test_store_wechat_work_inherits_self_oauth_config(): void
    {
        // 无套件授权：回退「企业微信配置」页自建 OAuth 凭证
        TenantSetting::setValue(1001, 'wechatwork', 'corp_id', 'ww-self-corp');
        TenantSetting::setValue(1001, 'wechatwork', 'secret', 'self-secret');
        TenantSetting::setValue(1001, 'wechatwork', 'agent_id', '1000003');

        $response = $this->actingAs($this->admin, 'sanctum')->postJson(self::API, [
            'channel_type' => 'wechat_work',
            'name' => 'Self Bot',
            'credentials' => ['token' => 'tok-5678'],
        ]);

        $response->assertStatus(201)->assertJson(['success' => true]);

        $ibot = Ibot::withoutGlobalScopes()->find($response->json('data.ibot_id'));
        $this->assertSame('ww-self-corp', $ibot->credentials['corp_id']);
        $this->assertSame('self-secret', $ibot->credentials['corp_secret']);
        $this->assertSame(1000003, (int) $ibot->agent_id);
    }

    // ========== 更新（局部合并） ==========

    public function test_update_merges_credentials_ignoring_empty_and_masked(): void
    {
        $ibot = $this->createIbot();

        $response = $this->actingAs($this->admin, 'sanctum')->putJson(self::API . "/{$ibot->ibot_id}", [
            'name' => '改名后的 Bot',
            'credentials' => [
                'corp_secret' => 'new-secret-9999', // 真实新值 → 覆盖
                'token' => '****1234',              // 掩码回传 → 忽略
                'corp_id' => '',                    // 空值 → 忽略
            ],
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $fresh = Ibot::withoutGlobalScopes()->find($ibot->ibot_id);
        $this->assertSame('改名后的 Bot', $fresh->name);
        $this->assertSame('new-secret-9999', $fresh->credentials['corp_secret']);
        $this->assertSame('tok-1234', $fresh->credentials['token']);
        $this->assertSame('wwcorp123', $fresh->credentials['corp_id']);
    }

    // ========== 状态切换 ==========

    public function test_update_status_toggles_active_disabled(): void
    {
        $ibot = $this->createIbot();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson(self::API . "/{$ibot->ibot_id}/status", ['status' => 'disabled'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'disabled');

        $this->assertSame('disabled', Ibot::withoutGlobalScopes()->find($ibot->ibot_id)->status);
    }

    // ========== 删除保护 ==========

    public function test_destroy_rejected_when_active_bindings_exist(): void
    {
        $ibot = $this->createIbot();

        OperatorIbotBinding::forceCreate([
            'tenant_id' => 1001,
            'operator_id' => 501,
            'ibot_id' => $ibot->ibot_id,
            'external_id' => 'zhangsan',
            'status' => OperatorIbotBinding::STATUS_ACTIVE,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson(self::API . "/{$ibot->ibot_id}")
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertNotNull(Ibot::withoutGlobalScopes()->find($ibot->ibot_id));
    }

    public function test_destroy_removes_ibot_and_inactive_bindings(): void
    {
        $ibot = $this->createIbot();

        OperatorIbotBinding::forceCreate([
            'tenant_id' => 1001,
            'operator_id' => 501,
            'ibot_id' => $ibot->ibot_id,
            'external_id' => 'zhangsan',
            'status' => OperatorIbotBinding::STATUS_REVOKED,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson(self::API . "/{$ibot->ibot_id}")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertNull(Ibot::withoutGlobalScopes()->find($ibot->ibot_id));
        $this->assertSame(0, OperatorIbotBinding::withoutGlobalScopes()->where('ibot_id', $ibot->ibot_id)->count());
    }
}
