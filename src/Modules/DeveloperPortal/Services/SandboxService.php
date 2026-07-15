<?php

namespace MultiTenantSaas\Modules\DeveloperPortal\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Jobs\CleanupSandboxJob;
use MultiTenantSaas\Modules\Infrastructure\Models\SandboxEnvironment;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Logging\Models\AuditLog;

/**
 * 沙箱服务
 *
 * 为开发者门户提供独立隔离的测试环境：
 *  - 通过 sandbox_tenant_id 隔离沙箱数据（使用 TenantContext 切换）
 *  - 配发测试 API Key（前缀 sbx_）
 *  - 24 小时 TTL，到期后通过队列延迟任务自动清理
 */
class SandboxService
{
    /** 沙箱默认 TTL（秒） */
    public const DEFAULT_TTL_SECONDS = 86400;

    /** 测试 API Key 前缀 */
    public const API_KEY_PREFIX = 'sbx_';

    /** 沙箱租户名称前缀 */
    public const TENANT_NAME_PREFIX = 'Sandbox-';

    // ----------------------------------------
    // 沙箱生命周期
    // ----------------------------------------

    /**
     * 创建沙箱环境
     *
     * 创建一个独立隔离的沙箱租户，并配发测试 API Key，
     * 同时调度 24 小时后的自动清理任务。
     *
     * @param  int  $developerId  开发者（用户）ID
     * @return array{sandbox: SandboxEnvironment, api_key: string, sandbox_tenant_id: int}
     */
    public function createSandbox(int $developerId): array
    {
        return DB::transaction(function () use ($developerId): array {
            // 创建独立隔离的沙箱租户
            $sandboxTenant = Tenant::create([
                'name' => self::TENANT_NAME_PREFIX . uniqid(),
                'slug' => 'sandbox-' . Str::random(16),
                'status' => 'sandbox',
                'subscription_plan' => 'sandbox',
            ]);

            $apiKey = $this->generateTestApiKey();
            $expiresAt = now()->addSeconds(self::DEFAULT_TTL_SECONDS);

            $sandbox = SandboxEnvironment::create([
                'developer_id' => $developerId,
                'sandbox_tenant_id' => $sandboxTenant->tenant_id,
                'api_key' => $apiKey,
                'status' => SandboxEnvironment::STATUS_ACTIVE,
                'expires_at' => $expiresAt,
            ]);

            $this->scheduleCleanup($sandbox->sandbox_environment_id, self::DEFAULT_TTL_SECONDS);

            $this->audit('sandbox.create', $sandbox->sandbox_environment_id, null, [
                'developer_id' => $developerId,
                'sandbox_tenant_id' => $sandboxTenant->tenant_id,
            ]);

            return [
                'sandbox' => $sandbox,
                'api_key' => $apiKey,
                'sandbox_tenant_id' => (int) $sandboxTenant->tenant_id,
            ];
        });
    }

    /**
     * 查找沙箱环境
     */
    public function findSandbox(int $sandboxId): ?SandboxEnvironment
    {
        return SandboxEnvironment::where('sandbox_environment_id', $sandboxId)->first();
    }

    /**
     * 根据测试 API Key 查找沙箱环境
     */
    public function findByApiKey(string $apiKey): ?SandboxEnvironment
    {
        if ($apiKey === '') {
            return null;
        }

        return SandboxEnvironment::where('api_key', $apiKey)->first();
    }

    /**
     * 切换当前上下文到沙箱租户
     *
     * 使用 TenantContext 实现沙箱数据隔离。
     *
     * @throws \RuntimeException 沙箱不存在或已过期
     */
    public function activateSandboxTenant(int $sandboxId): bool
    {
        $sandbox = $this->findSandbox($sandboxId);
        if (! $sandbox) {
            throw new \RuntimeException(trans('common.sandbox_not_found'));
        }
        if (! $sandbox->isUsable()) {
            throw new \RuntimeException(trans('common.sandbox_expired'));
        }

        $tenant = Tenant::where('tenant_id', $sandbox->sandbox_tenant_id)->first();
        if (! $tenant) {
            throw new \RuntimeException(trans('common.sandbox_not_found'));
        }

        TenantContext::setTenantId((string) $tenant->tenant_id);
        TenantContext::setTenant($tenant);

        return true;
    }

    // ----------------------------------------
    // 清理
    // ----------------------------------------

    /**
     * 清理指定沙箱环境（删除沙箱租户与沙箱记录）
     */
    public function cleanup(int $sandboxId): bool
    {
        $sandbox = $this->findSandbox($sandboxId);
        if (! $sandbox) {
            return false;
        }

        $snapshot = $sandbox->toArray();

        DB::transaction(function () use ($sandbox): void {
            // 软删除沙箱租户（隔离其数据）
            $tenant = Tenant::where('tenant_id', $sandbox->sandbox_tenant_id)->first();
            if ($tenant) {
                $tenant->delete();
            }

            // 删除沙箱环境记录
            $sandbox->delete();
        });

        $this->audit('sandbox.cleanup', $sandboxId, $snapshot, null);

        return true;
    }

    /**
     * 清理所有过期沙箱
     *
     * @return int 清理的沙箱数量
     */
    public function cleanupExpired(): int
    {
        $expired = SandboxEnvironment::where('status', SandboxEnvironment::STATUS_ACTIVE)
            ->where('expires_at', '<', now())
            ->get();

        $count = 0;
        foreach ($expired as $sandbox) {
            if ($this->cleanup($sandbox->sandbox_environment_id)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 调度沙箱自动清理（队列延迟任务）
     *
     * 在沙箱创建时调用，到期后由队列任务执行清理。
     *
     * @param  int  $sandboxId  沙箱环境 ID
     * @param  int  $delaySeconds  延迟秒数（默认 24 小时）
     */
    public function scheduleCleanup(int $sandboxId, int $delaySeconds = self::DEFAULT_TTL_SECONDS): void
    {
        CleanupSandboxJob::dispatch($sandboxId)->delay(now()->addSeconds($delaySeconds));
    }

    // ----------------------------------------
    // 内部辅助
    // ----------------------------------------

    /**
     * 列出所有活跃沙箱环境
     */
    public function listSandboxes(): Collection
    {
        return SandboxEnvironment::where('status', SandboxEnvironment::STATUS_ACTIVE)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * 获取当前租户的沙箱环境
     */
    public function getTenantSandbox(): ?SandboxEnvironment
    {
        $tenantId = TenantContext::getId();

        return SandboxEnvironment::where('sandbox_tenant_id', $tenantId)
            ->where('status', SandboxEnvironment::STATUS_ACTIVE)
            ->first();
    }

    /**
     * 生成测试 API Key（前缀 sbx_ + 64 位随机字符串）
     */
    public function generateTestApiKey(): string
    {
        return self::API_KEY_PREFIX . Str::random(64);
    }

    /**
     * 记录审计日志
     *
     * @param  array|string|null  $oldValues
     * @param  array|string|null  $newValues
     */
    protected function audit(string $action, ?int $resourceId, $oldValues = null, $newValues = null): void
    {
        try {
            AuditLog::create([
                'tenant_id' => TenantContext::getId(),
                'user_id' => auth()->id(),
                'action' => $action,
                'resource_type' => 'sandbox',
                'resource_id' => $resourceId,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SandboxService audit failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
