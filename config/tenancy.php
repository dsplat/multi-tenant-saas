<?php

return [
    'default_tenant_id' => null,
    
    'admin_domain' => env('ADMIN_DOMAIN', 'admin.example.com'),
    
    'platform_domains' => [
        'localhost',
        '127.0.0.1',
        env('ADMIN_DOMAIN', 'admin.example.com'),
    ],
    
    'cache' => [
        'prefix' => 'tenant:',
        'ttl' => 3600,
    ],

    // 全局ID生成器配置
    'id' => [
        'min_value' => (int) env('ID_GENERATOR_MIN', 1000000000000000),
        'max_value' => (int) env('ID_GENERATOR_MAX', 9007199254740991),
    ],

    // 文件存储配置
    'file_storage_disk' => env('FILE_STORAGE_DISK', 'local'),

    // 积分预警阈值
    'credit_warning_threshold' => (int) env('CREDIT_WARNING_THRESHOLD', 100),

    // 订阅计划配额限制
    'plans' => [
        'free' => [
            'limits' => [
                'max_users' => 5,
                'max_storage_mb' => 1024,
            ],
        ],
        'basic' => [
            'limits' => [
                'max_users' => 20,
                'max_storage_mb' => 10240,
            ],
        ],
        'pro' => [
            'limits' => [
                'max_users' => 100,
                'max_storage_mb' => 51200,
            ],
        ],
        'enterprise' => [
            'limits' => [
                'max_users' => PHP_INT_MAX,
                'max_storage_mb' => PHP_INT_MAX,
            ],
        ],
    ],
];
