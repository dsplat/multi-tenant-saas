<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 平台域名配置
    |--------------------------------------------------------------------------
    */
    'platform_domains' => [
        // 平台首页域名（www）
        'main' => env('PLATFORM_MAIN_DOMAIN'),
        'admin' => env('PLATFORM_ADMIN_DOMAIN', 'admin.example.com'),
        'console' => env('PLATFORM_CONSOLE_DOMAIN', 'console.example.com'),
        // 独立 API 域名（可选；未配置时 API 随各 SPA 域名提供）
        'api' => env('PLATFORM_API_DOMAIN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 租户通配子域名基础域名
    |--------------------------------------------------------------------------
    |
    | 平台为 OPC（一人公司）等无独立域名的租户提供公共子域名访问：
    |   {slug}.dsplat.com → 通过 tenants.slug 定位到具体租户
    |
    | 需配合：
    |   1. DNS: *.dsplat.com A 记录指向服务器
    |   2. SSL: *.dsplat.com 通配证书
    |   3. Nginx: server_name *.dsplat.com
    |
    | 设为 null 则禁用子域名解析。
    |
    */
    'wildcard_base' => env('PLATFORM_WILDCARD_BASE', 'dsplat.com'),

    /*
    |--------------------------------------------------------------------------
    | 备案检查开关
    |--------------------------------------------------------------------------
    */
    'icp_check_enabled' => env('DOMAIN_ICP_CHECK_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | 域名白名单路径
    |--------------------------------------------------------------------------
    */
    'nginx_map_file' => env('DOMAIN_NGINX_MAP_FILE', '/etc/nginx/conf.d/allowed-domains.map'),

    /*
    |--------------------------------------------------------------------------
    | SSL证书路径
    |--------------------------------------------------------------------------
    */
    'ssl_certs_path' => env('DOMAIN_SSL_CERTS_PATH', '/etc/nginx/ssl'),

    /*
    |--------------------------------------------------------------------------
    | SSL Nginx Map文件路径
    |--------------------------------------------------------------------------
    */
    'ssl_nginx_map_file' => env('DOMAIN_SSL_NGINX_MAP_FILE', '/etc/nginx/conf.d/ssl-map.conf'),

    /*
    |--------------------------------------------------------------------------
    | Nginx 域名接入层发布目录
    |--------------------------------------------------------------------------
    |
    | 租户域名接入层的全部 nginx 产物（白名单 map / SNI 证书 map / 基桩 server /
    | 域名软链接）统一落在「系统发布目录」下，系统 nginx 仅需 include 一次顶层
    | 文件 dsplat-tenants.conf 即可。默认 base_path('deploy/nginx')。
    |
    */
    'nginx_deploy_path' => env('NGINX_DEPLOY_PATH'),

    // 基桩监听形态：https = 443 直连（启用 ssl.map/SNI）；http = 80 层（SLB 已卸载 SSL）
    'nginx_listen_mode' => env('NGINX_LISTEN_MODE', 'https'),

    // 基桩 server 的 fastcgi 上游（如 unix:/run/php-fpm.sock）；未配置时用 nginx_fastcgi_port 拼 TCP 地址
    'nginx_fastcgi_pass' => env('NGINX_FASTCGI_PASS'),

    // 基桩 server 直连的 php-fpm 端口（废除 9100 nginx 代理层）
    'nginx_fastcgi_port' => (int) env('NGINX_FASTCGI_PORT', 9001),

    // 基桩 server 的 webroot（默认 public_path()）
    'nginx_public_path' => env('NGINX_PUBLIC_PATH'),

    // nginx 二进制路径（--reload 时用；生产常为 /usr/local/nginx/sbin/nginx）
    'nginx_binary' => env('NGINX_BINARY', 'nginx'),

    // 基桩 access/error 日志目录
    'nginx_log_dir' => env('NGINX_LOG_DIR', '/home/wwwlogs'),

    /*
    |--------------------------------------------------------------------------
    | 域名黑名单（保留域名）
    |--------------------------------------------------------------------------
    |
    | 以下域名禁止被任何租户绑定为 domain。
    | 包括平台主域名、管理后台域名、API 域名等。
    | 初始化时自动从 .env 读取并填充。
    |
    */
    'reserved_domains' => array_filter([
        env('PLATFORM_MAIN_DOMAIN'),
        env('PLATFORM_ADMIN_DOMAIN'),
        env('PLATFORM_CONSOLE_DOMAIN'),
        env('PLATFORM_API_DOMAIN'),
    ]),

    /*
    |--------------------------------------------------------------------------
    | Slug 治理配置
    |--------------------------------------------------------------------------
    |
    | 租户 slug 用于二级域名（{slug}.<wildcard_base>）。
    | 三层防护：黑名单硬拒 → AI 风险评估 → 后台打回。
    |
    */
    'reserved_slugs' => array_merge([
        // 系统保留
        'api', 'admin', 'console', 'app', 'login', 'register', 'auth',
        'assets', 'static', 'public', 'cdn', 'mail', 'www', 'webmail',
        'localhost', 'test', 'demo', 'staging', 'dev',
        // 通用高风险
        'official', 'support', 'help', 'service', 'system', 'root',
        'administrator', 'webmaster', 'postmaster', 'abuse', 'security',
    ], array_filter([
        // 品牌保护（从 .env 注入，框架不硬编码）
        env('PLATFORM_BRAND_SLUG'),
    ])),

    // Slug 最小长度
    'slug_min_length' => (int) env('SLUG_MIN_LENGTH', 3),

    // Slug 合法字符正则（小写字母、数字、连字符，不以连字符开头/结尾）
    'slug_pattern' => '/^[a-z0-9]([a-z0-9\-]{1,61}[a-z0-9])?$/',

    /*
    |--------------------------------------------------------------------------
    | 自动子域名（t-xxxxxx 免费兜底）配置
    |--------------------------------------------------------------------------
    |
    | 创建租户时若未指定 slug，自动生成 t-<随机码> 写入 slug 字段，作为
    | 免费兜底二级域名（{slug}.<wildcard_base>）。与用户自定义 slug 共用
    | 同一字段、走完全一致的二级域名链路；用户后续自行设置 slug 后即覆盖失效。
    |
    */
    // 自动码随机部分长度（不含 t- 前缀）
    'auto_slug_length' => (int) env('AUTO_SLUG_LENGTH', 6),

    // 自动码字符集（剔除易混字符 0/o/1/l/i）
    'auto_slug_alphabet' => env('AUTO_SLUG_ALPHABET', 'abcdefghjkmnpqrstuvwxyz23456789'),

    /*
    |--------------------------------------------------------------------------
    | SEO/GEO 隔离 map 文件路径
    |--------------------------------------------------------------------------
    |
    | seo.map：map $host $seo_allowed（平台域名/自定义域名=1，子域名含 t- =0）
    | bot.map：map $http_user_agent $is_ai_bot（AI 爬虫 UA 拦截，GEO 防护）
    |
    */
    'seo_map_file' => env('DOMAIN_SEO_MAP_FILE', '/etc/nginx/conf.d/seo.map'),
    'bot_map_file' => env('DOMAIN_BOT_MAP_FILE', '/etc/nginx/conf.d/bot.map'),

    /*
    |--------------------------------------------------------------------------
    | 域名归属文件验证（Domain Ownership Verification）
    |--------------------------------------------------------------------------
    |
    | 租户绑定自定义域名时，需在域名根目录放置验证文件：
    |   https://{domain}/.well-known/tenant-verify/{token}.txt
    | 文件内容为平台生成的 token 字符串。
    |
    */
    'verification' => [
        // 验证文件路径前缀
        'path_prefix' => env('DOMAIN_VERIFY_PATH_PREFIX', '.well-known/tenant-verify'),
        // token 长度
        'token_length' => (int) env('DOMAIN_VERIFY_TOKEN_LENGTH', 32),
        // HTTP 检查超时（秒）
        'http_timeout' => (int) env('DOMAIN_VERIFY_HTTP_TIMEOUT', 10),
        // 最大验证尝试次数（超过后需重新生成 token）
        'max_attempts' => (int) env('DOMAIN_VERIFY_MAX_ATTEMPTS', 5),
    ],
];
