<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

class Product extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId, SoftDeletes;

    public const TYPE_PHYSICAL = 'physical';

    public const TYPE_VIRTUAL = 'virtual';

    public const TYPE_COURSE = 'course';

    public const TYPE_EVENT = 'event';

    public const TYPE_POINTS_GOODS = 'points_goods';

    public const SALE_MODE_CASH = 'cash';

    public const SALE_MODE_POINTS = 'points';

    public const SALE_MODE_MIXED = 'mixed';

    protected $primaryKey = 'product_id';

    protected $fillable = [
        'tenant_id', 'category_id', 'name', 'description', 'price',
        'market_price', 'stock', 'status', 'type', 'sale_mode',
        'price_strategy', 'media_assets', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'price'          => 'decimal:2',
            'market_price'   => 'decimal:2',
            'stock'          => 'integer',
            'price_strategy' => 'array',
            'media_assets'   => 'array',
            'metadata'       => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id', 'product_category_id');
    }
}

