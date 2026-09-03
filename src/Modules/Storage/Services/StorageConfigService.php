<?php

namespace MultiTenantSaas\Modules\Storage\Services;

use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Infrastructure\Models\SystemSetting;
use MultiTenantSaas\Modules\Infrastructure\Services\TenantSettingService;

/**
 * 对象存储配置服务
 *
 * 两级逻辑预设（不做静默 fallback）：
 * 1. 租户自有 OSS（tenant_settings group=storage）→ 动态注册 tenant-oss 磁盘
 * 2. 租户未配置 → 平台存储（system_settings group=storage）→ 动态注册 platform-oss 磁盘
 *
 * 平台存储未配置时明确报错，绝不静默落本地盘；开发/单机环境可将平台存储
 * 显式配置为 driver=local（无需云端凭证）。
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

    /** 租户/平台均未配置（此时上传会明确报错） */
    public const SOURCE_NONE = 'none';

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
     * 解析写入用磁盘（两级预设：租户存储 → 平台存储），并确保动态磁盘已注册
     *
     * @throws \RuntimeException 租户与平台存储均未配置时
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

        throw new ServiceUnavailableException(
            '对象存储未配置：租户未配置专属存储时预设使用平台存储，'
            . '请在平台后台配置存储（OSS/S3，开发环境可选 local 驱动）'
        );
    }

    /**
     * 读取已有文件前确保其磁盘可用（动态磁盘按 tenant_id 重新注册）
     */
    public function ensureDiskRegistered(string $disk, ?int $tenantId = null): void
    {
        if ($disk === self::TENANT_DISK && $tenantId !== null && $this->registeredTenantId !== $tenantId) {
            $config = $this->getTenantOssConfig($tenantId);
            if ($config === null) {
                throw new DomainException("Tenant {$tenantId} OSS config missing for disk {$disk}");
            }
            $this->registerDisk(self::TENANT_DISK, $config);
            $this->registeredTenantId = $tenantId;
        }

        if ($disk === self::PLATFORM_DISK && ! $this->platformDiskRegistered) {
            $config = $this->getPlatformOssConfig();
            if ($config === null) {
                throw new DomainException('Platform OSS config missing for disk ' . $disk);
            }
            $this->registerDisk(self::PLATFORM_DISK, $config);
            $this->platformDiskRegistered = true;
        }
    }

    /**
     * 是否云端磁盘（支持 temporaryUrl）
     *
     * 动态磁盘（tenant-oss/platform-oss）可能被配置为 local 驱动
     * （单机部署预设），此时按本地盘处理，不能仅凭磁盘名一刀切。
     */
    public function isCloudDisk(string $disk): bool
    {
        if (! in_array($disk, ['s3', 'oss', self::TENANT_DISK, self::PLATFORM_DISK], true)) {
            return false;
        }

        $driver = config("filesystems.disks.{$disk}.driver");

        // 动态磁盘已注册为 local → 本地盘；未注册/未知 → 按云盘处理（默认即 s3/oss）
        return $driver === null || $driver !== 'local';
    }

    /**
     * 对象 URL → 可访问 URL（渲染侧统一出口）
     *
     * 私有桶规范下，存库的 canon URL（bucket 域名 + key）不能直接给客户端播：
     * 若 URL 属当前生效磁盘（tenant-oss/platform-oss）则按同 key 签发限时访问 URL；
     * 本地盘（/storage/...）、外部 CDN、已签 URL 原样返回，失败时降级返回原值。
     */
    public function resolveObjectUrl(int $tenantId, string $url, int $expiresInMinutes = 120): string
    {
        if ($url === '' || ! str_starts_with($url, 'http')) {
            return $url;
        }

        try {
            $diskName = $this->resolveDisk($tenantId);
            $cfg = config("filesystems.disks.{$diskName}");

            // 非云盘（本地盘）或云盘配置不完整 → 原样返回
            if (($cfg['driver'] ?? '') !== 's3' || empty($cfg['bucket'])) {
                return $url;
            }

            // 期望 host：优先自定义 url，否则虚拟主机风格（bucket.endpoint）或 path 风格
            $baseHost = $cfg['url']
                ? parse_url((string) $cfg['url'], PHP_URL_HOST)
                : (($cfg['use_path_style_endpoint'] ?? false)
                    ? parse_url((string) $cfg['endpoint'], PHP_URL_HOST)
                    : $cfg['bucket'] . '.' . parse_url((string) $cfg['endpoint'], PHP_URL_HOST));
            $urlHost = parse_url($url, PHP_URL_HOST) ?? '';

            if (empty($baseHost) || strcasecmp((string) $baseHost, $urlHost) !== 0) {
                return $url;
            }

            $key = ltrim((string) parse_url($url, PHP_URL_PATH), '/');

            return Storage::disk($diskName)->temporaryUrl($key, now()->addMinutes($expiresInMinutes));
        } catch (\Throwable $e) {
            return $url;
        }
    }

    /**
     * 当前生效来源（供设置页展示）；未配置时 source=none、disk=null，不抛异常
     */
    public function resolveStatus(int $tenantId): array
    {
        if ($this->getTenantOssConfig($tenantId) !== null || $this->getPlatformOssConfig() !== null) {
            $disk = $this->resolveDisk($tenantId);

            return [
                'source' => $disk === self::TENANT_DISK ? self::SOURCE_TENANT : self::SOURCE_PLATFORM,
                'disk' => $disk,
            ];
        }

        return [
            'source' => self::SOURCE_NONE,
            'disk' => null,
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
     * 构建 Laravel 磁盘配置（s3 关键字段不完整返回 null）
     */
    protected function buildDiskConfig(array $raw): ?array
    {
        // local 驱动：显式选择服务器本地盘（开发/单机部署预设），无需云端凭证
        if (($raw['driver'] ?? 's3') === 'local') {
            return [
                'driver' => 'local',
                'root' => storage_path('app'),
                'throw' => false,
            ];
        }

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
        // 配置未变化时跳过，避免反复 forgetDisk（也保证测试中 Storage::fake 不被清除）
        if (config("filesystems.disks.{$name}") === $config) {
            return;
        }

        if (($config['driver'] ?? 's3') === 's3' && ! class_exists(AwsS3V3Adapter::class)) {
            throw new DomainException(
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
