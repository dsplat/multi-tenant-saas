<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Course\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;
use MultiTenantSaas\Contracts\OrderableEntity;
use MultiTenantSaas\Modules\Order\Support\EntityTypes;

/**
 * 课程本体（价格载体：price/points_price/sale_mode，免费课程 price=0）
 *
 * 实现 OrderableEntity 契约：统一订单中心下单（entity_type=course）
 */
class Course extends Model implements OrderableEntity
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_OFFLINE = 'offline';

    protected $table = 'courses';

    protected $primaryKey = 'course_id';

    protected $fillable = [
        'tenant_id', 'title', 'cover', 'description', 'price', 'points_price',
        'sale_mode', 'completion_reward_points', 'status', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'price'                    => 'decimal:2',
            'points_price'             => 'integer',
            'completion_reward_points' => 'integer',
            'metadata'                 => 'array',
        ];
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(CourseChapter::class, 'course_id', 'course_id')
            ->orderBy('sort_order');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    // ---- OrderableEntity 契约实现 ----

    public function getEntityType(): string
    {
        return EntityTypes::COURSE;
    }

    public function getEntityId(): string
    {
        return (string) $this->course_id;
    }

    public function getPayableAmount(): float
    {
        return (float) $this->price;
    }

    public function isPurchasable(): bool
    {
        return $this->isPublished();
    }

    public function isFree(): bool
    {
        return (float) $this->price <= 0 && (int) $this->points_price <= 0;
    }
}
