<?php

namespace MultiTenantSaas\Tests;

class TenantMemberControllerTest extends TestCase
{
    public function test_controller_class_exists(): void
    {
        $this->assertTrue(class_exists(\App\Http\Controllers\Api\TenantMemberController::class));
    }
}
