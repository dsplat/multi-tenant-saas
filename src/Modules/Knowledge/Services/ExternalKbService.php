<?php

namespace MultiTenantSaas\Modules\Knowledge\Services;

use MultiTenantSaas\Modules\Infrastructure\Models\SystemSetting;
use MultiTenantSaas\Modules\Knowledge\Contracts\ExternalKbProviderContract;
use MultiTenantSaas\Modules\Knowledge\Models\ExternalKbConnection;
use MultiTenantSaas\Modules\Knowledge\Services\Providers\BailianProvider;
use MultiTenantSaas\Modules\Knowledge\Services\Providers\DifyProvider;
use MultiTenantSaas\Modules\Knowledge\Services\Providers\FastGptProvider;
use MultiTenantSaas\Modules\Knowledge\Services\Providers\RagFlowProvider;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 外部知识库服务
 *
 * 租户级连接管理 + 平台默认回退：
 * 1. 租户已配置激活连接 → 使用租户自己的外部知识库
 * 2. 租户未配置 → 回退平台默认连接（system_settings group=external_kb）
 * 3. 两者均未配置 → resolveProviderConfig 返回 null，调用方自行降级
 *
 * 外部知识库是 AI 检索链路的必备环节，平台默认配置保证租户开箱即用。
 */
class ExternalKbService
{
    /** 平台默认配置的 system_settings 分组 */
    public const SETTINGS_GROUP = 'external_kb';

    public const SOURCE_TENANT = 'tenant';

    public const SOURCE_PLATFORM = 'platform';

    /** @var array<string, class-string<ExternalKbProviderContract>> */
    public const PROVIDERS = [
        'dify' => DifyProvider::class,
        'ragflow' => RagFlowProvider::class,
        'fastgpt' => FastGptProvider::class,
        'bailian' => BailianProvider::class,
    ];

    /**
     * 创建 Provider 实例
     */
    public function makeProvider(string $providerType): ExternalKbProviderContract
    {
        $class = self::PROVIDERS[$providerType] ?? null;

        if ($class === null) {
            throw new \InvalidArgumentException("Unsupported external kb provider: {$providerType}");
        }

        return new $class;
    }

    /**
     * 租户连接列表（不暴露密钥）
     */
    public function listConnections(int $tenantId): array
    {
        return $this->tenantQuery($tenantId)
            ->orderByDesc('connection_id')
            ->get()
            ->map(fn (ExternalKbConnection $c) => $this->presentConnection($c))
            ->all();
    }

    /**
     * 创建租户连接
     */
    public function createConnection(int $tenantId, array $data): ExternalKbConnection
    {
        $connection = new ExternalKbConnection([
            'tenant_id' => $tenantId,
            'provider_type' => $data['provider_type'],
            'name' => $data['name'],
            'api_url' => $data['api_url'],
            'status' => $data['status'] ?? ExternalKbConnection::STATUS_ACTIVE,
            'config' => $data['config'] ?? null,
        ]);
        $connection->setApiKey($data['api_key'] ?? null);
        $connection->save();

        return $connection;
    }

    /**
     * 更新租户连接（api_key 传掩码或空时保留原值）
     */
    public function updateConnection(int $tenantId, int $connectionId, array $data): ExternalKbConnection
    {
        $connection = $this->findConnection($tenantId, $connectionId);

        $connection->fill(array_intersect_key($data, array_flip([
            'provider_type', 'name', 'api_url', 'status', 'config',
        ])));

        if (isset($data['api_key']) && $data['api_key'] !== '' && $data['api_key'] !== '********') {
            $connection->setApiKey($data['api_key']);
        }

        $connection->save();

        return $connection;
    }

    /**
     * 删除租户连接
     */
    public function deleteConnection(int $tenantId, int $connectionId): bool
    {
        return (bool) $this->findConnection($tenantId, $connectionId)->delete();
    }

    /**
     * 测试租户连接（真实调用 Provider 健康端点）
     */
    public function testConnection(int $tenantId, int $connectionId): array
    {
        $connection = $this->findConnection($tenantId, $connectionId);

        $provider = $this->makeProvider($connection->provider_type);
        $provider->configure($connection->toProviderConfig());

        $result = $provider->test();

        $connection->update([
            'status' => $result['success'] ? ExternalKbConnection::STATUS_ACTIVE : $connection->status,
            'metadata' => array_merge($connection->metadata ?? [], [
                'last_test' => [
                    'success' => $result['success'],
                    'message' => $result['message'],
                    'tested_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        return $result;
    }

    /**
     * 解析生效的 Provider 配置（租户连接优先，回退平台默认）
     *
     * @return array{source: string, provider_type: string, config: array, connection_id: int|null}|null
     */
    public function resolveProviderConfig(?int $tenantId): ?array
    {
        if ($tenantId !== null) {
            $connection = $this->tenantQuery($tenantId)->active()->orderByDesc('connection_id')->first();

            if ($connection !== null) {
                return [
                    'source' => self::SOURCE_TENANT,
                    'provider_type' => $connection->provider_type,
                    'config' => $connection->toProviderConfig(),
                    'connection_id' => (int) $connection->connection_id,
                ];
            }
        }

        $platform = $this->getPlatformDefault();

        if ($platform !== null) {
            return [
                'source' => self::SOURCE_PLATFORM,
                'provider_type' => $platform['provider_type'],
                'config' => $platform,
                'connection_id' => null,
            ];
        }

        return null;
    }

    /**
     * 平台默认连接配置（system_settings group=external_kb，未配置或未启用返回 null）
     */
    public function getPlatformDefault(): ?array
    {
        if (! SystemSetting::get(self::SETTINGS_GROUP, 'enabled', false)) {
            return null;
        }

        $config = [
            'provider_type' => (string) SystemSetting::get(self::SETTINGS_GROUP, 'provider_type', ''),
            'api_url' => (string) SystemSetting::get(self::SETTINGS_GROUP, 'api_url', ''),
            'api_key' => (string) SystemSetting::get(self::SETTINGS_GROUP, 'api_key', ''),
            'dataset_id' => (string) SystemSetting::get(self::SETTINGS_GROUP, 'dataset_id', ''),
            // 阿里云百炼等云厂商 Provider 的扩展凭证键
            'access_key_id' => (string) SystemSetting::get(self::SETTINGS_GROUP, 'access_key_id', ''),
            'workspace_id' => (string) SystemSetting::get(self::SETTINGS_GROUP, 'workspace_id', ''),
            'index_id' => (string) SystemSetting::get(self::SETTINGS_GROUP, 'index_id', ''),
        ];

        if ($config['provider_type'] === '' || $config['api_url'] === '' || ! isset(self::PROVIDERS[$config['provider_type']])) {
            return null;
        }

        return $config;
    }

    /**
     * 检索外部知识库（自动走租户/平台回退链）
     *
     * @return array{source: string|null, results: array}
     */
    public function search(string $query, int $limit = 10, ?int $tenantId = null): array
    {
        $resolved = $this->resolveProviderConfig($tenantId);

        if ($resolved === null) {
            return ['source' => null, 'results' => []];
        }

        $provider = $this->makeProvider($resolved['provider_type']);
        $provider->configure($resolved['config']);

        return [
            'source' => $resolved['source'],
            'results' => $provider->search($query, $limit),
        ];
    }

    /**
     * 推送文本文档到指定租户连接（文档知识库 → 外部同步）
     *
     * @return array{success: bool, message: string, external_id: string|null}
     */
    public function pushDocument(int $tenantId, int $connectionId, string $name, string $content): array
    {
        $connection = $this->findConnection($tenantId, $connectionId);

        $provider = $this->makeProvider($connection->provider_type);
        $provider->configure($connection->toProviderConfig());

        $result = $provider->pushDocument($name, $content);

        if ($result['success']) {
            $connection->update(['last_synced_at' => now()]);
        }

        return $result;
    }

    /**
     * 生效状态（供设置页展示：当前使用租户连接还是平台默认）
     */
    public function resolveStatus(int $tenantId): array
    {
        $resolved = $this->resolveProviderConfig($tenantId);

        return [
            'configured' => $resolved !== null,
            'source' => $resolved['source'] ?? null,
            'provider_type' => $resolved['provider_type'] ?? null,
            'connection_id' => $resolved['connection_id'] ?? null,
        ];
    }

    /**
     * 连接展示数据（密钥脱敏）
     */
    public function presentConnection(ExternalKbConnection $connection): array
    {
        return [
            'connection_id' => (int) $connection->connection_id,
            'provider_type' => $connection->provider_type,
            'name' => $connection->name,
            'api_url' => $connection->api_url,
            'api_key' => $connection->api_key_encrypted ? '********' : '',
            'status' => $connection->status,
            'config' => $connection->config,
            'last_test' => $connection->metadata['last_test'] ?? null,
            'created_at' => $connection->created_at?->toIso8601String(),
        ];
    }

    /**
     * 按 tenant_id 显式查询（绕过 TenantScope，安全由调用方保证）
     */
    protected function tenantQuery(int $tenantId)
    {
        return ExternalKbConnection::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId);
    }

    protected function findConnection(int $tenantId, int $connectionId): ExternalKbConnection
    {
        return $this->tenantQuery($tenantId)
            ->where('connection_id', $connectionId)
            ->firstOrFail();
    }
}
