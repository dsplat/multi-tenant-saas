<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Order\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 统一订单（一切交易皆订单）
 *
 * order_type：registration | product | course | exchange
 * pay_method：cash | points | mixed（积分折现抵扣 + 现金补差）
 * 计佣基数 = total_amount（实付现金，积分抵扣部分不计佣）
 *
 * 实体绑定隔离层：entity_type/entity_id（主实体）+ secondary_entity_*（次要关联，
 * 如"活动推广课程"），字符串枚举见 Support\EntityTypes，不绑类名、不加业务专属 ID 字段。
 */
class Order extends Model
{
    use SerializesFriendlyDates;
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
        'points_amount', 'pay_method', 'entity_type', 'entity_id',
        'secondary_entity_type', 'secondary_entity_id',
        'status', 'paid_at', 'refunded_at',
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

    /**
     * 按主实体过滤订单（entity_type 取 EntityTypes 白名单值）
     */
    public function scopeForEntity(Builder $query, string $entityType, string $entityId): Builder
    {
        return $query->where('entity_type', $entityType)->where('entity_id', $entityId);
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
