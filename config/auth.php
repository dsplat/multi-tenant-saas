<?php

use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Operator\Models\Operator;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | web：User 会话 guard（终端用户 SPA）。
    | operator-web：Operator 会话 guard（admin/console 管理端 SPA，
    |   配合 Sanctum stateful 域实现 httpOnly Cookie 双模认证）。
    | Bearer token（auth:sanctum）与会话 Cookie 并存，API 客户端不受影响。
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'operator-web' => [
            'driver' => 'session',
            'provider' => 'operators',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | 系统身份模型铁律：仅 Operator 与 User 两种可认证身份实体。
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        'operators' => [
            'driver' => 'eloquent',
            'model' => Operator::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

    /*
    |--------------------------------------------------------------------------
    | OAuth 统一回调域（平台级虚拟 IDP）
    |--------------------------------------------------------------------------
    |
    | 微信/企业微信/支付宝等第三方应用存在「单应用单回调域」限制：
    | 企业每接入一个平台（回调域不同）就需新建一个应用，openid 割裂。
    |
    | 配置 OAUTH_CALLBACK_DOMAIN（如 auth.neihang.com）后，所有租户的 OAuth
    | 回调统一指向 https://{callback_domain}/api/v1/auth/{provider}/callback：
    | - 租户在微信后台只需配置一次回调域，改自定义域名不再断登录
    | - state 携带租户上下文（{tenantId}.{random}），回调时据此恢复租户并回跳来源域
    |
    | 留空则回退到「按租户域名推导回调地址」的旧逻辑。
    | 委托模式（delegated/IDP）不参与：企业 IDP 场景微信回调域归企业自己管理。
    |
    */

    'oauth' => [
        'callback_domain' => env('OAUTH_CALLBACK_DOMAIN', ''),
    ],

];
