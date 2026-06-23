<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;

class DataIsolationTest extends TestCase
{
    public function test_queries_are_scoped_by_tenant_id(): void
    {
        // 为不同租户创建客户数据
        Customer::create(['tenant_id' => 1001, 'name' => 'Alice', 'email' => 'alice@example.com']);
        Customer::create(['tenant_id' => 1002, 'name' => 'Bob', 'email' => 'bob@example.com']);
        Customer::create(['tenant_id' => 1001, 'name' => 'Charlie', 'email' => 'charlie@example.com']);

        // 设置当前租户为 1001
        TenantContext::setId('1001');

        // 查询应该只返回租户 1001 的数据
        $customers = Customer::all();
        $this->assertCount(2, $customers);
        $this->assertTrue($customers->every(fn ($c) => $c->tenant_id == 1001));

        // 切换到租户 1002
        TenantContext::setId('1002');

        $customers = Customer::all();
        $this->assertCount(1, $customers);
        $this->assertEquals('Bob', $customers->first()->name);
    }

    public function test_create_auto_fills_tenant_id(): void
    {
        TenantContext::setId('1001');

        $customer = Customer::create(['name' => 'Dave']);

        $this->assertEquals('1001', $customer->tenant_id);
    }

    public function test_without_tenant_scope_returns_all(): void
    {
        Customer::create(['tenant_id' => 1001, 'name' => 'Alice']);
        Customer::create(['tenant_id' => 1002, 'name' => 'Bob']);

        TenantContext::setId('1001');

        // 带作用域：只返回 1001
        $this->assertCount(1, Customer::all());

        // withoutTenantScope：仅 admin 域名可用
        TenantContext::setDomainType('admin');
        $this->assertCount(2, Customer::withoutTenantScope()->get());
        TenantContext::setDomainType(null);
    }

    public function test_with_tenant_returns_specific_tenant_data(): void
    {
        Customer::create(['tenant_id' => 1001, 'name' => 'Alice']);
        Customer::create(['tenant_id' => 1002, 'name' => 'Bob']);
        Customer::create(['tenant_id' => 1003, 'name' => 'Charlie']);

        TenantContext::setId('1001');

        // withTenant：仅 admin 域名可用
        TenantContext::setDomainType('admin');
        $customers = Customer::withTenant('1002')->get();
        $this->assertCount(1, $customers);
        $this->assertEquals('Bob', $customers->first()->name);
        TenantContext::setDomainType(null);
    }

    public function test_for_all_tenants_returns_all(): void
    {
        Customer::create(['tenant_id' => 1001, 'name' => 'Alice']);
        Customer::create(['tenant_id' => 1002, 'name' => 'Bob']);

        TenantContext::setId('1001');

        // forAllTenants：仅 admin 域名可用
        TenantContext::setDomainType('admin');
        $customers = Customer::forAllTenants()->get();
        $this->assertCount(2, $customers);
        TenantContext::setDomainType(null);
    }

    public function test_no_tenant_context_returns_unscoped(): void
    {
        Customer::create(['tenant_id' => 1001, 'name' => 'Alice']);
        Customer::create(['tenant_id' => 1002, 'name' => 'Bob']);

        // 未设置租户上下文，应该返回所有数据
        $customers = Customer::all();
        $this->assertCount(2, $customers);
    }
}
