<?php

namespace MultiTenantSaas\Modules\Coupon\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 优惠券使用规则模型
 *
 * 定义高级用券规则：叠加规则、分类限制、阶梯门槛等。
 */
class CouponRule extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'rule_id';

    protected $fillable = [
        'coupon_id',
        'rule_type',
        'rule_config',
        'priority',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'rule_config' => 'array',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id', 'coupon_id');
    }
}