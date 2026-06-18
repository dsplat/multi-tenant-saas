<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 租户用户关系模型
 */
class TenantUser extends Model
{
    use BelongsToTenant, HasGlobalId;

    protected $primaryKey = 'tenant_user_id';

    protected $table = 'tenant_users';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'role',
        'credits',
        'is_active',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'is_active' => 'boolean',
            'joined_at' => 'datetime',
        ];
    }

    /**
     * 关联租户
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    /**
     * 关联用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * 是否为管理员
     */
    public function isAdmin(): bool
    {
        return $this->role === 'tenant_admin';
    }

    /**
     * 是否为普通用户
     */
    public function isEndUser(): bool
    {
        return $this->role === 'end_user';
    }
}
