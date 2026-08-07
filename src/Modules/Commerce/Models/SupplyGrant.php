<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 供给授权（内容分销 / 积分商城 SKU 共用，已决策）
 *
 * 租户购入 supply SKU 后获得的「代理证」：
 * - settlement: 签约时锁定的结算参数（改价保护，调价只影响新单）
 * - instance_payload: 项目侧 Provisioner 履约产物引用
 * 停供不停兑：suspend 冻结新兑换联动，已上架实例由项目侧处置。
 */
class SupplyGrant extends Model
{
    use BelongsToTenant, HasGlobalId;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    protected $primaryKey = 'grant_id';

    protected $fillable = [
        'tenant_id',
        'sku_id',
        'source_order_id',
        'status',
        'valid_from',
        'valid_until',
        'settlement',
        'instance_payload',
        'allocated_qty',
        'remaining_qty',
        'locked_qty',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'sku_id' => 'integer',
            'source_order_id' => 'integer',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'settlement' => 'array',
            'instance_payload' => 'array',
            'allocated_qty' => 'integer',
            'remaining_qty' => 'integer',
            'locked_qty' => 'integer',
        ];
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(CommerceSku::class, 'sku_id', 'sku_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CommerceOrder::class, 'source_order_id', 'order_id');
    }

    public function isEffective(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && ($this->valid_until === null || $this->valid_until->isFuture());
    }

    /**
     * 库存型授权（划拨了数量，走锁库存→扣预存→下发链路）
     */
    public function isStockManaged(): bool
    {
        return $this->allocated_qty > 0;
    }

    /**
     * 供货价（分）：settlement.supply_price 以元存储（历史口径），结算时换算
     */
    public function supplyPriceFen(): int
    {
        return (int) round(((float) ($this->settlement['supply_price'] ?? 0)) * 100);
    }
}
