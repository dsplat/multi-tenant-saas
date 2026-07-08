<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\StructuredLogService;

class StructuredLogServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(StructuredLogService::class, app(StructuredLogService::class));
    }
}
