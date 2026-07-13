<?php

namespace MultiTenantSaas\Models;

use Database\Factories\TenantUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class TenantUser extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    /**
     * 工厂类位于 Database\Factories 命名空间，而非 HasFactory 默认查找的路径
     */
    protected static function newFactory()
    {
        return TenantUserFactory::new();
    }

    protected $primaryKey = 'tenant_user_id';

    protected $table = 'tenant_users';

    /**
     * @deprecated 'role' 字段已废弃，请使用 role_id 关联 roles 表。
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'role',   // @deprecated 使用 role_id 代替
        'role_id',
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

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }

    public function getRoleId(): ?int
    {
        if ($this->role_id) {
            return $this->role_id;
        }

        if ($this->role) {
            $resolved = Role::where('name', $this->role)
                ->where(function ($q) {
                    $q->whereNull('tenant_id')->orWhere('tenant_id', $this->tenant_id);
                })->first();

            return $resolved?->role_id;
        }

        return null;
    }

    public function isAdmin(): bool
    {
        if ($this->role_id && $this->relationLoaded('role')) {
            return $this->role?->name === 'tenant_admin';
        }

        return $this->role === 'tenant_admin';
    }

    public function isEndUser(): bool
    {
        if ($this->role_id && $this->relationLoaded('role')) {
            return $this->role?->name === 'end_user';
        }

        return $this->role === 'end_user';
    }
}
