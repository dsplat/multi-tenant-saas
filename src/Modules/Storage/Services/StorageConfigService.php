<?php

namespace MultiTenantSaas\Modules\Storage\Services;

use Illuminate\Support\Facades\Storage;
use MultiTenantSaas\Modules\Infrastructure\Models\SystemSetting;
use MultiTenantSaas\Modules\Infrastructure\Services\TenantSettingService;

/**
 * 对象存储配置服务
 *
 * 三级 fallback 链（对象存储是文件流程的必备环节，平台默认配置保证租户开箱即用）：
 * 1. 租户自有 OSS（tenant_settings group=storage）→ 动态注册 tenant-oss 磁盘
 * 2. 平台默认 OSS（system_settings group=storage）→ 动态注册 platform-oss 磁盘
 * 3. 系统兜底 config('tenancy.file_storage_disk', 'local')
 *
 * 磁盘名 ≤ 20 字符（file_uploads.disk varchar(20)）。
 */
class StorageConfigService
{
    /** 配置分组（tenant_settings / system_settings 共用） */
    public const SETTINGS_GROUP = 'storage';

    public const TENANT_DISK = 'tenant-oss';

    public const PLATFORM_DISK = 'platform-oss';

    public const SOURCE_TENANT = 'tenant';

    public const SOURCE_PLATFORM = 'platform';

    public const SOURCE_SYSTEM = 'system';

    /** 加密存储的敏感键 */
    public const ENCRYPTED_KEYS = ['access_key_secret'];

    /** 租户可配置的键 */
    public const CONFIG_KEYS = [
        'enabled', 'driver', 'endpoint', 'bucket', 'region',
        'access_key_id', 'access_key_secret', 'url', 'use_path_style',
    ];

    /** 记录 tenant-oss 磁盘当前注册的租户，避免多租户进程（队列）串用配置 */
    private ?int $registeredTenantId = null;

    private bool $platformDiskRegistered = false;

    public function __construct(private readonly TenantSettingService $tenantSettings) {}

    /**
     * 解析写入用磁盘（fallback 链），并确保动态磁盘已注册
     */
    public function resolveDisk(?int $tenantId): string
    {
        if ($tenantId !== null) {
            $config = $this->getTenantOssConfig($tenantId);
            if ($config !== null) {
                $this->registerDisk(self::TENANT_DISK, $config);
                $this->registeredTenantId = $tenantId;

                return self::TENANT_DISK;
            }
        }

        $platform = $this->getPlatformOssConfig();
        if ($platform !== null) {
            $this->registerDisk(self::PLATFORM_DISK, $platform);
            $this->platformDiskRegistered = true;

            return self::PLATFORM_DISK;
        }

        return config('tenancy.file_storage_disk', 'local');
    }

    /**
     * 读取已有文件前确保其磁盘可用（动态磁盘按 tenant_id 重新注册）
     */
    public function ensureDiskRegistered(string $disk, ?int $tenantId = null): void
    {
        if ($disk === self::TENANT_DISK && $tenantId !== null && $this->registeredTenantId !== $tenantId) {
            $config = $this->getTenantOssConfig($tenantId);
            if ($config === null) {
                throw new \RuntimeException("Tenant {$tenantId} OSS config missing for disk {$disk}");
            }
            $this->registerDisk(self::TENANT_DISK, $config);
            $this->registeredTenantId = $tenantId;
        }

        if ($disk === self::PLATFORM_DISK && ! $this->platformDiskRegistered) {
            $config = $this->getPlatformOssConfig();
            if ($config === null) {
                throw new \RuntimeException('Platform OSS config missing for disk ' . $disk);
            }
            $this->registerDisk(self::PLATFORM_DISK, $config);
            $this->platformDiskRegistered = true;
        }
    }

    /**
     * 是否云端磁盘（支持 temporaryUrl）
     */
    public function isCloudDisk(string $disk): bool
    {
        return in_array($disk, ['s3', 'oss', self::TENANT_DISK, self::PLATFORM_DISK], true);
    }

    /**
     * 当前生效来源（供设置页展示）
     */
    public function resolveStatus(int $tenantId): array
    {
        if ($this->getTenantOssConfig($tenantId) !== null) {
            $source = self::SOURCE_TENANT;
        } elseif ($this->getPlatformOssConfig() !== null) {
            $source = self::SOURCE_PLATFORM;
        } else {
            $source = self::SOURCE_SYSTEM;
        }

        return [
            'source' => $source,
            'disk' => $this->resolveDisk($tenantId),
        ];
    }

    /**
     * 租户存储配置（敏感键脱敏，供设置页回显）
     */
    public function getTenantConfig(int $tenantId): array
    {
        $config = [];
        foreach (self::CONFIG_KEYS as $key) {
            $value = $this->tenantSettings->get($tenantId, self::SETTINGS_GROUP, $key);
            if (in_array($key, self::ENCRYPTED_KEYS, true)) {
                $value = $value ? '********' : '';
            }
            $config[$key] = $value;
        }

        return $config;
    }

    /**
     * 更新租户存储配置（敏感键传掩码或空时保留原值）
     */
    public function updateTenantConfig(int $tenantId, array $data): void
    {
        foreach (self::CONFIG_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            $encrypted = in_array($key, self::ENCRYPTED_KEYS, true);

            if ($encrypted && ($value === '' || $value === null || $value === '********')) {
                continue; // 保留原密钥
            }

            $this->tenantSettings->set($tenantId, self::SETTINGS_GROUP, $key, $value, $encrypted);
        }

        // 配置变更后强制下次重新注册磁盘
        if ($this->registeredTenantId === $tenantId) {
            $this->registeredTenantId = null;
            Storage::forgetDisk(self::TENANT_DISK);
        }
    }

    /**
     * 租户 OSS 配置（未启用或不完整返回 null）
     */
    public function getTenantOssConfig(int $tenantId): ?array
    {
        if (! $this->truthy($this->tenantSettings->get($tenantId, self::SETTINGS_GROUP, 'enabled', false))) {
            return null;
        }

        return $this->buildDiskConfig([
            'driver' => $this->tenantSettings->get($tenantId, self::SETTINGS_GROUP, 'driver', 's3'),
            'endpoint' => $this->tenantSettings->get($tenantId, self::SETTINGS_GROUP, 'endpoint'),
            'bucket' => $this->tenantSettings->get($tenantId, self::SETTINGS_GROUP, 'bucket'),
            'region' => $this->tenantSettings->get($tenantId, self::SETTINGS_GROUP, 'region'),
            'access_key_id' => $this->tenantSettings->get($tenantId, self::SETTINGS_GROUP, 'access_key_id'),
            'access_key_secret' => $this->tenantSettings->get($tenantId, self::SETTINGS_GROUP, 'access_key_secret'),
            'url' => $this->tenantSettings->get($tenantId, self::SETTINGS_GROUP, 'url'),
            'use_path_style' => $this->tenantSettings->get($tenantId, self::SETTINGS_GROUP, 'use_path_style', false),
        ]);
    }

    /**
     * 平台默认 OSS 配置（system_settings group=storage，未启用或不完整返回 null）
     */
    public function getPlatformOssConfig(): ?array
    {
        if (! $this->truthy(SystemSetting::get(self::SETTINGS_GROUP, 'enabled', false))) {
            return null;
        }

        return $this->buildDiskConfig([
            'driver' => SystemSetting::get(self::SETTINGS_GROUP, 'driver', 's3'),
            'endpoint' => SystemSetting::get(self::SETTINGS_GROUP, 'endpoint'),
            'bucket' => SystemSetting::get(self::SETTINGS_GROUP, 'bucket'),
            'region' => SystemSetting::get(self::SETTINGS_GROUP, 'region'),
            'access_key_id' => SystemSetting::get(self::SETTINGS_GROUP, 'access_key_id'),
            'access_key_secret' => SystemSetting::get(self::SETTINGS_GROUP, 'access_key_secret'),
            'url' => SystemSetting::get(self::SETTINGS_GROUP, 'url'),
            'use_path_style' => SystemSetting::get(self::SETTINGS_GROUP, 'use_path_style', false),
        ]);
    }

    /**
     * 构建 Laravel S3 磁盘配置（关键字段不完整返回 null）
     */
    protected function buildDiskConfig(array $raw): ?array
    {
        if (empty($raw['bucket']) || empty($raw['access_key_id']) || empty($raw['access_key_secret'])) {
            return null;
        }

        return [
            'driver' => 's3',
            'key' => (string) $raw['access_key_id'],
            'secret' => (string) $raw['access_key_secret'],
            'region' => (string) ($raw['region'] ?: 'us-east-1'),
            'bucket' => (string) $raw['bucket'],
            'endpoint' => $raw['endpoint'] ?: null,
            'url' => $raw['url'] ?: null,
            'use_path_style_endpoint' => $this->truthy($raw['use_path_style'] ?? false),
            'throw' => false,
        ];
    }

    /**
     * 注册动态磁盘（s3 driver 依赖 league/flysystem-aws-s3-v3，缺失时明确报错）
     */
    protected function registerDisk(string $name, array $config): void
    {
        if (! class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class)) {
            throw new \RuntimeException(
                'S3 storage requires league/flysystem-aws-s3-v3. Run: composer require league/flysystem-aws-s3-v3'
            );
        }

        config(["filesystems.disks.{$name}" => $config]);
        Storage::forgetDisk($name);
    }

    /**
     * 设置值转布尔（settings 存储可能为字符串 "true"/"1"）
     */
    protected function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
