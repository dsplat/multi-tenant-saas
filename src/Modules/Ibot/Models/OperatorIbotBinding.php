<?php

namespace MultiTenantSaas\Modules\Ibot\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Modules\Operator\Models\Operator;

/**
 * Operator ↔ 机器人绑定
 *
 * operator 扫码（绑定码）后与某 ibot 建立绑定；external_id 是该 operator
 * 在 IM 平台上的会话标识（如 TG chat_id）。is_default_channel 标记
 * 系统通知的默认出口（每 operator 至多一个）。
 *
 * 设计稿：docs/ibot.md 第三、四节。
 */
class OperatorIbotBinding extends Model
{
    use BelongsToTenant, HasGlobalId;

    const STATUS_PENDING = 'pending';

    const STATUS_ACTIVE = 'active';

    const STATUS_REVOKED = 'revoked';

    protected $table = 'operator_ibot_bindings';

    protected $primaryKey = 'binding_id';

    protected $fillable = [
        'tenant_id',
        'operator_id',
        'ibot_id',
        'external_id',
        'conversation_id',
        'is_default_channel',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_default_channel' => 'boolean',
        ];
    }

    public function ibot(): BelongsTo
    {
        return $this->belongsTo(Ibot::class, 'ibot_id', 'ibot_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'operator_id', 'operator_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
