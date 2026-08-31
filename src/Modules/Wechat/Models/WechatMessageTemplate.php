<?php

namespace MultiTenantSaas\Modules\Wechat\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 租户模板消息登记模型
 *
 * 将业务标识（template_key）映射到公众号后台选用的微信模板 ID：
 * 发送模板消息时按 template_key 查表取 template_id，业务方无需感知微信侧 ID。
 * 仅认证服务号可用（模板消息能力由微信侧校验，服务端不做二次判断）。
 */
class WechatMessageTemplate extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;
    use SerializesFriendlyDates;

    protected $primaryKey = 'message_template_id';

    protected $keyType = 'int';

    protected $table = 'wechat_message_templates';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    protected $fillable = [
        'tenant_id',
        'template_key',
        'template_id',
        'title',
        'content_example',
        'status',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'content_example' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * 仅查询启用模板
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
