<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * MFA 恢复码
 *
 * 明文仅在生成时显示一次，存储时使用 hash。
 * 为用户账户级安全数据，不参与租户隔离。
 */
class MfaRecoveryCode extends Model
{
    use HasGlobalId;

    public const UPDATED_AT = null;

    protected $primaryKey = 'recovery_code_id';

    protected $fillable = [
        'recovery_code_id',
        'tenant_id',
        'user_id',
        'code',
        'is_used',
        'used_at',
        'created_at',
    ];

    protected $hidden = [
        'code',
    ];

    protected function casts(): array
    {
        return [
            'is_used' => 'boolean',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
