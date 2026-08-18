<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 消费流水（订单支付成功时写入，现金/积分分轨）
 */
class ConsumptionRecord extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId;

    protected $table = 'consumption_records';

    protected $primaryKey = 'record_id';

    protected $fillable = [
        'tenant_id', 'user_id', 'order_id', 'order_type',
        'cash_amount', 'points_amount', 'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'cash_amount'   => 'decimal:2',
            'points_amount' => 'integer',
            'consumed_at'   => 'datetime',
        ];
    }
}
