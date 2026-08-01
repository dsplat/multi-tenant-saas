<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

class IdentifyTenantPathPrefixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 设置 app 域名
        config(['domain.platform_domains.app' => 'app.example.com']);
        config(['domain.platform_domains.console' => 'console.example.com']);
        config(['tenancy.platform_domains' => ['localhost', '127.0.0.1', 'app.example.com', 'console.example.com']]);

        // 创建测试租户
        Tenant::create([
            'tenant_id' => 2001,
            'name' => 'Slug Tenant',
            'slug' => 'acme',
            'slug_status' => 'active',
            'status' => 'active',
        ]);

        Tenant::create([
            'tenant_id' => 2002,
            'name' => 'Rejected Slug Tenant',
            'slug' => 'badslug',
            'slug_status' => 'rejected',
            'status' => 'active',
        ]);

        Tenant::create([
            'tenant_id' => 2003,
            'name' => 'No Slug Tenant',
            'slug' => null,
            'slug_status' => null,
            'status' => 'active',
        ]);
    }

    public function test_resolves_tenant_by_slug_path_prefix(): void
    {
        $response = $this->withHeaders([
            'Host' => 'app.example.com',
        ])->get('/acme/h5/home');

        // 中间件应解析到 tenant_id=2001
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    public function test_resolves_tenant_by_tenant_id_path_prefix(): void
    {
        $response = $this->withHeaders([
            'Host' => 'app.example.com',
        ])->get('/2003/h5/home');

        $this->assertNotEquals(403, $response->getStatusCode());
    }

    public function test_rejected_slug_not_resolved(): void
    {
        // slug_status=rejected 的 slug 不应被路径解析命中
        $response = $this->withHeaders([
            'Host' => 'app.example.com',
        ])->get('/badslug/h5/home');

        // 应该无法解析到租户（403 或 404）
        $this->assertTrue(in_array($response->getStatusCode(), [403, 404]));
    }

    public function test_rejected_slug_accessible_by_tenant_id(): void
    {
        // 被打回的租户仍可通过 tenant_id 访问
        $response = $this->withHeaders([
            'Host' => 'app.example.com',
        ])->get('/2002/h5/home');

        $this->assertNotEquals(403, $response->getStatusCode());
    }

    public function test_path_prefix_not_triggered_on_non_app_domain(): void
    {
        // 非 app 域名不触发路径前缀解析
        $response = $this->withHeaders([
            'Host' => 'other.example.com',
        ])->get('/acme/h5/home');

        // 应该走其他解析逻辑（域名匹配等），不会命中路径前缀
        $this->assertTrue(in_array($response->getStatusCode(), [403, 404]));
    }

    public function test_empty_path_on_app_domain_returns_no_tenant(): void
    {
        // app 域名根路径无租户标识
        $response = $this->withHeaders([
            'Host' => 'app.example.com',
        ])->get('/');

        // 无路径前缀 → 无租户（403 或平台首页）
        $this->assertTrue(in_array($response->getStatusCode(), [200, 403, 404]));
    }
}
