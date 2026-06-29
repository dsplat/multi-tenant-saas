<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 用户同意记录
 *
 * 记录用户对 Cookie、数据处理、营销、条款等的同意状态。
 * 为用户账户级合规数据，不参与租户隔离（同一用户可能属于多个租户）。
 * 具有法律效力，记录 IP 和时间戳。
 */
class Consent extends Model
{
    use HasGlobalId;

    protected $primaryKey = 'consent_id';

    protected $fillable = [
        'consent_id',
        'tenant_id',
        'user_id',
        'type',
        'version',
        'is_granted',
        'ip_address',
        'user_agent',
        'granted_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_granted' => 'boolean',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * 关联用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * 关联租户
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }
}
