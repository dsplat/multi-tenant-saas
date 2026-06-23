<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 微信支付配置
    |--------------------------------------------------------------------------
    */
    'wechat' => [
        'default' => [
            'app_id' => env('WECHAT_PAY_APP_ID', ''),
            'mch_id' => env('WECHAT_PAY_MCH_ID', ''),
            'notify_url' => env('WECHAT_PAY_NOTIFY_URL', ''),
            'serial_no' => env('WECHAT_PAY_SERIAL_NO', ''),
            'private_key' => env('WECHAT_PAY_PRIVATE_KEY', ''),
            'public_key_path' => env('WECHAT_PAY_PUBLIC_KEY_PATH', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 支付宝配置
    |--------------------------------------------------------------------------
    */
    'alipay' => [
        'default' => [
            'app_id' => env('ALIPAY_APP_ID', ''),
            'notify_url' => env('ALIPAY_NOTIFY_URL', ''),
            'return_url' => env('ALIPAY_RETURN_URL', ''),
            'ali_public_key' => env('ALIPAY_PUBLIC_KEY', ''),
            'private_key' => env('ALIPAY_PRIVATE_KEY', ''),
            'mode' => env('ALIPAY_MODE', 'normal'), // normal 或 sandbox
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 日志配置
    |--------------------------------------------------------------------------
    */
    'log' => [
        'enable' => true,
        'file' => storage_path('logs/pay.log'),
    ],
];
