<?php

declare(strict_types=1);

namespace MultiTenantSaas\Mcp;

use Illuminate\Support\Collection;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\McpClient;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * MCP 客户端注册表
 *
 * 支持 MCP 客户端的运行时注册、发现和按租户查询。
 * 运行时注册的客户端优先级高于数据库中的同名客户端。
 */
class McpClientRegistry
{
    /**
     * 运行时注册的客户端，按 name 索引
     *
     * @var array<string, McpClient>
     */
    private array $runtimeClients = [];

    /**
     * 注册一个 MCP 客户端到运行时注册表
     */
    public function register(McpClient $client): void
    {
        $this->runtimeClients[$client->name] = $client;
    }

    /**
     * 发现指定名称的 MCP 客户端
     *
     * 优先从运行时注册表查找，其次从数据库查询。
     */
    public function discover(string $name): ?McpClient
    {
        if (isset($this->runtimeClients[$name])) {
            return $this->runtimeClients[$name];
        }

        return $this->findDbClient($name);
    }

    /**
     * 获取指定租户的所有 MCP 客户端
     */
    public function listByTenant(string $tenantId): Collection
    {
        $dbClients = McpClient::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->get();

        return $this->mergeRuntime($dbClients, $tenantId);
    }

    /**
     * 获取当前租户的所有 MCP 客户端
     */
    public function listForCurrentTenant(): Collection
    {
        $tenantId = TenantContext::getId();

        if ($tenantId === null) {
            return collect();
        }

        $dbClients = McpClient::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->get();

        return $this->mergeRuntime($dbClients, $tenantId);
    }

    /**
     * 获取所有已注册的 MCP 客户端（运行时 + 数据库）
     */
    public function all(): Collection
    {
        $dbClients = McpClient::withoutGlobalScope(TenantScope::class)->get();

        return $this->mergeRuntime($dbClients, null);
    }

    /**
     * 获取当前租户下已启用的 MCP 客户端
     */
    public function listActiveForCurrentTenant(): Collection
    {
        $tenantId = TenantContext::getId();

        if ($tenantId === null) {
            return collect();
        }

        $dbClients = McpClient::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('status', McpClient::STATUS_ACTIVE)
            ->get();

        return $this->mergeRuntime($dbClients, $tenantId, true);
    }

    /**
     * 检查指定名称的客户端是否已注册
     */
    public function has(string $name): bool
    {
        if (isset($this->runtimeClients[$name])) {
            return true;
        }

        return McpClient::withoutGlobalScope(TenantScope::class)
            ->where('name', $name)
            ->exists();
    }

    /**
     * 移除运行时注册的客户端
     */
    public function unregister(string $name): void
    {
        unset($this->runtimeClients[$name]);
    }

    /**
     * 获取运行时注册的客户端数量
     */
    public function countRuntime(): int
    {
        return count($this->runtimeClients);
    }

    /**
     * 获取指定租户的客户端总数
     */
    public function countByTenant(string $tenantId): int
    {
        return McpClient::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->count();
    }

    /**
     * 从数据库查找指定名称的客户端
     */
    private function findDbClient(string $name): ?McpClient
    {
        return McpClient::withoutGlobalScope(TenantScope::class)
            ->where('name', $name)
            ->first();
    }

    /**
     * 合并运行时客户端到数据库查询结果
     *
     * 运行时注册的客户端覆盖数据库中同名的客户端。
     *
     * @param  Collection<int, McpClient>  $dbClients
     * @param  string|null  $tenantId     按租户过滤（null 表示不过滤）
     * @param  bool|null    $activeOnly   仅启用状态（null 表示不过滤）
     * @return Collection<int, McpClient>
     */
    private function mergeRuntime(Collection $dbClients, ?string $tenantId, ?bool $activeOnly = null): Collection
    {
        $merged = $dbClients->keyBy('name')->toArray();

        foreach ($this->runtimeClients as $name => $client) {
            if ($tenantId !== null && (string) $client->tenant_id !== $tenantId) {
                continue;
            }
            if ($activeOnly === true && !$client->isActive()) {
                continue;
            }
            $merged[$name] = $client;
        }

        return collect(array_values($merged));
    }
}
