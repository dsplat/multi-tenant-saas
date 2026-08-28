<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\WechatWork\Models\ServiceProvider;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkSuiteService;
use MultiTenantSaas\Support\WechatWork\SessionArchiveService;
use MultiTenantSaas\Support\WechatWork\WechatWorkApiClient;
use MultiTenantSaas\Support\WechatWork\WechatWorkProxy;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\WechatWorkModule;

/**
 * 企微出口代理测试（9.1）
 *
 * 覆盖：WechatWorkProxy::resolve 解析（未配置 / 未启用 / host / port /
 * username+password / scheme / 特殊字符转义）、WechatWorkApiClient 构造
 * proxy 注入、corpAccessToken 走代理场景请求正常、SessionArchiveService
 * config proxy 传递。服务商接口永不走代理由 WechatWorkSuiteServiceTest
 * 既有用例回归（服务商接口未经 withOptions 改造）。
 */
class WechatWorkProxyTest extends TestCase
{
    protected array $uses = [CoreModule::class, WechatWorkModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        TenantContext::setTenantId(9001);
    }

    private function saveProxy(array $config): void
    {
        // 与 AdminServiceProviderController::proxyUpdate 一致：整组加密存储
        TenantSetting::set(9001, WechatWorkProxy::GROUP, WechatWorkProxy::KEY, $config, true);
    }

    // ==================================================================
    // WechatWorkProxy::resolve 解析
    // ==================================================================

    public function test_resolve_returns_empty_without_config(): void
    {
        $this->assertSame([], WechatWorkProxy::resolve(9001));
    }

    public function test_resolve_returns_empty_when_disabled(): void
    {
        $this->saveProxy(['enabled' => false, 'host' => 'proxy.example.com']);

        $this->assertSame([], WechatWorkProxy::resolve(9001));
    }

    public function test_resolve_returns_empty_when_host_missing(): void
    {
        $this->saveProxy(['enabled' => true]);

        $this->assertSame([], WechatWorkProxy::resolve(9001));
    }

    public function test_resolve_builds_proxy_url_with_host_only(): void
    {
        $this->saveProxy(['enabled' => true, 'host' => 'proxy.example.com']);

        $this->assertSame(['proxy' => 'http://proxy.example.com'], WechatWorkProxy::resolve(9001));
    }

    public function test_resolve_builds_proxy_url_with_port(): void
    {
        $this->saveProxy(['enabled' => true, 'host' => '1.2.3.4', 'port' => 8080]);

        $this->assertSame(['proxy' => 'http://1.2.3.4:8080'], WechatWorkProxy::resolve(9001));
    }

    public function test_resolve_builds_proxy_url_with_credentials(): void
    {
        $this->saveProxy(['enabled' => true, 'host' => '1.2.3.4', 'port' => 8080, 'username' => 'ops', 'password' => 'p@ss:word']);

        $this->assertSame(['proxy' => 'http://ops:p%40ss%3Aword@1.2.3.4:8080'], WechatWorkProxy::resolve(9001));
    }

    public function test_resolve_builds_proxy_url_with_https_scheme(): void
    {
        $this->saveProxy(['enabled' => true, 'scheme' => 'https', 'host' => 'proxy.example.com']);

        $this->assertSame(['proxy' => 'https://proxy.example.com'], WechatWorkProxy::resolve(9001));
    }

    // ==================================================================
    // WechatWorkApiClient 构造注入
    // ==================================================================

    public function test_api_client_keeps_proxy_in_constructor(): void
    {
        $client = new WechatWorkApiClient('ww_corp', 'secret', '', null, 'http://user:pass@1.2.3.4:8080');

        $reflection = new \ReflectionProperty($client, 'proxy');
        $this->assertSame('http://user:pass@1.2.3.4:8080', $reflection->getValue($client));
    }

    public function test_api_client_proxy_null_defaults_to_direct(): void
    {
        $client = new WechatWorkApiClient('ww_corp', 'secret');

        $reflection = new \ReflectionProperty($client, 'proxy');
        $this->assertNull($reflection->getValue($client));
    }

    public function test_api_client_gettoken_still_works_with_proxy(): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0,
                'access_token' => 'token-proxy-1',
                'expires_in' => 7200,
            ]),
        ]);

        $client = new WechatWorkApiClient('ww_corp', 'secret', '', null, 'http://1.2.3.4:8080');

        $this->assertSame('token-proxy-1', $client->accessToken());

        Http::assertSent(fn ($request) => str_contains($request->url(), 'gettoken')
            && $request['corpid'] === 'ww_corp'
            && $request['corpsecret'] === 'secret');
    }

    // ==================================================================
    // corpAccessToken 走代理场景
    // ==================================================================

    public function test_corp_access_token_with_proxy_still_fetches(): void
    {
        $provider = ServiceProvider::create([
            'tenant_id' => null,
            'name' => 'Proxy Provider',
            'provider_corp_id' => 'corp_provider',
            'provider_secret' => 'provider-secret-123',
            'suite_id' => 'ww_suite_proxy',
            'suite_secret' => 'suite-secret-123',
            'callback_token' => 'cb-token',
            'callback_url' => 'https://auth.neihang.com/api/v1/wechat-work/suite/callback',
            'status' => ServiceProvider::STATUS_ACTIVE,
        ]);

        app(WechatWorkSuiteService::class)->saveAuthorization(9001, $provider->service_provider_id, [
            'corp_id' => 'ww_corp_1',
            'agent_id' => '1000001',
            'permanent_code' => 'perm-code-1',
        ]);

        // 配置出口代理：resolve 非空 → withOptions(['proxy' => ...]) 注入
        $this->saveProxy(['enabled' => true, 'host' => '1.2.3.4', 'port' => 8080]);

        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0,
                'access_token' => 'corp-token-proxy',
                'expires_in' => 7200,
            ]),
        ]);

        $this->assertSame('corp-token-proxy', app(WechatWorkSuiteService::class)->corpAccessToken(9001));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'gettoken')
            && $request['corpid'] === 'ww_corp_1'
            && $request['corpsecret'] === 'perm-code-1');
    }

    // ==================================================================
    // SessionArchiveService config 传递
    // ==================================================================

    public function test_session_archive_fetch_with_proxy_config(): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0,
                'access_token' => 'archive-token',
                'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/cgi-bin/msgaudit/*' => Http::response([
                'errcode' => 0,
                'chatdata' => [],
                'seq' => 5,
            ]),
        ]);

        $service = new SessionArchiveService([
            'corp_id' => 'ww_corp',
            'corp_secret' => 'archive-secret',
            'proxy' => 'http://1.2.3.4:8080',
        ]);

        $result = $service->fetchFromApi(0, 100);

        $this->assertSame([], $result['chatdata']);
        $this->assertSame(5, $result['seq']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'msgaudit/get_chat_data'));
    }
}
