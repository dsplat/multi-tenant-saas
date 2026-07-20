<?php

namespace MultiTenantSaas\Modules\Operator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;

/**
 * Operator-Tenant 关联表模型（Operator 直连租户模式）
 *
 * 字段：operator_id、tenant_id、role、role_id、is_active、invited_at、accepted_at
 * 不再有 user_id 字段——Operator 直接通过本表关联到 Tenant，
 * 而非通过 users/tenant_users。
 */
class OperatorTenant extends Model
{
    use BelongsToTenant;

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
