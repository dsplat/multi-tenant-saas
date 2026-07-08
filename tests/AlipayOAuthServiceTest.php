<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\AlipayOAuthService;

class AlipayOAuthServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(AlipayOAuthService::class, app(AlipayOAuthService::class));
    }
}
