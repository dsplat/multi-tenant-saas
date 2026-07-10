<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * Webhook 交付记录
 *
 * 记录每次 Webhook 投递的请求体、响应状态码、响应体、耗时及重试次数。
 */
class WebhookDelivery extends Model
{
    use BelongsToTenant, HasGlobalId;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    protected $primaryKey = 'webhook_delivery_id';

    protected $fillable = [
        'webhook_delivery_id',
        'webhook_id',
        'tenant_id',
        'event_type',
        'payload',
        'response_status_code',
        'response_body',
        'duration_ms',
        'attempts',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response_status_code' => 'integer',
            'duration_ms' => 'integer',
            'attempts' => 'integer',
        ];
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class, 'webhook_id', 'webhook_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
