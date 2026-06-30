<?php

return [
    'providers' => [
        'enterprise_wechat' => [
            'enabled' => false,
            'corp_id' => env('ENTERPRISE_WECHAT_CORP_ID', ''),
            'corp_secret' => env('ENTERPRISE_WECHAT_CORP_SECRET', ''),
            'agent_id' => env('ENTERPRISE_WECHAT_AGENT_ID', ''),
            'token' => env('ENTERPRISE_WECHAT_TOKEN', ''),
            'encoding_aes_key' => env('ENTERPRISE_WECHAT_ENCODING_AES_KEY', ''),
        ],

        'wechat_official' => [
            'enabled' => false,
            'app_id' => env('WECHAT_OFFICIAL_APP_ID', ''),
            'app_secret' => env('WECHAT_OFFICIAL_APP_SECRET', ''),
            'token' => env('WECHAT_OFFICIAL_TOKEN', ''),
            'encoding_aes_key' => env('WECHAT_OFFICIAL_ENCODING_AES_KEY', ''),
        ],

        'wechat_mini_program' => [
            'enabled' => false,
            'app_id' => env('WECHAT_MINI_APP_ID', ''),
            'app_secret' => env('WECHAT_MINI_APP_SECRET', ''),
        ],
    ],

    'cache' => [
        'prefix' => 'channel:',
        'token_ttl' => 7000,
    ],
];
