<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 健康检查配置
    |--------------------------------------------------------------------------
    */

    'checks' => [
        // 磁盘空间检查
        'disk_space' => [
            'enabled' => true,
            'threshold' => 80, // 百分比
        ],

        // 数据库检查
        'database' => [
            'enabled' => true,
            'connection' => env('DB_CONNECTION', 'mysql'),
        ],

        // Redis 检查
        'redis' => [
            'enabled' => true,
        ],

        // 队列检查
        'queue' => [
            'enabled' => true,
            'connection' => env('QUEUE_CONNECTION', 'database'),
        ],

        // 缓存检查
        'cache' => [
            'enabled' => true,
        ],

        // 调度器检查
        'schedule' => [
            'enabled' => true,
        ],

        // 环境检查
        'environment' => [
            'enabled' => true,
            'expected' => env('APP_ENV', 'production'),
        ],

        // 调试模式检查
        'debug_mode' => [
            'enabled' => true,
            'expected' => false,
        ],

        // 应用优化检查
        'optimized_app' => [
            'enabled' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 通知配置
    |--------------------------------------------------------------------------
    */

    'notifications' => [
        'enabled' => false,
        'slack_webhook_url' => env('HEALTH_SLACK_WEBHOOK_URL', ''),
        'mail_to' => env('HEALTH_MAIL_TO', ''),
    ],
];
