<?php

namespace MultiTenantSaas\Modules\Auth\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\Searchable;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasGlobalId, Notifiable, Searchable, SoftDeletes;

    protected array $searchable = ['name', 'email', 'phone'];

    /**
     * 工厂类位于 Database\Factories 命名空间，而非 HasFactory 默认查找的路径
     */
    protected static function newFactory()
    {
        return UserFactory::new();
    }

    protected $primaryKey = 'user_id';

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'phone',
        'password',
        'avatar',
        'is_active',
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
            ->withPivot('role_id', 'credits', 'is_active', 'joined_at')
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

    public function operatorTenants(): HasMany
    {
        return $this->hasMany(OperatorTenant::class, 'user_id', 'user_id');
    }

    public function operator(): HasOneThrough
    {
        return $this->hasOneThrough(
            Operator::class,
            OperatorTenant::class,
            'user_id',   // Foreign key on operator_tenants table
            'operator_id', // Foreign key on operators table
            'user_id',    // Local key on users table
            'operator_id' // Local key on operator_tenants table
        );
    }
}
