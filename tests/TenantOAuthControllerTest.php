<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Http\Controllers\TenantOAuthController;
use MultiTenantSaas\Modules\Auth\Services\SocialiteService;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\WechatWork\Models\ServiceProvider;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkSuiteService;
use MultiTenantSaas\Tests\Schema\WechatWorkModule;

class TenantOAuthControllerTest extends TestCase
{
    private const TEST_TENANT_ID = 5876537299704848;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::setTenantId(self::TEST_TENANT_ID);
    }

    /**
     * 建 WechatWork 模块表（service_providers + wechat_work_authorizations，
     * 模块迁移未加载时兜底，与生产迁移同构）
     */
    protected function ensureWechatWorkTables(): void
    {
        if (! Schema::hasTable('service_providers')) {
            (new WechatWorkModule)->createTables();
        }
    }

    /**
     * 两步式校验所需的企微环境：provider + suite_ticket + 授权行
     *
     * service_provider_id 不在 fillable，由 HasGlobalId 自动生成；
     * ticket/授权行的 provider 引用必须用模型返回的真实 ID（硬编码会不匹配）。
     * permanent_code 经模型 mutator 加密写入（DB::table 明文读取会解密失败）。
     */
    protected function seedSuiteContext(string $status, string $permanentCode = 'perm-code-1'): int
    {
        $this->ensureWechatWorkTables();

        // 用模型创建：suite_secret 等敏感字段由模型 mutator 加密写入（DB::table 明文会解密失败）
        $provider = ServiceProvider::create([
            'tenant_id' => null,
            'name' => 'Test Provider',
            'provider_corp_id' => 'corp_provider',
            'suite_id' => 'ww_suite_test',
            'suite_secret' => 'suite-secret-123',
            'callback_token' => 'cb-token',
            'callback_url' => 'https://auth.neihang.com/api/v1/wechat-work/suite/callback',
            'status' => ServiceProvider::STATUS_ACTIVE,
        ]);
        $providerId = (int) $provider->service_provider_id;

        $suite = app(WechatWorkSuiteService::class);
        $suite->storeSuiteTicket($providerId, 'ticket-abc');
        $suite->saveAuthorization(self::TEST_TENANT_ID, $providerId, [
            'corp_id' => 'wpUeXaBgTest',
            'agent_id' => '1000016',
            'permanent_code' => $permanentCode,
        ]);

        if ($status === 'revoked') {
            $suite->markRevokedByCorpId('wpUeXaBgTest');
        }

        return $providerId;
    }

    public function test_update_wechat_work_rejected_when_suite_authorized(): void
    {
        $this->ensureWechatWorkTables();
        DB::table('wechat_work_authorizations')->insert([
            'authorization_id' => 5876537299704849,
            'tenant_id' => self::TEST_TENANT_ID,
            'service_provider_id' => 1,
            'corp_id' => 'wpUeXaBgTest',
            'agent_id' => '1000016',
            'status' => 'authorized',
            'authorized_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $controller = new TenantOAuthController();
        $request = Request::create('/api/v1/tenant/auth/oauth/wechat_work', 'PUT', [
            'corp_id' => 'ww1234567890abcdef',
            'agent_id' => '1000001',
            'secret' => 'test-secret',
        ]);
        $response = $controller->updateOAuthConfig($request, 'wechat_work');

        $this->assertSame(422, $response->getStatusCode());
        // 自建凭证未写入（防止双轨并存）；凭证存 wechatwork 组（9.6 模块边界）
        $this->assertSame('', TenantSetting::get(self::TEST_TENANT_ID, 'wechatwork', 'corp_id', ''));
    }

    public function test_update_wechat_work_rejected_when_suite_revoked_but_wecom_installed(): void
    {
        // 两步式：本地已解除（revoked）但企微侧应用未删除 → 提交自建前提示先删
        $this->seedSuiteContext('revoked');
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/service/get_suite_token' => Http::response(['suite_access_token' => 'st', 'expires_in' => 7200]),
            'qyapi.weixin.qq.com/cgi-bin/service/get_auth_info*' => Http::response(['errcode' => 0, 'auth_corp_info' => ['corpid' => 'wpUeXaBgTest']]),
        ]);

        $controller = new TenantOAuthController();
        $request = Request::create('/api/v1/tenant/auth/oauth/wechat_work', 'PUT', [
            'corp_id' => 'ww1234567890abcdef',
            'agent_id' => '1000001',
            'secret' => 'test-secret',
        ]);
        $response = $controller->updateOAuthConfig($request, 'wechat_work');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('', TenantSetting::get(self::TEST_TENANT_ID, 'wechatwork', 'corp_id', ''));
    }

    public function test_update_wechat_work_allowed_when_suite_revoked_and_wecom_released(): void
    {
        // 两步式：本地已解除且企微侧已删除（permanent_code 失效）→ 允许提交自建
        $this->seedSuiteContext('revoked');
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/service/get_suite_token' => Http::response(['suite_access_token' => 'st', 'expires_in' => 7200]),
            'qyapi.weixin.qq.com/cgi-bin/service/get_auth_info*' => Http::response(['errcode' => 61007, 'errmsg' => 'invalid permanent_code']),
        ]);

        $controller = new TenantOAuthController();
        $request = Request::create('/api/v1/tenant/auth/oauth/wechat_work', 'PUT', [
            'corp_id' => 'ww1234567890abcdef',
            'agent_id' => '1000001',
            'secret' => 'test-secret',
        ]);
        $response = $controller->updateOAuthConfig($request, 'wechat_work');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ww1234567890abcdef', TenantSetting::get(self::TEST_TENANT_ID, 'wechatwork', 'corp_id', ''));
    }

    public function test_update_wechat_work_allowed_without_suite_authorization(): void
    {
        $this->ensureWechatWorkTables();

        $controller = new TenantOAuthController();
        $request = Request::create('/api/v1/tenant/auth/oauth/wechat_work', 'PUT', [
            'corp_id' => 'ww1234567890abcdef',
            'agent_id' => '1000001',
            'secret' => 'test-secret',
        ]);
        $response = $controller->updateOAuthConfig($request, 'wechat_work');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ww1234567890abcdef', TenantSetting::get(self::TEST_TENANT_ID, 'wechatwork', 'corp_id', ''));
    }

    public function test_service_exists(): void
    {
        $this->assertInstanceOf(SocialiteService::class, app(SocialiteService::class));
    }
}
