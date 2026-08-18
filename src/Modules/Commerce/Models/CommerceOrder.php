<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 商业体订单（租户向平台购买）
 *
 * 与 PaymentOrder 1:1（已决策）；回调/补偿等无租户上下文场景
 * 需 withoutGlobalScope(TenantScope::class) 访问。
 */
class CommerceOrder extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_PARTIAL_FAILED = 'partial_failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    protected $primaryKey = 'order_id';

    protected $fillable = [
        'order_no',
        'tenant_id',
        'amount',
        'status',
        'payment_order_id',
        'paid_at',
        'operator_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'tenant_id' => 'integer',
            'payment_order_id' => 'integer',
            'operator_id' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CommerceOrderItem::class, 'order_id', 'order_id');
    }

    public function isPayable(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
