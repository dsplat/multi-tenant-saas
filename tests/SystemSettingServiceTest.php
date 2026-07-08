<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\SystemSettingService;

class SystemSettingServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(SystemSettingService::class, app(SystemSettingService::class));
    }
}
