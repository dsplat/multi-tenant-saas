<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Infrastructure\Services\TenantSettingService;

class TenantSettingServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(TenantSettingService::class, app(TenantSettingService::class));
    }
}
