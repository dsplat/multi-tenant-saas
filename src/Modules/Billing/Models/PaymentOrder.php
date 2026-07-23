<?php

namespace MultiTenantSaas\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 支付订单模型
 */
class PaymentOrder extends Model
{
    use BelongsToTenant, HasGlobalId, SoftDeletes;

    protected $primaryKey = 'id';

    protected $fillable = [
        'tenant_id',
        'order_no',
        'driver',
        'amount',
        'description',
        'status',
        'paid_at',
        'transaction_id',
        'extra',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'extra' => 'array',
            'tenant_id' => 'integer',
        ];
    }

    /**
     * 作用域：按租户筛选
     */
    public function scopeByTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
