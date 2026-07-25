<?php

namespace MultiTenantSaas\Modules\Infrastructure;

use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Events\TokenAuthenticated;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Infrastructure\Observers\TenantObserver;
use MultiTenantSaas\Modules\Infrastructure\Observers\TenantSettingObserver;
use MultiTenantSaas\Modules\Infrastructure\Services\QuotaService;

class InfrastructureServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'infrastructure';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(QuotaService::class, fn ($app) => new QuotaService($app->make(TenantContextContract::class)));
    }

    protected function bootModule(): void
    {
        Tenant::observe(TenantObserver::class);
        TenantSetting::observe(TenantSettingObserver::class);

        // 滑动续期：活跃请求自动刷新 token 生命周期（sanctum.expiration 为固定窗口，
        // 无此机制时活跃会话也会在 30 分钟后强制过期）。每 10 分钟至多落库一次。
        Event::listen(TokenAuthenticated::class, function (TokenAuthenticated $event): void {
            $token = $event->token;
            if ($token->created_at && $token->created_at->lt(now()->subMinutes(10))) {
                $token->forceFill(['created_at' => now()])->save();
            }
        });
    }
}
