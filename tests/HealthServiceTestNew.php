<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\HealthService;

class HealthServiceTestNew extends TestCase
{
    public function test_service_class_exists(): void
    {
        $this->assertTrue(class_exists(HealthService::class));
    }

    public function test_register_horizon_check_does_not_throw(): void
    {
        HealthService::registerHorizonCheck();
        $this->assertTrue(true);
    }
}
