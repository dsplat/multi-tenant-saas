<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Course\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 课程权益（订单支付后授予；免费课程直授；外部导入/补偿/订阅）
 *
 * source 对标小鹅通 join_type（import/free/付费）：标记权益来源，
 * 迁移导入学员无订单时 order_id 可空、source='import'。
 * valid_until 支撑训练营营期、专栏订阅等时限权益，NULL=永久。
 */
class CourseEntitlement extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId;

    // 权益来源
    public const SOURCE_ORDER = 'order';

    public const SOURCE_FREE = 'free';

    public const SOURCE_IMPORT = 'import';

    public const SOURCE_COMPENSATION = 'compensation';

    public const SOURCE_SUBSCRIPTION = 'subscription';

    public const SOURCES = [
        self::SOURCE_ORDER,
        self::SOURCE_FREE,
        self::SOURCE_IMPORT,
        self::SOURCE_COMPENSATION,
        self::SOURCE_SUBSCRIPTION,
    ];

    protected $table = 'course_entitlements';

    protected $primaryKey = 'entitlement_id';

    protected $fillable = [
        'tenant_id', 'user_id', 'course_id', 'order_id', 'source', 'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'valid_until' => 'datetime',
        ];
    }

    /**
     * 权益是否在有效期内（valid_until 为 NULL 时永久有效）
     */
    public function isActive(): bool
    {
        return $this->valid_until === null || $this->valid_until->isFuture();
    }
}
