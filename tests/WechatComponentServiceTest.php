<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Wechat\Models\Authorization;
use MultiTenantSaas\Modules\Wechat\Models\ComponentProvider;
use MultiTenantSaas\Modules\Wechat\Services\WechatComponentService;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\WechatModule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 微信第三方平台组件服务测试
 *
 * 覆盖：component_access_token（verify_ticket 缺失报错 / 缓存提前过期 / API 错误透传）、
 * pre_auth_code 缓存、buildAuthorizeUrl（PC/H5 授权页 + state 租户前缀编码 + auth_type=3）、
 * exchangeAuthorization 解析、authorizerAccessToken 缓存、tenantIdFromState、
 * verifyAuthorizationState 一次性防重放、testComponentToken 诊断、授权入库幂等
 * （allowUnscoped）、markRevokedByAuthorizerAppid、isStillAuthorizedOnWechat 三态探测。
 */
class WechatComponentServiceTest extends TestCase
{
    protected array $uses = [CoreModule::class, WechatModule::class];

    private const APPID = 'wx_component_test';

    private const SECRET = 'component-secret-123';

    private const TICKET = 'ticket-abc';

    private WechatComponentService $component;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        // TenantScope fail-closed：无租户上下文时 WHERE 1=0，统一绑定测试租户
        TenantContext::setTenantId(9001);

        $this->component = app(WechatComponentService::class);
    }

    private function createProvider(array $overrides = []): ComponentProvider
    {
        return ComponentProvider::create(array_merge([
            'tenant_id' => null,
            'name' => 'Test Provider',
            'component_appid' => self::APPID,
            'component_secret' => self::SECRET,
            'component_token' => 'cb-token',
            'encoding_aes_key' => 'aes-key-43-characters-padding',
            'callback_url' => 'https://auth.neihang.com/api/v1/wechat/message/callback',
            'status' => ComponentProvider::STATUS_ACTIVE,
        ], $overrides));
    }

    // ==================================================================
    // component_access_token
    // ==================================================================

    public function test_component_access_token_requires_ticket(): void
    {
        $provider = $this->createProvider();

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('verify_ticket 缺失');

        $this->component->componentAccessToken($provider);
    }

    public function test_component_access_token_fetches_and_caches(): void
    {
        $provider = $this->createProvider();
        $this->component->storeComponentVerifyTicket($provider->component_provider_id, self::TICKET);

        Http::fake([
            // 微信 api_component_token 成功响应带 errcode=0
            'api.weixin.qq.com/cgi-bin/component/api_component_token' => Http::response([
                'errcode' => 0,
                'component_access_token' => 'component-token-abc',
                'expires_in' => 7200,
            ]),
        ]);

        $this->assertSame('component-token-abc', $this->component->componentAccessToken($provider));

        // 缓存命中（提前 5 分钟过期 = 6900s），不重复请求
        $this->assertSame('component-token-abc', $this->component->componentAccessToken($provider));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.weixin.qq.com/cgi-bin/component/api_component_token'
            && $request['component_appid'] === self::APPID
            && $request['component_appsecret'] === self::SECRET
            && $request['component_verify_ticket'] === self::TICKET);

        $this->assertSame('component-token-abc', Cache::get("wechat_component_token:{$provider->component_provider_id}"));
    }

    public function test_component_access_token_rejects_api_error(): void
    {
        $provider = $this->createProvider();
        $this->component->storeComponentVerifyTicket($provider->component_provider_id, self::TICKET);

        Http::fake([
            'api.weixin.qq.com/*' => Http::response([
                'errcode' => 61004,
                'errmsg' => 'invalid ip',
            ]),
        ]);

        try {
            $this->component->componentAccessToken($provider);
            $this->fail('应当抛出 ServiceUnavailableException');
        } catch (ServiceUnavailableException $e) {
            $this->assertStringContainsString('61004', $e->getMessage());
        }
    }

    // ==================================================================
    // pre_auth_code / 授权链接
    // ==================================================================

    public function test_pre_auth_code_fetches_and_caches(): void
    {
        $provider = $this->createProvider();
        $this->component->storeComponentVerifyTicket($provider->component_provider_id, self::TICKET);

        Http::fake([
            'api.weixin.qq.com/cgi-bin/component/api_component_token' => Http::response(['errcode' => 0, 'component_access_token' => 'ct', 'expires_in' => 7200]),
            // 注意: api_create_preauthcode 请求带 ?component_access_token= 查询参数,
            // Laravel Str::is 全串匹配, 模式必须带 * 通配才能命中
            'api.weixin.qq.com/cgi-bin/component/api_create_preauthcode*' => Http::response(['errcode' => 0, 'pre_auth_code' => 'pre-auth-1', 'expires_in' => 1800]),
        ]);

        $this->assertSame('pre-auth-1', $this->component->preAuthCode($provider));
        $this->assertSame('pre-auth-1', $this->component->preAuthCode($provider)); // 缓存命中
        Http::assertSentCount(2); // api_component_token 1 + api_create_preauthcode 1
    }

    public function test_build_authorize_url_pc_with_state_and_auth_type(): void
    {
        $provider = $this->createProvider();
        $this->component->storeComponentVerifyTicket($provider->component_provider_id, self::TICKET);

        Http::fake([
            'api.weixin.qq.com/cgi-bin/component/api_component_token' => Http::response(['errcode' => 0, 'component_access_token' => 'ct', 'expires_in' => 7200]),
            'api.weixin.qq.com/cgi-bin/component/api_create_preauthcode*' => Http::response(['errcode' => 0, 'pre_auth_code' => 'pre-auth-1', 'expires_in' => 1800]),
        ]);

        $url = $this->component->buildAuthorizeUrl($this->stateFromLaunch(9001, '3', 'pc'), '3', 'pc');

        $this->assertStringStartsWith('https://mp.weixin.qq.com/cgi-bin/componentloginpage?', $url);

        $query = [];
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
        $this->assertSame(self::APPID, $query['component_appid']);
        $this->assertSame('pre-auth-1', $query['pre_auth_code']);
        $this->assertSame('3', $query['auth_type']);
        $this->assertStringContainsString('/api/v1/wechat/authorize/callback', $query['redirect_uri']);

        // state 纯字母数字（微信限制 a-zA-Z0-9、≤32 字节），16 位租户前缀左补零 + 16  位随机
        $state = $query['state'];
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{32}$/', $state);
        $this->assertSame('0000000000009001', (string) substr($state, 0, 16));
        $this->assertSame(9001, $this->component->tenantIdFromState($state));
    }

    public function test_build_launch_url_uses_platform_callback_domain(): void
    {
        $this->createProvider();
        // 测试环境未设 OAUTH_CALLBACK_DOMAIN，显式指定平台回调域（callbackDomain 优先读它）
        config(['auth.oauth.callback_domain' => 'auth.neihang.com']);

        $launch = $this->component->buildLaunchUrl(9001, '3', 'pc');

        // 统一认证域（OAUTH_CALLBACK_DOMAIN）承载发起，非租户/console 域
        $this->assertStringStartsWith('https://auth.neihang.com/api/v1/wechat/authorize/launch?', $launch);

        $query = [];
        parse_str(parse_url($launch, PHP_URL_QUERY) ?: '', $query);
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{32}$/', $query['state']);
        $this->assertSame('0000000000009001', (string) substr($query['state'], 0, 16));
        $this->assertSame('3', $query['auth_type']);
        $this->assertSame('pc', $query['mode']);
    }

    public function test_build_authorize_url_h5_appends_wechat_redirect(): void
    {
        $provider = $this->createProvider();
        $this->component->storeComponentVerifyTicket($provider->component_provider_id, self::TICKET);

        Http::fake([
            'api.weixin.qq.com/cgi-bin/component/api_component_token' => Http::response(['errcode' => 0, 'component_access_token' => 'ct', 'expires_in' => 7200]),
            'api.weixin.qq.com/cgi-bin/component/api_create_preauthcode*' => Http::response(['errcode' => 0, 'pre_auth_code' => 'pre-auth-1', 'expires_in' => 1800]),
        ]);

        $url = $this->component->buildAuthorizeUrl($this->stateFromLaunch(9001, '2', 'h5'), '2', 'h5');

        $this->assertStringStartsWith('https://open.weixin.qq.com/wxaopen/safe/bindcomponent?', $url);
        $this->assertStringEndsWith('#wechat_redirect', $url);
        $this->assertStringContainsString('auth_type=2', $url);
    }

    // ==================================================================
    // 授权换取 / authorizer token
    // ==================================================================

    public function test_exchange_authorization_parses_info(): void
    {
        $provider = $this->createProvider();
        $this->component->storeComponentVerifyTicket($provider->component_provider_id, self::TICKET);

        Http::fake([
            'api.weixin.qq.com/cgi-bin/component/api_component_token' => Http::response(['errcode' => 0, 'component_access_token' => 'ct', 'expires_in' => 7200]),
            'api.weixin.qq.com/cgi-bin/component/api_query_auth*' => Http::response([
                'errcode' => 0,
                'authorization_info' => [
                    'authorizer_appid' => 'wx_authorizer_001',
                    'authorizer_access_token' => 'at-1',
                    'authorizer_refresh_token' => 'refresh-1',
                    'auth_type' => '1',
                    'authorizer_info' => [
                        'nick_name' => '蓝眼兔服务号',
                        'head_img' => 'https://mmbiz.qpic.cn/img',
                    ],
                ],
            ]),
        ]);

        $result = $this->component->exchangeAuthorization($provider, 'auth-code-1');

        $this->assertSame('wx_authorizer_001', $result['authorizer_appid']);
        $this->assertSame('refresh-1', $result['authorizer_refresh_token']);
        $this->assertSame('1', $result['auth_type']);
        $this->assertSame('蓝眼兔服务号', $result['nickname']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api_query_auth')
            && $request['component_appid'] === self::APPID
            && $request['authorization_code'] === 'auth-code-1');
    }

    public function test_authorizer_access_token_fetches_and_caches(): void
    {
        $provider = $this->createProvider();
        $this->component->storeComponentVerifyTicket($provider->component_provider_id, self::TICKET);

        // 授权行必须用模型方法写入（mutator 加密 refresh_token）
        $auth = $this->component->saveAuthorization(9001, $provider->component_provider_id, [
            'authorizer_appid' => 'wx_authorizer_001',
            'authorizer_refresh_token' => 'refresh-1',
        ]);

        Http::fake([
            'api.weixin.qq.com/cgi-bin/component/api_component_token' => Http::response(['errcode' => 0, 'component_access_token' => 'ct', 'expires_in' => 7200]),
            'api.weixin.qq.com/cgi-bin/component/api_authorizer_token*' => Http::response([
                'errcode' => 0,
                'authorizer_access_token' => 'authorizer-at-1',
                'expires_in' => 7200,
            ]),
        ]);

        $this->assertSame('authorizer-at-1', $this->component->authorizerAccessToken($auth));
        $this->assertSame('authorizer-at-1', $this->component->authorizerAccessToken($auth)); // 缓存命中
        Http::assertSentCount(2); // api_component_token 1 + api_authorizer_token 1
    }

    // ==================================================================
    // state 解析与防重放
    // ==================================================================

    public function test_tenant_id_from_state_supports_both_formats(): void
    {
        // 第三方平台格式：16 位租户前缀（左补零）+ 随机
        $this->assertSame(9001, $this->component->tenantIdFromState(str_pad('9001', 16, '0', STR_PAD_LEFT) . 'AbcDef1234567890'));
        // 登录 OAuth 旧格式：{tenantId}.{random}
        $this->assertSame(9001, $this->component->tenantIdFromState('9001.xYzRandom123'));
        // 非法格式
        $this->assertNull($this->component->tenantIdFromState('not-a-state'));
        $this->assertNull($this->component->tenantIdFromState(''));
    }

    public function test_verify_authorization_state_is_one_time(): void
    {
        $provider = $this->createProvider();
        $this->component->storeComponentVerifyTicket($provider->component_provider_id, self::TICKET);

        Http::fake([
            'api.weixin.qq.com/*' => Http::response(['errcode' => 0, 'pre_auth_code' => 'pre-auth-1', 'expires_in' => 1800, 'component_access_token' => 'ct']),
        ]);

        $url = $this->component->buildLaunchUrl(9001);
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
        $state = $query['state'];

        // 首次校验通过（返回 context）
        $this->component->verifyAuthorizationState($state, 9001);

        // 二次校验防重放：state 已消费
        try {
            $this->component->verifyAuthorizationState($state, 9001);
            $this->fail('应当抛出 403 HttpException');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_test_component_token_requires_ticket(): void
    {
        $provider = $this->createProvider();

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('verify_ticket 缺失');

        $this->component->testComponentToken($provider);
    }

    // ==================================================================
    // 授权入库 / 解除 / 三态探测
    // ==================================================================

    public function test_save_authorization_is_idempotent_per_tenant(): void
    {
        $provider = $this->createProvider();

        $first = $this->component->saveAuthorization(9001, $provider->component_provider_id, [
            'authorizer_appid' => 'wx_authorizer_001',
            'authorizer_type' => Authorization::TYPE_OFFICIAL_ACCOUNT,
            'authorizer_refresh_token' => 'refresh-1',
            'nickname' => '蓝眼兔服务号',
        ]);
        // 同租户再次回调（重复授权）：updateOrCreate 幂等，不新增行
        $this->component->saveAuthorization(9001, $provider->component_provider_id, [
            'authorizer_appid' => 'wx_authorizer_001',
            'authorizer_type' => Authorization::TYPE_OFFICIAL_ACCOUNT,
            'authorizer_refresh_token' => 'refresh-2',
        ]);

        $this->assertSame($first->authorization_id, $this->component->authorization(9001)->authorization_id);
        $this->assertSame('refresh-2', $this->component->authorization(9001)->authorizer_refresh_token);
        $this->assertSame(Authorization::STATUS_AUTHORIZED, $this->component->authorization(9001)->status);
        $this->assertSame('蓝眼兔服务号', $this->component->authorization(9001)->nickname);

        // allowUnscoped 生效：无租户上下文（模拟平台域回调）也能查
        TenantContext::setTenantId(null);
        $this->assertNotNull($this->component->authorization(9001));
        TenantContext::setTenantId(9001);
    }

    public function test_mark_revoked_by_authorizer_appid(): void
    {
        $provider = $this->createProvider();
        $this->component->saveAuthorization(9001, $provider->component_provider_id, [
            'authorizer_appid' => 'wx_authorizer_001',
            'authorizer_refresh_token' => 'refresh-1',
        ]);

        $count = $this->component->markRevokedByAuthorizerAppid('wx_authorizer_001');

        $this->assertSame(1, $count);
        $this->assertSame(Authorization::STATUS_REVOKED, $this->component->authorization(9001)->status);
        $this->assertNotNull($this->component->authorization(9001)->revoked_at);
    }

    // 注：三态探测拆为独立测试——Laravel Http::fake 为追加语义，
    // 同测试内多次 fake 不覆盖前序 stub，必须各自独立方法。

    public function test_is_still_authorized_on_wechat_true(): void
    {
        $provider = $this->createProvider();
        $this->component->storeComponentVerifyTicket($provider->component_provider_id, self::TICKET);
        $auth = $this->component->saveAuthorization(9001, $provider->component_provider_id, [
            'authorizer_appid' => 'wx_authorizer_001',
            'authorizer_refresh_token' => 'refresh-1',
        ]);

        // true：errcode=0（微信侧仍授权）
        Http::fake([
            'api.weixin.qq.com/cgi-bin/component/api_component_token' => Http::response(['errcode' => 0, 'component_access_token' => 'ct', 'expires_in' => 7200]),
            'api.weixin.qq.com/cgi-bin/component/api_authorizer_token*' => Http::response(['errcode' => 0, 'authorizer_access_token' => 'at', 'expires_in' => 7200]),
        ]);
        $this->assertTrue($this->component->isStillAuthorizedOnWechat($auth));
    }

    public function test_is_still_authorized_on_wechat_false(): void
    {
        $provider = $this->createProvider();
        $this->component->storeComponentVerifyTicket($provider->component_provider_id, self::TICKET);
        $auth = $this->component->saveAuthorization(9001, $provider->component_provider_id, [
            'authorizer_appid' => 'wx_authorizer_001',
            'authorizer_refresh_token' => 'refresh-1',
        ]);

        // false：61003 未授权关系（微信侧已解除）
        Http::fake([
            'api.weixin.qq.com/cgi-bin/component/api_component_token' => Http::response(['errcode' => 0, 'component_access_token' => 'ct', 'expires_in' => 7200]),
            'api.weixin.qq.com/cgi-bin/component/api_authorizer_token*' => Http::response(['errcode' => 61003, 'errmsg' => 'no authorized relationship']),
        ]);
        $this->assertFalse($this->component->isStillAuthorizedOnWechat($auth));
    }

    public function test_is_still_authorized_on_wechat_null_on_http_error(): void
    {
        $provider = $this->createProvider();
        $this->component->storeComponentVerifyTicket($provider->component_provider_id, self::TICKET);
        $auth = $this->component->saveAuthorization(9001, $provider->component_provider_id, [
            'authorizer_appid' => 'wx_authorizer_001',
            'authorizer_refresh_token' => 'refresh-1',
        ]);

        // null：HTTP 异常（探测失败，状态未知）
        Http::fake([
            'api.weixin.qq.com/cgi-bin/component/api_component_token' => Http::response(['errcode' => 0, 'component_access_token' => 'ct', 'expires_in' => 7200]),
            'api.weixin.qq.com/cgi-bin/component/api_authorizer_token*' => Http::response('', 500),
        ]);
        $this->assertNull($this->component->isStillAuthorizedOnWechat($auth));
    }

    public function test_callback_urls_use_platform_domain(): void
    {
        $this->assertStringEndsWith('/api/v1/wechat/message/callback', $this->component->callbackUrl());
        $this->assertStringEndsWith('/api/v1/wechat/authorize/callback', $this->component->authorizeCallbackUrl());
    }

    /**
     * 经 buildLaunchUrl 生成真实 state（已写防重放缓存），供 buildAuthorizeUrl 透传
     */
    protected function stateFromLaunch(int $tenantId, string $authType = '3', string $mode = 'pc'): string
    {
        $launch = $this->component->buildLaunchUrl($tenantId, $authType, $mode);
        parse_str(parse_url($launch, PHP_URL_QUERY) ?: '', $query);

        return (string) ($query['state'] ?? '');
    }
}
