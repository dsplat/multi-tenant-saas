<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\TenantService;

class TenantServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(TenantService::class, app(TenantService::class));
    }
}
