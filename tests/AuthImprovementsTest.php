<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\OauthAccount;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Auth\Services\AlipayOAuthService;
use MultiTenantSaas\Modules\Auth\Services\SocialiteService;
use MultiTenantSaas\Modules\Auth\Services\WechatWorkOAuthService;
use MultiTenantSaas\Modules\Domain\Services\NginxConfigService;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\BindSessionDomain;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\IdentifyTenant;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Infrastructure\Services\MailerService;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\PluginModule;
use MultiTenantSaas\Tests\Schema\SecurityModule;
use Symfony\Component\HttpFoundation\Response;

/**
 * 认证体系改进测试
 *
 * 覆盖 auth_plan.md 中 Phase 1-3 的核心改进项：
 * - Session Cookie 域名动态绑定
 * - OAuth provider 命名空间化
 * - 未识别域名 403 拒绝 + 通配子域名兜底
 * - 租户发现 API
 * - 企业微信 OAuth 服务
 * - 租户级 SMTP
 * - Nginx 通配白名单
 */
class AuthImprovementsTest extends TestCase
{
    protected SocialiteService $socialiteService;

    protected array $uses = [
        CoreModule::class,
        PluginModule::class,
        SecurityModule::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->socialiteService = $this->app->make(SocialiteService::class);
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

    // =============================================
    // Phase 1: Session Cookie 域名动态绑定
    // =============================================

    public function test_bind_session_domain_sets_cookie_domain(): void
    {
        $middleware = new BindSessionDomain;

        $request = Request::create('https://crm.test.com/login');
        $request->headers->set('X-Original-Host', 'crm.test.com');

        $response = $middleware->handle($request, function () {
            $this->assertEquals('crm.test.com', config('session.domain'));

            return new Response;
        });

        $this->assertInstanceOf(Response::class, $response);
    }

    public function test_bind_session_domain_uses_request_host_without_header(): void
    {
        $middleware = new BindSessionDomain;

        $request = Request::create('https://another.test.com/login');

        $response = $middleware->handle($request, function () {
            $this->assertEquals('another.test.com', config('session.domain'));

            return new Response;
        });

        $this->assertInstanceOf(Response::class, $response);
    }

    // =============================================
    // Phase 1: OAuth provider 命名空间化
    // =============================================

    public function test_namespaced_provider_format(): void
    {
        $this->assertEquals('wechat:tenant:1001', $this->socialiteService->namespacedProvider('wechat', 1001));
        $this->assertEquals('wechat_work:tenant:999', $this->socialiteService->namespacedProvider('wechat_work', 999));
        $this->assertEquals('alipay:tenant:42', $this->socialiteService->namespacedProvider('alipay', 42));
    }

    public function test_oauth_account_base_provider_extraction(): void
    {
        $account = new OauthAccount;
        $account->provider = 'wechat_work:tenant:1001';
        $this->assertEquals('wechat_work', $account->getBaseProvider());
        $this->assertTrue($account->isWechatWork());
        $this->assertFalse($account->isDingTalk());

        $account2 = new OauthAccount;
        $account2->provider = 'dingtalk:tenant:999';
        $this->assertEquals('dingtalk', $account2->getBaseProvider());
        $this->assertTrue($account2->isDingTalk());

        $account3 = new OauthAccount;
        $account3->provider = 'feishu:tenant:42';
        $this->assertTrue($account3->isFeishu());
    }

    // =============================================
    // Phase 1: 未识别域名 403 + 通配子域名
    // =============================================

    public function test_identify_tenant_resolves_domain(): void
    {
        $this->createTestTenant();

        $middleware = new IdentifyTenant;
        $request = Request::create('https://crm.test.com/api/v1/test');
        $request->headers->set('X-Original-Host', 'crm.test.com');

        $middleware->handle($request, function () {
            $this->assertEquals('1001', TenantContext::getId());

            return new Response;
        });
    }

    public function test_identify_tenant_wildcard_subdomain_resolves_default(): void
    {
        config(['tenancy.default_tenant_id' => 1001]);
        config(['domain.wildcard_base' => 'scrm.com']);
        $this->createTestTenant();

        $middleware = new IdentifyTenant;
        $request = Request::create('https://arthur.scrm.com/api/v1/test');
        $request->headers->set('X-Original-Host', 'arthur.scrm.com');

        $middleware->handle($request, function () {
            $this->assertEquals('1001', TenantContext::getId());

            return new Response;
        });
    }

    public function test_identify_tenant_wildcard_subdomain_resolves_by_slug(): void
    {
        config(['tenancy.default_tenant_id' => 9999]);
        config(['domain.wildcard_base' => 'dsplat.com']);

        // 创建 slug=lanyantu 的租户
        Tenant::create([
            'tenant_id' => 2001,
            'name' => 'LanYanTu',
            'slug' => 'lanyantu',
            'status' => 'active',
            'domain' => 'lanyantu.dsplat.com',
        ]);

        $middleware = new IdentifyTenant;
        $request = Request::create('https://lanyantu.dsplat.com/h5/');
        $request->headers->set('X-Original-Host', 'lanyantu.dsplat.com');

        $middleware->handle($request, function () {
            // 应解析到 slug 对应的租户 2001，而非默认 9999
            $this->assertEquals('2001', TenantContext::getId());

            return new Response;
        });
    }

    public function test_identify_tenant_wildcard_subdomain_resolves_by_tenant_id(): void
    {
        config(['tenancy.default_tenant_id' => 9999]);
        config(['domain.wildcard_base' => 'dsplat.com']);

        // 16 位雪花 ID 租户（与自动码 t-xxxxxx 同质的兜底访问形态）
        Tenant::create([
            'tenant_id' => 9007199254740993,
            'name' => 'IdSubdomain',
            'slug' => 't-abc123',
            'status' => 'active',
        ]);
        Tenant::create([
            'tenant_id' => 9999,
            'name' => 'Default',
            'slug' => 'default',
            'status' => 'active',
        ]);

        $middleware = new IdentifyTenant;
        $request = Request::create('https://9007199254740993.dsplat.com/h5/');
        $request->headers->set('X-Original-Host', '9007199254740993.dsplat.com');

        $middleware->handle($request, function () {
            // 纯数字 16 位子域名按 tenant_id 直查，而非默认租户
            $this->assertEquals('9007199254740993', TenantContext::getId());

            return new Response;
        });
    }

    public function test_identify_tenant_wildcard_subdomain_short_numeric_not_treated_as_id(): void
    {
        config(['tenancy.default_tenant_id' => 9999]);
        config(['domain.wildcard_base' => 'dsplat.com']);

        // 短数字不符合雪花 ID 长度，走 slug 查询（无命中）→ 兜底默认租户
        Tenant::create([
            'tenant_id' => 2001,
            'name' => 'LanYanTu',
            'slug' => 'lanyantu',
            'status' => 'active',
        ]);
        Tenant::create([
            'tenant_id' => 9999,
            'name' => 'Default',
            'slug' => 'default',
            'status' => 'active',
        ]);

        $middleware = new IdentifyTenant;
        $request = Request::create('https://2001.dsplat.com/h5/');
        $request->headers->set('X-Original-Host', '2001.dsplat.com');

        $middleware->handle($request, function () {
            $this->assertEquals('9999', TenantContext::getId());

            return new Response;
        });
    }

    public function test_identify_tenant_wildcard_subdomain_unknown_slug_falls_back_default(): void
    {
        config(['tenancy.default_tenant_id' => 9999]);
        config(['domain.wildcard_base' => 'dsplat.com']);

        // 创建一个不相关的租户
        Tenant::create([
            'tenant_id' => 2001,
            'name' => 'LanYanTu',
            'slug' => 'lanyantu',
            'status' => 'active',
            'domain' => 'lanyantu.dsplat.com',
        ]);
        // 默认租户
        Tenant::create([
            'tenant_id' => 9999,
            'name' => 'Default',
            'slug' => 'default',
            'status' => 'active',
        ]);

        $middleware = new IdentifyTenant;
        $request = Request::create('https://unknown-opc.dsplat.com/h5/');
        $request->headers->set('X-Original-Host', 'unknown-opc.dsplat.com');

        $middleware->handle($request, function () {
            // slug "unknown-opc" 不存在，应 fallback 到默认租户
            $this->assertEquals('9999', TenantContext::getId());

            return new Response;
        });
    }

    public function test_identify_tenant_unrecognized_domain_returns_null(): void
    {
        config(['domain.wildcard_base' => 'scrm.com']);
        config(['tenancy.default_tenant_id' => null]);

        $middleware = new IdentifyTenant;
        $request = Request::create('https://evil-domain.com/api/v1/test');
        $request->headers->set('X-Original-Host', 'evil-domain.com');

        $middleware->handle($request, function () {
            $this->assertNull(TenantContext::getId());

            return new Response;
        });
    }

    // =============================================
    // 残留 X-Tenant-ID 回退（会话误 403 修复回归）
    // =============================================

    public function test_identify_tenant_stale_header_falls_back_to_domain(): void
    {
        $this->createTestTenant();

        $user = User::create([
            'name' => 'Stale Header User',
            'email' => 'stale-header@example.com',
            'password' => bcrypt('password123'),
            'role' => 'platform_user',
        ]);

        // 已认证 User 携带不属于自己的残留 X-Tenant-ID：忽略 header，回退域名解析
        $middleware = new IdentifyTenant;
        $request = Request::create('https://crm.test.com/api/v1/test');
        $request->headers->set('X-Original-Host', 'crm.test.com');
        $request->headers->set('X-Tenant-ID', '9999');
        $request->setUserResolver(fn () => $user);

        $middleware->handle($request, function () {
            $this->assertEquals('1001', TenantContext::getId());

            return new Response;
        });
    }

    public function test_identify_tenant_operator_stale_header_falls_back_to_active_tenant(): void
    {
        config(['tenancy.default_tenant_id' => null]);
        $this->createTestTenant();

        $operator = Operator::create([
            'name' => 'Stale Header Operator',
            'email' => 'stale-header-op@example.com',
            'password' => bcrypt('password123'),
            'scope' => 'tenant',
            'status' => 'active',
        ]);
        OperatorTenant::create([
            'operator_id' => $operator->operator_id,
            'tenant_id' => 1001,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        // Operator 在未识别域名下携带无权访问的残留 X-Tenant-ID：忽略 header，回退首个活跃关联
        $middleware = new IdentifyTenant;
        $request = Request::create('https://unknown-host.com/api/v1/test');
        $request->headers->set('X-Original-Host', 'unknown-host.com');
        $request->headers->set('X-Tenant-ID', '8888');
        $request->setUserResolver(fn () => $operator);

        $middleware->handle($request, function () {
            $this->assertEquals('1001', TenantContext::getId());

            return new Response;
        });
    }

    public function test_operator_me_not_403_when_default_tenant_differs(): void
    {
        // 生产事故回归：default_tenant_id 指向其他租户时，模块 API 路由
        // （auth → throttle → tenant.identify → VerifyOperatorTenant 顺序）
        // 不得因归属校验早于租户识别 fallback 到默认租户而误判 403。
        config(['tenancy.default_tenant_id' => '9999']);
        $this->createTestTenant();

        $operator = Operator::create([
            'name' => 'Default Tenant Victim',
            'email' => 'default-victim-op@example.com',
            'password' => bcrypt('password123'),
            'scope' => 'tenant',
            'is_active' => true,
        ]);
        OperatorTenant::create([
            'operator_id' => $operator->operator_id,
            'tenant_id' => 1001,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($operator, 'sanctum')
            ->withHeaders(['X-Original-Host' => 'crm.test.com'])
            ->getJson('/api/v1/operator-auth/me');

        $response->assertStatus(200);
    }

    // =============================================
    // Phase 2: 租户发现 API
    // =============================================

    public function test_tenant_resolve_api_returns_tenant_info(): void
    {
        $this->createTestTenant([
            'branding' => ['primary_color' => '#ff0000', 'login_page_message' => 'Welcome'],
        ]);

        $response = $this->getJson('/api/v1/tenant/resolve?domain=crm.test.com');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tenant_id', 1001)
            ->assertJsonPath('data.name', 'Test Tenant')
            ->assertJsonPath('data.branding.primary_color', '#ff0000');
    }

    public function test_tenant_resolve_api_returns_404_for_unknown_domain(): void
    {
        $response = $this->getJson('/api/v1/tenant/resolve?domain=unknown.com');

        $response->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_tenant_resolve_api_falls_back_to_host_without_domain_param(): void
    {
        // domain 参数可选：缺省时从请求 Host 解析，测试环境 Host 无对应租户 → 404
        $response = $this->getJson('/api/v1/tenant/resolve');

        $response->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_login_config_api_returns_oauth_providers(): void
    {
        $this->createTestTenant();

        // 配置一个 OAuth provider
        TenantSetting::set(1001, 'oauth', 'github_client_id', 'test-client-id');
        TenantSetting::set(1001, 'oauth', 'github_client_secret', 'test-secret');

        $response = $this->getJson('/api/v1/tenant/login-config?domain=crm.test.com');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['login_methods', 'oauth_providers', 'sso_providers', 'allow_register']]);
    }

    // =============================================
    // Phase 3a: 企业微信 OAuth 服务
    // =============================================

    public function test_wechat_work_service_can_be_resolved(): void
    {
        $this->assertInstanceOf(WechatWorkOAuthService::class, app(WechatWorkOAuthService::class));
    }

    public function test_wechat_work_is_configured_returns_false_without_config(): void
    {
        $this->createTestTenant();

        $service = app(WechatWorkOAuthService::class);
        $this->assertFalse($service->isConfigured(1001));
    }

    public function test_wechat_work_is_configured_returns_true_with_config(): void
    {
        $this->createTestTenant();

        TenantSetting::set(1001, 'oauth', 'wechat_work_corp_id', 'ww1234567890');
        TenantSetting::set(1001, 'oauth', 'wechat_work_secret', 'test-secret', true);

        $service = app(WechatWorkOAuthService::class);
        $this->assertTrue($service->isConfigured(1001));
    }

    public function test_wechat_work_authorize_url_contains_corp_id(): void
    {
        $this->createTestTenant();

        TenantSetting::set(1001, 'oauth', 'wechat_work_corp_id', 'ww1234567890');
        TenantSetting::set(1001, 'oauth', 'wechat_work_agent_id', '1000002');
        TenantSetting::set(1001, 'oauth', 'wechat_work_secret', 'test-secret', true);
        TenantSetting::set(1001, 'oauth', 'wechat_work_redirect', 'https://crm.test.com/auth/wechat_work/callback');

        $service = app(WechatWorkOAuthService::class);
        $url = $service->getAuthorizeUrl(1001);

        $this->assertStringContainsString('open.work.weixin.qq.com', $url);
        $this->assertStringContainsString('appid=ww1234567890', $url);
        $this->assertStringContainsString('agentid=1000002', $url);
    }

    public function test_wechat_work_throws_without_config(): void
    {
        $this->createTestTenant();

        $service = app(WechatWorkOAuthService::class);

        $this->expectException(\RuntimeException::class);
        $service->getAuthorizeUrl(1001);
    }

    // =============================================
    // Phase 3a: SocialiteService 支持 wechat_work
    // =============================================

    public function test_supported_providers_includes_wechat_work(): void
    {
        $providers = $this->socialiteService->getSupportedProviders();

        $this->assertArrayHasKey('wechat_work', $providers);
        $this->assertArrayHasKey('alipay', $providers);
    }

    public function test_get_redirect_url_delegates_to_wechat_work(): void
    {
        $this->createTestTenant();

        TenantSetting::set(1001, 'oauth', 'wechat_work_corp_id', 'ww1234567890');
        TenantSetting::set(1001, 'oauth', 'wechat_work_agent_id', '1000002');
        TenantSetting::set(1001, 'oauth', 'wechat_work_secret', 'test-secret', true);

        $url = $this->socialiteService->getRedirectUrl('wechat_work', 1001);

        $this->assertStringContainsString('open.work.weixin.qq.com', $url);
    }

    // =============================================
    // Phase 3b: 租户级 SMTP
    // =============================================

    public function test_mailer_service_can_be_resolved(): void
    {
        $this->assertInstanceOf(MailerService::class, app(MailerService::class));
    }

    public function test_mailer_uses_global_smtp_without_tenant_config(): void
    {
        config(['mail.default' => 'log']);
        $this->createTestTenant();

        $service = app(MailerService::class);
        $result = $service->sendRaw('test@example.com', 'Test', '<p>Hello</p>', 1001);

        $this->assertTrue($result);
    }

    public function test_mailer_send_test_with_tenant_id(): void
    {
        config(['mail.default' => 'log']);
        $this->createTestTenant();

        $service = app(MailerService::class);
        $result = $service->sendTest('admin@test.com', 1001);

        $this->assertTrue($result);
    }

    // =============================================
    // Phase 3c: Nginx 通配白名单
    // =============================================

    public function test_nginx_config_generates_explicit_subdomain_entries(): void
    {
        config(['domain.wildcard_base' => 'scrm.com']);
        config(['domain.platform_domains.admin' => 'admin.scrm.com']);
        config(['domain.platform_domains.console' => 'console.scrm.com']);

        // 已配置 slug 的活跃租户 → 精确放行
        $this->createTestTenant(['tenant_id' => 1001, 'slug' => 'acme', 'slug_status' => 'active', 'domain' => null]);
        // slug 被打回 → 不放行
        $this->createTestTenant(['tenant_id' => 1002, 'slug' => 'rejected', 'slug_status' => 'rejected', 'domain' => null]);

        $service = new NginxConfigService;
        $outputPath = sys_get_temp_dir() . '/test-domains-' . uniqid() . '.map';

        $service->generateDomainWhitelistMap($outputPath);

        $content = file_get_contents($outputPath);
        unlink($outputPath);

        // 精确放行已配置 slug
        $this->assertStringContainsString('acme.scrm.com', $content);
        // 平台域名（含 console）
        $this->assertStringContainsString('admin.scrm.com', $content);
        $this->assertStringContainsString('console.scrm.com', $content);
        // 被打回的 slug 不放行
        $this->assertStringNotContainsString('rejected.scrm.com', $content);
        // 安全关键：不再通配放行所有子域名
        $this->assertStringNotContainsString('~^.*\.scrm\.com$', $content);
    }

    public function test_nginx_config_without_wildcard_base(): void
    {
        config(['domain.wildcard_base' => null]);
        config(['domain.platform_domains.admin' => 'admin.example.com']);

        $service = new NginxConfigService;
        $outputPath = sys_get_temp_dir() . '/test-domains-' . uniqid() . '.map';

        $service->generateDomainWhitelistMap($outputPath);

        $content = file_get_contents($outputPath);
        unlink($outputPath);

        $this->assertStringContainsString('未配置通配基础域名', $content);
    }

    // =============================================
    // OAuth 配置展示（含 wechat_work / alipay 特殊字段）
    // =============================================

    public function test_oauth_config_display_includes_wechat_work_fields(): void
    {
        $this->createTestTenant();

        TenantSetting::set(1001, 'oauth', 'wechat_work_corp_id', 'ww1234567890');
        TenantSetting::set(1001, 'oauth', 'wechat_work_agent_id', '1000002');
        TenantSetting::set(1001, 'oauth', 'wechat_work_secret', 'test-secret', true);

        $config = $this->socialiteService->getOAuthConfigForDisplay(1001);

        $this->assertArrayHasKey('wechat_work', $config);
        $this->assertTrue($config['wechat_work']['configured']);
        $this->assertEquals('ww1234567890', $config['wechat_work']['corp_id']);
        $this->assertEquals('1000002', $config['wechat_work']['agent_id']);
    }

    public function test_oauth_config_display_includes_alipay_fields(): void
    {
        $this->createTestTenant();

        TenantSetting::set(1001, 'oauth', 'alipay_app_id', '2021001234');
        TenantSetting::set(1001, 'oauth', 'alipay_private_key', 'test-key', true);

        $config = $this->socialiteService->getOAuthConfigForDisplay(1001);

        $this->assertArrayHasKey('alipay', $config);
        $this->assertTrue($config['alipay']['configured']);
        $this->assertEquals('2021001234', $config['alipay']['app_id']);
    }

    public function test_oauth_config_display_returns_plaintext_secrets(): void
    {
        $this->createTestTenant();

        TenantSetting::set(1001, 'oauth', 'wechat_work_corp_id', 'ww123');
        TenantSetting::set(1001, 'oauth', 'wechat_work_secret', 'real-secret', true);
        TenantSetting::set(1001, 'oauth', 'github_client_id', 'gh-id');
        TenantSetting::set(1001, 'oauth', 'github_client_secret', 'gh-secret', true);

        $config = $this->socialiteService->getOAuthConfigForDisplay(1001);

        // 明文回显（存储层加密，展示端透明解密供租户管理员核对）
        $this->assertEquals('real-secret', $config['wechat_work']['secret']);
        $this->assertEquals('gh-secret', $config['github']['client_secret']);
        // 未配置时返回空串
        $this->assertEquals('', $config['dingtalk']['client_secret']);
    }

    public function test_oauth_config_display_includes_idp_section(): void
    {
        $this->createTestTenant();

        TenantSetting::set(1001, 'oauth', 'oauth_mode', 'delegated');
        TenantSetting::set(1001, 'oauth', 'idp_base_url', 'https://id.example.com');
        TenantSetting::set(1001, 'oauth', 'idp_protocol', 'standard');
        TenantSetting::set(1001, 'oauth', 'idp_client_secret', 'idp-secret', true);

        $config = $this->socialiteService->getOAuthConfigForDisplay(1001);

        $this->assertArrayHasKey('idp', $config);
        $this->assertTrue($config['idp']['enabled']);
        $this->assertEquals('https://id.example.com', $config['idp']['base_url']);
        $this->assertEquals('standard', $config['idp']['protocol']);
        $this->assertEquals('idp-secret', $config['idp']['client_secret']);
    }

    public function test_update_oauth_config_idp_maps_enabled_to_oauth_mode(): void
    {
        $this->createTestTenant();

        $this->socialiteService->updateOAuthConfig(1001, 'idp', [
            'enabled' => true,
            'base_url' => 'https://id.example.com',
            'protocol' => 'standard',
            'client_id' => 'scrm_prod',
            'client_secret' => 'top-secret',
        ]);

        $this->assertEquals('delegated', TenantSetting::get(1001, 'oauth', 'oauth_mode'));
        $this->assertEquals('https://id.example.com', TenantSetting::get(1001, 'oauth', 'idp_base_url'));
        $this->assertEquals('top-secret', TenantSetting::get(1001, 'oauth', 'idp_client_secret'));
        // enabled 不应作为独立 key 写入
        $this->assertNull(TenantSetting::get(1001, 'oauth', 'idp_enabled'));

        // 关闭回 direct
        $this->socialiteService->updateOAuthConfig(1001, 'idp', ['enabled' => false]);
        $this->assertEquals('direct', TenantSetting::get(1001, 'oauth', 'oauth_mode'));
    }

    public function test_update_oauth_config_skips_masked_secret(): void
    {
        $this->createTestTenant();

        TenantSetting::set(1001, 'oauth', 'wechat_work_secret', 'original-secret', true);

        $this->socialiteService->updateOAuthConfig(1001, 'wechat_work', [
            'corp_id' => 'ww-new',
            'secret' => '********',
        ]);

        $this->assertEquals('ww-new', TenantSetting::get(1001, 'oauth', 'wechat_work_corp_id'));
        $this->assertEquals('original-secret', TenantSetting::get(1001, 'oauth', 'wechat_work_secret'));
    }

    public function test_is_configured_delegates_correctly(): void
    {
        $this->createTestTenant();

        // 未配置时
        $this->assertFalse($this->socialiteService->isConfigured(1001, 'wechat_work'));
        $this->assertFalse($this->socialiteService->isConfigured(1001, 'alipay'));
        $this->assertFalse($this->socialiteService->isConfigured(1001, 'github'));

        // 配置 wechat_work
        TenantSetting::set(1001, 'oauth', 'wechat_work_corp_id', 'ww123');
        TenantSetting::set(1001, 'oauth', 'wechat_work_secret', 'sec', true);
        $this->assertTrue($this->socialiteService->isConfigured(1001, 'wechat_work'));

        // 配置 github（标准 client_id/secret）
        TenantSetting::set(1001, 'oauth', 'github_client_id', 'gh-id');
        TenantSetting::set(1001, 'oauth', 'github_client_secret', 'gh-sec');
        $this->assertTrue($this->socialiteService->isConfigured(1001, 'github'));
    }

    // =============================================
    // OAuth State: Cache-based (no session)
    // =============================================

    public function test_wechat_work_state_uses_cache_not_session(): void
    {
        $this->createTestTenant();

        TenantSetting::set(1001, 'oauth', 'wechat_work_corp_id', 'ww1234567890');
        TenantSetting::set(1001, 'oauth', 'wechat_work_agent_id', '1000002');
        TenantSetting::set(1001, 'oauth', 'wechat_work_secret', 'test-secret', true);

        $service = app(WechatWorkOAuthService::class);
        $url = $service->getAuthorizeUrl(1001);

        // 从 URL 提取 state 参数
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $state = $params['state'] ?? '';

        $this->assertNotEmpty($state);
        $this->assertEquals(40, strlen($state));

        // 验证 state 存在于 Cache
        $cacheKey = sprintf('oauth_state:wechat_work:%d:%s', 1001, hash('sha256', $state));
        $this->assertTrue(Cache::has($cacheKey));
    }

    public function test_alipay_state_uses_cache_not_session(): void
    {
        $this->createTestTenant();

        TenantSetting::set(1001, 'oauth', 'alipay_app_id', '2021001234');
        TenantSetting::set(1001, 'oauth', 'alipay_private_key', 'test-key', true);

        $service = app(AlipayOAuthService::class);
        $url = $service->getAuthorizeUrl(1001);

        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $state = $params['state'] ?? '';

        $this->assertNotEmpty($state);

        $cacheKey = sprintf('oauth_state:alipay:%d:%s', 1001, hash('sha256', $state));
        $this->assertTrue(Cache::has($cacheKey));
    }
}
