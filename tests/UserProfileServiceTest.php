<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\UserProfileService;

class UserProfileServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(UserProfileService::class, app(UserProfileService::class));
    }
}
