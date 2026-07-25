<?php

namespace MultiTenantSaas\Modules\Operator;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Operator\Console\Commands\PlatformInitCommand;
use MultiTenantSaas\Modules\Operator\Http\Middleware\EnsureOperator;
use MultiTenantSaas\Modules\Operator\Services\OperatorService;

class OperatorServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'operator';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(OperatorService::class);
    }

    protected function registerModuleCommands(): void
    {
        $this->commands([
            PlatformInitCommand::class,
        ]);
    }

    protected function bootModule(): void
    {
        // 模块自注册中间件别名（框架路由 User/Routes/public.php 引用，下游无需手动注册）
        $this->app['router']->aliasMiddleware('operator.auth', EnsureOperator::class);
    }
}
