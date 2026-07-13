<?php

namespace MultiTenantSaas\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 信任设备
 *
 * 用户级安全数据（记住此设备 N 天免二次验证），
 * tenant_id 仅作为创建时租户上下文的审计引用，不参与租户隔离。
 */
class TrustedDevice extends Model
{
    use HasGlobalId;

    protected $primaryKey = 'trusted_device_id';

    protected $fillable = [
        'trusted_device_id',
        'tenant_id',
        'user_id',
        'device_fingerprint',
        'device_name',
        'ip_address',
        'user_agent',
        'expires_at',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * 信任是否已过期
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * 设备是否仍受信任
     */
    public function isTrusted(): bool
    {
        return ! $this->isExpired();
    }
}
