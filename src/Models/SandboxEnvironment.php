<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 沙箱环境
 *
 * 为开发者提供独立隔离的测试环境，使用 sandbox_tenant_id 隔离数据，
 * 配发测试 API Key，24 小时后自动清理。
 */
class SandboxEnvironment extends Model
{
    use HasGlobalId;

    protected $primaryKey = 'sandbox_environment_id';

    protected $fillable = [
        'sandbox_environment_id',
        'developer_id',
        'sandbox_tenant_id',
        'api_key',
        'status',
        'expires_at',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CLEANED = 'cleaned';

    public function developer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'developer_id', 'user_id');
    }

    public function sandboxTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'sandbox_tenant_id', 'tenant_id');
    }

    /**
     * 沙箱是否已过期
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * 沙箱是否可用（激活且未过期）
     */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->isExpired();
    }
}
