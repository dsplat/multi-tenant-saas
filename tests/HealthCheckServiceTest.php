<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\HealthCheckService;

class HealthCheckServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(HealthCheckService::class, app(HealthCheckService::class));
    }
}
