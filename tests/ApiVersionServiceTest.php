<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\ApiVersionService;

class ApiVersionServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(ApiVersionService::class, app(ApiVersionService::class));
    }
}
