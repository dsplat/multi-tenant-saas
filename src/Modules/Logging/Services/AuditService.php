<?php

namespace MultiTenantSaas\Modules\Logging\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Logging\Models\AuditLog;

/**
 * 审计服务
 */
class AuditService
{
    public function __construct(private readonly TenantContextContract $tenantContext) {}

    /**
     * 向后兼容：静态调用代理到容器实例。
     *
     * @deprecated 请改用构造器注入
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return app(static::class)->{$method}(...$arguments);
    }

    /**
     * 记录操作
     */
    public function log(
        string $action,
        string $resourceType,
        ?int $resourceId = null,
        \BackedEnum|float|int|string|array|null $oldValues = null,
        \BackedEnum|float|int|string|array|null $newValues = null
    ): AuditLog {
        return AuditLog::create([
            'tenant_id' => TenantContext::getId(),
            'user_id' => auth()->id(),
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * 查询审计日志
     */
    public function query(?int $tenantId = null)
    {
        return AuditLog::where('tenant_id', $tenantId ?? TenantContext::getId());
    }
}
