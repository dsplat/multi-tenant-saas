<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Auth\Services\MfaService;
use MultiTenantSaas\Tests\Schema\MfaModule;

class MfaControllerTest extends TestCase
{
    protected array $uses = [MfaModule::class];

    public function test_service_exists(): void
    {
        $this->assertInstanceOf(MfaService::class, app(MfaService::class));
    }
}
