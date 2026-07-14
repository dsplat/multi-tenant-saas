<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Infrastructure\Services\QuotaService;

class QuotaServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(QuotaService::class, app(QuotaService::class));
    }
}
