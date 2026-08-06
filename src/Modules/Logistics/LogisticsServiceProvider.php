<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Logistics;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

/**
 * Logistics 模块（物流）
 *
 * shipments 发货登记/跟踪：登记 → 发货（运单号）→ 签收 / 取消。
 * 仅建模与端点，不对接第三方快递 API。
 */
class LogisticsServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'logistics';
}
