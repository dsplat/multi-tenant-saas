<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\Auth\Services\SocialiteService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Wechat\Models\ComponentProvider;
use MultiTenantSaas\Modules\Wechat\Services\WechatComponentService;
use MultiTenantSaas\Modules\Wechat\Services\WechatOAuthService;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\PluginModule;
use MultiTenantSaas\Tests\Schema\WechatModule;

/**
 * 第三方登录服务测试（wechat 双轨委托 + 互斥防御）
 *
 * 覆盖：wechat 分支委托 WechatOAuthService（getRedirectUrl / handleCallback /
 * isConfigured）、getOAuthConfigForDisplay 双轨展示（component 授权租户显示
 * authorizer_appid 与平台域回调）、updateOAuthConfig wechat 分支互斥防御
 * （已授权拒绝写自建 / 微信侧仍授权提示先取消 / 无授权正常写入）。
 */
class SocialiteServiceTest extends TestCase
{
    protected array $uses = [CoreModule::class, PluginModule::class, WechatModule::class];

    private int $tenantId = 9001;

    private SocialiteService $socialite;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        TenantContext::setTenantId($this->tenantId);
        config(['auth.oauth.callback_domain' => 'auth.neihang.com']);

        Tenant::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        $this->socialite = app(SocialiteService::class);
    }

    private function createProvider(): ComponentProvider
    {
        return ComponentProvider::create([
            'tenant_id' => null,
            'name' => 'Test Provider',
            'component_appid' => 'wx_component_test',
            'component_secret' => 'component-secret-123',
            'component_token' => 'cb-token',
            'encoding_aes_key' => 'aes-key-43-characters-padding',
            'callback_url' => 'https://auth.neihang.com/api/v1/wechat/message/callback',
            'status' => ComponentProvider::STATUS_ACTIVE,
        ]);
    }

    private function createAuthorized(): ComponentProvider
    {
        $provider = $this->createProvider();
        app(WechatComponentService::class)->storeComponentVerifyTicket((int) $provider->component_provider_id, 'ticket-abc');
        app(WechatComponentService::class)->saveAuthorization($this->tenantId, (int) $provider->component_provider_id, [
            'authorizer_appid' => 'wx_authorizer_001',
            'authorizer_refresh_token' => 'refresh-1',
        ]);

        return $provider;
    }

    // ==================================================================
    // wechat 分支委托（9.6 模块边界：WechatOAuthService 承载）
    // ==================================================================

    public function test_wechat_get_redirect_url_delegates_to_wechat_oauth_service(): void
    {
        $this->createAuthorized();

        $url = $this->socialite->getRedirectUrl('wechat', $this->tenantId);

        // component 模式：authorizer_appid 充当 appid，网页授权页 + 平台域回调
        $this->assertStringStartsWith('https://open.weixin.qq.com/connect/oauth2/authorize?', $url);
        $this->assertStringContainsString('appid=wx_authorizer_001', $url);
        $this->assertStringContainsString(urlencode('https://auth.neihang.com/api/v1/auth/wechat/callback'), $url);
        $this->assertStringContainsString('snsapi_userinfo', $url);
        $this->assertStringEndsWith('#wechat_redirect', $url);
    }

    public function test_wechat_handle_callback_delegates_to_wechat_oauth_service(): void
    {
        $this->createAuthorized();

        Http::fake([
            'api.weixin.qq.com/cgi-bin/component/api_component_token' => Http::response(['errcode' => 0, 'component_access_token' => 'ct', 'expires_in' => 7200]),
            'api.weixin.qq.com/sns/oauth2/component/access_token*' => Http::response([
                'access_token' => 'wx-at-1',
                'expires_in' => 7200,
                'openid' => 'openid-abc',
                'scope' => 'snsapi_userinfo',
            ]),
            'api.weixin.qq.com/sns/userinfo*' => Http::response([
                'openid' => 'openid-abc',
                'nickname' => '蓝眼兔',
                'errcode' => 0,
            ]),
        ]);

        // 生成一次性 state 并模拟回调请求参数（handleCallback 内部从 request 读取）
        $service = new class extends WechatOAuthService
        {
            public function exposeGenerateState(int $tenantId): string
            {
                return $this->generateState($tenantId, 'wechat');
            }
        };
        $state = $service->exposeGenerateState($this->tenantId);
        request()->merge(['code' => 'code-1', 'state' => $state]);

        $result = $this->socialite->handleCallback('wechat', $this->tenantId);

        $this->assertSame('蓝眼兔', $result['user']['name']);
        $this->assertNotEmpty($result['token']);
    }

    public function test_wechat_is_configured_delegates_to_wechat_oauth_service(): void
    {
        // 未配置
        $this->assertFalse($this->socialite->isConfigured($this->tenantId, 'wechat'));

        // 自建凭证满足
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_client_id', 'wx_self_app');
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_client_secret', 'self-secret', true);
        $this->assertTrue($this->socialite->isConfigured($this->tenantId, 'wechat'));

        // 第三方平台授权满足（不依赖 tenant_settings）
        $this->createAuthorized();
        $this->assertTrue($this->socialite->isConfigured($this->tenantId, 'wechat'));
    }

    // ==================================================================
    // getOAuthConfigForDisplay 双轨展示
    // ==================================================================

    public function test_display_self_mode_returns_self_credentials(): void
    {
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_client_id', 'wx_self_app');
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_client_secret', 'self-secret', true);

        $result = $this->socialite->getOAuthConfigForDisplay($this->tenantId);

        $this->assertSame('wx_self_app', $result['wechat']['client_id']);
        $this->assertSame('self-secret', $result['wechat']['client_secret']);
        $this->assertSame('self', $result['wechat']['mode']);
        $this->assertSame('h5', $result['wechat']['oauth_mode']);
        $this->assertTrue($result['wechat']['configured']);
    }

    public function test_display_pc_mode_uses_platform_callback_domain(): void
    {
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_client_id', 'wx_self_app');
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_client_secret', 'self-secret', true);
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_oauth_mode', 'pc');

        $result = $this->socialite->getOAuthConfigForDisplay($this->tenantId);

        $this->assertSame('pc', $result['wechat']['oauth_mode']);
        // pc 形态展示平台统一回调域（网站应用「授权回调域」配置在开放平台后台）
        $this->assertSame('https://auth.neihang.com/api/v1/auth/wechat/callback', $result['wechat']['redirect']);
    }

    public function test_display_component_mode_returns_authorizer_credentials(): void
    {
        $this->createAuthorized();

        $result = $this->socialite->getOAuthConfigForDisplay($this->tenantId);

        // component 授权租户：显示授权记录真实凭证与平台域回调，secret 永不出库
        $this->assertSame('wx_authorizer_001', $result['wechat']['client_id']);
        $this->assertSame('', $result['wechat']['client_secret']);
        $this->assertSame('component', $result['wechat']['mode']);
        $this->assertTrue($result['wechat']['configured']);
        $this->assertSame('https://auth.neihang.com/api/v1/auth/wechat/callback', $result['wechat']['redirect']);
    }

    // ==================================================================
    // updateOAuthConfig wechat 互斥防御
    // ==================================================================

    public function test_update_oauth_config_wechat_rejects_when_authorized(): void
    {
        $this->createAuthorized();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('已使用微信第三方平台授权');

        $this->socialite->updateOAuthConfig($this->tenantId, 'wechat', [
            'client_id' => 'wx_self_app',
            'client_secret' => 'self-secret',
        ]);
    }

    public function test_update_oauth_config_wechat_rejects_when_wechat_still_authorized(): void
    {
        // 两步式解除：本地 revoked 但微信侧仍授权 → 提交自建前探测提示先取消
        $provider = $this->createAuthorized();
        app(WechatComponentService::class)->markRevokedByAuthorizerAppid('wx_authorizer_001');

        Http::fake([
            'api.weixin.qq.com/cgi-bin/component/api_component_token' => Http::response(['errcode' => 0, 'component_access_token' => 'ct', 'expires_in' => 7200]),
            'api.weixin.qq.com/cgi-bin/component/api_authorizer_token*' => Http::response(['errcode' => 0, 'authorizer_access_token' => 'at', 'expires_in' => 7200]),
        ]);

        try {
            $this->socialite->updateOAuthConfig($this->tenantId, 'wechat', [
                'client_id' => 'wx_self_app',
                'client_secret' => 'self-secret',
            ]);
            $this->fail('应当抛出 DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('仍处于生效状态', $e->getMessage());
        }
    }

    public function test_update_oauth_config_wechat_writes_self_when_no_authorization(): void
    {
        $this->socialite->updateOAuthConfig($this->tenantId, 'wechat', [
            'client_id' => 'wx_self_app',
            'client_secret' => 'self-secret',
        ]);

        $this->assertSame('wx_self_app', TenantSetting::get($this->tenantId, 'oauth', 'wechat_client_id', ''));
        $this->assertSame('self-secret', TenantSetting::get($this->tenantId, 'oauth', 'wechat_client_secret', ''));
    }

    public function test_update_oauth_config_wechat_skips_secret_mask(): void
    {
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_client_id', 'wx_self_app');
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_client_secret', 'real-secret', true);

        // 掩码回存：client_secret 占位符跳过，不覆盖真实密钥
        $this->socialite->updateOAuthConfig($this->tenantId, 'wechat', [
            'client_id' => 'wx_self_app',
            'client_secret' => '********',
        ]);

        $this->assertSame('real-secret', TenantSetting::get($this->tenantId, 'oauth', 'wechat_client_secret', ''));
    }
}
