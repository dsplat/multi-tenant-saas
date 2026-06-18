<?php

namespace MultiTenantSaas\Models;

use MultiTenantSaas\Concerns\HasGlobalId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasGlobalId, Notifiable, SoftDeletes;

    protected $primaryKey = 'user_id';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'avatar',
        'role',
        'last_active_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_active_at' => 'datetime',
        ];
    }

    /**
     * 获取 id 属性（兼容 $user->id 访问）
     */
    public function getIdAttribute()
    {
        return $this->attributes[$this->primaryKey] ?? null;
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users', 'user_id', 'tenant_id', 'user_id')
            ->withPivot('role', 'credits', 'is_active', 'joined_at')
            ->withTimestamps();
    }

    public function oauthAccounts(): HasMany
    {
        return $this->hasMany(OauthAccount::class, 'user_id', 'user_id');
    }

    public function creditAccounts(): HasMany
    {
        return $this->hasMany(CreditAccount::class, 'user_id', 'user_id');
    }
}
