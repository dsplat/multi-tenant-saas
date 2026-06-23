<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 第三方登录配置
    |--------------------------------------------------------------------------
    */

    'wechat' => [
        'client_id' => env('WECHAT_CLIENT_ID', ''),
        'client_secret' => env('WECHAT_CLIENT_SECRET', ''),
        'redirect' => env('WECHAT_REDIRECT_URI', '/auth/wechat/callback'),
    ],

    'dingtalk' => [
        'client_id' => env('DINGTALK_CLIENT_ID', ''),
        'client_secret' => env('DINGTALK_CLIENT_SECRET', ''),
        'redirect' => env('DINGTALK_REDIRECT_URI', '/auth/dingtalk/callback'),
    ],

    'feishu' => [
        'client_id' => env('FEISHU_CLIENT_ID', ''),
        'client_secret' => env('FEISHU_CLIENT_SECRET', ''),
        'redirect' => env('FEISHU_REDIRECT_URI', '/auth/feishu/callback'),
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID', ''),
        'client_secret' => env('GITHUB_CLIENT_SECRET', ''),
        'redirect' => env('GITHUB_REDIRECT_URI', '/auth/github/callback'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],
];
