<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Pay;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Pay\Services\VirtualPayChannelRegistry;

/**
 * Pay 模块（统一支付编排）
 *
 * - 现金支付：复用 Payment（充值网关）/ Billing（yansongda/pay）底座
 * - 虚拟支付：VirtualPayChannelContract 扩展点，项目层注册实现（如积分）
 * - 混合折现：SalesConfig 租户级配置（sales_configs）
 *
 * 注意：与既有 Payment 模块（第三方充值网关）区分，本模块是交易支付编排层。
 */
class PayServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'pay';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(VirtualPayChannelRegistry::class);
    }
}
