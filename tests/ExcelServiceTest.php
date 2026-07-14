<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Infrastructure\Services\ExcelService;

class ExcelServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(ExcelService::class, app(ExcelService::class));
    }
}
