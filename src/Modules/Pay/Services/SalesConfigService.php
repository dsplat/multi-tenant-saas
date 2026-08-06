<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Pay\Services;

use MultiTenantSaas\Modules\Pay\Models\SalesConfig;
use MultiTenantSaas\Context\TenantContext;

/**
 * 销售折现配置服务（租户级，每租户一行）
 *
 * - mixed_pay_enabled：积分折现混合支付开关
 * - points_to_cash_ratio：N 积分 = 1 元（如 100）
 * - max_points_deduct_ratio：积分最高可抵扣订单金额比例（%）
 */
class SalesConfigService
{
    public const DEFAULTS = [
        'mixed_pay_enabled'       => false,
        'points_to_cash_ratio'    => 100,
        'max_points_deduct_ratio' => 50,
    ];

    public function getConfig(int $tenantId): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $config = SalesConfig::where('tenant_id', $tenantId)->first();

        if (! $config) {
            return self::DEFAULTS;
        }

        return [
            'mixed_pay_enabled'       => (bool) $config->mixed_pay_enabled,
            'points_to_cash_ratio'    => (int) $config->points_to_cash_ratio,
            'max_points_deduct_ratio' => (int) $config->max_points_deduct_ratio,
        ];
    }

    public function updateConfig(int $tenantId, array $data): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $config = SalesConfig::firstOrNew(['tenant_id' => $tenantId]);

        $fillable = ['mixed_pay_enabled', 'points_to_cash_ratio', 'max_points_deduct_ratio'];
        $attributes = array_filter(
            array_intersect_key($data, array_flip($fillable)),
            fn ($v) => $v !== null
        );

        // 防御性约束：比例必须为正
        if (isset($attributes['points_to_cash_ratio'])) {
            $attributes['points_to_cash_ratio'] = max(1, (int) $attributes['points_to_cash_ratio']);
        }
        if (isset($attributes['max_points_deduct_ratio'])) {
            $attributes['max_points_deduct_ratio'] = min(100, max(0, (int) $attributes['max_points_deduct_ratio']));
        }

        $config->fill($attributes);
        $config->save();

        return $this->getConfig($tenantId);
    }
}
