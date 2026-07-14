<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

class TenantContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 创建测试租户
        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'status' => 'active',
        ]);
    }

    public function test_can_set_and_get_tenant_id(): void
    {
        TenantContext::setId('1001');

        $this->assertEquals('1001', TenantContext::getId());
    }

    public function test_can_set_and_get_tenant(): void
    {
        $tenant = Tenant::find(1001);
        TenantContext::setTenant($tenant);

        $this->assertEquals($tenant, TenantContext::getTenant());
        $this->assertEquals('1001', TenantContext::getId());
    }

    public function test_clear_resets_all_context(): void
    {
        TenantContext::setId('1001');
        TenantContext::setDomainType('customer');
        TenantContext::setTenantRole('admin');

        TenantContext::clear();

        $this->assertNull(TenantContext::getId());
        $this->assertNull(TenantContext::getTenant());
        $this->assertNull(TenantContext::getDomainType());
        $this->assertNull(TenantContext::getTenantRole());
    }

    public function test_domain_type_can_be_set_and_retrieved(): void
    {
        TenantContext::setDomainType('admin');
        $this->assertEquals('admin', TenantContext::getDomainType());

        TenantContext::setDomainType('customer');
        $this->assertEquals('customer', TenantContext::getDomainType());
    }

    public function test_tenant_role_can_be_set_and_retrieved(): void
    {
        TenantContext::setTenantRole('tenant_admin');
        $this->assertEquals('tenant_admin', TenantContext::getTenantRole());

        TenantContext::setTenantRole('end_user');
        $this->assertEquals('end_user', TenantContext::getTenantRole());
    }
}
