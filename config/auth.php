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

];
