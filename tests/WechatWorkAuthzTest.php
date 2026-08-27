<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Auth\Services\WechatWorkOAuthService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Modules\WechatWork\Models\ServiceProvider;
use MultiTenantSaas\Modules\WechatWork\Models\WechatWorkAuthorization;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkSuiteService;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\RbacModule;
use MultiTenantSaas\Tests\Schema\WechatWorkModule;

/**
 * 企微代开发租户授权链路 + 登录凭证双轨适配测试
 *
 * 覆盖：status/authorize/revoke 租户端点（console 权限链）、公开回调
 * （state 校验 + auth_code 换 permanent_code 幂等入库）、WechatWorkOAuthService
 * 双轨解析（mode=suite 授权优先 / mode=self 回退 tenant_settings /
 * 未配置报错 / getAccessToken 分轨 / isConfigured 任一满足）。
 */
class WechatWorkAuthzTest extends TestCase
{
    protected array $uses = [CoreModule::class, RbacModule::class, WechatWorkModule::class];

    private int $tenantId = 9001;

    private Operator $operator;

    private WechatWorkSuiteService $suite;

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

        // tenant scope Operator + X-Tenant-ID：IdentifyTenant 经 OperatorTenant
        // 归属校验识别租户并写入 TenantContext（TenantContext 存于 Request
        // attributes，setUp 阶段设置对测试请求无效）；RBAC 由 tenant_admin 角色
        // 授权（setting.update 在 RbacModule 种子中已映射）
        TenantContext::setTenantId($this->tenantId);
        $this->operator = $operator;

        $this->suite = app(WechatWorkSuiteService::class);
    }

    /**
     * console 租户端测试认证：tenant 路由走 tenant.identify，需 guard 用户可解析
     * （Bearer token 在测试环境中 user() 解析为空，沿用现有 tenant 路由测试的
     * actingAs + X-Tenant-ID header 惯例）
     */
    private function auth(): static
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $this->tenantId);
    }

    private function createProvider(array $overrides = []): ServiceProvider
    {
        return ServiceProvider::create(array_merge([
            'tenant_id' => null,
            'name' => 'Test Provider',
            'provider_corp_id' => 'corp_provider',
            'provider_secret' => 'provider-secret-123',
            'suite_id' => 'ww_suite_test',
            'suite_secret' => 'suite-secret-123',
            'callback_token' => 'cb-token',
            'callback_url' => 'https://auth.neihang.com/api/v1/wechat-work/suite/callback',
            'status' => ServiceProvider::STATUS_ACTIVE,
        ], $overrides));
    }

    /**
     * 暴露 protected getConfig 供双轨断言
     */
    private function oauthService(): WechatWorkOAuthService
    {
        return new class extends WechatWorkOAuthService
        {
            public function exposeGetConfig(int $tenantId): array
            {
                return $this->getConfig($tenantId);
            }
        };
    }

    // ==================================================================
    // console 租户端点（tenant.identify + rbac.permission:setting.update）
    // ==================================================================

    public function test_status_returns_pending_when_not_authorized(): void
    {
        $this->auth()->getJson('/api/v1/tenant/wechat-work/status')
            ->assertOk()
            ->assertJsonPath('data.status', WechatWorkAuthorization::STATUS_PENDING)
            ->assertJsonPath('data.corp_id', null);
    }

    public function test_status_returns_template_permissions_when_pending(): void
    {
        $this->createProvider([
            'metadata' => ['template_permissions' => ['contact:read', 'message:send']],
        ]);

        // 未授权时也展示模板权限清单：租户扫码前即可知晓将获得哪些权限
        $this->auth()->getJson('/api/v1/tenant/wechat-work/status')
            ->assertOk()
            ->assertJsonPath('data.status', WechatWorkAuthorization::STATUS_PENDING)
            ->assertJsonPath('data.permissions.0.key', 'contact:read')
            ->assertJsonPath('data.permissions.0.label', ServiceProvider::TEMPLATE_PERMISSIONS['contact:read'])
            ->assertJsonPath('data.permissions.1.key', 'message:send');
    }

    public function test_status_returns_authorization_when_authorized(): void
    {
        $provider = $this->createProvider();
        $this->suite->saveAuthorization($this->tenantId, $provider->service_provider_id, [
            'corp_id' => 'ww_corp_1',
            'agent_id' => '1000001',
            'permanent_code' => 'perm-code-1',
        ]);

        $this->auth()->getJson('/api/v1/tenant/wechat-work/status')
            ->assertOk()
            ->assertJsonPath('data.status', WechatWorkAuthorization::STATUS_AUTHORIZED)
            ->assertJsonPath('data.corp_id', 'ww_corp_1')
            ->assertJsonPath('data.agent_id', '1000001');
    }

    public function test_authorize_returns_503_when_provider_unconfigured(): void
    {
        $this->auth()->postJson('/api/v1/tenant/wechat-work/authorize')
            ->assertStatus(503)
            ->assertJsonPath('success', false);
    }

    public function test_authorize_returns_qrcode_url_when_provider_ready(): void
    {
        $provider = $this->createProvider();
        $this->suite->storeSuiteTicket($provider->service_provider_id, 'ticket-abc');

        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/service/get_provider_token' => Http::response([
                'provider_access_token' => 'pt',
                'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/*' => Http::response([
                'errcode' => 0,
                'qrcode_url' => 'https://open.work.weixin.qq.com/wwopen/customApp/authorize?auth_code=abc',
                'expires_in' => 864000,
            ]),
        ]);

        $response = $this->auth()->postJson('/api/v1/tenant/wechat-work/authorize')
            ->assertOk()
            ->assertJsonPath('success', true);

        $url = $response->json('data.url');
        // 代开发模式返回企微授权二维码 URL（非 3rdapp/install）
        $this->assertStringStartsWith('https://open.work.weixin.qq.com/wwopen/customApp/authorize?', $url);

        // 请求体携带纯字母数字 state（16 位租户 ID 左补零 + 16 位随机）与模板 ID
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'get_customized_auth_url')) {
                return false;
            }

            $state = (string) $request['state'];

            return preg_match('/^\d{16}[a-zA-Z0-9]{16}$/', $state) === 1
                && strlen($state) === 32
                && $this->suite->tenantIdFromState($state) === $this->tenantId
                && $request['templateid_list'] === ['ww_suite_test'];
        });
    }

    public function test_authorize_returns_template_permissions(): void
    {
        $provider = $this->createProvider([
            'metadata' => ['template_permissions' => ['contact:read', 'external_contact:write']],
        ]);
        $this->suite->storeSuiteTicket($provider->service_provider_id, 'ticket-abc');

        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/service/get_provider_token' => Http::response([
                'provider_access_token' => 'pt',
                'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/*' => Http::response([
                'errcode' => 0,
                'qrcode_url' => 'https://open.work.weixin.qq.com/wwopen/customApp/authorize?auth_code=abc',
                'expires_in' => 864000,
            ]),
        ]);

        // 授权 URL 携带服务商声明的模板权限清单（key + 展示名，未知 key 原样展示）
        $this->auth()->postJson('/api/v1/tenant/wechat-work/authorize')
            ->assertOk()
            ->assertJsonPath('data.provider.name', 'Test Provider')
            ->assertJsonPath('data.provider.suite_id', 'ww_suite_test')
            ->assertJsonPath('data.provider.permissions.0.key', 'contact:read')
            ->assertJsonPath('data.provider.permissions.0.label', ServiceProvider::TEMPLATE_PERMISSIONS['contact:read'])
            ->assertJsonPath('data.provider.permissions.1.key', 'external_contact:write')
            ->assertJsonPath('data.provider.permissions.1.label', ServiceProvider::TEMPLATE_PERMISSIONS['external_contact:write']);
    }

    public function test_authorize_returns_unknown_permission_key_as_is(): void
    {
        $provider = $this->createProvider([
            'metadata' => ['template_permissions' => ['future:scope']],
        ]);
        $this->suite->storeSuiteTicket($provider->service_provider_id, 'ticket-abc');

        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/service/get_provider_token' => Http::response([
                'provider_access_token' => 'pt',
                'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/*' => Http::response([
                'errcode' => 0,
                'qrcode_url' => 'https://open.work.weixin.qq.com/wwopen/customApp/authorize?auth_code=abc',
                'expires_in' => 864000,
            ]),
        ]);

        $this->auth()->postJson('/api/v1/tenant/wechat-work/authorize')
            ->assertOk()
            ->assertJsonPath('data.provider.permissions.0.key', 'future:scope')
            ->assertJsonPath('data.provider.permissions.0.label', 'future:scope');
    }

    public function test_revoke_rejects_without_authorization(): void
    {
        $this->auth()->postJson('/api/v1/tenant/wechat-work/revoke')
            ->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_revoke_marks_revoked(): void
    {
        $provider = $this->createProvider();
        $this->suite->saveAuthorization($this->tenantId, $provider->service_provider_id, [
            'corp_id' => 'ww_corp_1',
            'agent_id' => '1000001',
            'permanent_code' => 'perm-code-1',
        ]);

        $this->auth()->postJson('/api/v1/tenant/wechat-work/revoke')
            ->assertOk()
            ->assertJsonPath('success', true);

        $authorization = $this->suite->authorization($this->tenantId);
        $this->assertSame(WechatWorkAuthorization::STATUS_REVOKED, $authorization->status);
    }

    // ==================================================================
    // 公开授权回跳（3rdapp/install redirect_uri）
    // ==================================================================

    public function test_callback_exchanges_code_and_saves_authorization(): void
    {
        $provider = $this->createProvider();
        $this->suite->storeSuiteTicket($provider->service_provider_id, 'ticket-abc');

        // 捕获 buildAuthorizeUrl 生成的 state（代开发二维码无 query 参数，从请求体提取）
        $capturedState = null;

        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/service/get_provider_token' => Http::response([
                'provider_access_token' => 'pt',
                'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/*' => function ($request) use (&$capturedState) {
                if (str_contains($request->url(), 'get_customized_auth_url')) {
                    $capturedState = (string) $request['state'];

                    return Http::response(['errcode' => 0, 'qrcode_url' => 'https://open.work.weixin.qq.com/wwopen/customApp/authorize?auth_code=abc', 'expires_in' => 864000]);
                }

                // get_suite_token：成功响应无 errcode，字段为 suite_access_token
                if (str_contains($request->url(), 'get_suite_token')) {
                    return Http::response(['suite_access_token' => 'st', 'expires_in' => 7200]);
                }

                // get_permanent_code：代开发模式原样返回扫码时的 state
                return Http::response([
                    'errcode' => 0,
                    'auth_corp_info' => ['corpid' => 'ww_corp_1', 'corp_name' => '蓝眼兔'],
                    'permanent_code' => 'perm-code-1',
                    'auth_info' => ['agent' => [['agentid' => 1000001]]],
                    'state' => $capturedState,
                ]);
            },
        ]);

        // 用真实链路生成 state（generateCustomizedState 已写入缓存，回调一次性校验）
        $this->suite->buildAuthorizeUrl($this->tenantId);
        $this->assertNotNull($capturedState, '应捕获到 get_customized_auth_url 请求的 state');

        $response = $this->get('/api/v1/wechat-work/callback?state=' . urlencode((string) $capturedState) . '&auth_code=auth-code-1');

        $response->assertStatus(200);
        $this->assertStringContainsString('授权成功', $response->getContent());

        // 公开回调请求无租户上下文（TenantContext 存于 Request attributes），
        // 断言前恢复上下文以通过 TenantScope 查询
        TenantContext::setTenantId($this->tenantId);

        $authorization = $this->suite->authorization($this->tenantId);
        $this->assertNotNull($authorization);
        $this->assertTrue($authorization->isAuthorized());
        $this->assertSame('ww_corp_1', $authorization->corp_id);
        $this->assertSame('perm-code-1', $authorization->permanent_code);
        $this->assertNotNull($authorization->authorized_at);
    }

    public function test_callback_rejects_state_without_tenant_prefix(): void
    {
        $response = $this->get('/api/v1/wechat-work/callback?state=forged-state&auth_code=auth-code-1');

        $response->assertStatus(200);
        $this->assertStringContainsString('授权失败', $response->getContent());
    }

    public function test_callback_rejects_invalid_state(): void
    {
        // 未生成过 state，缓存校验失败
        $response = $this->get('/api/v1/wechat-work/callback?state=9001.abcdefghijklmnopqrstuvwx&auth_code=auth-code-1');

        $response->assertStatus(200);
        $this->assertStringContainsString('授权失败', $response->getContent());
    }

    // ==================================================================
    // 登录凭证双轨（WechatWorkOAuthService）
    // ==================================================================

    public function test_get_config_prefers_suite_mode(): void
    {
        config(['auth.oauth.callback_domain' => 'auth.neihang.com']);

        $provider = $this->createProvider();
        $this->suite->saveAuthorization($this->tenantId, $provider->service_provider_id, [
            'corp_id' => 'ww_corp_1',
            'agent_id' => '1000001',
            'permanent_code' => 'perm-code-1',
        ]);

        // 自建应用凭证也在，但授权记录优先
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_work_corp_id', 'ww_self_corp');
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_work_secret', 'self-secret', true);

        $config = $this->oauthService()->exposeGetConfig($this->tenantId);

        $this->assertSame('suite', $config['mode']);
        $this->assertSame('ww_corp_1', $config['corp_id']);
        $this->assertSame('perm-code-1', $config['secret']);
        $this->assertSame('1000001', $config['agent_id']);
        // 代开发模式回调域 = 平台统一回调域（服务商代配可信域名）
        $this->assertSame('https://auth.neihang.com/api/v1/auth/wechat_work/callback', $config['redirect']);
    }

    public function test_get_config_falls_back_to_self_mode(): void
    {
        config(['auth.oauth.callback_domain' => 'auth.neihang.com']);

        TenantSetting::set($this->tenantId, 'oauth', 'wechat_work_corp_id', 'ww_self_corp');
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_work_secret', 'self-secret', true);
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_work_agent_id', '1000002');

        $config = $this->oauthService()->exposeGetConfig($this->tenantId);

        $this->assertSame('self', $config['mode']);
        $this->assertSame('ww_self_corp', $config['corp_id']);
        $this->assertSame('self-secret', $config['secret']);
        // TenantSetting 数字字符串经 JSON 解码为 int（自建模式原样返回）
        $this->assertSame(1000002, $config['agent_id']);
    }

    public function test_get_config_throws_when_unconfigured(): void
    {
        $this->expectException(ServiceUnavailableException::class);

        $this->oauthService()->exposeGetConfig($this->tenantId);
    }

    public function test_get_access_token_suite_mode_uses_get_corp_token(): void
    {
        $provider = $this->createProvider();
        $this->suite->saveAuthorization($this->tenantId, $provider->service_provider_id, [
            'corp_id' => 'ww_corp_1',
            'agent_id' => '1000001',
            'permanent_code' => 'perm-code-1',
        ]);
        $this->suite->storeSuiteTicket($provider->service_provider_id, 'ticket-abc');

        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/service/get_suite_token' => Http::response([
                'suite_access_token' => 'st',
                'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/cgi-bin/service/get_corp_token*' => Http::response([
                'errcode' => 0,
                'access_token' => 'corp-token-xyz',
                'expires_in' => 7200,
            ]),
        ]);

        $token = app(WechatWorkOAuthService::class)->getAccessToken($this->tenantId);

        $this->assertSame('corp-token-xyz', $token);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'get_corp_token')
            && $request['auth_corpid'] === 'ww_corp_1'
            && $request['permanent_code'] === 'perm-code-1');
    }

    public function test_get_access_token_self_mode_uses_gettoken(): void
    {
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_work_corp_id', 'ww_self_corp');
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_work_secret', 'self-secret', true);

        Http::fake([
            // gettoken 为 GET 请求带查询参数（?corpid=&corpsecret=），模式需带 * 通配
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0,
                'access_token' => 'self-token-abc',
                'expires_in' => 7200,
            ]),
        ]);

        $token = app(WechatWorkOAuthService::class)->getAccessToken($this->tenantId);

        $this->assertSame('self-token-abc', $token);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'gettoken')
            && $request['corpid'] === 'ww_self_corp'
            && $request['corpsecret'] === 'self-secret');
    }

    public function test_is_configured_either_mode(): void
    {
        // 两种模式均未配置
        $this->assertFalse(app(WechatWorkOAuthService::class)->isConfigured($this->tenantId));

        // 自建应用模式满足
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_work_corp_id', 'ww_self_corp');
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_work_secret', 'self-secret', true);
        $this->assertTrue(app(WechatWorkOAuthService::class)->isConfigured($this->tenantId));

        // 授权记录满足（不依赖 tenant_settings）
        $provider = $this->createProvider();
        $this->suite->saveAuthorization($this->tenantId, $provider->service_provider_id, [
            'corp_id' => 'ww_corp_2',
            'agent_id' => '1000003',
            'permanent_code' => 'perm-code-2',
        ]);
        $this->assertTrue(app(WechatWorkOAuthService::class)->isConfigured($this->tenantId));
    }
}
