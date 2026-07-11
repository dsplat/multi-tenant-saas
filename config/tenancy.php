<?php

return [
    'default_tenant_id' => null,

    // 框架核心版本 (供模块 requires_core 校验)
    'core_version' => env('TENANCY_CORE_VERSION', '1.0.0'),

    // 部署模式: saas (完整多租户) | standalone (独立部署, 等同于关闭注册的单租户)
    'deployment_mode' => env('DEPLOYMENT_MODE', 'saas'),

    // SaaS 注册开关 (standalone 模式下自动关闭)
    'saas_registration' => (bool) env('SAAS_REGISTRATION', true),

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

    // Webhook 系统配置
    'webhooks' => [
        // 最大重试次数（指数退避：10s, 30s, 60s, 120s, 300s）
        'max_retries' => (int) env('WEBHOOK_MAX_RETRIES', 5),
        // HTTP 请求超时（秒）
        'timeout' => (int) env('WEBHOOK_TIMEOUT', 30),
        // 签名头部名称
        'signature_header' => env('WEBHOOK_SIGNATURE_HEADER', 'X-Webhook-Signature'),
        // 投递队列名称
        'queue' => env('WEBHOOK_QUEUE', 'default'),
    ],

    // 事件总线配置
    'event_bus' => [
        // 异步分发队列名称
        'queue' => env('EVENT_BUS_QUEUE', 'default'),
        // 事件分发最大重试次数（指数退避：5s, 15s, 30s）
        'max_retries' => (int) env('EVENT_BUS_MAX_RETRIES', 3),
        // 外部订阅 Webhook 投递超时（秒）
        'timeout' => (int) env('EVENT_BUS_TIMEOUT', 30),
    ],

    // 功能开关配置
    'feature_flags' => [
        // 缓存 TTL（秒）
        'cache_ttl' => (int) env('FEATURE_FLAG_CACHE_TTL', 300),
        // 是否在服务启动时自动播种预置开关
        'auto_seed' => (bool) env('FEATURE_FLAG_AUTO_SEED', false),
        // 预置开关定义
        'presets' => [
            ['name' => 'ai_text', 'description' => 'AI 文本生成', 'scope' => 'global', 'status' => 'active', 'rollout_percentage' => 100],
            ['name' => 'ai_image', 'description' => 'AI 图像生成', 'scope' => 'global', 'status' => 'active', 'rollout_percentage' => 100],
            ['name' => 'ai_video', 'description' => 'AI 视频生成', 'scope' => 'global', 'status' => 'active', 'rollout_percentage' => 100],
            ['name' => 'beta_features', 'description' => 'Beta 功能集合', 'scope' => 'tenant', 'status' => 'inactive', 'rollout_percentage' => 0],
            ['name' => 'new_dashboard', 'description' => '新版控制台', 'scope' => 'tenant', 'status' => 'inactive', 'rollout_percentage' => 0],
            // SCRM 模块功能开关
            ['name' => 'scrm_customers', 'description' => 'SCRM 客户管理', 'scope' => 'tenant', 'status' => 'active', 'rollout_percentage' => 100],
            ['name' => 'scrm_agents', 'description' => 'SCRM AI Agent', 'scope' => 'tenant', 'status' => 'active', 'rollout_percentage' => 100],
            ['name' => 'scrm_automation', 'description' => 'SCRM 自动化规则', 'scope' => 'tenant', 'status' => 'inactive', 'rollout_percentage' => 0],
            ['name' => 'scrm_channels', 'description' => 'SCRM 渠道管理', 'scope' => 'tenant', 'status' => 'active', 'rollout_percentage' => 100],
            ['name' => 'scrm_communities', 'description' => 'SCRM 社群管理', 'scope' => 'tenant', 'status' => 'active', 'rollout_percentage' => 100],
            ['name' => 'scrm_live_codes', 'description' => 'SCRM 活码管理', 'scope' => 'tenant', 'status' => 'active', 'rollout_percentage' => 100],
            ['name' => 'scrm_knowledge', 'description' => 'SCRM 知识库', 'scope' => 'tenant', 'status' => 'active', 'rollout_percentage' => 100],
            ['name' => 'scrm_campaigns', 'description' => 'SCRM 营销活动', 'scope' => 'tenant', 'status' => 'inactive', 'rollout_percentage' => 0],
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

    // 租户模块默认开通配置 (tenant_toggleable 模块)
    // key = 模块名 (composer.json extra.saas 的 name), value = 新租户默认是否开通
    'tenant_module_defaults' => [
        'domain' => true,
        'ssl' => false,
        'payment' => false,
        'api-token' => false,
        'coupon' => true,
        'form' => true,
        'lottery' => true,
        'sms' => true,
        'voting' => true,
    ],

    // 按套餐差异化模块开通 (覆盖 tenant_module_defaults)
    'plan_modules' => [
        'free' => [
            'coupon' => false,
            'lottery' => false,
            'sms' => false,
        ],
        'basic' => [
            'coupon' => true,
            'lottery' => false,
            'sms' => true,
        ],
        'pro' => [
            'coupon' => true,
            'lottery' => true,
            'sms' => true,
        ],
        'enterprise' => [
            'coupon' => true,
            'lottery' => true,
            'sms' => true,
            'payment' => true,
            'api-token' => true,
            'ssl' => true,
            'domain' => true,
        ],
    ],

    // 邮件模板配置
    'mail_templates' => [
        'default_from_address' => env('MAIL_FROM_ADDRESS', 'noreply@example.com'),
        'default_from_name' => env('MAIL_FROM_NAME', 'Tenant SaaS'),
        'cache_ttl' => 3600,
    ],

    // 品牌配置
    'branding' => [
        'default_primary_color' => env('BRANDING_PRIMARY_COLOR', '#1890ff'),
        'default_secondary_color' => env('BRANDING_SECONDARY_COLOR', '#666666'),
        'default_login_page_style' => env('BRANDING_LOGIN_STYLE', 'default'),
        'default_email_template' => env('BRANDING_EMAIL_TEMPLATE', 'default'),
        'custom_domain_enabled' => (bool) env('BRANDING_CUSTOM_DOMAIN_ENABLED', true),
        'logo_mime_types' => ['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'],
        'logo_max_size' => (int) env('BRANDING_LOGO_MAX_SIZE', 2097152),
    ],

    // 数据驻留配置
    'residency' => [
        // 默认区域
        'default_region' => env('RESIDENCY_DEFAULT_REGION', 'CN'),
        // 合规强制开关
        'compliance_enforced' => (bool) env('RESIDENCY_COMPLIANCE_ENFORCED', true),
        // 跨区域迁移开关
        'cross_region_migration_enabled' => (bool) env('RESIDENCY_CROSS_REGION_MIGRATION', true),
        // 驻留配置 TenantSetting group 名
        'settings_group' => env('RESIDENCY_SETTINGS_GROUP', 'residency'),
        // 可用区域
        'regions' => [
            'CN' => ['name' => '中国大陆', 'storage_disk' => 'local'],
            'US' => ['name' => '美国', 'storage_disk' => 's3-us'],
            'EU' => ['name' => '欧盟', 'storage_disk' => 's3-eu'],
            'APAC' => ['name' => '亚太', 'storage_disk' => 's3-apac'],
        ],
        // 套餐允许的区域
        'plan_allowed_regions' => [
            'free' => ['CN'],
            'basic' => ['CN', 'US'],
            'pro' => ['CN', 'US', 'EU', 'APAC'],
            'enterprise' => ['CN', 'US', 'EU', 'APAC'],
        ],
    ],

    // 租户克隆配置
    'clone' => [
        // 排除的设置组（敏感信息不复制）
        'excluded_setting_groups' => ['secrets'],
    ],

    // 报表模板配置
    'reports' => [
        'templates' => [
            'errors_summary' => [
                'metrics_config' => ['error_count', 'error_rate'],
                'dimensions' => ['date', 'error_type'],
                'format' => 'csv',
            ],
            'usage_summary' => [
                'metrics_config' => ['api_calls', 'tokens_used'],
                'dimensions' => ['date', 'tenant_id'],
                'format' => 'csv',
            ],
        ],
    ],

    // 定时任务开关 (key = 任务名, value = true/false)
    'scheduler' => [
        'subscriptions' => true,
        'credits' => true,
        'retention' => true,
        'sms-batch' => true,
        'reports' => true,
        'memory-cleanup' => true,
        'memory-decay' => true,
    ],

    // Packagist 发布配置 (用于 module:create --publish)
    'packagist' => [
        'user' => env('PACKAGIST_USER', ''),
        'token' => env('PACKAGIST_TOKEN', ''),
    ],
];
