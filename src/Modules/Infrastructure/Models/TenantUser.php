<?php

namespace MultiTenantSaas\Modules\Infrastructure\Models;

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

    protected $fillable = [
        'tenant_id',
        'user_id',
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

    public function isAdmin(): bool
    {
        if ($this->relationLoaded('role')) {
            return $this->role?->name === 'tenant_admin';
        }

        if (! $this->role_id) {
            return false;
        }

        return Role::where('role_id', $this->role_id)
            ->where('name', 'tenant_admin')
            ->exists();
    }

    public function isEndUser(): bool
    {
        if ($this->relationLoaded('role')) {
            return $this->role?->name === 'end_user';
        }

        if (! $this->role_id) {
            return false;
        }

        return Role::where('role_id', $this->role_id)
            ->where('name', 'end_user')
            ->exists();
    }
}
