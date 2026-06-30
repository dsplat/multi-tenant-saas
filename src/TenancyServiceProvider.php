<?php

namespace MultiTenantSaas;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use MultiTenantSaas\Console\Commands\CheckTenantIsolation;
use MultiTenantSaas\Contracts\AgentMonitorContract;
use MultiTenantSaas\Contracts\AgentRuntimeContract;
use MultiTenantSaas\Contracts\AgentServiceContract;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Events\TenantActivated;
use MultiTenantSaas\Events\TenantCreated;
use MultiTenantSaas\Events\TenantSuspended;
use MultiTenantSaas\Events\UserLoggedIn;
use MultiTenantSaas\Events\UserRegistered;
use MultiTenantSaas\Listeners\LogEventListener;
use MultiTenantSaas\Services\Ai\AiTextService;
use MultiTenantSaas\Services\Agent\AgentMonitor;
use MultiTenantSaas\Services\Agent\AgentRuntime;
use MultiTenantSaas\Services\Agent\AgentService;
use MultiTenantSaas\Services\Agent\MemoryCompressor;
use MultiTenantSaas\Services\Agent\ToolRegistry;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Services\AlipayOAuthService;
use MultiTenantSaas\Services\AlertService;
use MultiTenantSaas\Services\ApiVersionService;
use MultiTenantSaas\Services\CacheService;
use MultiTenantSaas\Services\CostService;
use MultiTenantSaas\Services\DeveloperPortalService;
use MultiTenantSaas\Services\EventBusService;
use MultiTenantSaas\Services\ExportService;
use MultiTenantSaas\Services\MetricsService;
use MultiTenantSaas\Services\FeatureFlagService;
use MultiTenantSaas\Services\HealthService;
use MultiTenantSaas\Services\IdGenerator;
use MultiTenantSaas\Services\LoginLogService;
use MultiTenantSaas\Services\PaymentSecurityService;
use MultiTenantSaas\Services\PerformanceService;
use MultiTenantSaas\Services\PluginService;
use MultiTenantSaas\Services\QueueService;
use MultiTenantSaas\Services\RateLimitService;
use MultiTenantSaas\Services\ResourceService;
use MultiTenantSaas\Services\SandboxService;
use MultiTenantSaas\Services\SlaService;
use MultiTenantSaas\Services\SocialiteService;
use MultiTenantSaas\Services\StructuredLogService;
use MultiTenantSaas\Services\SubscriptionService;
use MultiTenantSaas\Services\TenantProfileService;
use MultiTenantSaas\Services\UserPreferenceService;
use MultiTenantSaas\Services\UserProfileService;

class TenancyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 发布核心配置
        $this->publishes([
            __DIR__.'/../config/tenancy.php' => config_path('tenancy.php'),
        ], 'tenancy-config');

        // 发布迁移
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'tenancy-migrations');

        // 发布模块配置
        $this->publishes([
            __DIR__.'/Modules/ApiToken/Config/apitoken.php' => config_path('apitoken.php'),
            __DIR__.'/Modules/Payment/Config/payment.php' => config_path('payment.php'),
        ], 'tenancy-modules-config');

        // 注册健康检查
        HealthService::registerChecks();

        // 注册 Artisan 命令
        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckTenantIsolation::class,
            ]);
        }

        // 注册认证后 API 限流策略
        // 按用户 ID 限流，每分钟 60 次
        RateLimiter::for('api', function ($request) {
            $user = $request->user();

            return Limit::perMinute(60)->by(
                $user ? $user->getAuthIdentifier() : $request->ip()
            );
        });

        // 注册事件监听器（事件系统）
        Event::listen(TenantCreated::class, [LogEventListener::class, 'handleTenantCreated']);
        Event::listen(TenantSuspended::class, [LogEventListener::class, 'handleTenantSuspended']);
        Event::listen(TenantActivated::class, [LogEventListener::class, 'handleTenantActivated']);
        Event::listen(UserRegistered::class, [LogEventListener::class, 'handleUserRegistered']);
        Event::listen(UserLoggedIn::class, [LogEventListener::class, 'handleUserLoggedIn']);
    }

    public function register(): void
    {
        // 合并核心配置
        $this->mergeConfigFrom(__DIR__.'/../config/tenancy.php', 'tenancy');

        // 合并模块配置
        $this->mergeConfigFrom(__DIR__.'/Modules/ApiToken/Config/apitoken.php', 'apitoken');
        $this->mergeConfigFrom(__DIR__.'/Modules/Payment/Config/payment.php', 'payment');

        // 注册ID生成器（绑定接口契约 + 具体实现）
        $this->app->singleton(IdGeneratorContract::class, function () {
            return new IdGenerator;
        });
        $this->app->alias(IdGeneratorContract::class, IdGenerator::class);

        // 注册租户上下文（绑定接口契约 + 具体实现）
        $this->app->singleton(TenantContextContract::class, function () {
            return new TenantContext;
        });
        $this->app->alias(TenantContextContract::class, TenantContext::class);

        // 注册 AI 文本推理服务（绑定接口契约 + 具体实现，AgentRuntime 推理引擎）
        $this->app->singleton(AiTextServiceContract::class, function () {
            return new AiTextService();
        });
        $this->app->alias(AiTextServiceContract::class, AiTextService::class);

        // 注册 Agent 服务（绑定接口契约 + 具体实现）
        $this->app->singleton(AgentServiceContract::class, function ($app) {
            return new AgentService(
                $app->make(TenantContextContract::class)
            );
        });
        $this->app->alias(AgentServiceContract::class, AgentService::class);

        // 注册 Agent 监控服务（绑定接口契约 + 具体实现）
        $this->app->singleton(AgentMonitorContract::class, function ($app) {
            return new AgentMonitor(
                $app->make(TenantContextContract::class)
            );
        });
        $this->app->alias(AgentMonitorContract::class, AgentMonitor::class);

        // 注册工具注册表（绑定接口契约 + 具体实现）
        $this->app->singleton(ToolRegistryContract::class, function ($app) {
            return new ToolRegistry(
                $app->make(\Illuminate\Contracts\Container\Container::class)
            );
        });
        $this->app->alias(ToolRegistryContract::class, ToolRegistry::class);

        // 注册记忆压缩器
        $this->app->singleton(MemoryCompressor::class, function ($app) {
            return new MemoryCompressor(
                $app->make(AiTextServiceContract::class),
                $app->make(TenantContextContract::class),
            );
        });

        // 注册 Agent 运行时（绑定接口契约 + 具体实现，ReAct 循环引擎）
        $this->app->singleton(AgentRuntimeContract::class, function ($app) {
            return new AgentRuntime(
                $app->make(AiTextServiceContract::class),
                $app->make(ToolRegistryContract::class),
                $app->make(AgentMonitorContract::class),
                $app->make(TenantContextContract::class),
                $app->make(MemoryCompressor::class),
            );
        });
        $this->app->alias(AgentRuntimeContract::class, AgentRuntime::class);

        // 注册配置存储
        $this->app->singleton(TenantConfigStore::class, function () {
            return new TenantConfigStore;
        });

        // 注册 ApiToken 模块服务（仅在启用时）
        if (config('apitoken.enabled', false)) {
            $this->app->singleton(
                ApiTokenService::class
            );
        }

        // 注册 Payment 模块服务（仅在启用时）
        if (config('payment.enabled', false)) {
            $this->app->singleton(
                PaymentService::class
            );
        }

        // 注册支付宝 OAuth 服务
        $this->app->singleton(AlipayOAuthService::class);

        // 注册核心业务服务
        $this->app->singleton(UserProfileService::class);
        $this->app->singleton(UserPreferenceService::class);
        $this->app->singleton(LoginLogService::class);
        $this->app->singleton(StructuredLogService::class);
        $this->app->singleton(ApiVersionService::class);
        $this->app->singleton(ExportService::class);
        $this->app->singleton(PluginService::class);
        $this->app->singleton(RateLimitService::class);
        $this->app->singleton(AlertService::class);
        $this->app->singleton(PerformanceService::class);
        $this->app->singleton(CacheService::class);
        $this->app->singleton(PaymentSecurityService::class);
        $this->app->singleton(SubscriptionService::class);
        $this->app->singleton(EventBusService::class);
        $this->app->singleton(TenantProfileService::class);
        $this->app->singleton(QueueService::class);
        $this->app->singleton(SocialiteService::class);
        $this->app->singleton(FeatureFlagService::class);
        $this->app->singleton(CostService::class);
        $this->app->singleton(ResourceService::class);

        // 注册 AI 网关服务（模型路由、提供商注册、限流、重试与请求日志）
        $this->app->singleton(AiGatewayService::class);

        // 注册 AI 视频服务（视频生成、异步任务轮询、结果存储）
        $this->app->singleton(AiVideoService::class);

        // 注册开发者门户与沙箱服务
        $this->app->singleton(DeveloperPortalService::class);
        $this->app->singleton(SandboxService::class);

        // 注册指标采集与 SLA 监控服务
        $this->app->singleton(MetricsService::class);
        $this->app->singleton(SlaService::class);
    }
}
