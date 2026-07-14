<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Infrastructure\Services\CrossTenantService;

class CrossTenantServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(CrossTenantService::class, app(CrossTenantService::class));
    }
}
