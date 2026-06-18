<?php

namespace MultiTenantSaas\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\QuotaExceededException;
use MultiTenantSaas\Models\Tenant;

/**
 * 配额服务
 */
class QuotaService
{
    /**
     * 检查配额
     */
    public static function check(string $resource, ?int $tenantId = null): void
    {
        $tenantId = $tenantId ?? TenantContext::getId();
        $tenant = Tenant::find($tenantId);

        if (!$tenant) {
            return;
        }

        $limit = static::getLimit($resource, $tenant);
        $current = static::getCurrent($resource, $tenantId);

        if ($current >= $limit) {
            throw new QuotaExceededException(
                "资源 {$resource} 已达上限 ({$limit})，请升级套餐"
            );
        }
    }

    /**
     * 获取配额限制
     */
    protected static function getLimit(string $resource, Tenant $tenant): int
    {
        $plan = $tenant->subscription_plan;
        return config("tenancy.plans.{$plan}.limits.{$resource}", PHP_INT_MAX);
    }

    /**
     * 获取当前使用量
     */
    protected static function getCurrent(string $resource, int $tenantId): int
    {
        $counterClass = config("tenancy.resources.{$resource}.counter");

        if ($counterClass && class_exists($counterClass)) {
            return app($counterClass)->count($tenantId);
        }

        return 0;
    }

    /**
     * 获取配额信息
     */
    public static function getQuota(string $resource, ?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? TenantContext::getId();
        $tenant = Tenant::find($tenantId);

        if (!$tenant) {
            return ['limit' => 0, 'current' => 0, 'remaining' => 0];
        }

        $limit = static::getLimit($resource, $tenant);
        $current = static::getCurrent($resource, $tenantId);

        return [
            'limit' => $limit,
            'current' => $current,
            'remaining' => max(0, $limit - $current),
        ];
    }
}
