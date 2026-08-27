<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\WechatWork\Models\ServiceProvider;
use MultiTenantSaas\Modules\WechatWork\Models\WechatWorkAuthorization;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkSuiteService;
use MultiTenantSaas\Scopes\TenantScope;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\WechatWorkModule;

/**
 * 企微服务商代开发套件服务测试
 *
 * 覆盖：suite_access_token（suite_ticket 缺失报错 / 缓存提前过期 / API 错误透传）、
 * pre_auth_code 缓存、buildAuthorizeUrl（state 带租户前缀 + 平台统一回调域）、
 * exchangePermanentCode 解析、corpAccessToken（未授权报错 / get_corp_token 缓存）、
 * saveAuthorization 幂等、markRevokedByCorpId、testSuiteToken 诊断、未配置服务商报错。
 */
class WechatWorkSuiteServiceTest extends TestCase
{
    protected array $uses = [CoreModule::class, WechatWorkModule::class];

    private const SUITE_ID = 'ww_suite_test';

    private const SUITE_SECRET = 'suite-secret-123';

    private const TICKET = 'ticket-abc';

    private WechatWorkSuiteService $suite;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        // TenantScope fail-closed：无租户上下文时 WHERE 1=0，统一绑定测试租户
        TenantContext::setTenantId(9001);

        $this->suite = app(WechatWorkSuiteService::class);
    }

    private function createProvider(): ServiceProvider
    {
        return ServiceProvider::create([
            'tenant_id' => null,
            'name' => 'Test Provider',
            'provider_corp_id' => 'corp_provider',
            'suite_id' => self::SUITE_ID,
            'suite_secret' => self::SUITE_SECRET,
            'callback_token' => 'cb-token',
            'callback_url' => 'https://auth.neihang.com/api/v1/wechat-work/suite/callback',
            'status' => ServiceProvider::STATUS_ACTIVE,
        ]);
    }

    // ==================================================================
    // suite_access_token
    // ==================================================================

    public function test_suite_access_token_requires_ticket(): void
    {
        $provider = $this->createProvider();

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('suite_ticket 缺失');

        $this->suite->suiteAccessToken($provider);
    }

    public function test_suite_access_token_fetches_and_caches(): void
    {
        $provider = $this->createProvider();
        $this->suite->storeSuiteTicket($provider->service_provider_id, self::TICKET);

        Http::fake([
            // 企微 get_suite_token 成功响应无 errcode，字段为 suite_access_token
            'qyapi.weixin.qq.com/cgi-bin/service/get_suite_token' => Http::response([
                'suite_access_token' => 'suite-token-abc',
                'expires_in' => 7200,
            ]),
        ]);

        $this->assertSame('suite-token-abc', $this->suite->suiteAccessToken($provider));

        // 缓存命中（提前 5 分钟过期 = 6900s），不重复请求
        $this->assertSame('suite-token-abc', $this->suite->suiteAccessToken($provider));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://qyapi.weixin.qq.com/cgi-bin/service/get_suite_token'
            && $request['suite_id'] === self::SUITE_ID
            && $request['suite_secret'] === self::SUITE_SECRET
            && $request['suite_ticket'] === self::TICKET);

        $this->assertSame('suite-token-abc', Cache::get("wechat_work_suite_token:{$provider->service_provider_id}"));
    }

    public function test_suite_access_token_rejects_api_error(): void
    {
        $provider = $this->createProvider();
        $this->suite->storeSuiteTicket($provider->service_provider_id, self::TICKET);

        Http::fake([
            'qyapi.weixin.qq.com/*' => Http::response([
                'errcode' => 40013,
                'errmsg' => 'invalid suite secret',
            ]),
        ]);

        try {
            $this->suite->suiteAccessToken($provider);
            $this->fail('应当抛出 ServiceUnavailableException');
        } catch (ServiceUnavailableException $e) {
            $this->assertStringContainsString('40013', $e->getMessage());
        }
    }

    // ==================================================================
    // pre_auth_code / 授权 URL
    // ==================================================================

    public function test_pre_auth_code_fetches_and_caches(): void
    {
        $provider = $this->createProvider();
        $this->suite->storeSuiteTicket($provider->service_provider_id, self::TICKET);

        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/service/get_suite_token' => Http::response(['suite_access_token' => 'st', 'expires_in' => 7200]),
            // 注意: get_pre_auth_code 请求带 ?suite_access_token= 查询参数,
            // Laravel Str::is 全串匹配, 模式必须带 * 通配才能命中
            'qyapi.weixin.qq.com/cgi-bin/service/get_pre_auth_code*' => Http::response(['errcode' => 0, 'pre_auth_code' => 'pre-auth-1', 'expires_in' => 7200]),
        ]);

        $this->assertSame('pre-auth-1', $this->suite->preAuthCode($provider));
        $this->assertSame('pre-auth-1', $this->suite->preAuthCode($provider)); // 缓存命中
        Http::assertSentCount(2); // get_suite_token 1 + get_pre_auth_code 1
    }

    public function test_build_authorize_url_contains_suite_state_and_platform_callback(): void
    {
        config(['auth.oauth.callback_domain' => 'auth.neihang.com']);

        $provider = $this->createProvider();
        $this->suite->storeSuiteTicket($provider->service_provider_id, self::TICKET);

        Http::fake([
            // get_suite_token 与 get_pre_auth_code 共用通配：前者读 suite_access_token，后者读 pre_auth_code
            'qyapi.weixin.qq.com/*' => Http::response([
                'suite_access_token' => 'st',
                'pre_auth_code' => 'pre-auth-1',
                'expires_in' => 7200,
            ]),
        ]);

        $url = $this->suite->buildAuthorizeUrl(9001);

        $this->assertStringStartsWith('https://open.work.weixin.qq.com/3rdapp/install?', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame(self::SUITE_ID, $params['suite_id']);
        $this->assertSame('pre-auth-1', $params['pre_auth_code']);
        $this->assertSame('https://auth.neihang.com/api/v1/wechat-work/callback', $params['redirect_uri']);
        // state 携带租户前缀，供统一回调域恢复租户上下文
        $this->assertMatchesRegularExpression('/^9001\.[A-Za-z0-9]{24}$/', (string) $params['state']);
    }

    // ==================================================================
    // permanent_code 换取
    // ==================================================================

    public function test_exchange_permanent_code_parses_response(): void
    {
        $provider = $this->createProvider();
        $this->suite->storeSuiteTicket($provider->service_provider_id, self::TICKET);

        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/service/get_suite_token' => Http::response(['suite_access_token' => 'st', 'expires_in' => 7200]),
            'qyapi.weixin.qq.com/*' => Http::response([
                'errcode' => 0,
                'auth_corp_info' => ['corpid' => 'ww_corp_1', 'corp_name' => '蓝眼兔'],
                'permanent_code' => 'perm-code-1',
                'auth_info' => ['agent' => [['agentid' => 1000001]]],
            ]),
        ]);

        $result = $this->suite->exchangePermanentCode($provider, 'auth-code-1');

        $this->assertSame('ww_corp_1', $result['corp_id']);
        $this->assertSame('perm-code-1', $result['permanent_code']);
        $this->assertSame('1000001', $result['agent_id']);
        $this->assertSame('蓝眼兔', $result['corp_name']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'get_permanent_code')
            && $request['auth_code'] === 'auth-code-1');
    }

    public function test_exchange_permanent_code_rejects_missing_corp_id(): void
    {
        $provider = $this->createProvider();
        $this->suite->storeSuiteTicket($provider->service_provider_id, self::TICKET);

        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/service/get_suite_token' => Http::response(['suite_access_token' => 'st', 'expires_in' => 7200]),
            'qyapi.weixin.qq.com/*' => Http::response([
                'errcode' => 0,
                'permanent_code' => 'perm-code-1',
                'auth_info' => ['agent' => [['agentid' => 1]]],
            ]),
        ]);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('缺少 corp_id / permanent_code');

        $this->suite->exchangePermanentCode($provider, 'auth-code-1');
    }

    // ==================================================================
    // corp_access_token（代开发模式登录凭证）
    // ==================================================================

    public function test_corp_access_token_requires_authorized_tenant(): void
    {
        $this->createProvider();

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('未完成企微代开发授权');

        $this->suite->corpAccessToken(9001);
    }

    public function test_corp_access_token_fetches_and_caches(): void
    {
        $provider = $this->createProvider();
        $this->suite->saveAuthorization(9001, $provider->service_provider_id, [
            'corp_id' => 'ww_corp_1',
            'agent_id' => '1000001',
            'permanent_code' => 'perm-code-1',
        ]);
        $this->suite->storeSuiteTicket($provider->service_provider_id, self::TICKET);

        Http::fake([
            // get_corp_token 请求带 ?suite_access_token= 查询参数, 模式需带 * 通配
            'qyapi.weixin.qq.com/cgi-bin/service/get_corp_token*' => Http::response([
                'errcode' => 0,
                'access_token' => 'corp-token-xyz',
                'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/*' => Http::response([
                'suite_access_token' => 'st',
                'expires_in' => 7200,
            ]),
        ]);

        $this->assertSame('corp-token-xyz', $this->suite->corpAccessToken(9001));
        $this->assertSame('corp-token-xyz', $this->suite->corpAccessToken(9001)); // 缓存命中
        $this->assertSame('corp-token-xyz', Cache::get('wechat_work_corp_token:9001'));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'get_corp_token')
            && $request['auth_corpid'] === 'ww_corp_1'
            && $request['permanent_code'] === 'perm-code-1');
    }

    // ==================================================================
    // 授权记录：幂等写入 / 取消授权
    // ==================================================================

    public function test_save_authorization_is_idempotent(): void
    {
        $this->suite->saveAuthorization(9001, 1, ['corp_id' => 'ww_corp_1', 'agent_id' => '1000001', 'permanent_code' => 'p1']);
        $this->suite->saveAuthorization(9001, 1, ['corp_id' => 'ww_corp_1', 'agent_id' => '1000001', 'permanent_code' => 'p2']);

        $this->assertSame(1, TenantScope::allowUnscoped(fn () => WechatWorkAuthorization::count()));

        $authorization = $this->suite->authorization(9001);
        $this->assertNotNull($authorization);
        $this->assertTrue($authorization->isAuthorized());
        $this->assertSame('p2', $authorization->permanent_code, '重复授权应覆盖 permanent_code');
        $this->assertNotNull($authorization->authorized_at);
        $this->assertNull($authorization->revoked_at);
    }

    public function test_mark_revoked_by_corp_id(): void
    {
        $this->suite->saveAuthorization(9001, 1, ['corp_id' => 'ww_corp_1', 'agent_id' => '1000001', 'permanent_code' => 'p1']);

        $affected = $this->suite->markRevokedByCorpId('ww_corp_1');
        $this->assertSame(1, $affected);

        $authorization = $this->suite->authorization(9001);
        $this->assertSame(WechatWorkAuthorization::STATUS_REVOKED, $authorization->status);
        $this->assertFalse($authorization->isAuthorized());
        $this->assertNotNull($authorization->revoked_at);

        // 已 revoked 不再重复更新
        $this->assertSame(0, $this->suite->markRevokedByCorpId('ww_corp_1'));
    }

    // ==================================================================
    // 连接测试诊断 / 未配置兜底
    // ==================================================================

    public function test_test_suite_token_requires_ticket(): void
    {
        $provider = $this->createProvider();

        try {
            $this->suite->testSuiteToken($provider);
            $this->fail('应当抛出 ServiceUnavailableException');
        } catch (ServiceUnavailableException $e) {
            $this->assertStringContainsString('suite_ticket 缺失', $e->getMessage());
        }
    }

    public function test_test_suite_token_returns_masked_prefix(): void
    {
        $provider = $this->createProvider();
        $this->suite->storeSuiteTicket($provider->service_provider_id, self::TICKET);

        Http::fake([
            'qyapi.weixin.qq.com/*' => Http::response(['suite_access_token' => 'abcdefgh-suite-token', 'expires_in' => 7200]),
        ]);

        $result = $this->suite->testSuiteToken($provider);
        $this->assertSame('abcdefgh…', $result['access_token']);
        $this->assertSame(7200, $result['expires_in']);
    }

    public function test_require_provider_throws_when_unconfigured(): void
    {
        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('未配置企微服务商');

        $this->suite->requireProvider();
    }
}
