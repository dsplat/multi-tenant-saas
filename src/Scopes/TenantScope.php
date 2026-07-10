<?php

namespace MultiTenantSaas\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use MultiTenantSaas\Context\TenantContext;

/**
 * 租户全局作用域
 *
 * 自动为查询添加 tenant_id 过滤条件
 * 确保数据隔离，防止跨租户数据泄露
 *
 * 安全原则：
 * - 所有查询默认按租户过滤
 * - withoutTenantScope/forAllTenants 仅允许在 admin 域名下使用
 * - 租户上下文内禁止绕过隔离
 */
class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = TenantContext::getId();

        if ($tenantId) {
            $builder->where($model->getTable() . '.tenant_id', $tenantId);
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
