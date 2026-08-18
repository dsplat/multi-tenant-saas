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

class ProductCategory extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId, SoftDeletes;

    protected $primaryKey = 'product_category_id';

    protected $fillable = [
        'tenant_id', 'name', 'parent_id', 'sort_order', 'status',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id', 'product_category_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id', 'product_category_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id', 'product_category_id');
    }
}
