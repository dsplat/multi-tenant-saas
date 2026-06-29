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

    // IP 白名单配置
    'ip_whitelist' => [
        // 是否启用中间件拦截
        'enabled' => (bool) env('IP_WHITELIST_ENABLED', true),
        // 默认生效范围：all / api / admin
        'default_scope' => env('IP_WHITELIST_DEFAULT_SCOPE', 'all'),
        // 默认信任设备天数
        'trusted_device_days' => (int) env('TRUSTED_DEVICE_DAYS', 30),
    ],

    // GDPR 合规配置
    'gdpr' => [
        // 当前条款版本
        'terms_version' => env('GDPR_TERMS_VERSION', '1.0'),
        // 数据擦除时使用的匿名化邮箱后缀
        'erasure_email_domain' => env('GDPR_ERASURE_EMAIL_DOMAIN', 'deleted.local'),
        // 数据导出包含的数据类型
        'export_types' => [
            'user',
            'tenants',
            'sessions',
            'api_tokens',
            'oauth_accounts',
            'mfa_devices',
            'trusted_devices',
            'password_histories',
            'consents',
            'audit_logs',
            'ai_requests',
            'credit_transactions',
            'file_uploads',
        ],
        // 清理前通知天数
        'cleanup_notice_days' => (int) env('GDPR_CLEANUP_NOTICE_DAYS', 7),
    ],

    // 数据保留策略默认配置
    'retention' => [
        // 默认保留天数
        'default_retention_days' => (int) env('RETENTION_DEFAULT_DAYS', 365),
        // 默认是否自动清理
        'auto_cleanup' => (bool) env('RETENTION_AUTO_CLEANUP', true),
        // 默认清理策略：delete / anonymize
        'cleanup_strategy' => env('RETENTION_CLEANUP_STRATEGY', 'anonymize'),
        // 系统级默认策略（按数据类型）
        'default_policies' => [
            'user_sessions' => ['days' => 90, 'strategy' => 'delete'],
            'audit_logs' => ['days' => 365, 'strategy' => 'anonymize'],
            'ai_requests' => ['days' => 180, 'strategy' => 'anonymize'],
            'password_histories' => ['days' => 365, 'strategy' => 'delete'],
            'structured_logs' => ['days' => 180, 'strategy' => 'anonymize'],
            'consents' => ['days' => 1095, 'strategy' => 'anonymize'],
        ],
    ],

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
