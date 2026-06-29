<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | 默认缓存存储
    |--------------------------------------------------------------------------
    |
    | 生产环境默认使用 Redis（高吞吐、低延迟）；本地/测试环境通过 env 降级为
    | array 或 database 驱动。CacheService 在 Redis 不可用时会自动降级。
    |
    */

    'default' => env('CACHE_STORE', env('CACHE_DRIVER', 'database')),

    /*
    |--------------------------------------------------------------------------
    | 缓存存储
    |--------------------------------------------------------------------------
    |
    | stores 定义了所有可用驱动。Redis 为生产主存储，database 为备选，
    | array 用于单元测试（无副作用）。
    |
    */

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'connection' => env('DB_CACHE_CONNECTION', env('DB_CONNECTION', 'sqlite')),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION', env('DB_CONNECTION', 'sqlite')),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('CACHE_REDIS_CONNECTION', 'cache'),
            'lock_connection' => env('CACHE_REDIS_LOCK_CONNECTION', 'cache'),
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data/locks'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | 缓存 Key 前缀
    |--------------------------------------------------------------------------
    |
    | 全局前缀避免多实例共享 Redis 时 Key 冲突。
    |
    */

    'prefix' => env('CACHE_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_cache_'),

    /*
    |--------------------------------------------------------------------------
    | TTL 策略（按数据热度分级）
    |--------------------------------------------------------------------------
    |
    | CacheService::getTtlConfig() 读取此配置，对热数据采用较短 TTL 以保证一致性，
    | 冷数据采用较长 TTL 以提升命中率。
    |
    */

    'ttl' => [
        'user_profile' => (int) env('CACHE_TTL_USER_PROFILE', 1800),     // 30 分钟
        'tenant_config' => (int) env('CACHE_TTL_TENANT_CONFIG', 3600),   // 1 小时
        'permissions' => (int) env('CACHE_TTL_PERMISSIONS', 7200),       // 2 小时
        'subscription_plan' => (int) env('CACHE_TTL_PLAN', 1800),        // 30 分钟
        'api_response' => (int) env('CACHE_TTL_API_RESPONSE', 60),       // 1 分钟
        'metrics' => (int) env('CACHE_TTL_METRICS', 300),                // 5 分钟
        'default' => (int) env('CACHE_TTL_DEFAULT', 3600),               // 1 小时
    ],

    /*
    |--------------------------------------------------------------------------
    | 热数据预热
    |--------------------------------------------------------------------------
    |
    | 启动时预加载高频访问的配置（订阅计划、系统设置、权限定义）。
    | 由 CacheService::warmup() 触发。
    |
    */

    'warmup' => [
        'enabled' => (bool) env('CACHE_WARMUP_ENABLED', true),
        'keys' => [
            'subscription_plans',
            'system_settings',
            'feature_flags',
        ],
    ],

];
