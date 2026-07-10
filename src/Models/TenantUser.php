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

    protected $fillable = [
        'tenant_id',
        'user_id',
        'role',
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

    public function isAdmin(): bool
    {
        return $this->role === 'tenant_admin';
    }

    public function isEndUser(): bool
    {
        return $this->role === 'end_user';
    }
}
