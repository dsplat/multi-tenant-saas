<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Mcp;

use Illuminate\Support\Collection;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\McpClient;

/**
 * MCP 客户端注册表
 *
 * 支持 MCP 客户端的运行时注册、发现和按租户查询。
 * 运行时注册的客户端优先级高于数据库中的同名客户端。
 *
 * 安全原则：
 * - 所有查询默认按当前租户过滤
 * - 跨租户查询（listByTenant/all/countByTenant）仅允许 admin 域名使用
 * - 运行时注册的客户端也按租户隔离
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
     *
     * @throws McpException 当 tenant_id 为空或同名客户端已存在时抛出
     */
    public function register(McpClient $client): void
    {
        if ($client->tenant_id === null) {
            throw McpException::invalidRequest(
                'Cannot register runtime MCP client without tenant_id.'
            );
        }

        if (isset($this->runtimeClients[$client->name])) {
            throw McpException::invalidRequest(
                "MCP client [{$client->name}] is already registered in runtime."
            );
        }

        $this->runtimeClients[$client->name] = $client;
    }

    /**
     * 发现指定名称的 MCP 客户端
     *
     * 优先从运行时注册表查找，其次从数据库查询。
     * 默认按当前租户过滤；传入 tenantId 可指定租户（跨租户需 admin 上下文）。
     */
    public function discover(string $name, ?string $tenantId = null): ?McpClient
    {
        $tenantId ??= TenantContext::getId();

        if (isset($this->runtimeClients[$name])) {
            $client = $this->runtimeClients[$name];
            if ($tenantId === null || (string) $client->tenant_id === $tenantId) {
                return $client;
            }
        }

        return $this->findDbClient($name, $tenantId);
    }

    /**
     * 获取指定租户的所有 MCP 客户端
     *
     * 仅允许 admin 域名使用。
     */
    public function listByTenant(string $tenantId): Collection
    {
        $this->assertAdminContext('listByTenant');

        $dbClients = McpClient::withoutTenantScope()
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

        $dbClients = McpClient::query()->get();

        return $this->mergeRuntime($dbClients, $tenantId);
    }

    /**
     * 获取所有已注册的 MCP 客户端（运行时 + 数据库）
     *
     * 仅允许 admin 域名使用。
     */
    public function all(): Collection
    {
        $this->assertAdminContext('all');

        $dbClients = McpClient::withoutTenantScope()->get();

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

        $dbClients = McpClient::query()
            ->where('status', McpClient::STATUS_ACTIVE)
            ->get();

        return $this->mergeRuntime($dbClients, $tenantId, true);
    }

    /**
     * 检查指定名称的客户端是否已注册
     *
     * 默认按当前租户过滤；传入 tenantId 可指定租户（跨租户需 admin 上下文）。
     */
    public function has(string $name, ?string $tenantId = null): bool
    {
        $tenantId ??= TenantContext::getId();

        if (isset($this->runtimeClients[$name])) {
            $client = $this->runtimeClients[$name];
            if ($tenantId === null || (string) $client->tenant_id === $tenantId) {
                return true;
            }
        }

        return $this->findDbClient($name, $tenantId) !== null;
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
     * 获取指定租户的客户端总数（含运行时注册的客户端）
     *
     * 仅允许 admin 域名使用。
     */
    public function countByTenant(string $tenantId): int
    {
        $this->assertAdminContext('countByTenant');

        $dbCount = McpClient::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->count();

        $runtimeCount = 0;
        foreach ($this->runtimeClients as $client) {
            if ((string) $client->tenant_id === $tenantId) {
                $runtimeCount++;
            }
        }

        return $dbCount + $runtimeCount;
    }

    /**
     * 从数据库查找指定名称的客户端
     *
     * 跨租户查询需要 admin 上下文。
     */
    private function findDbClient(string $name, ?string $tenantId = null): ?McpClient
    {
        $currentTenantId = TenantContext::getId();

        if ($tenantId !== null && $tenantId !== $currentTenantId) {
            $this->assertAdminContext('findDbClient');

            return McpClient::withoutTenantScope()
                ->where('name', $name)
                ->where('tenant_id', $tenantId)
                ->first();
        }

        return McpClient::query()->where('name', $name)->first();
    }

    /**
     * 合并运行时客户端到数据库查询结果
     *
     * 运行时注册的客户端覆盖数据库中同名的客户端。
     *
     * @param  Collection<int, McpClient>  $dbClients
     * @param  string|null  $tenantId     按租户过滤（null 表示不过滤）
     * @param  bool         $activeOnly   仅启用状态
     * @return Collection<int, McpClient>
     */
    private function mergeRuntime(Collection $dbClients, ?string $tenantId, bool $activeOnly = false): Collection
    {
        $merged = $dbClients->keyBy('name')->toArray();

        foreach ($this->runtimeClients as $name => $client) {
            if ($tenantId !== null && (string) $client->tenant_id !== $tenantId) {
                continue;
            }
            if ($activeOnly && !$client->isActive()) {
                continue;
            }
            $merged[$name] = $client;
        }

        return collect(array_values($merged));
    }

    /**
     * 断言当前上下文为 admin 域名
     *
     * @throws McpException 非 admin 上下文时抛出
     */
    private function assertAdminContext(string $method): void
    {
        if (TenantContext::getDomainType() !== 'admin') {
            throw McpException::forbidden(
                "{$method}() is only allowed in admin context."
            );
        }
    }
}
