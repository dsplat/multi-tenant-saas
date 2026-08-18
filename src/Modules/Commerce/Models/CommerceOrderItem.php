<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 商业体订单项
 *
 * 无 tenant_id（经 order 归属租户），不走租户隔离 Scope。
 * payload_snapshot 为下单时 SKU payload 快照，防 SKU 后续变更影响履约。
 */
class CommerceOrderItem extends Model
{
    use SerializesFriendlyDates;
    use HasGlobalId;

    public const FULFILL_PENDING = 'pending';

    public const FULFILL_FULFILLED = 'fulfilled';

    public const FULFILL_FAILED = 'failed';

    public const FULFILL_REVOKED = 'revoked';

    protected $primaryKey = 'item_id';

    protected $fillable = [
        'order_id',
        'sku_id',
        'qty',
        'unit_price',
        'fulfill_status',
        'fulfill_at',
        'retry_count',
        'fail_reason',
        'payload_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'sku_id' => 'integer',
            'qty' => 'integer',
            'unit_price' => 'decimal:2',
            'fulfill_at' => 'datetime',
            'retry_count' => 'integer',
            'payload_snapshot' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CommerceOrder::class, 'order_id', 'order_id');
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(CommerceSku::class, 'sku_id', 'sku_id');
    }
}
