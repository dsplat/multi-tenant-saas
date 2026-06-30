<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 事件订阅
 *
 * 租户级事件订阅记录，支持内部处理器（internal）与外部 Webhook（webhook）两种订阅类型。
 * - internal: handler 为处理器类名（需实现 handle(string $eventType, array $payload): void 或 __invoke）
 * - webhook:  handler 为目标 URL，secret 用于 HMAC-SHA256 签名
 */
class EventSubscription extends Model
{
    use BelongsToTenant, HasGlobalId, SoftDeletes;

    /** 订阅类型：内部处理器 */
    public const TYPE_INTERNAL = 'internal';

    /** 订阅类型：外部 Webhook */
    public const TYPE_WEBHOOK = 'webhook';

    /** 状态：激活 */
    public const STATUS_ACTIVE = true;

    /** 状态：停用 */
    public const STATUS_INACTIVE = false;

    protected $primaryKey = 'event_subscription_id';

    protected $fillable = [
        'event_subscription_id',
        'tenant_id',
        'event_type',
        'subscription_type',
        'handler',
        'secret',
        'is_active',
        'description',
    ];

    protected $hidden = [
        'secret',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    public function deadLetters(): HasMany
    {
        return $this->hasMany(DeadLetter::class, 'subscription_id', 'event_subscription_id');
    }

    /**
     * 是否为内部订阅
     */
    public function isInternal(): bool
    {
        return $this->subscription_type === self::TYPE_INTERNAL;
    }

    /**
     * 是否为外部 Webhook 订阅
     */
    public function isWebhook(): bool
    {
        return $this->subscription_type === self::TYPE_WEBHOOK;
    }

    /**
     * 是否处于激活状态
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }
}
