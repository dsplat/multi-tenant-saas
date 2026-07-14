<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Infrastructure\Services\HorizonService;

class HorizonServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(HorizonService::class, app(HorizonService::class));
    }
}
