<?php

namespace MultiTenantSaas\Tests;

use App\Http\Controllers\Api\TenantSslController;

class TenantSslControllerTest extends TestCase
{
    public function test_controller_class_exists(): void
    {
        $this->assertTrue(class_exists(TenantSslController::class));
    }
}
