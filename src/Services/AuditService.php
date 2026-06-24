<?php

namespace MultiTenantSaas\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\AuditLog;

/**
 * 审计服务
 */
class AuditService
{
    /**
     * 记录操作
     */
    public static function log(
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
    public static function query(?int $tenantId = null)
    {
        return AuditLog::where('tenant_id', $tenantId ?? TenantContext::getId());
    }
}
