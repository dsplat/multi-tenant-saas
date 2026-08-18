<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 平台内容包（content_pack SKU payload.pack_id 指向）
 */
class PlatformContentPack extends Model
{
    use SerializesFriendlyDates;
    use HasGlobalId;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    protected $primaryKey = 'pack_id';

    protected $fillable = [
        'name',
        'description',
        'cover_url',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(
            PlatformContent::class,
            'platform_content_pack_items',
            'pack_id',
            'content_id',
            'pack_id',
            'content_id'
        )
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
