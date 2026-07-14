<?php

namespace MultiTenantSaas\Modules\Infrastructure\Models;

use Database\Factories\TenantFactory;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\Searchable;
use MultiTenantSaas\Modules\Billing\Models\CreditAccount;
use MultiTenantSaas\Modules\Billing\Models\SubscriptionPlan;

/**
 * 租户模型
 */
class Tenant extends Model
{
    use HasFactory, HasGlobalId, Searchable, SoftDeletes;

    protected array $searchable = ['name', 'slug', 'contact_email'];

    /**
     * 工厂类位于 Database\Factories 命名空间，而非 HasFactory 默认查找的路径
     */
    protected static function newFactory()
    {
        return TenantFactory::new();
    }

    /**
     * 主键字段名
     */
    protected $primaryKey = 'tenant_id';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'domain',
        'custom_domain',
        'logo',
        'description',
        'admin_id',
        'admin_name',
        'subscription_plan',
        'subscription_plan_id',
        'subscription_started_at',
        'subscription_expires_at',
        'auto_renew',
        'trial_ends_at',
        'total_credits',
        'used_credits',
        'contact_name',
        'contact_email',
        'contact_phone',
        'settings',
        'branding',
        'is_platform_default',
        'status',
        'ssl_uploaded_at',
        'ssl_cert_expires_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'subscription_started_at' => 'datetime',
            'subscription_expires_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'ssl_uploaded_at' => 'datetime',
            'ssl_cert_expires_at' => 'datetime',
            'settings' => 'array',
            'branding' => 'array',
            'total_credits' => 'integer',
            'used_credits' => 'integer',
            'tenant_id' => 'integer',
            'is_platform_default' => 'boolean',
            'auto_renew' => 'boolean',
        ];
    }

    /**
     * 关联用户（多对多）
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users', 'tenant_id', 'user_id', 'tenant_id')
            ->withPivot('role', 'role_id', 'credits', 'is_active', 'joined_at')
            ->withTimestamps();
    }

    /**
     * 管理员用户
     */
    public function admins(): BelongsToMany
    {
        return $this->users()->wherePivot('role', 'tenant_admin');
    }

    /**
     * 普通用户
     */
    public function endUsers(): BelongsToMany
    {
        return $this->users()->wherePivot('role', 'end_user');
    }

    /**
     * 积分账户
     */
    public function creditAccounts(): HasMany
    {
        return $this->hasMany(CreditAccount::class, 'tenant_id', 'tenant_id');
    }

    /**
     * 订阅计划
     */
    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    /**
     * 订阅是否有效
     */
    public function isSubscriptionActive(): bool
    {
        if ($this->subscription_plan === 'free') {
            return true;
        }

        if (! $this->subscription_expires_at) {
            return false;
        }

        return $this->subscription_expires_at->isFuture();
    }

    /**
     * 租户是否激活
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * 企业可用积分
     */
    public function getAvailableCreditsAttribute(): int
    {
        return max(0, $this->total_credits - $this->used_credits);
    }

    /**
     * 获取企业后台 URL
     */
    public function getConsoleUrlAttribute(): string
    {
        if ($this->custom_domain) {
            return 'https://' . $this->custom_domain;
        }

        return config('app.url') . '/console?tenant_id=' . $this->tenant_id;
    }

    /**
     * 获取用户数量
     */
    public function getUserCountAttribute(): int
    {
        return $this->users()->count();
    }

    /**
     * Scope: 只查询激活的租户
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope: 按套餐筛选
     */
    public function scopeByPlan(Builder $query, string $plan): Builder
    {
        return $query->where('subscription_plan', $plan);
    }

    /**
     * Scope: 按状态筛选
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: 订阅即将过期（7天内）
     */
    public function scopeSubscriptionExpiring(Builder $query): Builder
    {
        return $query->where('subscription_expires_at', '<=', now()->addDays(7))
            ->where('subscription_expires_at', '>', now());
    }

    /**
     * Scope: 订阅已过期
     */
    public function scopeSubscriptionExpired(Builder $query): Builder
    {
        return $query->where('subscription_expires_at', '<', now());
    }
}
