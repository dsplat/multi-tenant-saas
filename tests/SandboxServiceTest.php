<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\DeveloperPortal\Services\SandboxService;

class SandboxServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(SandboxService::class, app(SandboxService::class));
    }
}
