<?php

namespace MultiTenantSaas\Modules\Operator\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

class Operator extends Authenticatable
{
    use HasApiTokens, HasGlobalId, SoftDeletes;

    protected $primaryKey = 'operator_id';

    protected $fillable = [
        'email',
        'name',
        'password',
        'phone',
        'avatar',
        'scope',
        'is_active',
        'email_verified_at',
        'last_login_at',
        'login_attempts',
        'locked_until',
        'password_changed_at',
        'invite_token',
        'invite_expires_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'invite_expires_at' => 'datetime',
        ];
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'operator_tenants', 'operator_id', 'tenant_id', 'operator_id')
            ->withPivot('role', 'role_id', 'is_active', 'invited_at', 'accepted_at')
            ->withTimestamps();
    }

    public function isPlatform(): bool
    {
        return $this->scope === 'platform';
    }

    public function isTenant(): bool
    {
        return $this->scope === 'tenant';
    }

    public function getTenantRole(int $tenantId): ?string
    {
        return $this->tenants()
            ->where('tenants.tenant_id', $tenantId)
            ->first()?->pivot?->role;
    }
}
