<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Infrastructure\Services\HealthService;

class HealthServiceTestNew extends TestCase
{
    public function test_service_class_exists(): void
    {
        $this->assertTrue(class_exists(HealthService::class));
    }

    public function test_register_horizon_check_does_not_throw(): void
    {
        app(HealthService::class)->registerHorizonCheck();
        $this->assertTrue(true);
    }
}
