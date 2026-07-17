<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 自动将路由参数中的数字字符串转换为整数
 *
 * 解决 Laravel 路由参数始终是 string 类型，但 Controller/Service
 * 方法声明了 int 类型提示导致 TypeError 的问题。
 */
class CastRouteParameters
{
    /**
     * 需要转换为整数的常见路由参数名
     */
    private const INT_PARAMS = [
        'tenantId', 'userId', 'operatorId', 'roleId', 'permissionId',
        'planId', 'webhookId', 'orderId', 'ticketId', 'sandboxId',
        'moduleId', 'pluginId', 'workflowId', 'executionId',
        'alertId', 'ruleId', 'reportId', 'snapshotId',
        'id',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route) {
            foreach (self::INT_PARAMS as $param) {
                $value = $route->parameter($param);
                if (is_string($value) && ctype_digit($value)) {
                    $route->setParameter($param, (int) $value);
                }
            }
        }

        return $next($request);
    }
}
