<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Billing\Services\PayService;

class PayServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(PayService::class, app(PayService::class));
    }
}
