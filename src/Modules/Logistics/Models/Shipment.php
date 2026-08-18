<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 发货单（物流登记，不对接第三方快递 API）
 *
 * 一笔订单可拆多个发货单（shipment_no 唯一）。
 */
class Shipment extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId, SoftDeletes;

    public const STATUS_PENDING = 'pending';      // 待发货
    public const STATUS_SHIPPED = 'shipped';      // 已发货
    public const STATUS_DELIVERED = 'delivered';  // 已签收
    public const STATUS_CANCELLED = 'cancelled';  // 已取消

    protected $table = 'shipments';

    protected $primaryKey = 'shipment_id';

    public $incrementing = false;

    protected $fillable = [
        'shipment_id',
        'tenant_id',
        'order_id',
        'order_no',
        'user_id',
        'carrier',
        'tracking_no',
        'status',
        'receiver_name',
        'receiver_phone',
        'receiver_address',
        'items',
        'remark',
        'shipped_at',
        'delivered_at',
    ];

    protected $casts = [
        'items'      => 'array',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function canShip(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function canDeliver(): bool
    {
        return $this->status === self::STATUS_SHIPPED;
    }

    public function canCancel(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_SHIPPED], true);
    }
}
