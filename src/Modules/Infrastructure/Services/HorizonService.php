<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Laravel\Horizon\Horizon;

/**
 * 队列监控服务（DI 实例方法）。
 *
 * 集成 laravel/horizon
 *
 * 向后兼容：保留 __callStatic 代理，新代码应通过构造器注入使用。
 */
class HorizonService
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
     * 获取 Horizon 状态
     */
    public function getStatus(): array
    {
        if (! class_exists(Horizon::class)) {
            return ['status' => 'not_installed'];
        }

        return [
            'status' => 'installed',
            'version' => Horizon::version(),
        ];
    }

    /**
     * 获取队列统计
     */
    public function getStats(): array
    {
        if (! class_exists(Horizon::class)) {
            return [];
        }

        $stats = Horizon::stats();

        return [
            'jobs_per_minute' => $stats->jobsPerMinute ?? 0,
            'recent_jobs' => $stats->recentJobs ?? 0,
            'recently_failed' => $stats->recentlyFailed ?? 0,
            'max_wait_time' => $stats->maxWaitTime ?? 0,
            'max_runtime' => $stats->maxRuntime ?? 0,
            'max_throughput' => $stats->maxThroughput ?? 0,
        ];
    }
}
