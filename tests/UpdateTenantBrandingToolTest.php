<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Auth\Services\RbacService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Ai\Services\Tool\UpdateTenantBrandingTool;
use MultiTenantSaas\Tests\Schema\MfaModule;
use MultiTenantSaas\Tests\Schema\RbacModule;

/**
 * update_tenant_branding 工具单元测试
 *
 * 覆盖：字段白名单写入与 branding 合并、空参数拒绝、无权限拒绝
 */
class UpdateTenantBrandingToolTest extends TestCase
{
    protected array $uses = [MfaModule::class, RbacModule::class];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Brand Tenant',
            'slug' => 'brand-tenant',
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

    public function test_updates_branding_fields(): void
    {
        $this->allowRbac();

        $result = (new UpdateTenantBrandingTool)([
            'name' => '新团队名',
            'logo' => 'https://example.com/logo.png',
            'primary_color' => '#1890ff',
        ], $this->tenant->tenant_id);

        $this->assertTrue($result['success']);

        $this->tenant->refresh();
        $this->assertSame('新团队名', $this->tenant->name);
        $this->assertSame('https://example.com/logo.png', $this->tenant->logo);
        $this->assertSame('#1890ff', $this->tenant->branding['primary_color']);
    }

    public function test_branding_merge_keeps_existing_keys(): void
    {
        $this->allowRbac();

        $this->tenant->branding = ['login_page_message' => '旧欢迎语'];
        $this->tenant->save();

        $result = (new UpdateTenantBrandingTool)([
            'primary_color' => '#10b981',
        ], $this->tenant->tenant_id);

        $this->assertTrue($result['success']);

        $this->tenant->refresh();
        $this->assertSame('#10b981', $this->tenant->branding['primary_color']);
        $this->assertSame('旧欢迎语', $this->tenant->branding['login_page_message']);
    }

    public function test_rejects_empty_arguments(): void
    {
        $this->allowRbac();

        $result = (new UpdateTenantBrandingTool)([
            'name' => '',
            'logo' => null,
        ], $this->tenant->tenant_id);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('未提供任何要设置的字段', $result['message']);
    }

    public function test_rejects_without_permission(): void
    {
        // 未认证上下文：RbacService::check 返回 false
        $result = (new UpdateTenantBrandingTool)([
            'name' => '新团队名',
        ], $this->tenant->tenant_id);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('tenant.update', $result['message']);
    }
}
