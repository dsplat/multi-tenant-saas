<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Models\User;

// 认证相关 API
Route::prefix('v1/auth')->group(function () {
    
    // 登录
    Route::post('/login', function (Request $request) {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !password_verify($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => '邮箱或密码错误',
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => '账号已被禁用',
            ], 403);
        }

        $token = $user->createToken('admin-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'user_id' => $user->user_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'token' => $token,
            ],
        ]);
    });

    // 获取当前用户
    Route::middleware('auth:sanctum')->get('/me', function (Request $request) {
        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => $request->user()->user_id,
                'name' => $request->user()->name,
                'email' => $request->user()->email,
                'role' => $request->user()->role,
            ],
        ]);
    });

    // 登出
    Route::middleware('auth:sanctum')->post('/logout', function (Request $request) {
        $request->user()->currentAccessToken()->delete();
        return response()->json([
            'success' => true,
            'message' => '已登出',
        ]);
    });
});

// 租户列表（需要认证）
Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    
    // 租户列表
    Route::get('/tenants', function () {
        $tenants = \MultiTenantSaas\Models\Tenant::paginate(15);
        return response()->json([
            'success' => true,
            'data' => $tenants->items(),
            'meta' => [
                'current_page' => $tenants->currentPage(),
                'last_page' => $tenants->lastPage(),
                'per_page' => $tenants->perPage(),
                'total' => $tenants->total(),
            ],
        ]);
    });
});
