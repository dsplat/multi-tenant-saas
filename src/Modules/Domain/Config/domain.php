<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 平台域名配置
    |--------------------------------------------------------------------------
    */
    'platform_domains' => [
        'admin' => env('PLATFORM_ADMIN_DOMAIN', 'admin.example.com'),
        'app' => env('PLATFORM_APP_DOMAIN', 'app.example.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 个人用户子域名通配基础域名
    |--------------------------------------------------------------------------
    |
    | 平台开放给个人用户的子域名基础，如 *.scrm.com。
    | 匹配该模式的域名将兜底到默认租户。
    |
    */
    'wildcard_base' => env('PLATFORM_WILDCARD_BASE', 'scrm.com'),

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
    | 域名黑名单（保留域名）
    |--------------------------------------------------------------------------
    |
    | 以下域名禁止被任何租户绑定为 custom_domain。
    | 包括平台主域名、管理后台域名、API 域名等。
    | 初始化时自动从 .env 读取并填充。
    |
    */
    'reserved_domains' => array_filter([
        env('PLATFORM_MAIN_DOMAIN'),
        env('PLATFORM_ADMIN_DOMAIN'),
        env('PLATFORM_APP_DOMAIN'),
        env('PLATFORM_API_DOMAIN'),
    ]),

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
