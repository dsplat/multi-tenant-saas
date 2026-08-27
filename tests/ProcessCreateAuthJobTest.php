<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\WechatWork\Jobs\ProcessCreateAuthJob;
use MultiTenantSaas\Modules\WechatWork\Models\ServiceProvider;
use MultiTenantSaas\Modules\WechatWork\Models\WechatWorkAuthorization;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkSuiteService;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\WechatWorkModule;

/**
 * create_auth 异步入库 Job 测试
 *
 * 覆盖：auth_code 换 permanent_code → 一次性 state 校验消费 → 幂等入库；
 * state 已消费（重放）时跳过入库。
 */
class ProcessCreateAuthJobTest extends TestCase
{
    protected array $uses = [CoreModule::class, WechatWorkModule::class];

    private const SUITE_ID = 'ww_suite_test';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        TenantContext::setTenantId(9001);
    }

    private function createProvider(): ServiceProvider
    {
        return ServiceProvider::create([
            'tenant_id' => null,
            'name' => 'Test Provider',
            'provider_corp_id' => 'corp_provider',
            'suite_id' => self::SUITE_ID,
            'suite_secret' => 'suite-secret-123',
            'callback_token' => 'cb-token',
            'encoding_aes_key' => 'aeskey0123456789abcdefghijklmnopqrstuvwxyzA',
            'callback_url' => 'https://auth.neihang.com/api/v1/wechat-work/suite/callback',
            'status' => ServiceProvider::STATUS_ACTIVE,
        ]);
    }

    private function fakeWechatApis(): void
    {
        Http::fake([
            // 企微 get_suite_token 成功响应无 errcode，字段为 suite_access_token
            'qyapi.weixin.qq.com/cgi-bin/service/get_suite_token' => Http::response([
                'suite_access_token' => 'suite-token-1',
                'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/*' => Http::response([
                'errcode' => 0,
                'auth_corp_info' => ['corpid' => 'ww_corp_1', 'corp_name' => '蓝眼兔'],
                'permanent_code' => 'perm-code-1',
                'auth_info' => ['agent' => [['agentid' => 1000001]]],
                'state' => '',
            ]),
        ]);
    }

    public function test_job_exchanges_code_and_persists_authorization(): void
    {
        $provider = $this->createProvider();
        Cache::put("wechat_work_suite_ticket:{$provider->service_provider_id}", 'ticket-abc');
        $this->fakeWechatApis();

        $state = str_pad('9001', 16, '0', STR_PAD_LEFT) . str_repeat('x', 16);
        Cache::put('oauth_state:wechat_work_suite:9001:' . hash('sha256', $state), true, 600);

        $job = new ProcessCreateAuthJob('auth-code-1', $state, 9001, (int) $provider->service_provider_id);
        $job->handle(app(WechatWorkSuiteService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'get_permanent_code')
            && $request['auth_code'] === 'auth-code-1');

        $authorization = app(WechatWorkSuiteService::class)->authorization(9001);
        $this->assertNotNull($authorization);
        $this->assertTrue($authorization->isAuthorized());
        $this->assertSame('ww_corp_1', $authorization->corp_id);
        $this->assertSame('1000001', $authorization->agent_id);
        $this->assertSame('perm-code-1', $authorization->permanent_code);
        $this->assertSame(WechatWorkAuthorization::STATUS_AUTHORIZED, $authorization->status);
    }

    public function test_job_skips_when_state_already_consumed(): void
    {
        $provider = $this->createProvider();
        Cache::put("wechat_work_suite_ticket:{$provider->service_provider_id}", 'ticket-abc');
        $this->fakeWechatApis();

        $state = str_pad('9001', 16, '0', STR_PAD_LEFT) . str_repeat('x', 16);
        // 不放入 state（一次性校验会失败），模拟重放/伪造场景

        $job = new ProcessCreateAuthJob('auth-code-1', $state, 9001, (int) $provider->service_provider_id);
        $job->handle(app(WechatWorkSuiteService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'get_permanent_code'));

        // state 校验失败被 catch，不落库
        $this->assertNull(app(WechatWorkSuiteService::class)->authorization(9001));
    }
}
