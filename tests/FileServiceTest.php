<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Storage\Services\FileService;

class FileServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(FileService::class, app(FileService::class));
    }
}
