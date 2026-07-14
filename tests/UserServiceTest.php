<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\User\Services\UserService;

class UserServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(UserService::class, app(UserService::class));
    }
}
