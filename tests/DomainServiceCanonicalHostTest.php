<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Domain\Services\DomainService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Tests\Schema\CoreModule;

/**
 * DomainService::getCanonicalHost 规范入口解析测试
 *
 * 规则（与 EnforceCanonicalEntry 单一事实源）：
 *   自定义域名（domain 非空 且 domain_status=approved）
 *   > {slug}.{wildcard_base}（slug_status=active）
 *   > {tenant_id}.{wildcard_base}（兜底）
 *   全不满足 → null
 */
class DomainServiceCanonicalHostTest extends TestCase
{
    protected array $uses = [CoreModule::class];

    protected DomainService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DomainService;

        config(['domain.wildcard_base' => 'example.com']);
    }

    protected function createTenant(array $overrides = []): Tenant
    {
        static $seq = 5000;
        $seq++;

        return Tenant::create(array_merge([
            'tenant_id' => $seq,
            'name' => 'Tenant ' . $seq,
            'slug' => 't-a1b2c3',
            'slug_status' => 'active',
            'status' => 'active',
            'domain' => null,
        ], $overrides));
    }

    public function test_approved_custom_domain_wins(): void
    {
        $tenant = $this->createTenant(['domain' => 'club.mtedu.com']);
        TenantSetting::set($tenant->tenant_id, DomainService::GROUP_DOMAIN, 'domain_status', DomainService::STATUS_APPROVED);

        $this->assertSame('club.mtedu.com', $this->service->getCanonicalHost((int) $tenant->tenant_id));
    }

    public function test_pending_custom_domain_not_effective_falls_back_to_slug(): void
    {
        $tenant = $this->createTenant([
            'domain' => 'club.mtedu.com',
            'slug' => 't-93kjb7',
            'slug_status' => 'active',
        ]);
        // domain_status 默认 pending（未 approve），不生效 → slug 二级域名
        $this->assertSame('t-93kjb7.example.com', $this->service->getCanonicalHost((int) $tenant->tenant_id));
    }

    public function test_slug_active_returns_slug_subdomain(): void
    {
        $tenant = $this->createTenant(['slug' => 'lanyantu', 'slug_status' => 'active']);

        $this->assertSame('lanyantu.example.com', $this->service->getCanonicalHost((int) $tenant->tenant_id));
    }

    public function test_slug_inactive_falls_back_to_tenant_id(): void
    {
        $tenant = $this->createTenant(['slug' => 't-x1y2z3', 'slug_status' => 'rejected']);

        $this->assertSame("{$tenant->tenant_id}.example.com", $this->service->getCanonicalHost((int) $tenant->tenant_id));
    }

    public function test_no_slug_falls_back_to_tenant_id(): void
    {
        $tenant = $this->createTenant(['slug' => null, 'slug_status' => null]);

        $this->assertSame("{$tenant->tenant_id}.example.com", $this->service->getCanonicalHost((int) $tenant->tenant_id));
    }

    public function test_no_wildcard_base_returns_null(): void
    {
        config(['domain.wildcard_base' => null]);

        $tenant = $this->createTenant();

        $this->assertNull($this->service->getCanonicalHost((int) $tenant->tenant_id));
    }

    public function test_missing_tenant_returns_null(): void
    {
        $this->assertNull($this->service->getCanonicalHost(999999999));
    }
}
