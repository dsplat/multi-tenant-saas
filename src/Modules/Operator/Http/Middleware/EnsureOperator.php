<?php

namespace MultiTenantSaas\Modules\Operator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureOperator — 强制要求当前请求以已认证的 Operator 身份发起
 *
 * 实现机制：直接解析 Bearer token（Sanctum PersonalAccessToken），
 * 通过 morph 关系还原出 Operator 实例，避免依赖 web session guard。
 *
 * 中间件别名：auth:operator（在 bootstrap/app.php 中注册）
 *
 * 用法：
 *   Route::middleware('auth:operator')->group(...)
 *   Route::post('/tenants/onboarding/...', ...)->middleware('auth:operator');
 *
 * 失败响应：
 *   - 缺失/无效 token：401 JSON { success:false, message:'Unauthorized' }
 *   - token 对应不是 Operator 实例：403 JSON { success:false, message:'Operator scope required' }
 *
 * 成功后请求属性：
 *   - $request->user() 返回 Operator 实例（设置 userResolver）
 *   - $request->attributes->get('operator_id') 返回 operator_id（兼容既有中间件链）
 */
class EnsureOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $operator = $this->resolveOperator($request);

        if (! $operator) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        // 让后续 $request->user() 直接返回 Operator
        $request->setUserResolver(fn () => $operator);

        $request->attributes->set('operator_id', $operator->operator_id);
        $request->attributes->set('operator', $operator);

        return $next($request);
    }

    /**
     * 从 Authorization Bearer 头解析出 Operator 实例
     */
    protected function resolveOperator(Request $request): ?Operator
    {
        $header = $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $plainText = trim(substr($header, 7));
        if ($plainText === '') {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($plainText);
        if (! $accessToken) {
            return null;
        }

        $tokenable = $accessToken->tokenable;

        // 严格校验 token 持有者是 Operator，而非其他模型（防止 User token 冒用）
        if (! $tokenable instanceof Operator) {
            return null;
        }

        return $tokenable;
    }
}
