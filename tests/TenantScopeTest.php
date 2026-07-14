<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;

class TenantScopeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'scope-a', 'status' => 'active']);
        Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant B', 'slug' => 'scope-b', 'status' => 'active']);
    }

    public function test_tenant_scope_filters_by_tenant_id(): void
    {
        TenantContext::setTenantId('1001');
        TenantSetting::set(1001, 'info', 'name', 'Tenant A');
        TenantSetting::set(1002, 'info', 'name', 'Tenant B');

        $settings = TenantSetting::all();
        $this->assertCount(1, $settings);
        $this->assertEquals(1001, $settings->first()->tenant_id);
    }

    public function test_without_tenant_scope_returns_all(): void
    {
        TenantContext::setTenantId('1001');
        TenantSetting::set(1001, 'info', 'name', 'Tenant A');
        TenantSetting::set(1002, 'info', 'name', 'Tenant B');

        TenantContext::setDomainType('admin');
        $settings = TenantSetting::withoutTenantScope()->get();
        $this->assertCount(2, $settings);
    }

    public function test_with_tenant_filters_by_specific_tenant(): void
    {
        TenantContext::setTenantId('1001');
        TenantSetting::set(1001, 'info', 'name', 'Tenant A');
        TenantSetting::set(1002, 'info', 'name', 'Tenant B');

        TenantContext::setDomainType('admin');
        $settings = TenantSetting::withTenant(1002)->get();
        $this->assertCount(1, $settings);
        $this->assertEquals(1002, $settings->first()->tenant_id);
    }

    public function test_without_tenant_scope_throws_in_tenant_context(): void
    {
        TenantContext::setTenantId('1001');
        TenantContext::setDomainType('tenant');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('安全限制');
        TenantSetting::withoutTenantScope()->get();
    }

    public function test_admin_context_can_bypass_scope(): void
    {
        TenantContext::setDomainType('admin');
        TenantSetting::set(1001, 'info', 'name', 'Tenant A');
        TenantSetting::set(1002, 'info', 'name', 'Tenant B');

        $settings = TenantSetting::withoutTenantScope()->get();
        $this->assertCount(2, $settings);
    }
}
