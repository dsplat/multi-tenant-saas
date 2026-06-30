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

    /*
    |--------------------------------------------------------------------------
    | SLA 监控配置
    |--------------------------------------------------------------------------
    |
    | 多级 SLA 目标值（按月/季/年统计）：
    |  - standard:    99.9%  允许月度不可用约 43.2 分钟
    |  - premium:     99.95% 允许月度不可用约 21.6 分钟
    |  - enterprise:  99.99% 允许月度不可用约 4.3 分钟
    |
    */

    'sla' => [
        'enabled' => (bool) env('SLA_ENABLED', true),
        'default_level' => env('SLA_DEFAULT_LEVEL', 'standard'),

        'levels' => [
            'standard' => 99.9,
            'premium' => 99.95,
            'enterprise' => 99.99,
        ],

        // 检查周期：monthly / quarterly / yearly
        'check_period' => 'monthly',

        // 触发违约告警的最低严重级别
        'alert_min_severity' => 'critical',
    ],
];
