<?php

namespace MultiTenantSaas\Modules\Coupon\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 优惠券核销记录模型
 *
 * 记录每次优惠券的使用：关联租户、用户、发票/订阅计划与实际抵扣金额。
 * tenant_id 可为 null（平台级优惠券在无租户上下文时使用）。
 *
 * 说明：本模型未启用 BelongsToTenant 全局作用域。CouponService 通过显式
 * tenant_id 参数管理租户隔离，以支持管理端跨租户查询与指定租户核销。
 * 若需自动租户作用域，可在补齐 tenant_id 列后启用该 trait。
 */
class CouponUsage extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'coupon_usage_id';

    protected $fillable = [
        'tenant_id',
        'coupon_id',
        'user_id',
        'invoice_id',
        'subscription_plan_id',
        'discount_amount',
        'currency',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'discount_amount' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    /**
     * 所属优惠券
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }
}
