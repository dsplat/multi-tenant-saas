<?php

namespace MultiTenantSaas\Modules\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * Webhook 端点
 *
 * 租户级 Webhook 注册，订阅指定事件类型。
 * 创建时自动生成 secret，用于 HMAC-SHA256 签名验证。
 */
class Webhook extends Model
{
    use BelongsToTenant, HasGlobalId, SoftDeletes;

    protected $primaryKey = 'webhook_id';

    protected $fillable = [
        'webhook_id',
        'tenant_id',
        'url',
        'events',
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
            'events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'webhook_id', 'webhook_id');
    }

    /**
     * 是否订阅了指定事件
     */
    public function subscribesTo(string $eventType): bool
    {
        $events = $this->events ?? [];

        return in_array($eventType, $events, true);
    }
}
