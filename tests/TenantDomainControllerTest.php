<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Domain\Services\DomainService;

class TenantDomainControllerTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(DomainService::class, app(DomainService::class));
    }
}
