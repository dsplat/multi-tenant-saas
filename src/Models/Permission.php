<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\HasGlobalId;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasGlobalId, HasFactory;

    protected $primaryKey = 'permission_id';

    protected $fillable = [
        'name',
        'display_name',
        'group',
        'description',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }

    public function scopeByGroup($query, string $group)
    {
        return $query->where('group', $group);
    }
}
