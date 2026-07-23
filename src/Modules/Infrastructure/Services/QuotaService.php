<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Exceptions\QuotaExceededException;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

/**
 * 配额服务
 */
class QuotaService
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
     * 检查配额
     */
    public function check(string $resource, ?int $tenantId = null): void
    {
        $tenantId = $tenantId ?? TenantContext::getId();
        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            return;
        }

        $limit = $this->getLimit($resource, $tenant);
        $current = $this->getCurrent($resource, $tenantId);

        if ($current >= $limit) {
            throw new QuotaExceededException(
                "资源 {$resource} 已达上限 ({$limit})，请升级套餐"
            );
        }
    }

    /**
     * 获取配额限制
     */
    protected function getLimit(string $resource, Tenant $tenant): int
    {
        $plan = $tenant->subscription_plan;

        return config("tenancy.plans.{$plan}.limits.{$resource}", PHP_INT_MAX);
    }

    /**
     * 获取当前使用量
     */
    protected function getCurrent(string $resource, int $tenantId): int
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
    public function getQuota(string $resource, ?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? TenantContext::getId();
        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            return ['limit' => 0, 'current' => 0, 'remaining' => 0];
        }

        $limit = $this->getLimit($resource, $tenant);
        $current = $this->getCurrent($resource, $tenantId);

        return [
            'limit' => $limit,
            'current' => $current,
            'remaining' => max(0, $limit - $current),
        ];
    }
}
