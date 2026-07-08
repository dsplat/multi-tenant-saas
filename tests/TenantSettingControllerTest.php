<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\TenantSettingService;

class TenantSettingControllerTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(TenantSettingService::class, app(TenantSettingService::class));
    }

    public function test_set_and_get_setting(): void
    {
        TenantSettingService::set(9999, 'test_group', 'test_key', 'test_value');
        $result = TenantSettingService::get(9999, 'test_group', 'test_key');
        $this->assertEquals('test_value', $result);
    }
}
