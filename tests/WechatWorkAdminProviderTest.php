<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Modules\WechatWork\Models\ServiceProvider;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\RbacModule;
use MultiTenantSaas\Tests\Schema\WechatWorkModule;

/**
 * 平台管理后台 - 企微服务商凭证 CRUD 测试
 *
 * 覆盖：服务商凭证创建/更新/列表，模板权限集（metadata.template_permissions）
 * 的声明与回读（服务商在平台声明权限，租户扫码授权即一次性获得全部权限）、
 * provider_secret / suite_secret / encoding_aes_key 掩码安全。
 */
class WechatWorkAdminProviderTest extends TestCase
{
    protected array $uses = [CoreModule::class, RbacModule::class, WechatWorkModule::class];

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

        Tenant::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
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
            'name' => '蓝眼兔服务商',
            'provider_corp_id' => 'corp_provider',
            'provider_secret' => 'provider-secret-123',
            'suite_id' => 'ww_suite_test',
            'suite_secret' => 'suite-secret-123',
            'callback_token' => 'cb-token',
            'encoding_aes_key' => 'aes-key-123',
            'callback_url' => 'https://auth.neihang.com/api/v1/wechat-work/suite/callback',
            'status' => 'active',
            'permissions' => ['contact:read', 'message:send'],
        ], $overrides);
    }

    public function test_provider_store_persists_permissions(): void
    {
        $response = $this->auth()->postJson('/api/v1/admin/wechat-work/providers', $this->providerPayload([
            'permissions' => ['contact:read', 'message:send', 'custom:scope'],
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.permissions', ['contact:read', 'message:send', 'custom:scope'])
            ->assertJsonPath('data.provider_secret', '********');

        // 权限集落库于 metadata.template_permissions（去重保序）
        $provider = ServiceProvider::where('suite_id', 'ww_suite_test')->first();
        $this->assertNotNull($provider);
        $this->assertSame(
            ['contact:read', 'message:send', 'custom:scope'],
            $provider->metadata['template_permissions'],
        );
    }

    public function test_provider_index_returns_permissions(): void
    {
        $this->auth()->postJson('/api/v1/admin/wechat-work/providers', $this->providerPayload());

        $this->auth()->getJson('/api/v1/admin/wechat-work/providers')
            ->assertOk()
            ->assertJsonPath('data.0.permissions', ['contact:read', 'message:send'])
            ->assertJsonPath('data.0.name', '蓝眼兔服务商');
    }

    public function test_provider_update_replaces_permissions(): void
    {
        $create = $this->auth()->postJson('/api/v1/admin/wechat-work/providers', $this->providerPayload());
        $providerId = $create->json('data.service_provider_id');

        $this->auth()->putJson("/api/v1/admin/wechat-work/providers/{$providerId}", $this->providerPayload([
            'permissions' => ['external_contact:read'],
        ]))
            ->assertOk()
            ->assertJsonPath('data.permissions', ['external_contact:read']);

        $provider = ServiceProvider::find($providerId);
        $this->assertSame(['external_contact:read'], $provider->metadata['template_permissions']);
    }

    public function test_provider_store_rejects_invalid_permissions(): void
    {
        $this->auth()->postJson('/api/v1/admin/wechat-work/providers', $this->providerPayload([
            'permissions' => ['contact:read', 123],
        ]))
            ->assertStatus(422);
    }

    public function test_provider_returns_masked_secrets_and_plain_callback_token(): void
    {
        $create = $this->auth()->postJson('/api/v1/admin/wechat-work/providers', $this->providerPayload());

        $create->assertCreated()
            ->assertJsonPath('data.provider_secret', '********')
            ->assertJsonPath('data.suite_secret', '********')
            ->assertJsonPath('data.encoding_aes_key', '********')
            ->assertJsonPath('data.callback_token', 'cb-token');
    }

    public function test_provider_app_callback_credentials_round_trip_with_mask(): void
    {
        // 模板级应用回调凭证：token 明文返回，aes key 掩码；更新时掩码/留空不覆盖
        $create = $this->auth()->postJson('/api/v1/admin/wechat-work/providers', $this->providerPayload([
            'app_callback_token' => 'app-cb-token',
            'app_encoding_aes_key' => 'app-aes-key',
        ]));

        $create->assertCreated()
            ->assertJsonPath('data.app_callback_token', 'app-cb-token')
            ->assertJsonPath('data.app_encoding_aes_key', '********');

        $providerId = $create->json('data.service_provider_id');

        // 掩码回存不覆盖真实密钥
        $this->auth()->putJson("/api/v1/admin/wechat-work/providers/{$providerId}", $this->providerPayload([
            'app_callback_token' => 'app-cb-token',
            'app_encoding_aes_key' => '********',
        ]))->assertOk();

        $provider = ServiceProvider::find($providerId);
        $this->assertSame('app-cb-token', $provider->app_callback_token);
        $this->assertSame('app-aes-key', $provider->app_encoding_aes_key);
    }
}
