<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Pay\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 租户级销售折现配置（每租户一行）
 */
class SalesConfig extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId;

    protected $table = 'sales_configs';

    protected $primaryKey = 'sales_config_id';

    protected $fillable = [
        'tenant_id', 'mixed_pay_enabled', 'points_to_cash_ratio', 'max_points_deduct_ratio',
    ];

    protected function casts(): array
    {
        return [
            'mixed_pay_enabled'       => 'boolean',
            'points_to_cash_ratio'    => 'integer',
            'max_points_deduct_ratio' => 'integer',
        ];
    }
}
