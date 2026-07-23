<?php

namespace MultiTenantSaas\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use MultiTenantSaas\Context\TenantContext;

/**
 * 租户全局作用域（fail-closed）
 *
 * 自动为查询添加 tenant_id 过滤条件，确保数据隔离。
 * 当无租户上下文时，追加 WHERE 1=0 阻止任何数据返回（fail-closed）。
 *
 * 安全原则：
 * - 所有查询默认按租户过滤
 * - 无租户上下文时拒绝返回数据（fail-closed）
 * - withoutTenantScope/forAllTenants 仅允许在 admin 域名下使用
 * - 队列/CLI 等无 HTTP 上下文场景需显式调用 allowUnscoped()
 */
class TenantScope implements Scope
{
    /**
     * 标记当前执行上下文允许无租户查询（队列、CLI、系统任务）。
     * 使用闭包包装，执行完毕自动恢复。
     */
    protected static bool $unscopedAllowed = false;

    /**
     * 在允许无租户上下文的场景中执行回调。
     * 用于队列 Job、Artisan Command 等系统级操作。
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function allowUnscoped(callable $callback): mixed
    {
        $previous = static::$unscopedAllowed;
        static::$unscopedAllowed = true;

        try {
            return $callback();
        } finally {
            static::$unscopedAllowed = $previous;
        }
    }

    /**
     * 当前是否允许无租户查询。
     */
    public static function isUnscopedAllowed(): bool
    {
        return static::$unscopedAllowed;
    }

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * Fail-closed：无租户上下文且未显式豁免时，追加 WHERE 1=0。
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = TenantContext::getId();

        if ($tenantId) {
            $builder->where($model->getTable().'.tenant_id', $tenantId);

            return;
        }

        // 无租户上下文：系统级豁免 or 阻断
        if (! static::$unscopedAllowed) {
            $builder->whereRaw('1 = 0');
        }
    }

    /**
     * Extend the query builder with the needed functions.
     */
    public function extend(Builder $builder): void
    {
        $this->addWithoutTenantScope($builder);
        $this->addWithTenant($builder);
        $this->addForAllTenants($builder);
    }

    /**
     * 添加 withoutTenantScope 方法（仅 admin 域名可用）
     */
    protected function addWithoutTenantScope(Builder $builder): void
    {
        $builder->macro('withoutTenantScope', function (Builder $builder) {
            self::enforceAdminContext('withoutTenantScope');

            return $builder->withoutGlobalScope(TenantScope::class);
        });
    }

    /**
     * 添加 withTenant 方法（指定租户ID）
     */
    protected function addWithTenant(Builder $builder): void
    {
        $builder->macro('withTenant', function (Builder $builder, string $tenantId) {
            self::enforceAdminContext('withTenant');

            return $builder->withoutGlobalScope(TenantScope::class)
                ->where($builder->getModel()->getTable() . '.tenant_id', $tenantId);
        });
    }

    /**
     * 添加 forAllTenants 方法（仅 admin 域名可用）
     */
    protected function addForAllTenants(Builder $builder): void
    {
        $builder->macro('forAllTenants', function (Builder $builder) {
            self::enforceAdminContext('forAllTenants');

            return $builder->withoutGlobalScope(TenantScope::class);
        });
    }

    /**
     * 强制检查：仅允许在 admin 域名下绕过租户隔离
     *
     * @throws \RuntimeException
     */
    protected static function enforceAdminContext(string $method): void
    {
        $domainType = TenantContext::getDomainType();

        if ($domainType !== 'admin') {
            throw new \RuntimeException(
                "安全限制：{$method}() 仅允许在系统后台 (admin) 使用，当前域名类型: {$domainType}"
            );
        }
    }
}
