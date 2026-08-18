<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 商品 SKU（统一商品交易体系的规格层）
 *
 * 两种形态：
 * - 自建 SKU：product_id 指向 products 表
 * - 镜像 SKU：ref_type/ref_id 指向外部供给（event_ticket / points_product / course），
 *   库存与售罄逻辑以源表为准，SKU 仅作为交易引用层
 */
class ProductSku extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId, SoftDeletes;

    public const REF_EVENT_TICKET = 'event_ticket';

    public const REF_POINTS_PRODUCT = 'points_product';

    public const REF_COURSE = 'course';

    protected $table = 'product_skus';

    protected $primaryKey = 'sku_id';

    protected $fillable = [
        'tenant_id', 'product_id', 'ref_type', 'ref_id', 'name',
        'spec_attrs', 'price', 'points_price', 'stock', 'sold_count', 'status',
    ];

    protected function casts(): array
    {
        return [
            'price'        => 'decimal:2',
            'points_price' => 'integer',
            'stock'        => 'integer',
            'sold_count'   => 'integer',
            'spec_attrs'   => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    /**
     * 是否为镜像 SKU（引用外部供给）
     */
    public function isMirror(): bool
    {
        return $this->ref_type !== null && $this->ref_id !== null;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
