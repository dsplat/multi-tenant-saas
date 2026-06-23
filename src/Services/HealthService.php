<?php

namespace MultiTenantSaas\Services;

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
 * 健康检查服务
 *
 * 集成 spatie/laravel-health
 *
 * 访问：GET /up (Laravel 内置)
 * 详情：php artisan health:list
 */
class HealthService
{
    /**
     * 注册默认健康检查
     */
    public static function registerChecks(): void
    {
        if (!class_exists(\Spatie\Health\Facades\Health::class)) {
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
    public static function registerHorizonCheck(): void
    {
        if (class_exists(\Laravel\Horizon\HorizonServiceProvider::class)) {
            Health::checks([
                HorizonCheck::new(),
            ]);
        }
    }

    /**
     * 获取健康状态
     */
    public static function getStatus(): array
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
