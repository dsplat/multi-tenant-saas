<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Auth\Services\SocialiteService;

class SocialiteServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(SocialiteService::class, app(SocialiteService::class));
    }
}
