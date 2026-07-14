<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Infrastructure\Services\TenantCreditService;

class TenantCreditControllerTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(TenantCreditService::class, app(TenantCreditService::class));
    }
}
