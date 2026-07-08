<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\SocialiteService;

class TenantOAuthControllerTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(SocialiteService::class, app(SocialiteService::class));
    }
}
