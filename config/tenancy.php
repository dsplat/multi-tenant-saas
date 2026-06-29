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

    // 成本追踪配置（TASK-024）
    'cost_tracking' => [
        // 默认币种
        'default_currency' => env('COST_DEFAULT_CURRENCY', 'CNY'),
        // 分摊周期（monthly/weekly/daily）
        'period' => 'monthly',
        // 趋势预测月数
        'forecast_months' => (int) env('COST_FORECAST_MONTHS', 3),
        // 历史数据回溯月数（用于趋势预测）
        'history_months' => (int) env('COST_HISTORY_MONTHS', 6),
        // 基础设施成本分摊依据
        'infrastructure_basis' => [
            'compute' => 'by_users',
            'storage' => 'by_storage',
            'bandwidth' => 'by_requests',
        ],
    ],

    // 资源监控配置（TASK-024）
    'resource_monitoring' => [
        // 数据库连接数告警阈值
        'db_connections_threshold' => (int) env('RESOURCE_DB_CONN_THRESHOLD', 100),
        // 队列积压告警阈值（任务数）
        'queue_backlog_threshold' => (int) env('RESOURCE_QUEUE_THRESHOLD', 1000),
        // 缓存命中率告警阈值（百分比）
        'cache_hit_rate_threshold' => (float) env('CACHE_HIT_RATE_THRESHOLD', 80.0),
        // 存储用量告警阈值（MB）
        'storage_usage_threshold_mb' => (int) env('STORAGE_USAGE_THRESHOLD_MB', 10240),
    ],

    // 错误追踪配置（TASK-025）
    'error_tracking' => [
        // Sentry 集成开关（需 composer require sentry/sentry-laravel）
        'sentry' => [
            'enabled' => (bool) env('SENTRY_ENABLED', false),
            'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),
            // 采样率（0~1）
            'sample_rate' => (float) env('SENTRY_SAMPLE_RATE', 1.0),
            // 上下文环境
            'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),
        ],
        // 错误聚合默认时间窗口（小时）
        'aggregation_window_hours' => (int) env('ERROR_AGGREGATION_WINDOW_HOURS', 24),
        // 错误趋势默认粒度（day / hour）
        'default_granularity' => env('ERROR_TREND_GRANULARITY', 'day'),
        // 单次聚合最大错误类型数
        'max_error_groups' => (int) env('ERROR_MAX_GROUPS', 100),
    ],

    // 自定义报表配置（TASK-025）
    'reports' => [
        // 默认导出格式
        'default_format' => env('REPORT_DEFAULT_FORMAT', 'csv'),
        // PDF 视图模板
        'pdf_view' => env('REPORT_PDF_VIEW', 'pdf.report'),
        // 定时发送队列
        'queue' => env('REPORT_QUEUE', 'default'),
        // 每日发送时刻（H:i）
        'daily_send_time' => env('REPORT_DAILY_SEND_TIME', '08:00'),
        // 报表模板预置
        'templates' => [
            'errors_summary' => [
                'metrics_config' => ['metrics' => ['errors'], 'aggregation' => 'count'],
                'dimensions' => ['by_day', 'by_tenant'],
                'format' => 'csv',
                'description' => '错误汇总报表',
            ],
            'cost_overview' => [
                'metrics_config' => ['metrics' => ['costs', 'ai_requests'], 'aggregation' => 'sum'],
                'dimensions' => ['by_day'],
                'format' => 'excel',
                'description' => '成本与 AI 用量概览',
            ],
            'operations_daily' => [
                'metrics_config' => ['metrics' => ['errors', 'alerts'], 'aggregation' => 'count'],
                'dimensions' => ['by_day'],
                'format' => 'pdf',
                'description' => '运营日报',
            ],
        ],
    ],

    // 数据库隔离配置（TASK-027）
    'isolation' => [
        // 默认隔离策略：shared（共享数据库+行级隔离）/ database（独立数据库）/ schema（独立 Schema，仅 PostgreSQL）
        'default' => env('TENANCY_ISOLATION_DEFAULT', 'shared'),
        // 租户连接名前缀（database/schema 策略动态注册的连接名 = 前缀 + tenant_id）
        'connection_prefix' => env('TENANCY_ISOLATION_CONNECTION_PREFIX', 'tenant.'),
        // 基础连接名（租户连接继承该连接配置，仅覆盖 database/search_path）
        'base_connection' => env('TENANCY_ISOLATION_BASE_CONNECTION', env('DB_CONNECTION', 'sqlite')),
        // 管理员连接名（用于 CREATE/DROP DATABASE，需 DBA 权限；留空则使用基础连接）
        'admin_connection' => env('TENANCY_ISOLATION_ADMIN_CONNECTION'),
        // 是否在 setupDatabase 后自动运行迁移（设为 false 可由运维手动管理 schema）
        'run_migrations' => (bool) env('TENANCY_ISOLATION_RUN_MIGRATIONS', true),
        // 迁移文件路径（database 策略在新库上执行的迁移目录）
        'migrations_path' => env('TENANCY_ISOLATION_MIGRATIONS_PATH', database_path('migrations')),
        // 租户数据表清单（迁移工具导出/导入时遍历的租户表，需运维按项目实际填入）
        'tenant_tables' => [],
        // 独立数据库名命名模板（{:id} 替换为租户 ID）
        'database_name_template' => env('TENANCY_ISOLATION_DB_NAME_TEMPLATE', 'tenant_{:id}'),
        // 独立 Schema 名命名模板（{:id} 替换为租户 ID）
        'schema_name_template' => env('TENANCY_ISOLATION_SCHEMA_NAME_TEMPLATE', 'tenant_{:id}'),
    ],
];
