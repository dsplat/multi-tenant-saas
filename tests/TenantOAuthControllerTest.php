<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Http\Controllers\TenantOAuthController;
use MultiTenantSaas\Modules\Auth\Services\SocialiteService;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;

class TenantOAuthControllerTest extends TestCase
{
    private const TEST_TENANT_ID = 5876537299704848;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::setTenantId(self::TEST_TENANT_ID);
    }

    /**
     * 手工建授权表（WechatWork 模块迁移未加载时兜底）
     */
    protected function ensureAuthorizationTable(): void
    {
        if (! Schema::hasTable('wechat_work_authorizations')) {
            Schema::create('wechat_work_authorizations', function ($table) {
                $table->unsignedBigInteger('authorization_id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('service_provider_id');
                $table->string('corp_id', 64);
                $table->string('agent_id', 64)->nullable();
                $table->text('permanent_code')->nullable();
                $table->string('status', 20)->default('pending');
                $table->timestamp('authorized_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function test_update_wechat_work_rejected_when_suite_authorized(): void
    {
        $this->ensureAuthorizationTable();
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

    public function test_update_wechat_work_allowed_without_suite_authorization(): void
    {
        $this->ensureAuthorizationTable();

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
