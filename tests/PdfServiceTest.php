<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\PdfService;

class PdfServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(PdfService::class, app(PdfService::class));
    }
}
