<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Services\AlipayOAuthService;
use MultiTenantSaas\Modules\Auth\Services\SocialiteService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\PluginModule;

/**
 * 支付宝 OAuth 认证模块测试（TASK-004 T3.3）
 *
 * 覆盖：
 *  - AlipayOAuthService 容器解析（singleton 注册验证）
 *  - isConfigured() 未配置时返回 false
 *  - $this->socialiteService->getSupportedProviders() 包含 alipay
 *
 * 注意：不测试实际 HTTP 调用（需要 mock），只测试服务解析与配置逻辑。
 */
class AlipayOAuthTest extends TestCase
{
    protected SocialiteService $socialiteService;

    protected array $uses = [PluginModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        $this->socialiteService = $this->app->make(SocialiteService::class);


        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        TenantContext::setTenantId('1001');
    }

    public function test_alipay_service_can_be_resolved(): void
    {
        $service = app(AlipayOAuthService::class);

        $this->assertInstanceOf(AlipayOAuthService::class, $service);
    }

    public function test_alipay_is_configured_returns_false_when_not_set(): void
    {
        $service = app(AlipayOAuthService::class);

        $this->assertFalse($service->isConfigured(1001));
    }

    public function test_alipay_is_in_supported_providers(): void
    {
        $providers = $this->socialiteService->getSupportedProviders();

        $this->assertArrayHasKey('alipay', $providers);
    }
}
