<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\HasGlobalId;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasGlobalId, HasFactory;

    protected $primaryKey = 'role_id';

    protected $fillable = [
        'tenant_id',
        'name',
        'display_name',
        'description',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'tenant_id' => 'integer',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_id');
    }

    public function isSystem(): bool
    {
        return $this->is_system;
    }

    public function scopeSystem($query)
    {
        return $query->whereNull('tenant_id');
    }

    public function scopeTenantScoped($query, int $tenantId)
    {
        return $query->where(function ($q) use ($tenantId) {
            $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
        });
    }

    public function hasPermission(string $permissionName): bool
    {
        return $this->permissions()->where('name', $permissionName)->exists();
    }

    public function grantPermission(int $permissionId): void
    {
        $this->permissions()->syncWithoutDetaching([$permissionId]);
    }

    public function revokePermission(int $permissionId): void
    {
        $this->permissions()->detach($permissionId);
    }
}
