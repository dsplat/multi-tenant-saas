<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Auth\Services\LoginLogService;

class LoginLogServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(LoginLogService::class, app(LoginLogService::class));
    }
}
