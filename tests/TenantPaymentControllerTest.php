<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\PayService;

class TenantPaymentControllerTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(PayService::class, app(PayService::class));
    }
}
