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
            \MultiTenantSaas\Middleware\IdentifyDomain::class,
        ]);

        $middleware->web(prepend: [
            \MultiTenantSaas\Middleware\IdentifyTenant::class,
            \MultiTenantSaas\Modules\Operator\Http\Middleware\IdentifyOperator::class,
        ]);

        $middleware->api(prepend: [
            \MultiTenantSaas\Middleware\IdentifyTenant::class,
            \MultiTenantSaas\Modules\Operator\Http\Middleware\IdentifyOperator::class,
            \MultiTenantSaas\Middleware\SetLocale::class,
        ]);

        $middleware->alias([
            'tenant.ensure' => \MultiTenantSaas\Middleware\EnsureTenantContext::class,
            'tenant.permission' => \MultiTenantSaas\Modules\Auth\Http\Middleware\CheckPermission::class,
            'rbac.permission' => \MultiTenantSaas\Modules\Auth\Http\Middleware\CheckRbacPermission::class,
            'mcp.auth' => \MultiTenantSaas\Middleware\McpMiddleware::class,
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

        // 生产环境隐藏详细错误
        $exceptions->renderable(function (\Throwable $e) {
            if (app()->environment('production')) {
                return response()->json([
                    'success' => false,
                    'message' => '服务器内部错误',
                ], 500);
            }
        });
    })->create();
