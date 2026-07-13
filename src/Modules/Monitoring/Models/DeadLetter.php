<?php

namespace MultiTenantSaas\Modules\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 死信队列
 *
 * 记录事件分发失败后的原始数据、失败原因与重试次数，支持手动重投与标记解决。
 */
class DeadLetter extends Model
{
    use BelongsToTenant, HasGlobalId;

    /** 状态：失败 */
    public const STATUS_FAILED = 'failed';

    /** 状态：已重试 */
    public const STATUS_RETRIED = 'retried';

    /** 状态：已解决 */
    public const STATUS_RESOLVED = 'resolved';

    protected $primaryKey = 'dead_letter_id';

    protected $fillable = [
        'dead_letter_id',
        'tenant_id',
        'event_type',
        'subscription_id',
        'original_data',
        'failure_reason',
        'retry_count',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'original_data' => 'array',
            'retry_count' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(EventSubscription::class, 'subscription_id', 'event_subscription_id');
    }

    /**
     * 是否处于失败状态
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
