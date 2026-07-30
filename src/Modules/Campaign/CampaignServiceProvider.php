<?php

namespace MultiTenantSaas\Modules\Campaign;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Campaign\Console\CampaignProcessDueCommand;
use MultiTenantSaas\Modules\Campaign\Services\CampaignTaskExecutor;
use MultiTenantSaas\Modules\Campaign\Services\PlanCompiler;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

/**
 * Campaign 模块 — 活动排期引擎（docs/event-plan.md Phase 0）
 *
 * 计划编译（plan_doc → campaign_tasks）→ 定时调度（campaign:process-due）→
 * 任务执行（tool/human）→ 待办通知（database + ibot）。
 * 平台级开关 ai.campaign.enabled（默认关闭，AI 可选性铁律）。
 */
class CampaignServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'campaign';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(PlanCompiler::class);
        $this->app->singleton(CampaignTaskExecutor::class);
    }

    protected function registerModuleCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CampaignProcessDueCommand::class,
            ]);
        }
    }

    /**
     * 覆写基类路由加载：tenant.php 需挂到 api/v1 前缀 + tenant.identify
     * （范式同 Ibot 模块）
     */
    protected function loadModuleRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $tenantRoute = $this->getModulePath('Routes/tenant.php');
        if ($tenantRoute && file_exists($tenantRoute)) {
            Route::middleware(['auth:sanctum', 'throttle:api', 'tenant.identify'])
                ->prefix('api/v1')
                ->group($tenantRoute);
        }
    }
}
