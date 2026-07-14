<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Infrastructure\Services\ApiVersionService;

class ApiVersionServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(ApiVersionService::class, app(ApiVersionService::class));
    }
}
