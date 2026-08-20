<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * Package 组成项（多态实体引用 + 可选规格锁定）
 */
class PackageItem extends Model
{
    use BelongsToTenant, HasGlobalId;

    protected $table = 'package_items';

    protected $primaryKey = 'package_item_id';

    protected $fillable = [
        'tenant_id', 'package_id', 'item_type', 'item_id',
        'sku_id', 'quantity', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'package_id' => 'integer',
            'sku_id'     => 'integer',
            'quantity'   => 'integer',
            'sort'       => 'integer',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'package_id', 'product_id');
    }
}
