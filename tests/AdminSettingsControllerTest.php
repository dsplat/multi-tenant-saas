<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\AdminSettingsService;

class AdminSettingsControllerTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertTrue(class_exists(\App\Http\Controllers\Api\AdminSettingsController::class));
    }
}
