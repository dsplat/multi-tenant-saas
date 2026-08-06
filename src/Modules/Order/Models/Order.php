<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 统一订单（一切交易皆订单）
 *
 * order_type：registration | product | course | exchange
 * pay_method：cash | points | mixed（积分折现抵扣 + 现金补差）
 * 计佣基数 = total_amount（实付现金，积分抵扣部分不计佣）
 */
class Order extends Model
{
    use BelongsToTenant, HasGlobalId, SoftDeletes;

    public const TYPE_REGISTRATION = 'registration';

    public const TYPE_PRODUCT = 'product';

    public const TYPE_COURSE = 'course';

    public const TYPE_EXCHANGE = 'exchange';

    public const PAY_CASH = 'cash';

    public const PAY_POINTS = 'points';

    public const PAY_MIXED = 'mixed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_REFUND_FAILED = 'refund_failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'orders';

    protected $primaryKey = 'order_id';

    protected $fillable = [
        'tenant_id', 'user_id', 'order_no', 'order_type', 'total_amount',
        'points_amount', 'pay_method', 'status', 'paid_at', 'refunded_at',
        'payment_order_id', 'source', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'total_amount'  => 'decimal:2',
            'points_amount' => 'integer',
            'paid_at'       => 'datetime',
            'refunded_at'   => 'datetime',
            'source'        => 'array',
            'metadata'      => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'order_id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function canRefund(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_REFUND_FAILED], true);
    }

    public static function generateOrderNo(): string
    {
        return 'ORD' . date('YmdHis') . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
