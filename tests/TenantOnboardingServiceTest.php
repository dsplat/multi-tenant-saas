<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Infrastructure\Services\TenantOnboardingService;

class TenantOnboardingServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(TenantOnboardingService::class, app(TenantOnboardingService::class));
    }
}
