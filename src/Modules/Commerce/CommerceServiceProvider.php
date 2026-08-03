<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce;

use MultiTenantSaas\Modules\Commerce\Console\Commands\ProcessCommerceRetry;
use MultiTenantSaas\Modules\Commerce\Services\CommerceHandlerRegistry;
use MultiTenantSaas\Modules\Commerce\Services\SupplyProvisionerRegistry;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class CommerceServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'commerce';

    protected function registerModuleBindings(): void
    {
        // 注册表持有状态（下游 boot 阶段可追加 Handler），须单例
        $this->app->singleton(CommerceHandlerRegistry::class);
        // 供给落地器注册表：下游项目 boot 阶段 register() 注入 Provisioner
        $this->app->singleton(SupplyProvisionerRegistry::class);
    }

    protected function registerModuleCommands(): void
    {
        $this->commands([
            ProcessCommerceRetry::class,
        ]);
    }
}
