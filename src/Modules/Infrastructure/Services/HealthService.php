<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Laravel\Horizon\HorizonServiceProvider;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\OptimizedAppCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

/**
 * 健康检查服务（DI 实例方法）。
 *
 * 集成 spatie/laravel-health
 *
 * 向后兼容：保留 __callStatic 代理，新代码应通过构造器注入使用。
 */
class HealthService
{
    /**
     * 向后兼容：静态调用代理到容器实例。
     *
     * @deprecated 请改用构造器注入
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return app(static::class)->{$method}(...$arguments);
    }

    /**
     * 注册默认健康检查
     */
    public function registerChecks(): void
    {
        if (! class_exists(Health::class)) {
            return;
        }

        Health::checks([
            CacheCheck::new(),
            DatabaseCheck::new(),
            DebugModeCheck::new(),
            EnvironmentCheck::new(),
            OptimizedAppCheck::new(),
            QueueCheck::new(),
            RedisCheck::new(),
            ScheduleCheck::new(),
            UsedDiskSpaceCheck::new(),
        ]);
    }

    /**
     * 注册 Horizon 检查（如果安装了 Horizon）
     */
    public function registerHorizonCheck(): void
    {
        if (class_exists(HorizonServiceProvider::class)) {
            Health::checks([
                HorizonCheck::new(),
            ]);
        }
    }

    /**
     * 获取健康状态
     */
    public function getStatus(): array
    {
        $result = Health::registeredChecks()->run();

        return [
            'status' => $result->isHealthy() ? 'healthy' : 'unhealthy',
            'checks' => array_map(function ($check) {
                return [
                    'name' => $check->getLabel(),
                    'status' => $check->status->value,
                    'message' => $check->getSummary(),
                ];
            }, $result->storedCheckResults->toArray()),
        ];
    }
}
