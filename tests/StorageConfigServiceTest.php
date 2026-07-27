<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantConfigStore;
use MultiTenantSaas\Modules\Infrastructure\Models\SystemSetting;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Services\TenantSettingService;
use MultiTenantSaas\Modules\Storage\Services\StorageConfigService;
use MultiTenantSaas\Tests\Schema\InfrastructureModule;

/**
 * StorageConfigService 测试套件
 *
 * 覆盖：两级预设（租户存储 → 平台存储，未配置明确报错）、local 驱动显式预设、
 * 敏感键加密与掩码保留、生效来源解析。
 */
class StorageConfigServiceTest extends TestCase
{
    protected array $uses = [InfrastructureModule::class];

    protected ?StorageConfigService $service = null;

    protected ?TenantSettingService $tenantSettings = null;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 3001, 'name' => 'Tenant A', 'slug' => 'oss-tenant-a', 'status' => 'active']);

        // TenantConfigStore 为进程级静态缓存，清除跨用例残留
        TenantConfigStore::clear();

        $this->tenantSettings = $this->app->make(TenantSettingService::class);
        // 桩掉磁盘注册（测试环境未安装 league/flysystem-aws-s3-v3）
        $this->service = new class($this->tenantSettings) extends StorageConfigService
        {
            public array $registered = [];

            protected function registerDisk(string $name, array $config): void
            {
                $this->registered[$name] = $config;
            }
        };
    }

    public function test_throws_when_nothing_configured(): void
    {
        $status = $this->service->resolveStatus(3001);
        $this->assertSame(StorageConfigService::SOURCE_NONE, $status['source']);
        $this->assertNull($status['disk']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('对象存储未配置');
        $this->service->resolveDisk(3001);
    }

    public function test_platform_local_driver_is_explicit_preset(): void
    {
        // 开发/单机环境：平台存储显式配置为 local 驱动，无需云端凭证
        SystemSetting::set(StorageConfigService::SETTINGS_GROUP, 'enabled', true);
        SystemSetting::set(StorageConfigService::SETTINGS_GROUP, 'driver', 'local');

        $this->assertSame(StorageConfigService::PLATFORM_DISK, $this->service->resolveDisk(3001));

        $config = $this->service->registered[StorageConfigService::PLATFORM_DISK];
        $this->assertSame('local', $config['driver']);
        $this->assertArrayHasKey('root', $config);
    }

    public function test_uses_platform_default_when_tenant_not_configured(): void
    {
        $this->enablePlatformOss();

        $this->assertSame(StorageConfigService::TENANT_DISK, StorageConfigService::TENANT_DISK);
        $this->assertSame(StorageConfigService::PLATFORM_DISK, $this->service->resolveDisk(3001));

        $config = $this->service->registered[StorageConfigService::PLATFORM_DISK];
        $this->assertSame('platform-bucket', $config['bucket']);
        $this->assertSame('platform-ak', $config['key']);
        $this->assertSame('platform-sk', $config['secret']);

        $status = $this->service->resolveStatus(3001);
        $this->assertSame(StorageConfigService::SOURCE_PLATFORM, $status['source']);
    }

    public function test_tenant_own_oss_takes_priority(): void
    {
        $this->enablePlatformOss();
        $this->enableTenantOss(3001);

        $this->assertSame(StorageConfigService::TENANT_DISK, $this->service->resolveDisk(3001));

        $config = $this->service->registered[StorageConfigService::TENANT_DISK];
        $this->assertSame('tenant-bucket', $config['bucket']);
        $this->assertSame('tenant-sk', $config['secret']);

        $status = $this->service->resolveStatus(3001);
        $this->assertSame(StorageConfigService::SOURCE_TENANT, $status['source']);
    }

    public function test_incomplete_config_is_ignored(): void
    {
        // 启用但缺 bucket/密钥
        SystemSetting::set(StorageConfigService::SETTINGS_GROUP, 'enabled', true);
        SystemSetting::set(StorageConfigService::SETTINGS_GROUP, 'endpoint', 'https://oss.example.com');

        $this->assertNull($this->service->getPlatformOssConfig());

        // 租户启用但配置不完整同样忽略
        $this->tenantSettings->set(3001, StorageConfigService::SETTINGS_GROUP, 'enabled', true);
        $this->assertNull($this->service->getTenantOssConfig(3001));

        // 两级均无有效配置 → 明确报错
        $this->expectException(\RuntimeException::class);
        $this->service->resolveDisk(3001);
    }

    public function test_disabled_config_is_ignored(): void
    {
        $this->enablePlatformOss();
        SystemSetting::set(StorageConfigService::SETTINGS_GROUP, 'enabled', false);

        $this->assertNull($this->service->getPlatformOssConfig());

        $this->expectException(\RuntimeException::class);
        $this->service->resolveDisk(3001);
    }

    public function test_update_tenant_config_keeps_secret_when_masked(): void
    {
        $this->service->updateTenantConfig(3001, [
            'enabled' => true,
            'bucket' => 'tenant-bucket',
            'access_key_id' => 'tenant-ak',
            'access_key_secret' => 'tenant-sk',
        ]);

        // 掩码不覆盖原密钥
        $this->service->updateTenantConfig(3001, [
            'access_key_secret' => '********',
            'bucket' => 'renamed-bucket',
        ]);

        $this->assertSame(
            'tenant-sk',
            $this->tenantSettings->get(3001, StorageConfigService::SETTINGS_GROUP, 'access_key_secret')
        );
        $this->assertSame(
            'renamed-bucket',
            $this->tenantSettings->get(3001, StorageConfigService::SETTINGS_GROUP, 'bucket')
        );

        // 回显脱敏
        $config = $this->service->getTenantConfig(3001);
        $this->assertSame('********', $config['access_key_secret']);
        $this->assertSame('renamed-bucket', $config['bucket']);
    }

    public function test_is_cloud_disk(): void
    {
        $this->assertTrue($this->service->isCloudDisk('s3'));
        $this->assertTrue($this->service->isCloudDisk('oss'));
        $this->assertTrue($this->service->isCloudDisk(StorageConfigService::TENANT_DISK));
        $this->assertTrue($this->service->isCloudDisk(StorageConfigService::PLATFORM_DISK));
        $this->assertFalse($this->service->isCloudDisk('local'));
    }

    public function test_dynamic_disk_names_fit_column_limit(): void
    {
        // file_uploads.disk 为 varchar(20)
        $this->assertLessThanOrEqual(20, strlen(StorageConfigService::TENANT_DISK));
        $this->assertLessThanOrEqual(20, strlen(StorageConfigService::PLATFORM_DISK));
    }

    protected function enablePlatformOss(): void
    {
        SystemSetting::set(StorageConfigService::SETTINGS_GROUP, 'enabled', true);
        SystemSetting::set(StorageConfigService::SETTINGS_GROUP, 'endpoint', 'https://oss.platform.com');
        SystemSetting::set(StorageConfigService::SETTINGS_GROUP, 'bucket', 'platform-bucket');
        SystemSetting::set(StorageConfigService::SETTINGS_GROUP, 'region', 'cn-hangzhou');
        SystemSetting::set(StorageConfigService::SETTINGS_GROUP, 'access_key_id', 'platform-ak');
        SystemSetting::set(StorageConfigService::SETTINGS_GROUP, 'access_key_secret', 'platform-sk', true);
    }

    protected function enableTenantOss(int $tenantId): void
    {
        $this->tenantSettings->set($tenantId, StorageConfigService::SETTINGS_GROUP, 'enabled', true);
        $this->tenantSettings->set($tenantId, StorageConfigService::SETTINGS_GROUP, 'endpoint', 'https://oss.tenant.com');
        $this->tenantSettings->set($tenantId, StorageConfigService::SETTINGS_GROUP, 'bucket', 'tenant-bucket');
        $this->tenantSettings->set($tenantId, StorageConfigService::SETTINGS_GROUP, 'region', 'cn-shanghai');
        $this->tenantSettings->set($tenantId, StorageConfigService::SETTINGS_GROUP, 'access_key_id', 'tenant-ak');
        $this->tenantSettings->set($tenantId, StorageConfigService::SETTINGS_GROUP, 'access_key_secret', 'tenant-sk', true);
    }
}
