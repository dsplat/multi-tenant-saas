<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Domain\Http\Controllers\VerificationFileController;

/*
|--------------------------------------------------------------------------
| 域名验证文件公开路由（裸路由，无中间件链）
|--------------------------------------------------------------------------
|
| 微信/企微/支付宝验证服务器直接 GET 租户域名根路径文件：
|   - 平台归属验证：/.well-known/tenant-verify/{token}.txt
|   - 第三方平台验证：/{WW_verify|MP_verify|alipay_verify|verify_}_{rand}.txt
|
| 不挂 web 组：规避 EnforceCanonicalEntry 对 pending 自定义域名的 301 收敛
| （验证文件必须在域名未生效时即可访问）。控制器内按 Host 手动解析租户。
|
*/

Route::get('/.well-known/tenant-verify/{token}.txt', [VerificationFileController::class, 'token'])
    ->where('token', '[A-Za-z0-9]{16,64}');

Route::get('/{file}.txt', [VerificationFileController::class, 'file'])
    ->where('file', '(WW_verify|MP_verify|alipay_verify|verify_)[A-Za-z0-9_]{8,64}');
