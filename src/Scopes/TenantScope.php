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
            $builder->where($model->getTable().'.tenant_id', $tenantId);
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
     * 添加 withoutTenantScope 方法
     */
    protected function addWithoutTenantScope(Builder $builder): void
    {
        $builder->macro('withoutTenantScope', function (Builder $builder) {
            return $builder->withoutGlobalScope($this);
        });
    }

    /**
     * 添加 withTenant 方法（指定租户ID）
     */
    protected function addWithTenant(Builder $builder): void
    {
        $builder->macro('withTenant', function (Builder $builder, string $tenantId) {
            return $builder->withoutGlobalScope($this)
                ->where($builder->getModel()->getTable().'.tenant_id', $tenantId);
        });
    }

    /**
     * 添加 forAllTenants 方法（查询所有租户数据）
     */
    protected function addForAllTenants(Builder $builder): void
    {
        $builder->macro('forAllTenants', function (Builder $builder) {
            return $builder->withoutGlobalScope($this);
        });
    }
}
