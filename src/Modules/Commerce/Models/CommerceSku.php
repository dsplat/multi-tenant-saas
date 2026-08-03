<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 平台商品（SKU）
 *
 * 平台级资产（无 tenant_id，不走租户隔离）。
 * role 为第一级分类（consumer|supply），type 为第二级（见 docs/commerce-sku.md）。
 */
class CommerceSku extends Model
{
    use HasGlobalId;

    public const ROLE_CONSUMER = 'consumer';

    public const ROLE_SUPPLY = 'supply';

    public const TYPE_PLAN = 'plan';

    public const TYPE_MODULE = 'module';

    public const TYPE_CREDIT_PACK = 'credit_pack';

    public const TYPE_CONTENT_PACK = 'content_pack';

    public const TYPE_MALL_SUPPLY = 'mall_supply';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    protected $primaryKey = 'sku_id';

    protected $fillable = [
        'name',
        'type',
        'role',
        'lifecycle',
        'fulfill_handler',
        'price',
        'billing_cycle',
        'payload',
        'refundable',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'payload' => 'array',
            'refundable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
