<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 平台内容条目（内容库，平台级无 tenant_id）
 *
 * 展示消费（Layer B）归下游项目；本模型只承载库存储。
 */
class PlatformContent extends Model
{
    use HasGlobalId;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RETIRED = 'retired';

    protected $primaryKey = 'content_id';

    protected $fillable = [
        'title',
        'type',
        'body',
        'file_url',
        'cover_url',
        'tags',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
