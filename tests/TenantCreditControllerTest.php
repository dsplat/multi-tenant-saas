<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\TenantCreditService;

class TenantCreditControllerTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(TenantCreditService::class, app(TenantCreditService::class));
    }
}
