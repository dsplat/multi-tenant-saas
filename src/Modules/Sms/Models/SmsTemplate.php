<?php

namespace MultiTenantSaas\Modules\Sms\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 短信模板模型
 *
 * 营销短信模板管理。
 */
class SmsTemplate extends Model
{
    use HasFactory, HasGlobalId;

    // 渠道类型
    const CHANNEL_MARKETING = 'marketing';

    const CHANNEL_VERIFICATION = 'verification';

    const CHANNEL_NOTIFICATION = 'notification';

    const CHANNEL_TRANSACTIONAL = 'transactional';

    const CHANNELS = [
        self::CHANNEL_MARKETING,
        self::CHANNEL_VERIFICATION,
        self::CHANNEL_NOTIFICATION,
        self::CHANNEL_TRANSACTIONAL,
    ];

    // 状态
    const STATUS_DRAFT = 'draft';

    const STATUS_PENDING = 'pending';

    const STATUS_PENDING_APPROVAL = 'pending_approval';

    const STATUS_APPROVED = 'approved';

    const STATUS_REJECTED = 'rejected';

    const STATUS_DISABLED = 'disabled';

    protected $primaryKey = 'sms_template_id';

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'content',
        'type',
        'channel',
        'sign_name',
        'params',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * 按渠道筛选
     */
    public function scopeOfChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    /**
     * 按状态筛选
     */
    public function scopeOfStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
