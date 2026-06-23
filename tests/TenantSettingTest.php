<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TenantSetting;

class TenantSettingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);
    }

    public function test_can_get_and_set_setting(): void
    {
        TenantSetting::set(1001, 'info', 'name', 'Test Tenant');
        $this->assertEquals('Test Tenant', TenantSetting::get(1001, 'info', 'name'));
    }

    public function test_can_get_group_settings(): void
    {
        TenantSetting::set(1001, 'oauth', 'wechat_app_id', 'wx123');
        TenantSetting::set(1001, 'oauth', 'wechat_secret', 'secret');
        $group = TenantSetting::getGroup(1001, 'oauth');
        $this->assertEquals('wx123', $group['wechat_app_id']);
    }

    public function test_encrypted_setting_is_stored_encrypted(): void
    {
        TenantSetting::set(1001, 'oauth', 'client_secret', 'my-secret', true);
        $value = TenantSetting::get(1001, 'oauth', 'client_secret');
        $this->assertEquals('my-secret', $value);

        $raw = \DB::table('tenant_settings')
            ->where('tenant_id', 1001)
            ->where('key', 'client_secret')
            ->first();
        $this->assertNotEquals('my-secret', $raw->value);
        $this->assertTrue((bool) $raw->is_encrypted);
    }

    public function test_can_remove_setting(): void
    {
        TenantSetting::set(1001, 'info', 'name', 'Test');
        $this->assertNotNull(TenantSetting::get(1001, 'info', 'name'));
        TenantSetting::remove(1001, 'info', 'name');
        $this->assertNull(TenantSetting::get(1001, 'info', 'name'));
    }

    public function test_settings_are_isolated_by_tenant(): void
    {
        TenantSetting::set(1001, 'info', 'name', 'Tenant A');
        TenantSetting::set(1002, 'info', 'name', 'Tenant B');
        $this->assertEquals('Tenant A', TenantSetting::get(1001, 'info', 'name'));
        $this->assertEquals('Tenant B', TenantSetting::get(1002, 'info', 'name'));
    }
}
