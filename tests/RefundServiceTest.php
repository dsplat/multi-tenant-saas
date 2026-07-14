<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Billing\Services\RefundService;

class RefundServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(RefundService::class, app(RefundService::class));
    }
}
