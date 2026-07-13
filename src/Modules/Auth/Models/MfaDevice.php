<?php

namespace MultiTenantSaas\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 多因素认证设备
 *
 * 类型：totp（基于时间的一次性密码）、email（邮箱验证码）、sms（短信验证码）
 *
 * 说明：MFA 设备为用户账户级安全数据（跟随 User 模型，不参与租户隔离），
 * tenant_id 仅作为创建时租户上下文的审计引用。
 */
class MfaDevice extends Model
{
    use HasGlobalId;

    protected $primaryKey = 'mfa_device_id';

    protected $fillable = [
        'mfa_device_id',
        'tenant_id',
        'user_id',
        'type',
        'secret',
        'label',
        'is_primary',
        'is_verified',
        'last_used_at',
    ];

    protected $hidden = [
        'secret',
    ];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'is_primary' => 'boolean',
            'is_verified' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * 是否为 TOTP 设备
     */
    public function isTotp(): bool
    {
        return $this->type === 'totp';
    }

    /**
     * 是否为邮箱验证码设备
     */
    public function isEmail(): bool
    {
        return $this->type === 'email';
    }

    /**
     * 是否为短信验证码设备
     */
    public function isSms(): bool
    {
        return $this->type === 'sms';
    }
}
