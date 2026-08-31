<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Modules\Wechat\Models\Authorization;
use MultiTenantSaas\Modules\Wechat\Models\ComponentProvider;
use MultiTenantSaas\Modules\Wechat\Services\WechatComponentService;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\RbacModule;
use MultiTenantSaas\Tests\Schema\WechatModule;

/**
 * 微信第三方平台租户授权链路测试
 *
 * 覆盖：status/authorize/revoke/capability 租户端点（console 权限链）、
 * 单向状态对账（微信侧已解除 → 本地标记 revoked / 探测失败保持现状）、
 * 两步式解除下的恢复对账（本地 revoked + 微信侧仍授权 → 重新授权直接恢复）。
 */
class WechatAuthzTest extends TestCase
{
    protected array $uses = [CoreModule::class, RbacModule::class, WechatModule::class];

    private int $tenantId = 9001;

    private Operator $operator;

    private WechatComponentService $component;

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
        ]);

        $admin = User::create([
            'user_id' => 9001,
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'tenant_id' => $this->tenantId,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        // console 租户端需 tenant scope Operator：IdentifyTenant 对 platform
        // Operator 直接放行（不建立租户上下文），必须走 header 归属校验分支
        $operator = Operator::create([
            'email' => 'admin@test.com',
            'name' => 'Admin',
            'scope' => 'tenant',
            'is_active' => true,
        ]);

        $tenantAdminRoleId = DB::table('roles')
            ->where('name', 'tenant_admin')
            ->whereNull('tenant_id')
            ->value('role_id');

        OperatorTenant::create([
            'operator_id' => $operator->operator_id,
            'tenant_id' => $this->tenantId,
            'user_id' => $admin->user_id,
            'role' => 'tenant_admin',
            'role_id' => $tenantAdminRoleId,
            'is_active' => true,
            'accepted_at' => now(),
        ]);

        DB::table('tenant_users')->insert([
            'tenant_id' => $this->tenantId,
            'user_id' => $admin->user_id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TenantContext::setTenantId($this->tenantId);
        $this->operator = $operator;

        $this->component = app(WechatComponentService::class);
    }

    /**
     * console 租户端测试认证：tenant 路由走 tenant.identify，需 guard 用户可解析
     * （沿用现有 tenant 路由测试的 actingAs + X-Tenant-ID header 惯例）
     */
    private function auth(): static
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $this->tenantId);
    }

    private function createProvider(array $overrides = []): ComponentProvider
    {
        return ComponentProvider::create(array_merge([
            'tenant_id' => null,
            'name' => 'Test Provider',
            'component_appid' => 'wx_component_test',
            'component_secret' => 'component-secret-123',
            'component_token' => 'cb-token',
            'encoding_aes_key' => 'aes-key-43-characters-padding',
            'callback_url' => 'https://auth.neihang.com/api/v1/wechat/message/callback',
            'status' => ComponentProvider::STATUS_ACTIVE,
            'metadata' => ['permissions' => ['authorize:userinfo', 'message:receive']],
        ], $overrides));
    }

    /**
     * 建已授权租户记录（ticket + 授权行），供状态/解除测试
     */
    private function createAuthorized(string $appid = 'wx_authorizer_001'): ComponentProvider
    {
        $provider = $this->createProvider();
        $this->component->storeComponentVerifyTicket((int) $provider->component_provider_id, 'ticket-abc');
        $this->component->saveAuthorization($this->tenantId, (int) $provider->component_provider_id, [
            'authorizer_appid' => $appid,
            'authorizer_refresh_token' => 'refresh-1',
        ]);

        return $provider;
    }

    // ==================================================================
    // console 租户端点（tenant.identify + rbac.permission:setting.update）
    // ==================================================================

    public function test_status_returns_pending_when_not_authorized(): void
    {
        $this->auth()->getJson('/api/v1/tenant/wechat/status')
            ->assertOk()
            ->assertJsonPath('data.status', Authorization::STATUS_PENDING)
            ->assertJsonPath('data.authorizer_appid', null);
    }

    public function test_status_returns_permissions_and_callback_when_pending(): void
    {
        $this->createProvider();

        // 未授权时也展示组件权限集与回调链路：租户授权前即可知晓将获得哪些权限
        $this->auth()->getJson('/api/v1/tenant/wechat/status')
            ->assertOk()
            ->assertJsonPath('data.status', Authorization::STATUS_PENDING)
            ->assertJsonPath('data.permissions.0.key', 'authorize:userinfo')
            ->assertJsonPath('data.permissions.0.label', ComponentProvider::TEMPLATE_PERMISSIONS['authorize:userinfo'])
            ->assertJsonPath('data.permissions.1.key', 'message:receive')
            ->assertJsonPath('data.callback.callback_url', 'https://auth.neihang.com/api/v1/wechat/message/callback')
            ->assertJsonPath('data.callback.authorize_callback_url', fn ($value) => str_contains((string) $value, '/api/v1/wechat/authorize/callback'));
    }

    public function test_status_returns_authorization_when_authorized(): void
    {
        $this->createAuthorized();

        // 状态对账：微信侧仍授权（api_authorizer_token 成功）→ 本地 authorized 保持
        Http::fake([
            'api.weixin.qq.com/cgi-bin/component/api_component_token' => Http::response(['errcode' => 0, 'component_access_token' => 'ct', 'expires_in' => 7200]),
            'api.weixin.qq.com/cgi-bin/component/api_authorizer_token*' => Http::response(['errcode' => 0, 'authorizer_access_token' => 'at', 'expires_in' => 7200]),
        ]);

        $this->auth()->getJson('/api/v1/tenant/wechat/status')
            ->assertOk()
            ->assertJsonPath('data.status', Authorization::STATUS_AUTHORIZED)
            ->assertJsonPath('data.authorizer_appid', 'wx_authorizer_001')
            ->assertJsonPath('data.authorizer_type', Authorization::TYPE_OFFICIAL_ACCOUNT);
    }

    public function test_status_marks_revoked_when_wechat_released(): void
    {
        $this->createAuthorized();

        // 本地 authorized 但微信侧已解除（unauthorized 事件丢失场景）→ 对账标记 revoked
        Http::fake([
            'api.weixin.qq.com/cgi-bin/component/api_component_token' => Http::response(['errcode' => 0, 'component_access_token' => 'ct', 'expires_in' => 7200]),
            'api.weixin.qq.com/cgi-bin/component/api_authorizer_token*' => Http::response(['errcode' => 61003, 'errmsg' => 'no authorized relationship']),
        ]);

        $this->auth()->getJson('/api/v1/tenant/wechat/status')
            ->assertOk()
            ->assertJsonPath('data.status', Authorization::STATUS_REVOKED);

        $authorization = $this->component->authorization($this->tenantId);
        $this->assertSame(Authorization::STATUS_REVOKED, $authorization->status);
        $this->assertNotNull($authorization->revoked_at);
    }

    public function test_status_keeps_state_when_probe_fails(): void
    {
        // 无 verify_ticket → 探测失败（null）→ 保持现状，不误伤 authorized
        $provider = $this->createProvider();
        $this->component->saveAuthorization($this->tenantId, (int) $provider->component_provider_id, [
            'authorizer_appid' => 'wx_authorizer_001',
            'authorizer_refresh_token' => 'refresh-1',
        ]);

        $this->auth()->getJson('/api/v1/tenant/wechat/status')
            ->assertOk()
            ->assertJsonPath('data.status', Authorization::STATUS_AUTHORIZED);
    }

    public function test_authorize_returns_503_when_provider_unconfigured(): void
    {
        $this->auth()->postJson('/api/v1/tenant/wechat/authorize')
            ->assertStatus(503)
            ->assertJsonPath('success', false);
    }

    public function test_authorize_returns_authorize_url_when_provider_ready(): void
    {
        $provider = $this->createProvider();
        $this->component->storeComponentVerifyTicket((int) $provider->component_provider_id, 'ticket-abc');

        Http::fake([
            'api.weixin.qq.com/cgi-bin/component/api_component_token' => Http::response(['errcode' => 0, 'component_access_token' => 'ct', 'expires_in' => 7200]),
            // 注意: api_create_preauthcode 请求带 ?component_access_token= 查询参数,
            // Laravel Str::is 全串匹配, 模式必须带 * 通配才能命中
            'api.weixin.qq.com/cgi-bin/component/api_create_preauthcode*' => Http::response(['errcode' => 0, 'pre_auth_code' => 'pre-auth-1', 'expires_in' => 1800]),
        ]);

        // 测试环境未设 OAUTH_CALLBACK_DOMAIN，请求前显式指定平台回调域（接口即返回 auth 域 URL）
        config(['auth.oauth.callback_domain' => 'auth.neihang.com']);

        $response = $this->auth()->postJson('/api/v1/tenant/wechat/authorize')
            ->assertOk()
            ->assertJsonPath('success', true);

        $url = $response->json('data.url');
        // 统一认证域发起（非微信授权页直链）：launch 端点 302 到微信授权页，
        // 满足微信「授权发起页域名」的跳转来源校验（租户任意域名均可发起）
        $this->assertStringStartsWith('https://auth.neihang.com/api/v1/wechat/authorize/launch?', $url);

        $query = [];
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
        $state = (string) ($query['state'] ?? '');
        $this->assertMatchesRegularExpression('/^\d{16}[a-zA-Z0-9]{16}$/', $state);
        $this->assertSame($this->tenantId, $this->component->tenantIdFromState($state));
        $this->assertSame('3', $query['auth_type']);
        $this->assertSame('pc', $query['mode']);

        $response->assertJsonPath('data.provider.component_appid', 'wx_component_test')
            ->assertJsonPath('data.provider.permissions.0.key', 'authorize:userinfo');
    }

    public function test_start_authorization_recovers_revoked_when_wechat_still_authorized(): void
    {
        $provider = $this->createAuthorized();
        $this->component->markRevokedByAuthorizerAppid('wx_authorizer_001');

        // 本地 revoked 但微信侧仍授权（两步式解除后想恢复）→ 直接恢复，无需重新授权
        Http::fake([
            'api.weixin.qq.com/cgi-bin/component/api_component_token' => Http::response(['errcode' => 0, 'component_access_token' => 'ct', 'expires_in' => 7200]),
            'api.weixin.qq.com/cgi-bin/component/api_authorizer_token*' => Http::response(['errcode' => 0, 'authorizer_access_token' => 'at', 'expires_in' => 7200]),
        ]);

        $this->auth()->postJson('/api/v1/tenant/wechat/authorize')
            ->assertOk()
            ->assertJsonPath('data.recovered', true);

        $authorization = $this->component->authorization($this->tenantId);
        $this->assertSame(Authorization::STATUS_AUTHORIZED, $authorization->status);
        $this->assertNull($authorization->revoked_at);
    }

    public function test_revoke_rejects_without_authorization(): void
    {
        $this->auth()->postJson('/api/v1/tenant/wechat/revoke')
            ->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_revoke_marks_revoked_with_guidance(): void
    {
        // 两步式第一步：仅解除本地映射（微信服务商不能主动解除授权）
        $this->createAuthorized();

        $this->auth()->postJson('/api/v1/tenant/wechat/revoke')
            ->assertOk()
            ->assertJsonPath('success', true);

        $authorization = $this->component->authorization($this->tenantId);
        $this->assertSame(Authorization::STATUS_REVOKED, $authorization->status);
        $this->assertNotNull($authorization->revoked_at);
    }

    // ==================================================================
    // capability（双轨登录模式 + 组件就绪状态）
    // ==================================================================

    public function test_capability_reports_none_when_unconfigured(): void
    {
        $this->auth()->getJson('/api/v1/tenant/wechat/capability')
            ->assertOk()
            ->assertJsonPath('data.provider_ready', false)
            ->assertJsonPath('data.login_mode', 'none');
    }

    public function test_capability_reports_self_when_self_configured(): void
    {
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_client_id', 'wx_self_app');
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_client_secret', 'self-secret', true);

        $this->auth()->getJson('/api/v1/tenant/wechat/capability')
            ->assertOk()
            ->assertJsonPath('data.provider_ready', false)
            ->assertJsonPath('data.login_mode', 'self');
    }

    public function test_capability_reports_component_when_authorized(): void
    {
        $this->createAuthorized();

        $this->auth()->getJson('/api/v1/tenant/wechat/capability')
            ->assertOk()
            ->assertJsonPath('data.provider_ready', true)
            ->assertJsonPath('data.provider_name', 'Test Provider')
            ->assertJsonPath('data.login_mode', 'component')
            ->assertJsonPath('data.authorize_callback_url', fn ($value) => str_contains((string) $value, '/api/v1/wechat/authorize/callback'));
    }
}
