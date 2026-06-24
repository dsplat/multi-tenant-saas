<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Global ID Generator Configuration
    |--------------------------------------------------------------------------
    |
    | 全局随机ID生成器配置
    |
    | - ID长度: 16位数字
    | - JS安全: <= Number.MAX_SAFE_INTEGER (9007199254740991)
    | - 全局唯一: 所有表共用ID空间
    | - 完全无序: 无法推测业务增长
    |
    */

    // ID配置
    'id_length' => 16,

    // JS安全范围（Number.MAX_SAFE_INTEGER）
    'js_max_safe_integer' => 9007199254740991,

    // ID最小值（16位数字下限）
    'min_value' => 1000000000000000,

    // ID最大值（JS安全上限）
    'max_value' => 9007199254740991,

    /*
    |--------------------------------------------------------------------------
    | 平台新用户欢迎积分
    |--------------------------------------------------------------------------
    |
    | 用户首次注册平台（邮箱、手机、微信 OAuth）时自动赠送的积分数量。
    | 设置为 0 可关闭此功能。
    |
    */
    'platform_welcome_credits' => env('PLATFORM_WELCOME_CREDITS', 500),

    /*
    |--------------------------------------------------------------------------
    | 平台默认租户 ID
    |--------------------------------------------------------------------------
    |
    | 公共平台用户（不属于任何企业）统一归入平台默认租户。
    | 这个值是固定的，由 PlatformTenantSeeder 写入数据库。
    | IdentifyTenant 中间件无法识别租户时自动注入此 ID。
    |
    */
    'platform_tenant_id' => env('PLATFORM_TENANT_ID', 9007199254740991),

    /*
    |--------------------------------------------------------------------------
    | Guest 用户 ID
    |--------------------------------------------------------------------------
    |
    | 未登录访客通过 X-Session-Token 访问时，统一映射到此 guest 用户。
    | 由 GuestUserSeeder 写入数据库，不可通过登录接口认证。
    |
    */
    'guest_user_id' => (int) env('GUEST_USER_ID', 1),

    /*
    |--------------------------------------------------------------------------
    | Guest 会话请求限制
    |--------------------------------------------------------------------------
    |
    | guest_invoke_max_requests: 单次调用模式（invoke）每个 session 最大请求数，0=不限制
    | guest_conversation_max_requests: 对话模式（chat）每个 session 最大请求数，0=不限制
    |
    */
    'guest_invoke_max_requests' => (int) env('GUEST_INVOKE_MAX_REQUESTS', 10),
    'guest_conversation_max_requests' => (int) env('GUEST_CONVERSATION_MAX_REQUESTS', 0),

    /*
    |--------------------------------------------------------------------------
    | Guest IP 防刷限制
    |--------------------------------------------------------------------------
    |
    | guest_rate_limit_per_ip: 单 IP 每小时最大 guest 请求总数
    | guest_max_sessions_per_ip: 单 IP 每小时最多使用的不同 session_token 数
    |
    */
    'guest_rate_limit_per_ip' => (int) env('GUEST_RATE_LIMIT_PER_IP', 30),
    'guest_max_sessions_per_ip' => (int) env('GUEST_MAX_SESSION_PER_IP', 5),
];
