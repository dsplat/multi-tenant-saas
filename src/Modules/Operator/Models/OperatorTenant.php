<?php

namespace MultiTenantSaas\Modules\Operator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Operator-Tenant 关联表模型（Operator 直连租户模式）
 *
 * 字段：operator_id、tenant_id、role、role_id、is_active、invited_at、accepted_at
 * 不再有 user_id 字段——Operator 直接通过本表关联到 Tenant，
 * 而非通过 users/tenant_users。
 *
 * ⚠️ 本表定义"Operator 属于哪些租户"的归属关系本身，被 IdentifyTenant/
 * VerifyOperatorTenant/RbacService 在租户上下文建立之前查询。
 * 禁止使用 BelongsToTenant（fail-closed TenantScope 会在无上下文时
 * WHERE 1=0 阻断归属校验，导致所有请求 403 死锁）。
 * 所有调用点必须显式按 operator_id 过滤。
 */
class OperatorTenant extends Model
{
    protected $table = 'operator_tenants';

    protected $fillable = [
        'operator_id',
        'tenant_id',
        'user_id',
        'role',
        'role_id',
        'is_active',
        'invited_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'operator_id', 'operator_id');
    }
}
