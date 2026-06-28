<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 优惠券模型
 *
 * 系统级优惠券，支持固定金额（fixed）与百分比（percentage）两种折扣类型。
 * 通过 max_uses / max_uses_per_tenant / starts_at / expires_at / min_amount /
 * subscription_plan_id 等字段控制使用限制。
 *
 * 说明：coupons 表无 tenant_id 列（见迁移 2026_06_27_000011_create_coupons_tables），
 * 优惠券为全局可用，故本模型未启用 BelongsToTenant 全局作用域。
 * 若后续需要租户级优惠券，需先补迁移为 coupons 表增加 nullable tenant_id 列。
 */
class Coupon extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'coupon_id';

    public const TYPE_FIXED = 'fixed';

    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPES = [
        self::TYPE_FIXED,
        self::TYPE_PERCENTAGE,
    ];

    public const APPLIES_TO_SUBSCRIPTION = 'subscription';

    public const APPLIES_TO_INVOICE = 'invoice';

    public const APPLIES_TO_ALL = 'all';

    public const APPLIES_TO = [
        self::APPLIES_TO_SUBSCRIPTION,
        self::APPLIES_TO_INVOICE,
        self::APPLIES_TO_ALL,
    ];

    protected $fillable = [
        'code',
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
        'used_count',
        'starts_at',
        'expires_at',
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
            'used_count' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * 优惠券核销记录
     */
    public function usages(): HasMany
    {
        return $this->hasMany(CouponUsage::class, 'coupon_id');
    }

    public function isFixed(): bool
    {
        return $this->type === self::TYPE_FIXED;
    }

    public function isPercentage(): bool
    {
        return $this->type === self::TYPE_PERCENTAGE;
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    public function hasStarted(): bool
    {
        return ! $this->starts_at || $this->starts_at->lte(now());
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(now());
    }

    public function hasReachedMaxUses(): bool
    {
        return $this->max_uses !== null && $this->used_count >= $this->max_uses;
    }

    /**
     * 作用域：仅启用的优惠券
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * 作用域：按优惠码筛选
     */
    public function scopeByCode($query, string $code)
    {
        return $query->where('code', $code);
    }
}
