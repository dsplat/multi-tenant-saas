<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Auth\Services\RbacService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Ai\Services\Tool\UpdateTenantSettingsTool;
use MultiTenantSaas\Tests\Schema\MfaModule;
use MultiTenantSaas\Tests\Schema\RbacModule;

/**
 * update_tenant_settings 工具单元测试
 *
 * 覆盖：mail 组写入与脱敏、非法组/非法字段拒绝、空 settings 拒绝、无权限拒绝
 */
class UpdateTenantSettingsToolTest extends TestCase
{
    protected array $uses = [MfaModule::class, RbacModule::class];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Settings Tenant',
            'slug' => 'settings-tenant',
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

    public function test_updates_mail_group_and_masks_password(): void
    {
        $this->allowRbac();

        $result = (new UpdateTenantSettingsTool)([
            'group' => 'mail',
            'settings' => [
                'driver' => 'smtp',
                'host' => 'smtp.example.com',
                'port' => '465',
                'username' => 'noreply@example.com',
                'password' => 'secret123',
                'encryption' => 'ssl',
            ],
        ], $this->tenant->tenant_id);

        $this->assertTrue($result['success']);
        $this->assertSame('mail', $result['group']);

        // 落库核验
        $this->assertSame('smtp.example.com', TenantSetting::get($this->tenant->tenant_id, 'mail', 'host'));
        $this->assertSame('secret123', TenantSetting::get($this->tenant->tenant_id, 'mail', 'password'));

        // 返回结果中密码脱敏
        $this->assertSame('***', $result['updated']['password']['new']);
    }

    public function test_updates_registration_group(): void
    {
        $this->allowRbac();

        $result = (new UpdateTenantSettingsTool)([
            'group' => 'registration',
            'settings' => ['allow_register' => '1', 'welcome_credits' => '100'],
        ], $this->tenant->tenant_id);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, TenantSetting::get($this->tenant->tenant_id, 'registration', 'allow_register'));
        $this->assertEquals(100, TenantSetting::get($this->tenant->tenant_id, 'registration', 'welcome_credits'));
    }

    public function test_rejects_unknown_group(): void
    {
        $this->allowRbac();

        $result = (new UpdateTenantSettingsTool)([
            'group' => 'oauth',
            'settings' => ['wechat_client_id' => 'x'],
        ], $this->tenant->tenant_id);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('不支持的配置组', $result['message']);
    }

    public function test_rejects_unknown_key_in_group(): void
    {
        $this->allowRbac();

        $result = (new UpdateTenantSettingsTool)([
            'group' => 'mail',
            'settings' => ['host' => 'smtp.example.com', 'evil_key' => '1'],
        ], $this->tenant->tenant_id);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('evil_key', $result['message']);
        // 白名单外的字段不应部分写入
        $this->assertNull(TenantSetting::get($this->tenant->tenant_id, 'mail', 'host'));
    }

    public function test_rejects_empty_settings(): void
    {
        $this->allowRbac();

        $result = (new UpdateTenantSettingsTool)([
            'group' => 'mail',
            'settings' => [],
        ], $this->tenant->tenant_id);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('未提供 settings', $result['message']);
    }

    public function test_rejects_without_permission(): void
    {
        // 未认证上下文：RbacService::check 返回 false
        $result = (new UpdateTenantSettingsTool)([
            'group' => 'mail',
            'settings' => ['host' => 'smtp.example.com'],
        ], $this->tenant->tenant_id);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('setting.update', $result['message']);
    }
}
