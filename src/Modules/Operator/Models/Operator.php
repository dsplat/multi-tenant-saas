<?php

namespace MultiTenantSaas\Modules\Operator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

class Operator extends Model
{
    use HasGlobalId, SoftDeletes;

    protected $primaryKey = 'operator_id';

    protected $fillable = [
        'email',
        'name',
        'password',
        'phone',
        'avatar',
        'scope',
        'is_active',
        'invite_token',
        'invite_expires_at',
    ];

    protected $hidden = [
        'password',
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
            ->withPivot('user_id', 'role', 'role_id', 'is_active', 'invited_at', 'accepted_at')
            ->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'operator_tenants', 'operator_id', 'user_id', 'operator_id')
            ->withPivot('tenant_id', 'role', 'role_id', 'is_active', 'invited_at', 'accepted_at')
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
