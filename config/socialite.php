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

    /*
    |--------------------------------------------------------------------------
    | SAML 2.0 Service Provider 配置
    |--------------------------------------------------------------------------
    |
    | 本系统作为 SAML Service Provider 的默认参数。
    | 租户级 IdP 配置存储在 sso_providers 表中（见 SsoService）。
    |
    */

    'saml' => [
        // SP 默认 EntityID（可被租户级配置覆盖）
        'sp_entity_id' => env('SAML_SP_ENTITY_ID', 'saml:sp'),
        // 默认 ACS 路径（相对站点根）
        'acs_path' => env('SAML_ACS_PATH', '/api/v1/sso/saml/acs'),
        // 是否强制 IdP 签名校验（生产环境建议 true）
        'require_signed' => env('SAML_REQUIRE_SIGNED', true),
        // 默认属性映射（IdP 属性 -> 本地字段）
        'attribute_mapping' => [
            'external_id' => 'nameid',
            'email' => 'email',
            'name' => 'displayname',
            'avatar' => 'picture',
        ],
    ],
];
