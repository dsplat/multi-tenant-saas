<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        \MultiTenantSaas\TenancyServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend([
            \App\Http\Middleware\AddSecurityHeaders::class,
            \MultiTenantSaas\Modules\Infrastructure\Http\Middleware\IdentifyDomain::class,
            \MultiTenantSaas\Modules\Infrastructure\Http\Middleware\EnforceDomainSegregation::class,
            \MultiTenantSaas\Modules\Infrastructure\Http\Middleware\BindSessionDomain::class,
        ]);

        // API 请求未认证时返回 401 JSON，不重定向到 login 路由
        $middleware->redirectTo(null);

        $middleware->web(prepend: [
            \MultiTenantSaas\Modules\Infrastructure\Http\Middleware\CastRouteParameters::class,
            \MultiTenantSaas\Modules\Infrastructure\Http\Middleware\IdentifyTenant::class,
            \MultiTenantSaas\Modules\Operator\Http\Middleware\IdentifyOperator::class,
        ]);

        $middleware->api(prepend: [
            // Sanctum 双模认证：仅对 stateful 域（admin/console SPA，按 Origin/Referer 判定）
            // 启用会话中间件（Cookie 加密/会话启动/CSRF 校验）；第三方 API 客户端不受影响。
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \MultiTenantSaas\Modules\Infrastructure\Http\Middleware\CastRouteParameters::class,
            \MultiTenantSaas\Modules\Infrastructure\Http\Middleware\IdentifyTenant::class,
            \MultiTenantSaas\Modules\Operator\Http\Middleware\IdentifyOperator::class,
            \MultiTenantSaas\Modules\Infrastructure\Http\Middleware\SetLocale::class,
        ]);

        $middleware->alias([
            'tenant.identify' => \MultiTenantSaas\Modules\Infrastructure\Http\Middleware\IdentifyTenant::class,
            'tenant.ensure' => \MultiTenantSaas\Modules\Infrastructure\Http\Middleware\EnsureTenantContext::class,
            'tenant.permission' => \MultiTenantSaas\Modules\Auth\Http\Middleware\CheckPermission::class,
            'rbac.permission' => \MultiTenantSaas\Modules\Auth\Http\Middleware\CheckRbacPermission::class,
            'mcp.auth' => \MultiTenantSaas\Modules\Infrastructure\Http\Middleware\McpMiddleware::class,
            'operator.auth' => \MultiTenantSaas\Modules\Operator\Http\Middleware\EnsureOperator::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // API 请求统一返回 JSON
        $exceptions->shouldRenderJsonWhen(fn() => true);

        // 验证异常 → 422
        $exceptions->render(function (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        });

        // 领域异常 → 按异常声明的状态码返回统一 JSON 结构（500 级在生产环境隐藏细节）
        $exceptions->render(function (\MultiTenantSaas\Exceptions\DomainException $e) {
            $statusCode = $e->getStatusCode();
            $message = ($statusCode >= 500 && app()->environment('production'))
                ? '服务器内部错误'
                : $e->getMessage();

            return response()->json([
                'success' => false,
                'message' => $message,
            ], $statusCode);
        });

        // 生产环境隐藏 500 级错误细节（携带 <500 状态码的异常保留原状态码渲染）
        $exceptions->renderable(function (\Throwable $e) {
            if (app()->environment('production')) {
                $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;

                // HttpException（abort() 抛出的 403/404/429 等）保留原状态码和消息
                if ($statusCode < 500) {
                    if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                        return response()->json([
                            'success' => false,
                            'message' => $e->getMessage() ?: 'Request failed',
                        ], $statusCode);
                    }

                    return null;
                }

                // 其他未知异常统一 500，不暴露细节
                return response()->json([
                    'success' => false,
                    'message' => '服务器内部错误',
                ], 500);
            }
        });
    })->create();
