<?php

namespace MultiTenantSaas\Concerns;

use MultiTenantSaas\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

/**
 * 多租户 Trait
 *
 * 为模型自动应用租户作用域
 * 确保数据按租户隔离
 */
trait BelongsToTenant
{
    /**
     * Boot the BelongsToTenant trait for a model.
     */
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        // 创建时自动填充 tenant_id
        static::creating(function (Model $model) {
            if (empty($model->tenant_id)) {
                $model->tenant_id = static::getCurrentTenantId();
            }
        });
    }

    /**
     * 获取当前租户ID
     */
    protected static function getCurrentTenantId(): ?string
    {
        return \MultiTenantSaas\Context\TenantContext::getId();
    }

    /**
     * 获取租户关系
     */
    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\MultiTenantSaas\Models\Tenant::class, 'tenant_id', 'tenant_id');
    }
}
