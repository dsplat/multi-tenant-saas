<?php

namespace MultiTenantSaas\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 用户会话
 *
 * 记录用户登录会话信息，支持设备指纹、异常检测与强制下线。
 * 为用户账户级安全数据，不参与租户隔离。
 */
class UserSession extends Model
{
    use HasGlobalId;

    protected $primaryKey = 'user_session_id';

    protected $fillable = [
        'user_session_id',
        'tenant_id',
        'user_id',
        'token_id',
        'session_id',
        'ip_address',
        'device_info',
        'device_fingerprint',
        'login_at',
        'last_active_at',
        'location',
        'is_anomalous',
    ];

    protected function casts(): array
    {
        return [
            'token_id' => 'integer',
            'login_at' => 'datetime',
            'last_active_at' => 'datetime',
            'is_anomalous' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
