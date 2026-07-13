<?php

namespace MultiTenantSaas\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 密码历史
 *
 * 用于实现"最近 N 次密码禁止重复"策略。
 * 仅存储密码 hash（bcrypt），永不存储明文。
 *
 * 说明：密码历史为用户账户级安全数据（跟随 User 模型，不参与租户隔离），
 * tenant_id 仅作为创建时租户上下文的审计引用。
 */
class PasswordHistory extends Model
{
    use HasGlobalId;

    protected $primaryKey = 'password_history_id';

    protected $fillable = [
        'password_history_id',
        'tenant_id',
        'user_id',
        'password_hash',
    ];

    protected $hidden = [
        'password_hash',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
