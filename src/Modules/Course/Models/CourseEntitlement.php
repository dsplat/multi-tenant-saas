<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Course\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 课程权益（订单支付后授予；免费课程直授）
 */
class CourseEntitlement extends Model
{
    use BelongsToTenant, HasGlobalId;

    protected $table = 'course_entitlements';

    protected $primaryKey = 'entitlement_id';

    protected $fillable = [
        'tenant_id', 'user_id', 'course_id', 'order_id',
    ];
}
