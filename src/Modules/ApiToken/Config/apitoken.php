<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Token 模块配置
    |--------------------------------------------------------------------------
    | 对接 new-api（apisvr）后端，为租户用户提供 API 能力接入
    | 包括 Token 创建/查询/禁用/轮换、Quota 充值与同步、模型白名单管理
    */

    // new-api 后端地址
    'base_url' => env('APITOKEN_BASE_URL', 'https://api.example.com'),

    // 管理员密钥（Admin Authorization Key）
    'admin_key' => env('APITOKEN_ADMIN_KEY', ''),

    // 管理员用户 ID（New-Api-User Header）
    'admin_user_id' => (int) env('APITOKEN_ADMIN_USER_ID', 1),

    // HTTP 超时（秒）
    'timeout' => (int) env('APITOKEN_HTTP_TIMEOUT', 15),

    // 默认 Token 过期时间（-1 = 永不过期）
    'default_expired_time' => (int) env('APITOKEN_DEFAULT_EXPIRED_TIME', -1),

    // 默认 Token 分组
    'default_group' => env('APITOKEN_DEFAULT_GROUP', 'default'),

    // 是否启用模块
    'enabled' => (bool) env('APITOKEN_ENABLED', false),
];
