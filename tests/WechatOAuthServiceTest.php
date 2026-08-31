<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Auth\Models\OauthAccount;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Wechat\Models\Authorization;
use MultiTenantSaas\Modules\Wechat\Models\ComponentProvider;
use MultiTenantSaas\Modules\Wechat\Services\WechatComponentService;
use MultiTenantSaas\Modules\Wechat\Services\WechatOAuthService;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\PluginModule;
use MultiTenantSaas\Tests\Schema\WechatModule;

/**
 * 微信登录双轨服务测试
 *
 * 覆盖：getConfig 双轨判定（component 授权优先 / self 回退 tenant_settings /
 * 未配置报错 / 授权表缺失防御式回退）、handleCallback 分轨换 token
 * （component 走 sns/oauth2/component/access_token，self 走 oauth2/access_token）、
 * 用户查找创建与 OAuth 账号记录、isConfigured 任一满足。
 */
class WechatOAuthServiceTest extends TestCase
{
    protected array $uses = [CoreModule::class, PluginModule::class, WechatModule::class];

    private int $tenantId = 9001;

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
    }

    /**
     * 暴露 protected getConfig / generateState 供双轨与回调链路断言
     */
    private function oauthService(): WechatOAuthService
    {
        return new class extends WechatOAuthService
        {
            public function exposeGetConfig(int $tenantId): array
            {
                return $this->getConfig($tenantId);
            }

            public function exposeGenerateState(int $tenantId): string
            {
                return $this->generateState($tenantId, 'wechat', ['origin_domain' => '']);
            }
        };
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
            'callback_url' => 'https://auth.neihang.com/api/v1/wechat/component/callback',
            'status' => ComponentProvider::STATUS_ACTIVE,
        ]);
    }

    /**
     * 建已授权租户记录（component 模式前置）
     */
    private function createAuthorized(string $appid = 'wx_authorizer_001'): ComponentProvider
    {
        $provider = $this->createProvider();
        app(WechatComponentService::class)->storeComponentVerifyTicket((int) $provider->component_provider_id, 'ticket-abc');
        app(WechatComponentService::class)->saveAuthorization($this->tenantId, (int) $provider->component_provider_id, [
            'authorizer_appid' => $appid,
            'authorizer_refresh_token' => 'refresh-1',
        ]);

        return $provider;
    }

    private function configureSelf(): void
    {
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_client_id', 'wx_self_app');
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_client_secret', 'self-secret', true);
    }

    // ==================================================================
    // 双轨判定（getConfig）
    // ==================================================================

    public function test_get_config_prefers_component_mode(): void
    {
        $this->createAuthorized();
        // 自建应用凭证也在，但授权记录优先
        $this->configureSelf();

        $config = $this->oauthService()->exposeGetConfig($this->tenantId);

        $this->assertSame('component', $config['mode']);
        $this->assertSame('wx_authorizer_001', $config['app_id']);
        $this->assertSame('', $config['secret']);
        // component 模式回调域 = 平台统一回调域（第三方平台代配回调域名）
        $this->assertSame('https://auth.neihang.com/api/v1/auth/wechat/callback', $config['redirect']);
    }

    public function test_get_config_falls_back_to_self_mode(): void
    {
        $this->configureSelf();

        $config = $this->oauthService()->exposeGetConfig($this->tenantId);

        $this->assertSame('self', $config['mode']);
        $this->assertSame('wx_self_app', $config['app_id']);
        $this->assertSame('self-secret', $config['secret']);
    }

    public function test_get_config_throws_when_unconfigured(): void
    {
        $this->expectException(ServiceUnavailableException::class);

        $this->oauthService()->exposeGetConfig($this->tenantId);
    }

    public function test_get_config_falls_back_when_authorization_table_missing(): void
    {
        // 防御式：下游未拆包/未迁移（表不存在）→ 授权记录读不到，回退自建模式
        $this->configureSelf();
        $this->createAuthorized();

        Schema::shouldReceive('hasTable')->with('wechat_authorizations')->andReturn(false);

        $config = $this->oauthService()->exposeGetConfig($this->tenantId);

        $this->assertSame('self', $config['mode']);
        $this->assertSame('wx_self_app', $config['app_id']);
    }

    // ==================================================================
    // 回调换 token（双轨分轨）
    // ==================================================================

    public function test_component_mode_callback_exchanges_via_component_endpoint(): void
    {
        $provider = $this->createAuthorized();

        Http::fake([
            'api.weixin.qq.com/cgi-bin/component/api_component_token' => Http::response(['errcode' => 0, 'component_access_token' => 'ct', 'expires_in' => 7200]),
            // component 换 token 为 GET 带查询参数，模式需带 * 通配
            'api.weixin.qq.com/sns/oauth2/component/access_token*' => Http::response([
                'access_token' => 'wx-at-1',
                'expires_in' => 7200,
                'openid' => 'openid-abc',
                'unionid' => 'unionid-xyz',
                'scope' => 'snsapi_userinfo',
            ]),
            'api.weixin.qq.com/sns/userinfo*' => Http::response([
                'openid' => 'openid-abc',
                'unionid' => 'unionid-xyz',
                'nickname' => '蓝眼兔',
                'headimgurl' => 'https://mmbiz.qpic.cn/head',
                'errcode' => 0,
            ]),
        ]);

        $service = $this->oauthService();
        $state = $service->exposeGenerateState($this->tenantId);
        request()->merge(['code' => 'code-1', 'state' => $state]);

        $result = $service->handleCallback($this->tenantId);

        $this->assertSame('蓝眼兔', $result['user']['name']);
        $this->assertNotEmpty($result['token']);

        // component 端点携带 component_appid + component_access_token（非 secret）
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sns/oauth2/component/access_token')
                && $request['appid'] === 'wx_authorizer_001'
                && $request['component_appid'] === 'wx_component_test'
                && $request['component_access_token'] === 'ct'
                && $request['code'] === 'code-1';
        });

        // OAuth 账号记录：provider 命名空间隔离 + unionid 冗余
        $account = OauthAccount::where('provider', 'wechat:tenant:9001')
            ->where('provider_id', 'openid-abc')
            ->first();
        $this->assertNotNull($account);
        $this->assertSame('unionid-xyz', $account->unionid);
        $this->assertSame('wx_authorizer_001', $account->appid);
    }

    public function test_self_mode_callback_exchanges_via_self_endpoint(): void
    {
        $this->configureSelf();

        Http::fake([
            'api.weixin.qq.com/sns/oauth2/access_token*' => Http::response([
                'access_token' => 'self-at-1',
                'expires_in' => 7200,
                'openid' => 'openid-self',
                'scope' => 'snsapi_userinfo',
            ]),
            'api.weixin.qq.com/sns/userinfo*' => Http::response([
                'openid' => 'openid-self',
                'nickname' => '自建用户',
                'headimgurl' => 'https://mmbiz.qpic.cn/head',
                'errcode' => 0,
            ]),
        ]);

        $service = $this->oauthService();
        $state = $service->exposeGenerateState($this->tenantId);
        request()->merge(['code' => 'code-2', 'state' => $state]);

        $result = $service->handleCallback($this->tenantId);

        $this->assertSame('自建用户', $result['user']['name']);
        $this->assertNotEmpty($result['token']);

        // self 端点携带 appid + secret（非 component 参数）
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sns/oauth2/access_token')
                && $request['appid'] === 'wx_self_app'
                && $request['secret'] === 'self-secret'
                && $request['code'] === 'code-2';
        });

        $account = OauthAccount::where('provider', 'wechat:tenant:9001')
            ->where('provider_id', 'openid-self')
            ->first();
        $this->assertNotNull($account);
        $this->assertSame('wx_self_app', $account->appid);
    }

    public function test_callback_reuses_existing_user_by_openid(): void
    {
        $this->createAuthorized();

        Http::fake([
            'api.weixin.qq.com/cgi-bin/component/api_component_token' => Http::response(['errcode' => 0, 'component_access_token' => 'ct', 'expires_in' => 7200]),
            'api.weixin.qq.com/sns/oauth2/component/access_token*' => Http::response([
                'access_token' => 'wx-at-2',
                'expires_in' => 7200,
                'openid' => 'openid-repeat',
                'scope' => 'snsapi_userinfo',
            ]),
            'api.weixin.qq.com/sns/userinfo*' => Http::response([
                'openid' => 'openid-repeat',
                'nickname' => '蓝眼兔',
                'errcode' => 0,
            ]),
        ]);

        $service = $this->oauthService();
        $state = $service->exposeGenerateState($this->tenantId);
        request()->merge(['code' => 'code-3', 'state' => $state]);

        $first = $service->handleCallback($this->tenantId);

        // 二次登录：同 openid 命中已有 OAuth 账号与用户（不重复建号）
        $state = $service->exposeGenerateState($this->tenantId);
        request()->merge(['code' => 'code-4', 'state' => $state]);

        $second = $service->handleCallback($this->tenantId);

        $this->assertSame($first['user']['user_id'], $second['user']['user_id']);
        $this->assertSame(1, OauthAccount::where('provider', 'wechat:tenant:9001')->where('provider_id', 'openid-repeat')->count());
    }

    // ==================================================================
    // isConfigured
    // ==================================================================

    public function test_is_configured_either_mode(): void
    {
        // 两种模式均未配置
        $this->assertFalse(app(WechatOAuthService::class)->isConfigured($this->tenantId));

        // 自建应用模式满足
        $this->configureSelf();
        $this->assertTrue(app(WechatOAuthService::class)->isConfigured($this->tenantId));

        // 授权记录满足（不依赖 tenant_settings）
        $this->createAuthorized();
        $this->assertTrue(app(WechatOAuthService::class)->isConfigured($this->tenantId));
    }

    public function test_is_configured_falls_back_to_self_when_table_missing(): void
    {
        $this->createAuthorized();
        $this->configureSelf();
        Schema::shouldReceive('hasTable')->with('wechat_authorizations')->andReturn(false);

        // 表缺失防御：授权记录读不到，但自建凭证满足 → 仍视为已配置
        $this->assertTrue(app(WechatOAuthService::class)->isConfigured($this->tenantId));
    }
}
