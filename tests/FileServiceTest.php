<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\FileService;

class FileServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(FileService::class, app(FileService::class));
    }
}
