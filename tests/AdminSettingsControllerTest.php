<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Platform\Http\Controllers\AdminSettingsController;

class AdminSettingsControllerTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertTrue(class_exists(AdminSettingsController::class));
    }
}
