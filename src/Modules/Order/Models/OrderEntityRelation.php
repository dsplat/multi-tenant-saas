<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 订单-次要实体关系（归因/关联，非购买明细）
 *
 * 承载订单主实体之外的次要实体关联（如"活动推广课程"），relation_type 取
 * Support\OrderRelationTypes 白名单值。购买明细在 order_items，主实体在
 * orders.entity_type/entity_id，三者职责分离、互不重叠。
 */
class OrderEntityRelation extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId, SoftDeletes;

    protected $table = 'order_entity_relations';

    protected $primaryKey = 'relation_id';

    protected $fillable = [
        'tenant_id', 'order_id', 'entity_type', 'entity_id',
        'relation_type', 'share_amount', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'share_amount' => 'decimal:2',
            'metadata'     => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }
}
