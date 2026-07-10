<?php

namespace MultiTenantSaas\Tests;

use App\Http\Controllers\Api\AdminSettingsController;

class AdminSettingsControllerTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertTrue(class_exists(AdminSettingsController::class));
    }
}
