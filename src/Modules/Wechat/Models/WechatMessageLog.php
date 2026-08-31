<?php

namespace MultiTenantSaas\Modules\Wechat\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 服务号消息发送记录模型（模板消息 / 客服消息统一）
 *
 * 每次发送落库：微信侧返回 errcode/msgid 回写状态，失败原因完整保留，
 * 供 console 发送记录查询与排障（客服消息有 48 小时互动窗口、模板消息
 * 有日调用上限等微信侧限制均以失败码形式回写）。
 */
class WechatMessageLog extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;
    use SerializesFriendlyDates;

    protected $primaryKey = 'message_log_id';

    protected $keyType = 'int';

    protected $table = 'wechat_message_logs';

    public const TYPE_TEMPLATE = 'template';

    public const TYPE_CUSTOM = 'custom';

    public const TYPES = [
        self::TYPE_TEMPLATE,
        self::TYPE_CUSTOM,
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SUCCESS,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'tenant_id',
        'message_type',
        'template_key',
        'openid',
        'user_id',
        'content',
        'msg_id',
        'status',
        'error_code',
        'error_message',
        'sent_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'user_id' => 'integer',
            'content' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }
}
