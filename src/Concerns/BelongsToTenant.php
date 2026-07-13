<?php

namespace MultiTenantSaas\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Scopes\TenantScope;

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

        // 创建时自动填充 tenant_id（使用 is_null 而非 empty，允许显式传入 null 创建系统级记录）
        static::creating(function (Model $model) {
            if (is_null($model->tenant_id)) {
                $model->tenant_id = static::getCurrentTenantId();
            }
        });
    }

    /**
     * 获取当前租户ID
     */
    protected static function getCurrentTenantId(): ?string
    {
        return TenantContext::getId();
    }

    /**
     * 获取租户关系
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }
}
