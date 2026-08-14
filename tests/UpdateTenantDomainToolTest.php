<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Auth\Services\RbacService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Ai\Services\Tool\UpdateTenantDomainTool;
use MultiTenantSaas\Modules\Domain\Services\DomainService;
use MultiTenantSaas\Tests\Schema\InfrastructureModule;
use MultiTenantSaas\Tests\Schema\RbacModule;

/**
 * update_tenant_domain 工具单元测试
 *
 * 覆盖：绑定写入与状态置 pending、大小写归一化、非法域名拒绝、被占用拒绝、空参数拒绝、无权限拒绝
 */
class UpdateTenantDomainToolTest extends TestCase
{
    protected array $uses = [RbacModule::class, InfrastructureModule::class];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Domain Tenant',
            'slug' => 'domain-tenant',
            'status' => 'active',
        ]);
    }

    /** 放行权限（工具内 RBAC 校验由 RbacService 负责，此处隔离测试工具自身逻辑） */
    private function allowRbac(): void
    {
        app()->instance(RbacService::class, new class
        {
            public function check(string $permission): bool
            {
                return true;
            }
        });
    }

    public function test_binds_custom_domain_and_sets_pending(): void
    {
        $this->allowRbac();

        $result = (new UpdateTenantDomainTool)([
            'domain' => 'club.example.com',
        ], $this->tenant->tenant_id);

        $this->assertTrue($result['success']);
        $this->assertSame('pending', $result['status']);
        $this->assertNotEmpty($result['verify_file_path']);

        $this->tenant->refresh();
        $this->assertSame('club.example.com', $this->tenant->domain);
        $this->assertSame(DomainService::STATUS_PENDING, TenantSetting::get($this->tenant->tenant_id, DomainService::GROUP_DOMAIN, 'domain_status'));
    }

    public function test_normalizes_domain_to_lowercase(): void
    {
        $this->allowRbac();

        $result = (new UpdateTenantDomainTool)([
            'domain' => 'Club.Example.COM',
        ], $this->tenant->tenant_id);

        $this->assertTrue($result['success']);

        $this->tenant->refresh();
        $this->assertSame('club.example.com', $this->tenant->domain);
    }

    public function test_rejects_invalid_domain_format(): void
    {
        $this->allowRbac();

        $result = (new UpdateTenantDomainTool)([
            'domain' => 'not a domain',
        ], $this->tenant->tenant_id);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('域名绑定失败', $result['message']);
    }

    public function test_rejects_domain_used_by_other_tenant(): void
    {
        $this->allowRbac();

        Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => 'active',
            'domain' => 'club.example.com',
        ]);

        $result = (new UpdateTenantDomainTool)([
            'domain' => 'club.example.com',
        ], $this->tenant->tenant_id);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('域名绑定失败', $result['message']);
    }

    public function test_rejects_empty_domain(): void
    {
        $this->allowRbac();

        $result = (new UpdateTenantDomainTool)([
            'domain' => '',
        ], $this->tenant->tenant_id);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('未提供要绑定的自定义域名', $result['message']);
    }

    public function test_rejects_without_permission(): void
    {
        // 未认证上下文：RbacService::check 返回 false
        $result = (new UpdateTenantDomainTool)([
            'domain' => 'club.example.com',
        ], $this->tenant->tenant_id);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('tenant.update', $result['message']);
    }
}
