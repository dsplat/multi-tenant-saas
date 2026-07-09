<?php

namespace MultiTenantSaas\Modules\Coupon\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 优惠券分发记录模型
 *
 * 记录批量发券和裂变券的发放来源。
 */
class CouponDistribution extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'distribution_id';

    protected $fillable = [
        'coupon_id',
        'template_id',
        'tenant_id',
        'user_id',
        'distribution_type',
        'source_user_id',
        'batch_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id', 'coupon_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CouponTemplate::class, 'template_id', 'template_id');
    }
}