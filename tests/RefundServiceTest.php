<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\RefundService;

class RefundServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(RefundService::class, app(RefundService::class));
    }
}
