<?php

namespace MultiTenantSaas\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 税务规则模型
 *
 * 按区域（region_code）定义税率、税名及生效/失效日期，
 * 用于发票开票时的税率匹配与税额计算。
 */
class TaxRule extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'tax_rule_id';

    protected $fillable = [
        'tenant_id',
        'region_code',
        'tax_rate',
        'tax_name',
        'effective_date',
        'expiry_date',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'tax_rate' => 'decimal:4',
            'effective_date' => 'date',
            'expiry_date' => 'date',
            'is_default' => 'boolean',
        ];
    }

    /**
     * 作用域：筛选当前生效的税务规则
     *
     * 规则生效需满足：effective_date <= 今天 且（expiry_date 为空 或 expiry_date >= 今天）
     */
    public function scopeEffective($query)
    {
        $today = now()->toDateString();

        return $query->where('effective_date', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today);
            });
    }

    /**
     * 作用域：按区域筛选
     */
    public function scopeByRegion($query, string $regionCode)
    {
        return $query->where('region_code', $regionCode);
    }

    /**
     * 作用域：默认规则
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * 是否在生效期内
     */
    public function isEffective(): bool
    {
        $today = now()->toDateString();

        if ($this->effective_date && $this->effective_date->toDateString() > $today) {
            return false;
        }

        if ($this->expiry_date && $this->expiry_date->toDateString() < $today) {
            return false;
        }

        return true;
    }
}
