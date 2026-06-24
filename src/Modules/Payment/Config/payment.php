<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 支付模块配置
    |--------------------------------------------------------------------------
    | 独立支付网关对接（如馒头支付平台）
    | 框架核心支付能力由 PayService（yansongda/pay）提供
    | 本模块为可选的第三方支付网关适配层
    */

    // 支付网关地址
    'gateway' => env('PAYMENT_GATEWAY', 'https://pay.example.com'),

    // 服务 ID
    'service_id' => env('PAYMENT_SERVICE_ID', ''),

    // 服务密码
    'password' => env('PAYMENT_PASSWORD', ''),

    // 应用 ID
    'app_id' => env('PAYMENT_APP_ID', ''),

    // 异步通知地址
    'notify_url' => env('PAYMENT_NOTIFY_URL', ''),

    // 同步跳转地址
    'return_url' => env('PAYMENT_RETURN_URL', ''),

    // 展示地址
    'show_url' => env('PAYMENT_SHOW_URL', ''),

    // 是否启用模块
    'enabled' => (bool) env('PAYMENT_ENABLED', false),
];
