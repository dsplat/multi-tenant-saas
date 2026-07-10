<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

/**
 * 模块启动器
 *
 * 在应用启动时, 根据 ModuleManager 的加载列表, 注册并启动已启用模块的 ServiceProvider。
 *
 * 职责:
 * - 从 ModuleManager 获取按 priority + 依赖排序的模块列表
 * - 逐个 register() 模块的 ServiceProvider (服务绑定、配置合并)
 * - 逐个 boot() 模块的 ServiceProvider (路由、迁移、视图、翻译、命令)
 * - 记录加载日志和错误
 *
 * 用法:
 *   // TenancyServiceProvider::boot() 中调用
 *   $this->app->make(ModuleBootstrapper::class)->bootstrap();
 */
class ModuleBootstrapper
{
    /** @var ModuleServiceProvider[] 已注册的模块 Provider */
    protected array $registered = [];

    /** @var ModuleServiceProvider[] 已启动的模块 Provider */
    protected array $booted = [];

    public function __construct(
        protected ModuleManager $manager,
        protected ModuleRegistry $registry,
    ) {}

    /**
     * 启动所有已启用模块
     *
     * 流程:
     * 1. 从 ModuleManager 获取启用模块列表 (按 priority + 拓扑排序)
     * 2. 逐个 register() — 注册服务绑定、合并配置
     * 3. 逐个 boot() — 加载路由、迁移、视图、翻译、命令
     */
    public function bootstrap(): void
    {
        $loadOrder = $this->manager->getLoadOrder();

        if (empty($loadOrder)) {
            Log::debug('[ModuleBootstrapper] 无已启用模块');

            return;
        }

        // Phase 1: register
        foreach ($loadOrder as $name => $meta) {
            $this->registerModule($name, $meta);
        }

        // Phase 2: boot
        foreach ($this->registered as $name => $provider) {
            $this->bootModule($name, $provider);
        }

        Log::info('[ModuleBootstrapper] 模块启动完成', [
            'registered' => array_keys($this->registered),
            'booted' => array_keys($this->booted),
            'total' => count($this->booted),
        ]);
    }

    /**
     * 注册单个模块 (register 阶段)
     */
    protected function registerModule(string $name, array $meta): void
    {
        $providerClass = $meta['provider'] ?? null;

        if (! $providerClass) {
            Log::warning("[ModuleBootstrapper] 模块 [{$name}] 无 provider 定义");

            return;
        }

        if (! class_exists($providerClass)) {
            Log::warning("[ModuleBootstrapper] 模块 [{$name}] Provider 类不存在: {$providerClass}");

            return;
        }

        try {
            $provider = app()->register($providerClass);

            if ($provider instanceof ModuleServiceProvider) {
                $provider->setModuleMeta($meta);
            }

            $this->registered[$name] = $provider;

            Log::debug("[ModuleBootstrapper] 模块 [{$name}] 已注册");
        } catch (\Throwable $e) {
            Log::error("[ModuleBootstrapper] 模块 [{$name}] 注册失败", [
                'error' => $e->getMessage(),
                'provider' => $providerClass,
            ]);
        }
    }

    /**
     * 启动单个模块 (boot 阶段)
     */
    protected function bootModule(string $name, $provider): void
    {
        if (! $provider instanceof ModuleServiceProvider) {
            // 非标准模块 Provider, Laravel 已自动 boot
            $this->booted[$name] = $provider;

            return;
        }

        try {
            // ModuleServiceProvider 的 boot() 在 app->register() 时已被 Laravel 调用
            // 这里记录状态即可
            $this->booted[$name] = $provider;

            Log::debug("[ModuleBootstrapper] 模块 [{$name}] 已启动");
        } catch (\Throwable $e) {
            Log::error("[ModuleBootstrapper] 模块 [{$name}] 启动失败", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取已注册的模块 Provider
     *
     * @return ModuleServiceProvider[]
     */
    public function getRegistered(): array
    {
        return $this->registered;
    }

    /**
     * 获取已启动的模块 Provider
     *
     * @return ModuleServiceProvider[]
     */
    public function getBooted(): array
    {
        return $this->booted;
    }

    /**
     * 检查模块是否已注册
     */
    public function isRegistered(string $name): bool
    {
        return isset($this->registered[$name]);
    }

    /**
     * 检查模块是否已启动
     */
    public function isBooted(string $name): bool
    {
        return isset($this->booted[$name]);
    }

    /**
     * 获取指定模块的 Provider 实例
     */
    public function getProvider(string $name): ?ModuleServiceProvider
    {
        return $this->registered[$name] ?? null;
    }
}
