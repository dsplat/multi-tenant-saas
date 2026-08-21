<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Infrastructure\Models\SystemSetting;
use MultiTenantSaas\Modules\Storage\Models\FileUpload;
use MultiTenantSaas\Modules\Storage\Services\FileService;
use MultiTenantSaas\Modules\Storage\Services\StorageConfigService;
use MultiTenantSaas\Tests\Schema\BillingModule;
use MultiTenantSaas\Tests\Schema\InfrastructureModule;
use MultiTenantSaas\Tests\Schema\PluginModule;

class FileServiceTest extends TestCase
{
    protected array $uses = [BillingModule::class, InfrastructureModule::class, PluginModule::class];

    public function test_service_exists(): void
    {
        $this->assertInstanceOf(FileService::class, app(FileService::class));
    }

    public function test_get_url_for_local_private_file_uses_download_route(): void
    {
        // 单机部署预设：平台存储为 local 驱动
        SystemSetting::set(StorageConfigService::SETTINGS_GROUP, 'enabled', true);
        SystemSetting::set(StorageConfigService::SETTINGS_GROUP, 'driver', 'local');

        // 触发磁盘注册（真实链路：upload → resolveDisk → registerDisk）
        $storageConfig = app(StorageConfigService::class);
        $this->assertSame(
            StorageConfigService::PLATFORM_DISK,
            $storageConfig->resolveDisk(1001)
        );
        $this->assertFalse($storageConfig->isCloudDisk(StorageConfigService::PLATFORM_DISK));

        $file = FileUpload::create([
            'tenant_id' => 1001,
            'disk' => StorageConfigService::PLATFORM_DISK,
            'path' => 'uploads/1001/general/private/test.png',
            'filename' => 'test.png',
            'mime_type' => 'image/png',
            'size' => 10,
            'hash' => 'abc',
            'category' => 'general',
            'is_public' => false,
        ]);

        $url = app(FileService::class)->getUrl($file);

        // 主键为 file_upload_id，URL 不得出现空 id（历史 bug：files//download）
        $this->assertStringContainsString("/api/v1/files/{$file->file_upload_id}/download", $url);
        $this->assertStringNotContainsString('//download', $url);
    }
}
