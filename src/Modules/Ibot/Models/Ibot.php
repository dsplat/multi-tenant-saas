<?php

namespace MultiTenantSaas\Modules\Ibot\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * IM 机器人实例（租户级）
 *
 * 每个 ibot 是某 IM 平台上的一个机器人实体（如 Telegram Bot、企微自建应用），
 * operator 扫码绑定后即得随身 AI 小助理。凭证加密存储。
 *
 * 设计稿：docs/ibot.md 第三节。
 */
class Ibot extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId;

    // 频道类型
    const CHANNEL_TELEGRAM = 'telegram';

    const CHANNEL_WECHAT_WORK = 'wechat_work';

    const CHANNEL_WECHAT_KF = 'wechat_kf';

    const CHANNEL_WECHAT = 'wechat'; // 个人号（iLink）

    const CHANNEL_DINGTALK = 'dingtalk';

    const CHANNEL_FEISHU = 'feishu';

    // 传输形态
    const TRANSPORT_WEBHOOK = 'webhook';

    const TRANSPORT_LONGCONN = 'longconn';

    const TRANSPORT_ILINK = 'ilink';

    // 状态
    const STATUS_ACTIVE = 'active';

    const STATUS_DISABLED = 'disabled';

    protected $table = 'ibots';

    protected $primaryKey = 'ibot_id';

    protected $fillable = [
        'tenant_id',
        'channel_type',
        'transport',
        'name',
        'credentials',
        'webhook_secret',
        'agent_id',
        'status',
    ];

    protected $hidden = [
        'credentials',
        'webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
        ];
    }

    public function bindings(): HasMany
    {
        return $this->hasMany(OperatorIbotBinding::class, 'ibot_id', 'ibot_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
