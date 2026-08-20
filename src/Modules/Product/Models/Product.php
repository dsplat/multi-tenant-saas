<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public const TYPE_ACTIVITY = 'activity';

    public const TYPE_POINTS_GOODS = 'points_goods';

    /** 组合实体（Package）：组成见 package_items，履约递归拆解 */
    public const TYPE_PACKAGE = 'package';

    /** 商品类型白名单（唯一事实源，校验/AI schema 一律引用此常量） */
    public const TYPES = [
        self::TYPE_PHYSICAL,
        self::TYPE_VIRTUAL,
        self::TYPE_COURSE,
        self::TYPE_ACTIVITY,
        self::TYPE_POINTS_GOODS,
        self::TYPE_PACKAGE,
    ];

    public const SALE_MODE_CASH = 'cash';

    public const SALE_MODE_POINTS = 'points';

    public const SALE_MODE_MIXED = 'mixed';

    public const SALE_MODES = [
        self::SALE_MODE_CASH,
        self::SALE_MODE_POINTS,
        self::SALE_MODE_MIXED,
    ];

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

    /** Package 组成项（仅 type=package 时有意义） */
    public function packageItems(): HasMany
    {
        return $this->hasMany(PackageItem::class, 'package_id', 'product_id');
    }
}

