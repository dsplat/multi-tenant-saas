<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\User\Services\UserPreferenceService;

class UserPreferenceServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(UserPreferenceService::class, app(UserPreferenceService::class));
    }
}
