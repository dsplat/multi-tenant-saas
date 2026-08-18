<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 订单商品行（下单时快照名称/规格/单价）
 */
class OrderItem extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId;

    protected $table = 'order_items';

    protected $primaryKey = 'item_id';

    protected $fillable = [
        'tenant_id', 'order_id', 'sku_id', 'product_id', 'item_type', 'ref_id',
        'item_name', 'spec', 'quantity', 'unit_price', 'points_unit_price', 'amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity'          => 'integer',
            'unit_price'        => 'decimal:2',
            'points_unit_price' => 'integer',
            'amount'            => 'decimal:2',
            'spec'              => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }
}
