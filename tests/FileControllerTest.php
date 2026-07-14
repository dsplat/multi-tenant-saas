<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Storage\Services\FileService;

class FileControllerTest extends TestCase
{
    // ========== 文件服务测试 ==========

    public function test_file_service_exists(): void
    {
        $service = app(FileService::class);

        $this->assertInstanceOf(FileService::class, $service);
    }
}
