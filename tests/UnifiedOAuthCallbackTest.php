<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use MultiTenantSaas\Modules\Auth\Services\Concerns\ManagesOAuthState;
use MultiTenantSaas\Modules\Auth\Services\IdentityProviderOAuthService;
use MultiTenantSaas\Modules\Auth\Services\SocialiteService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\PluginModule;
use MultiTenantSaas\Tests\Schema\SecurityModule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * OAuth 统一回调域（平台级虚拟 IDP）测试
 *
 * 覆盖回调域解析与完整链路：
 * - resolveRedirectUrl 自定义域名优先 / 显式覆盖 / 无自定义域名回退统一域 / 租户域回退
 * - state 携带租户前缀 + 上下文（origin_domain）往返，旧格式兼容
 * - 统一回调域下回调请求从 state 恢复租户并回跳来源域
 * - origin_domain 白名单校验（防 open redirect）
 * - IDP 委托模式不参与统一域
 */
class UnifiedOAuthCallbackTest extends TestCase
{
    protected array $uses = [
        CoreModule::class,
        PluginModule::class,
        SecurityModule::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // 统一回调域开关（每个测试独立配置，避免污染）
        config(['auth.oauth.callback_domain' => 'auth.neihang.com']);
        // 通配基础域（模块配置在测试环境未合并，显式补齐供 origin_domain 白名单校验）
        config(['domain.wildcard_base' => 'dsplat.com']);
        // 排除 TenantContext 兜底干扰 state 恢复断言
        config(['tenancy.default_tenant_id' => null]);
    }

    protected function createTestTenant(array $overrides = []): Tenant
    {
        return Tenant::create(array_merge([
            'tenant_id' => 1001,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
            'domain' => 'crm.test.com',
        ], $overrides));
    }

    protected function configureWechat(): void
    {
        TenantSetting::set(1001, 'oauth', 'wechat_client_id', 'wx-test-appid');
        TenantSetting::set(1001, 'oauth', 'wechat_client_secret', 'wx-test-secret', true);
    }

    /**
     * 暴露 trait 的 protected 方法供测试使用
     */
    protected function stateStore(): object
    {
        return new class
        {
            use ManagesOAuthState;

            public function gen(int $tenantId, string $provider, array $context = []): string
            {
                return $this->generateState($tenantId, $provider, $context);
            }

            public function verify(string $state, int $tenantId, string $provider): array
            {
                return $this->verifyState($state, $tenantId, $provider);
            }

            public function tenantFrom(string $state): ?int
            {
                return $this->tenantIdFromState($state);
            }
        };
    }

    // ==================== resolveRedirectUrl ====================

    public function test_resolve_redirect_url_prefers_custom_domain_over_unified(): void
    {
        $this->createTestTenant();

        // 微信/企微回调域要求备案主体与企业主体一致，租户自定义域名优先于平台统一域
        $url = app(SocialiteService::class)->resolveRedirectUrl(1001, 'wechat');

        $this->assertSame('https://crm.test.com/api/v1/auth/wechat/callback', $url);
    }

    public function test_resolve_redirect_url_falls_back_to_unified_domain_without_custom(): void
    {
        $this->createTestTenant(['domain' => '']);

        $url = app(SocialiteService::class)->resolveRedirectUrl(1001, 'wechat');

        $this->assertSame('https://auth.neihang.com/api/v1/auth/wechat/callback', $url);
    }

    public function test_resolve_redirect_url_stored_full_url_wins(): void
    {
        $this->createTestTenant();

        $url = app(SocialiteService::class)->resolveRedirectUrl(1001, 'wechat', 'https://custom.example.com/cb');

        $this->assertSame('https://custom.example.com/cb', $url);
    }

    public function test_resolve_redirect_url_falls_back_to_tenant_domain(): void
    {
        $this->createTestTenant();
        config(['auth.oauth.callback_domain' => '']);

        $url = app(SocialiteService::class)->resolveRedirectUrl(1001, 'wechat');

        $this->assertSame('https://crm.test.com/api/v1/auth/wechat/callback', $url);
    }

    public function test_resolve_tenant_redirect_url_ignores_unified_domain(): void
    {
        $this->createTestTenant();

        // IDP 委托模式专用：统一域配置下仍按租户域名推导
        $url = app(SocialiteService::class)->resolveTenantRedirectUrl(1001, 'wechat');

        $this->assertSame('https://crm.test.com/api/v1/auth/wechat/callback', $url);
    }

    // ==================== state 上下文 ====================

    public function test_state_roundtrip_with_context(): void
    {
        $this->createTestTenant();
        $store = $this->stateStore();

        $state = $store->gen(1001, 'wechat', ['origin_domain' => 'crm.test.com']);

        // 租户前缀：统一回调域下从 state 恢复租户
        $this->assertStringStartsWith('1001.', $state);
        $this->assertSame(1001, $store->tenantFrom($state));

        // 上下文往返：回调时取回 origin_domain
        $this->assertSame(['origin_domain' => 'crm.test.com'], $store->verify($state, 1001, 'wechat'));

        // 一次性使用：二次校验失败
        $this->expectException(HttpException::class);
        $store->verify($state, 1001, 'wechat');
    }

    public function test_legacy_state_format_still_verifies(): void
    {
        $this->createTestTenant();
        $store = $this->stateStore();

        // 旧格式：纯随机 40 字符，Cache 值为 true
        $legacy = Str::random(40);
        Cache::put(sprintf('oauth_state:wechat:%d:%s', 1001, hash('sha256', $legacy)), true, 600);

        $this->assertNull($store->tenantFrom($legacy));
        $this->assertSame([], $store->verify($legacy, 1001, 'wechat'));
    }

    public function test_forged_tenant_prefix_is_rejected(): void
    {
        $this->createTestTenant();
        $store = $this->stateStore();

        $state = $store->gen(1001, 'wechat', ['origin_domain' => 'crm.test.com']);
        $forged = '9999999999999999.' . substr($state, 17);

        // 篡改前缀 → 按伪造租户 ID 校验 Cache 不存在 → 拒绝
        $this->expectException(HttpException::class);
        $store->verify($forged, 9999999999999999, 'wechat');
    }

    // ==================== 授权跳转（origin_domain 透传） ====================

    public function test_redirect_endpoint_uses_unified_redirect_and_state_prefix(): void
    {
        // 无自定义域名的租户走平台统一回调域；经 TenantContext 兜底识别租户，
        // state 携带租户前缀供回调时恢复（真实场景由 IdentifyTenant 中间件设置上下文）
        $this->createTestTenant(['domain' => '']);
        $this->configureWechat();
        config(['tenancy.default_tenant_id' => '1001']);

        $response = $this->getJson('/api/v1/auth/wechat/redirect?origin_domain=1001.dsplat.com');

        $response->assertOk();
        $url = $response->json('data.url');
        $this->assertStringContainsString('redirect_uri=' . urlencode('https://auth.neihang.com/api/v1/auth/wechat/callback'), $url);
        $this->assertStringContainsString('state=1001.', $url);
    }

    public function test_redirect_endpoint_uses_custom_domain_redirect(): void
    {
        $this->createTestTenant();
        $this->configureWechat();

        $response = $this->getJson('/api/v1/auth/wechat/redirect?domain=crm.test.com&origin_domain=crm.test.com');

        $response->assertOk();
        $url = $response->json('data.url');
        $this->assertStringContainsString('redirect_uri=' . urlencode('https://crm.test.com/api/v1/auth/wechat/callback'), $url);
        $this->assertStringContainsString('state=1001.', $url);
    }

    public function test_redirect_endpoint_rejects_foreign_origin_domain(): void
    {
        $this->createTestTenant();
        $this->configureWechat();

        $response = $this->getJson('/api/v1/auth/wechat/redirect?domain=crm.test.com&origin_domain=evil.com');

        $response->assertStatus(422);
    }

    public function test_redirect_endpoint_accepts_wildcard_subdomain_origin(): void
    {
        $this->createTestTenant();
        $this->configureWechat();

        // {tenant_id}.{wildcard_base} 形态的来源域属于租户合法接入域
        $response = $this->getJson('/api/v1/auth/wechat/redirect?domain=crm.test.com&origin_domain=1001.dsplat.com');

        $response->assertOk();
    }

    // ==================== 统一域回调端到端 ====================

    public function test_callback_recovers_tenant_from_state_and_redirects_to_origin(): void
    {
        $this->createTestTenant();
        $this->configureWechat();

        // 真实签发的 state（含租户前缀 + origin_domain 上下文）
        $state = $this->stateStore()->gen(1001, 'wechat', ['origin_domain' => 'crm.test.com']);

        // 模拟微信 API
        Http::fake([
            '*/sns/oauth2/access_token*' => Http::response(['access_token' => 'at-test', 'openid' => 'oX-test', 'unionid' => 'u-test', 'expires_in' => 7200]),
            '*/sns/userinfo*' => Http::response(['nickname' => '测试用户', 'headimgurl' => 'https://x/a.png']),
        ]);

        // 统一回调域回调：无 domain 参数、无租户上下文，仅 state 携带租户
        $response = $this->get("/api/v1/auth/wechat/callback?state={$state}&code=wx-code");

        // 租户恢复成功 → 走到正常登录链路 → 302 回跳 H5
        $response->assertStatus(302);
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://crm.test.com/h5/', $location);
        // wechat 用户无有效联系方式 → 最小注册分支（needs_bindcontact）
        $this->assertStringContainsString('needs_bindcontact=1', $location);
    }

    public function test_callback_without_state_prefix_still_works_via_domain_param(): void
    {
        $this->createTestTenant();
        $this->configureWechat();

        // 旧链路：租户域回调（domain 参数显式指定租户），state 无前缀（旧格式）
        $state = $this->stateStore()->gen(1001, 'wechat', []);

        Http::fake([
            '*/sns/oauth2/access_token*' => Http::response(['access_token' => 'at-test', 'openid' => 'oX-test', 'unionid' => 'u-test', 'expires_in' => 7200]),
            '*/sns/userinfo*' => Http::response(['nickname' => '测试用户', 'headimgurl' => 'https://x/a.png']),
        ]);

        $response = $this->get("/api/v1/auth/wechat/callback?state={$state}&code=wx-code&domain=crm.test.com");

        $response->assertStatus(302);
        $this->assertStringStartsWith('https://crm.test.com/h5/', $response->headers->get('Location'));
    }

    public function test_callback_with_unknown_state_prefix_returns_404(): void
    {
        $this->createTestTenant();
        $this->configureWechat();

        // 伪造的前缀指向不存在的租户
        $response = $this->get('/api/v1/auth/wechat/callback?state=9999999999999999.fake&code=wx-code');

        $response->assertStatus(404);
    }

    // ==================== IDP 委托模式 ====================

    public function test_idp_callback_url_keeps_tenant_domain_under_unified_domain(): void
    {
        $this->createTestTenant();
        TenantSetting::set(1001, 'oauth', 'oauth_mode', 'delegated');
        TenantSetting::set(1001, 'oauth', 'idp_base_url', 'https://id.lanyantu.com');

        $url = app(IdentityProviderOAuthService::class)->resolveCallbackUrl(1001, 'wechat');

        // 委托模式不参与统一回调域：微信回调域归企业 IDP 管理
        $this->assertSame('https://crm.test.com/api/v1/auth/wechat/callback', $url);
    }

    public function test_idp_standard_state_carries_origin_domain(): void
    {
        $this->createTestTenant();
        TenantSetting::set(1001, 'oauth', 'oauth_mode', 'delegated');
        TenantSetting::set(1001, 'oauth', 'idp_base_url', 'https://id.lanyantu.com');
        TenantSetting::set(1001, 'oauth', 'idp_protocol', 'standard');
        TenantSetting::set(1001, 'oauth', 'idp_client_id', 'scrm_prod');
        TenantSetting::set(1001, 'oauth', 'idp_client_secret', 'secret', true);

        $url = app(IdentityProviderOAuthService::class)->getRedirectUrl(1001, 'wechat', 'crm.test.com');

        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $state = $params['state'] ?? '';
        $this->assertNotEmpty($state);

        // 上下文存于 cache：租户 + 来源域
        $cached = Cache::get("idp_state:{$state}");
        $this->assertSame(['tenant_id' => 1001, 'origin_domain' => 'crm.test.com'], $cached);
    }
}
