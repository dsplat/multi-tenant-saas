<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Modules\Wechat\Models\Authorization;
use MultiTenantSaas\Modules\Wechat\Models\ComponentProvider;
use MultiTenantSaas\Modules\Wechat\Services\WechatComponentService;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\RbacModule;
use MultiTenantSaas\Tests\Schema\WechatModule;

/**
 * 平台管理后台 - 微信第三方平台组件凭证 CRUD 测试
 *
 * 覆盖：组件凭证创建/更新/列表，权限集（metadata.permissions）的声明与回读、
 * component_secret / encoding_aes_key 掩码安全、删除保护（有生效授权拒绝）、
 * 连接测试（verify_ticket 缺失 502 / 成功返回 token 前缀）、已授权租户列表。
 */
class WechatAdminProviderTest extends TestCase
{
    protected array $uses = [CoreModule::class, RbacModule::class, WechatModule::class];

    private int $tenantId = 9001;

    private User $admin;

    private string $token = '';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // 与生产一致：sanctum guard 不绑定 provider，Operator/User token 均可认证
        $app['config']->set('auth.guards.sanctum.provider', null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        Tenant::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
            'domain' => 'test-tenant.neihang.com',
        ]);

        $this->admin = User::create([
            'user_id' => 9001,
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'tenant_id' => $this->tenantId,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        // admin 接口需 platform scope Operator（RBAC 直通），且需 operator_tenants 记录过租户校验
        $operator = Operator::create([
            'email' => 'admin@test.com',
            'name' => 'Admin',
            'scope' => 'platform',
            'is_active' => true,
        ]);

        $tenantAdminRoleId = DB::table('roles')
            ->where('name', 'tenant_admin')
            ->whereNull('tenant_id')
            ->value('role_id');

        OperatorTenant::create([
            'operator_id' => $operator->operator_id,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->admin->user_id,
            'role' => 'tenant_admin',
            'role_id' => $tenantAdminRoleId,
            'is_active' => true,
            'accepted_at' => now(),
        ]);

        DB::table('tenant_users')->insert([
            'tenant_id' => $this->tenantId,
            'user_id' => $this->admin->user_id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TenantContext::setTenantId($this->tenantId);
        $this->token = $operator->createToken('test')->plainTextToken;
    }

    private function auth(): static
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    private function providerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => '蓝眼兔第三方平台',
            'component_appid' => 'wxb1234567890abcd',
            'component_secret' => 'component-secret-123',
            'component_token' => 'cb-token',
            'encoding_aes_key' => 'aes-key-43-characters-padding',
            'callback_url' => 'https://auth.neihang.com/api/v1/wechat/component/callback',
            'status' => 'active',
            'permissions' => ['authorize:userinfo', 'message:receive'],
        ], $overrides);
    }

    public function test_provider_store_persists_permissions(): void
    {
        $response = $this->auth()->postJson('/api/v1/admin/wechat/providers', $this->providerPayload([
            'permissions' => ['authorize:userinfo', 'message:receive', 'custom:scope'],
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.permissions', ['authorize:userinfo', 'message:receive', 'custom:scope'])
            ->assertJsonPath('data.component_secret', '********');

        // 权限集落库于 metadata.permissions（去重保序）
        $provider = ComponentProvider::where('component_appid', 'wxb1234567890abcd')->first();
        $this->assertNotNull($provider);
        $this->assertSame(
            ['authorize:userinfo', 'message:receive', 'custom:scope'],
            $provider->metadata['permissions'],
        );
        // 系统级配置：tenant_id 为 null
        $this->assertNull($provider->tenant_id);
    }

    public function test_provider_index_returns_permissions(): void
    {
        $this->auth()->postJson('/api/v1/admin/wechat/providers', $this->providerPayload());

        $this->auth()->getJson('/api/v1/admin/wechat/providers')
            ->assertOk()
            ->assertJsonPath('data.0.permissions', ['authorize:userinfo', 'message:receive'])
            ->assertJsonPath('data.0.name', '蓝眼兔第三方平台');
    }

    public function test_provider_update_replaces_permissions_and_keeps_secret_on_mask(): void
    {
        $create = $this->auth()->postJson('/api/v1/admin/wechat/providers', $this->providerPayload());
        $providerId = $create->json('data.component_provider_id');

        // 掩码/留空 = 未修改：component_secret / encoding_aes_key 跳过回存避免覆盖真实密钥
        $this->auth()->putJson("/api/v1/admin/wechat/providers/{$providerId}", $this->providerPayload([
            'permissions' => ['user:manage'],
            'component_secret' => '********',
            'encoding_aes_key' => '',
        ]))
            ->assertOk()
            ->assertJsonPath('data.permissions', ['user:manage'])
            ->assertJsonPath('data.component_secret', '********');

        $provider = ComponentProvider::find($providerId);
        $this->assertSame(['user:manage'], $provider->metadata['permissions']);
        $this->assertSame('component-secret-123', $provider->component_secret);
        $this->assertSame('aes-key-43-characters-padding', $provider->encoding_aes_key);
    }

    public function test_provider_store_rejects_invalid_permissions(): void
    {
        $this->auth()->postJson('/api/v1/admin/wechat/providers', $this->providerPayload([
            'permissions' => ['authorize:userinfo', 123],
        ]))
            ->assertStatus(422);
    }

    public function test_provider_returns_masked_secrets_and_plain_callback_token(): void
    {
        $create = $this->auth()->postJson('/api/v1/admin/wechat/providers', $this->providerPayload());

        $create->assertCreated()
            ->assertJsonPath('data.component_secret', '********')
            ->assertJsonPath('data.encoding_aes_key', '********')
            ->assertJsonPath('data.component_token', 'cb-token');
    }

    public function test_provider_destroy_rejects_when_authorized_tenants_exist(): void
    {
        $create = $this->auth()->postJson('/api/v1/admin/wechat/providers', $this->providerPayload());
        $providerId = $create->json('data.component_provider_id');

        // 已授权租户（authorized）→ 409 保护，提示先解除授权
        app(WechatComponentService::class)->saveAuthorization($this->tenantId, $providerId, [
            'authorizer_appid' => 'wx_authorizer_001',
            'authorizer_refresh_token' => 'refresh-1',
        ]);

        $this->auth()->deleteJson("/api/v1/admin/wechat/providers/{$providerId}")
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertNotNull(ComponentProvider::find($providerId));
    }

    public function test_provider_destroy_succeeds_without_authorizations(): void
    {
        $create = $this->auth()->postJson('/api/v1/admin/wechat/providers', $this->providerPayload());
        $providerId = $create->json('data.component_provider_id');

        $this->auth()->deleteJson("/api/v1/admin/wechat/providers/{$providerId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNull(ComponentProvider::find($providerId));
    }

    public function test_provider_test_returns_502_without_ticket(): void
    {
        $create = $this->auth()->postJson('/api/v1/admin/wechat/providers', $this->providerPayload());
        $providerId = $create->json('data.component_provider_id');

        // verify_ticket 未收到（平台侧未配置回调推送）→ 连接测试失败
        $this->auth()->postJson("/api/v1/admin/wechat/providers/{$providerId}/test")
            ->assertStatus(502)
            ->assertJsonPath('success', false);
    }

    public function test_provider_test_returns_token_prefix_when_ok(): void
    {
        $create = $this->auth()->postJson('/api/v1/admin/wechat/providers', $this->providerPayload());
        $providerId = $create->json('data.component_provider_id');

        app(WechatComponentService::class)->storeComponentVerifyTicket($providerId, 'ticket-abc');

        Http::fake([
            'api.weixin.qq.com/cgi-bin/component/api_component_token' => Http::response([
                'errcode' => 0,
                'component_access_token' => 'component-token-xyz',
                'expires_in' => 7200,
            ]),
        ]);

        $this->auth()->postJson("/api/v1/admin/wechat/providers/{$providerId}/test")
            ->assertOk()
            ->assertJsonPath('data.component_appid', 'wxb1234567890abcd')
            // testComponentToken 有意截断前缀展示（不暴露完整 token）
            ->assertJsonPath('data.access_token_prefix', 'componen…')
            ->assertJsonPath('data.expires_in', 7200);
    }

    public function test_authorizations_lists_tenants_with_type_label(): void
    {
        $create = $this->auth()->postJson('/api/v1/admin/wechat/providers', $this->providerPayload());
        $providerId = $create->json('data.component_provider_id');

        app(WechatComponentService::class)->saveAuthorization($this->tenantId, $providerId, [
            'authorizer_appid' => 'wx_authorizer_001',
            'authorizer_type' => Authorization::TYPE_OFFICIAL_ACCOUNT,
            'authorizer_refresh_token' => 'refresh-1',
            'nickname' => '蓝眼兔服务号',
        ]);

        $this->auth()->getJson('/api/v1/admin/wechat/authorizations')
            ->assertOk()
            ->assertJsonPath('data.0.tenant_id', $this->tenantId)
            ->assertJsonPath('data.0.tenant_name', 'Test Tenant')
            ->assertJsonPath('data.0.authorizer_appid', 'wx_authorizer_001')
            ->assertJsonPath('data.0.authorizer_type_label', '公众号')
            ->assertJsonPath('data.0.status', Authorization::STATUS_AUTHORIZED);
    }
}
