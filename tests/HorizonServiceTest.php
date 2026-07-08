<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\HorizonService;

class HorizonServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(HorizonService::class, app(HorizonService::class));
    }
}
