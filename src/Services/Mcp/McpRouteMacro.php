<?php

namespace MultiTenantSaas\Services\Mcp;

use Illuminate\Support\Facades\Route;

/**
 * Route::mcp() 宏注册
 *
 * 一行代码注册 MCP 端点。
 *
 * 用法:
 * Route::mcp()  → 注册 POST /v1/mcp 和 GET /v1/mcp/sse
 * Route::mcp('custom-path')  → 注册 POST /v1/custom-path 和 GET /v1/custom-path/sse
 */
class McpRouteMacro
{
    public static function register(): void
    {
        Route::macro('mcp', function (string $prefix = 'mcp') {
            $mcp = Route::prefix("v1/{$prefix}")
                ->middleware(['mcp.auth', 'throttle:mcp'])
                ->group(function () {
                    Route::post('/', [\App\Http\Controllers\Api\McpServerController::class, 'handle']);
                    Route::get('/sse', [\App\Http\Controllers\Api\McpServerController::class, 'handle']);
                    Route::post('/sse', [\App\Http\Controllers\Api\McpServerController::class, 'handle']);
                });

            return $mcp;
        });
    }
}