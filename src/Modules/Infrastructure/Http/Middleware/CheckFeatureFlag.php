<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Platform\Services\FeatureFlagService;
use Symfony\Component\HttpFoundation\Response;

/**
 * 功能开关中间件
 *
 * 通过路由参数指定开关名，未启用时返回 404。
 *
 * 用法：
 *   Route::get('...', '...')->middleware('feature.flag:ai_text');
 */
class CheckFeatureFlag
{
    public function __construct(
        private readonly FeatureFlagService $flagService,
    ) {}

    /**
     * @param  string  $flagName  中间件参数指定的开关名称
     */
    public function handle(Request $request, Closure $next, string $flagName): Response
    {
        $tenantId = TenantContext::getId() !== null ? (int) TenantContext::getId() : null;
        $userId = $request->user()?->getAuthIdentifier();
        $userId = $userId !== null ? (int) $userId : null;

        if (! $this->flagService->isEnabled($flagName, $tenantId, $userId)) {
            return response()->json([
                'success' => false,
                'message' => trans('common.feature_flag_disabled'),
                'error_code' => 'FEATURE_FLAG_DISABLED',
            ], Response::HTTP_NOT_FOUND);
        }

        return $next($request);
    }
}
