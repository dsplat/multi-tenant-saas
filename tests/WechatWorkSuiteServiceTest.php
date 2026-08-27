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
 * pre_auth_code 缓存、providerAccessToken（缺失报错 / 获取并缓存）、
 * buildAuthorizeUrl（get_customized_auth_url 返回二维码 URL + state 纯字母数字）、
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

    private function createProvider(array $overrides = []): ServiceProvider
    {
        return ServiceProvider::create(array_merge([
            'tenant_id' => null,
            'name' => 'Test Provider',
            'provider_corp_id' => 'corp_provider',
            'provider_secret' => 'provider-secret-123',
            'suite_id' => self::SUITE_ID,
            'suite_secret' => self::SUITE_SECRET,
            'callback_token' => 'cb-token',
            'callback_url' => 'https://auth.neihang.com/api/v1/wechat-work/suite/callback',
            'status' => ServiceProvider::STATUS_ACTIVE,
        ], $overrides));
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
    // pre_auth_code / provider_access_token / 授权二维码
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

    public function test_provider_access_token_requires_secret(): void
    {
        $provider = $this->createProvider(['provider_secret' => null]);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('provider_secret');

        $this->suite->providerAccessToken($provider);
    }

    public function test_provider_access_token_fetches_and_caches(): void
    {
        $provider = $this->createProvider();

        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/service/get_provider_token' => Http::response([
                'provider_access_token' => 'provider-token-abc',
                'expires_in' => 7200,
            ]),
        ]);

        $this->assertSame('provider-token-abc', $this->suite->providerAccessToken($provider));
        $this->assertSame('provider-token-abc', $this->suite->providerAccessToken($provider)); // 缓存命中

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://qyapi.weixin.qq.com/cgi-bin/service/get_provider_token'
            && $request['corpid'] === 'corp_provider'
            && $request['provider_secret'] === 'provider-secret-123');

        $this->assertSame('provider-token-abc', Cache::get("wechat_work_provider_token:{$provider->service_provider_id}"));
    }

    public function test_build_authorize_url_returns_qrcode_url_with_alnum_state(): void
    {
        $provider = $this->createProvider();
        $this->suite->storeSuiteTicket($provider->service_provider_id, self::TICKET);

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

        $url = $this->suite->buildAuthorizeUrl(9001);

        // 返回企微授权二维码 URL（代开发模式，非 3rdapp/install）
        $this->assertSame('https://open.work.weixin.qq.com/wwopen/customApp/authorize?auth_code=abc', $url);

        // state 纯字母数字（企微限制 a-zA-Z0-9、≤32 字节），携带租户前缀
        Http::assertSent(function ($request) use ($provider) {
            if (! str_contains($request->url(), 'get_customized_auth_url')) {
                return false;
            }

            $state = (string) $request['state'];

            return $state !== ''
                // 32 字节纯字母数字：16 位租户 ID（左补零）+ 16 位随机
                && preg_match('/^\d{16}[a-zA-Z0-9]{16}$/', $state) === 1
                && strlen($state) === 32
                && $this->suite->tenantIdFromState($state) === 9001
                && $request['templateid_list'] === [self::SUITE_ID];
        });
    }

    public function test_tenant_id_from_state_supports_both_formats(): void
    {
        // 代开发格式：16 位租户 ID（左补零）+ 16 位随机
        $this->assertSame(9001, $this->suite->tenantIdFromState(str_pad('9001', 16, '0', STR_PAD_LEFT) . str_repeat('x', 16)));
        // 旧第三方应用格式：{tenantId}.{random}
        $this->assertSame(9001, $this->suite->tenantIdFromState('9001.abcdefghijklmnopqrstuvwx'));
        // 纯随机（无租户前缀）与空串均返回 null
        $this->assertNull($this->suite->tenantIdFromState(str_repeat('x', 40)));
        $this->assertNull($this->suite->tenantIdFromState(''));
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
        $this->assertSame('', $result['state'], '无 state 时返回空串');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'get_permanent_code')
            && $request['auth_code'] === 'auth-code-1');
    }

    public function test_exchange_permanent_code_returns_state(): void
    {
        $provider = $this->createProvider();
        $this->suite->storeSuiteTicket($provider->service_provider_id, self::TICKET);

        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/service/get_suite_token' => Http::response(['suite_access_token' => 'st', 'expires_in' => 7200]),
            'qyapi.weixin.qq.com/*' => Http::response([
                'errcode' => 0,
                'auth_corp_info' => ['corpid' => 'ww_corp_1'],
                'permanent_code' => 'perm-code-1',
                'auth_info' => ['agent' => [['agentid' => 1000001]]],
                'state' => '9001abcdefghijklmnopqrst',
            ]),
        ]);

        $result = $this->suite->exchangePermanentCode($provider, 'auth-code-1');
        $this->assertSame('9001abcdefghijklmnopqrst', $result['state'], '代开发扫码授权后应原样返回 state');
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
    // 应用回调：URL 生成 / 凭证加密 / 候选解析
    // ==================================================================

    public function test_app_callback_url_uses_platform_domain_with_tenant_id(): void
    {
        config()->set('auth.oauth.callback_domain', 'auth.neihang.com');

        $this->assertSame(
            'https://auth.neihang.com/api/v1/wechat-work/suite/cz/9001',
            $this->suite->appCallbackUrl(9001)
        );
    }

    public function test_app_callback_url_unified_uses_template_endpoint(): void
    {
        config()->set('auth.oauth.callback_domain', 'auth.neihang.com');

        // 统一地址 = 模板回调同址（「开始代开发应用」自动带出），所有企业一致、不带租户标识
        $this->assertSame(
            'https://auth.neihang.com/api/v1/wechat-work/suite/callback',
            $this->suite->appCallbackUrlUnified()
        );
    }

    public function test_app_credentials_falls_back_to_provider_template_level(): void
    {
        // 模板级应用回调凭证（service_providers.app_callback_*）已配置，企业级未回填 → 回退模板级
        $provider = $this->createProvider([
            'app_callback_token' => 'tmpl-app-token',
            'app_encoding_aes_key' => 'tmpl-app-aes-key',
        ]);

        $authorization = $this->suite->saveAuthorization(9001, (int) $provider->service_provider_id, [
            'corp_id' => 'ww_corp_1',
            'agent_id' => '1000001',
            'permanent_code' => 'p1',
        ]);

        $credentials = $this->suite->appCredentials($authorization);
        $this->assertSame('tmpl-app-token', $credentials['token']);
        $this->assertSame('tmpl-app-aes-key', $credentials['aes_key']);
        $this->assertTrue($this->suite->appCallbackConfigured($authorization));
    }

    public function test_app_credentials_prefers_enterprise_override(): void
    {
        // 企业级覆盖非空时优先于模板级（企微侧手动改过该企业回调配置的场景）
        $provider = $this->createProvider([
            'app_callback_token' => 'tmpl-app-token',
            'app_encoding_aes_key' => 'tmpl-app-aes-key',
        ]);

        $authorization = $this->suite->saveAuthorization(9001, (int) $provider->service_provider_id, [
            'corp_id' => 'ww_corp_1',
            'agent_id' => '1000001',
            'permanent_code' => 'p1',
            'app_callback_token' => 'ent-app-token',
            'app_encoding_aes_key' => 'ent-app-aes-key',
        ]);

        $credentials = $this->suite->appCredentials($authorization);
        $this->assertSame('ent-app-token', $credentials['token']);
        $this->assertSame('ent-app-aes-key', $credentials['aes_key']);
    }

    public function test_app_credentials_empty_when_neither_configured(): void
    {
        $this->createProvider();
        $authorization = $this->suite->saveAuthorization(9001, 1, [
            'corp_id' => 'ww_corp_1',
            'agent_id' => '1000001',
            'permanent_code' => 'p1',
        ]);

        $credentials = $this->suite->appCredentials($authorization);
        $this->assertSame('', $credentials['token']);
        $this->assertSame('', $credentials['aes_key']);
        $this->assertFalse($this->suite->appCallbackConfigured($authorization));
    }

    public function test_provider_app_callback_credentials_round_trip_encrypted(): void
    {
        $provider = $this->createProvider([
            'app_callback_token' => 'tmpl-app-token',
            'app_encoding_aes_key' => 'tmpl-app-aes-key',
        ]);

        $this->assertSame('tmpl-app-token', $provider->app_callback_token);
        $this->assertSame('tmpl-app-aes-key', $provider->app_encoding_aes_key);
        // 加密落库：原始密文不含明文
        $this->assertNotSame('tmpl-app-aes-key', $provider->getRawOriginal('app_encoding_aes_key'));
        $this->assertStringNotContainsString('tmpl-app-aes-key', (string) $provider->getRawOriginal('app_encoding_aes_key'));
    }


    public function test_app_authorizations_filters_by_tenant_and_status(): void
    {
        $this->suite->saveAuthorization(9001, 1, ['corp_id' => 'ww_corp_1', 'agent_id' => '1000001', 'permanent_code' => 'p1']);

        // 带租户标识：仅该租户
        $found = $this->suite->appAuthorizations(9001);
        $this->assertCount(1, $found);
        $this->assertSame('ww_corp_1', $found[0]->corp_id);

        // 无租户标识：全部已授权
        $this->assertCount(1, $this->suite->appAuthorizations(null));

        // revoked 记录不参与候选
        $this->suite->markRevokedByCorpId('ww_corp_1');
        $this->assertSame([], $this->suite->appAuthorizations(null));
        $this->assertSame([], $this->suite->appAuthorizations(9001));
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
