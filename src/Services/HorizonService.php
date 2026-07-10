<?php

namespace MultiTenantSaas\Services;

use Laravel\Horizon\Horizon;

/**
 * 队列监控服务
 *
 * 集成 laravel/horizon
 *
 * 访问：/horizon (需要 super_admin 权限)
 * 命令：php artisan horizon
 */
class HorizonService
{
    /**
     * 获取 Horizon 状态
     */
    public static function getStatus(): array
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
    public static function getStats(): array
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
