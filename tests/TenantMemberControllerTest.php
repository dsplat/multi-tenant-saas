<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\User\Http\Controllers\TenantMemberController;

class TenantMemberControllerTest extends TestCase
{
    public function test_controller_class_exists(): void
    {
        $this->assertTrue(class_exists(TenantMemberController::class));
    }
}
