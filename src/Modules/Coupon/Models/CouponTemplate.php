<?php

namespace MultiTenantSaas\Modules\Coupon\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 优惠券模板模型
 *
 * 支持批量发券和裂变发券的模板化配置。
 */
class CouponTemplate extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'template_id';

    protected $fillable = [
        'name',
        'description',
        'type',
        'value',
        'currency',
        'min_amount',
        'max_discount',
        'applies_to',
        'subscription_plan_id',
        'duration_months',
        'max_uses',
        'max_uses_per_tenant',
        'valid_days',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_amount' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'duration_months' => 'integer',
            'max_uses' => 'integer',
            'max_uses_per_tenant' => 'integer',
            'valid_days' => 'integer',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }
}